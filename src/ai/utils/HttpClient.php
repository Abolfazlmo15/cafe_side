<?php
// src/ai/utils/HttpClient.php
// =============================================================
// Minimal HTTP client. Uses cURL if available, falls back to
// file_get_contents with a stream context otherwise. Both paths
// return the same shape so providers never need to know which
// was used.
//
// IPv4 enforcement:
//   On Windows/XAMPP, PHP's resolver returns IPv6 addresses first.
//   Many shared hosts (and some home routers) drop IPv6 traffic
//   silently, causing cURL to hang until the connect timeout fires
//   and report "Could not resolve host". Forcing IPv4 resolution
//   bypasses the problem entirely.
//
// Phase 7 — Cloudflare Worker relay support:
//   When setForceRelay(true) is called, requests to known AI
//   provider hosts are rewritten to flow through a Cloudflare
//   Worker relay. The relay adds the real Authorization header
//   on the server side, so the PHP app never needs to send keys
//   over the wire when using the relay.
//
//   URL rewriting:
//     https://api.groq.com/openai/v1/chat/completions
//       -> https://<relay>/groq/openai/v1/chat/completions
//
//   Header rewriting:
//     Authorization: Bearer <provider-key>   [removed]
//     X-Relay-Secret: <relay-secret>         [added]
//
//   The relay is a fallback, not a default. ProviderRegistry
//   flips this flag only after a direct request fails with a
//   network-level error.
//
// Return shape (every request):
//   [
//     'ok'         => bool,    // 2xx?
//     'status'     => int,     // HTTP status, 0 on transport failure
//     'body'       => string,  // raw response body
//     'headers'    => array,   // response headers, lowercase keys
//     'error'      => ?string, // transport error, null on success
//     'latency_ms' => int,     // wall-clock time for the request
//   ]

class HttpClient
{
    private int    $timeout;
    private int    $connectTimeout;
    private string $userAgent;
    private bool   $forceRelay = false;

    /**
     * Map of real provider hostnames to their relay path segments.
     * Only hosts in this map are eligible for relay rewriting. Any
     * other host (httpbin, proxy test endpoints, etc.) passes through
     * untouched even when forceRelay is on.
     */
    private const RELAY_HOST_MAP = [
        'api.groq.com'                  => 'groq',
        'openrouter.ai'                 => 'openrouter',
        'api.deepseek.com'              => 'deepseek',
        'api.mistral.ai'                => 'mistral',
        'api.together.xyz'              => 'together',
        'api-inference.huggingface.co'  => 'huggingface',
    ];

    public function __construct(
        int $timeout = 30,
        int $connectTimeout = 10,
        string $userAgent = 'CafeSideAI/1.0'
    ) {
        $this->timeout        = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->userAgent      = $userAgent;
    }

    /**
     * Enable or disable relay mode on this instance.
     * When true, every request to a mapped provider host is
     * rewritten to flow through the Cloudflare Worker relay.
     */
    public function setForceRelay(bool $force): void
    {
        $this->forceRelay = $force;
    }

    public function isForceRelay(): bool
    {
        return $this->forceRelay;
    }

    /**
     * True when the .env has both AI_RELAY_URL and AI_RELAY_SECRET set.
     * The caller (ProviderRegistry) uses this to decide whether a
     * relay retry is even possible.
     */
    public static function relayAvailable(): bool
    {
        return defined('AI_RELAY_URL')
            && AI_RELAY_URL !== ''
            && defined('AI_RELAY_SECRET')
            && AI_RELAY_SECRET !== '';
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers, null);
    }

    public function post(string $url, array $headers = [], ?string $body = null): array
    {
        return $this->request('POST', $url, $headers, $body);
    }

    /**
     * Rewrite a request URL and its headers to flow through the relay.
     * Returns [$newUrl, $newHeaders]. If the host isn't in the map,
     * or the relay isn't configured, returns the inputs unchanged.
     */
    private function applyRelay(string $url, array $headers): array
    {
        if (!self::relayAvailable()) {
            return [$url, $headers];
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return [$url, $headers];
        }

        $segment = self::RELAY_HOST_MAP[$host] ?? null;
        if ($segment === null) {
            // Not a mapped provider — leave untouched.
            return [$url, $headers];
        }

        $path  = parse_url($url, PHP_URL_PATH)  ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);
        $queryStr = is_string($query) && $query !== '' ? '?' . $query : '';

        $relayUrl = rtrim(AI_RELAY_URL, '/') . '/' . $segment . $path . $queryStr;

        // Strip the provider Authorization header — the worker adds
        // its own. Add X-Relay-Secret for the worker to authenticate us.
        $newHeaders = [];
        foreach ($headers as $h) {
            if (stripos($h, 'authorization:') === 0) {
                continue;
            }
            $newHeaders[] = $h;
        }
        $newHeaders[] = 'X-Relay-Secret: ' . AI_RELAY_SECRET;

        return [$relayUrl, $newHeaders];
    }

    private function request(string $method, string $url, array $headers, ?string $body): array
    {
        $start = microtime(true);

        if ($this->forceRelay) {
            [$url, $headers] = $this->applyRelay($url, $headers);
        }

        if (function_exists('curl_init')) {
            $result = $this->requestWithCurl($method, $url, $headers, $body);
        } else {
            $result = $this->requestWithStream($method, $url, $headers, $body);
        }

        $result['latency_ms'] = (int) ((microtime(true) - $start) * 1000);
        return $result;
    }

    private function requestWithCurl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => $this->userAgent,

            // Force IPv4 resolution — see file header comment.
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $raw        = curl_exec($ch);
        $err        = curl_error($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        // curl_close() is a no-op since PHP 8.0 and deprecated in PHP 8.5.
        // The handle is garbage-collected. We simply don't call it.

        if ($raw === false) {
            return [
                'ok'      => false,
                'status'  => 0,
                'body'    => '',
                'headers' => [],
                'error'   => $err ?: 'cURL request failed',
            ];
        }

        $rawHeaders = substr($raw, 0, $headerSize);
        $respBody   = substr($raw, $headerSize);

        return [
            'ok'      => $status >= 200 && $status < 300,
            'status'  => $status,
            'body'    => $respBody,
            'headers' => $this->parseHeaders($rawHeaders),
            'error'   => null,
        ];
    }

    private function requestWithStream(string $method, string $url, array $headers, ?string $body): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'content'       => $body ?? '',
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
                'user_agent'    => $this->userAgent,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
            'socket' => [
                'bindto' => '0.0.0.0:0',
            ],
        ]);

        $respBody = @file_get_contents($url, false, $ctx);
        $status   = 0;
        $respHeaders = [];

        // PHP 8.5 deprecated the $http_response_header magic variable.
        // Use the new function if available, else fall back to the global.
        if (function_exists('http_get_last_response_headers')) {
            $rawLines = http_get_last_response_headers() ?? [];
        } else {
            // Pre-8.4 PHP populates $http_response_header automatically
            // in the local scope of the file_get_contents call above.
            $rawLines = $http_response_header ?? [];
        }

        if (is_array($rawLines)) {
            foreach ($rawLines as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                    $status = (int) $m[1];
                } elseif (strpos($line, ':') !== false) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
            }
        }

        if ($respBody === false) {
            $lastErr = error_get_last();
            return [
                'ok'      => false,
                'status'  => 0,
                'body'    => '',
                'headers' => [],
                'error'   => $lastErr['message'] ?? 'Stream request failed',
            ];
        }

        return [
            'ok'      => $status >= 200 && $status < 300,
            'status'  => $status,
            'body'    => $respBody,
            'headers' => $respHeaders,
            'error'   => null,
        ];
    }

    private function parseHeaders(string $raw): array
    {
        $out = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $out[strtolower(trim($k))] = trim($v);
            }
        }
        return $out;
    }
}