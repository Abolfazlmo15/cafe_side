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

    public function __construct(int $timeout = 30, int $connectTimeout = 10, string $userAgent = 'CafeSideAI/1.0')
    {
        $this->timeout        = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->userAgent      = $userAgent;
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers, null);
    }

    public function post(string $url, array $headers = [], ?string $body = null): array
    {
        return $this->request('POST', $url, $headers, $body);
    }

    private function request(string $method, string $url, array $headers, ?string $body): array
    {
        $start = microtime(true);

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

        // Note: curl_close() is a no-op since PHP 8.0 and deprecated in
        // PHP 8.5. The handle is garbage-collected. We simply don't call it.

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