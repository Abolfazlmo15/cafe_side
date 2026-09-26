/**
 * Cafe Side — Cloudflare Worker Relay
 *
 * Sits between the Iranian-hosted PHP app and AI providers that
 * may be blocked at the network layer from Iran.
 *
 * URL scheme:
 *   https://<worker>/<provider>/<original-path>?<query>
 *
 * Example:
 *   POST https://relay.example.workers.dev/groq/openai/v1/chat/completions
 *     -> forwards to https://api.groq.com/openai/v1/chat/completions
 *        with Authorization: Bearer <GROQ_API_KEY>
 *
 * Auth:
 *   Every request must include X-Relay-Secret: <RELAY_SECRET>.
 *   Mismatched or missing -> 403.
 *
 * Environment variables (set via `wrangler secret put`):
 *   RELAY_SECRET
 *   GROQ_API_KEY
 *   OPENROUTER_API_KEY
 *   DEEPSEEK_API_KEY
 *   MISTRAL_API_KEY
 *   TOGETHER_API_KEY
 *   HUGGINGFACE_TOKEN
 */

const PROVIDERS = {
  groq:        'https://api.groq.com',
  openrouter:  'https://openrouter.ai',
  deepseek:    'https://api.deepseek.com',
  mistral:     'https://api.mistral.ai',
  together:    'https://api.together.xyz',
  huggingface: 'https://api-inference.huggingface.co',
};

const ENV_KEYS = {
  groq:        'GROQ_API_KEY',
  openrouter:  'OPENROUTER_API_KEY',
  deepseek:    'DEEPSEEK_API_KEY',
  mistral:     'MISTRAL_API_KEY',
  together:    'TOGETHER_API_KEY',
  huggingface: 'HUGGINGFACE_TOKEN',
};

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    // Health check — no auth required so you can verify the worker
    // is deployed and which providers have keys.
    if (url.pathname === '/_ping') {
      const configured = Object.keys(PROVIDERS).filter(p => !!env[ENV_KEYS[p]]);
      return new Response(
        JSON.stringify({ ok: true, providers: configured }, null, 2),
        { headers: { 'Content-Type': 'application/json' } }
      );
    }

    // Auth check
    const provided = request.headers.get('X-Relay-Secret') || '';
    const expected = env.RELAY_SECRET || '';
    if (!expected || provided !== expected) {
      return new Response('Forbidden\n', { status: 403 });
    }

    // Parse provider from first path segment
    const parts = url.pathname.split('/').filter(Boolean);
    if (parts.length === 0) {
      return new Response('Missing provider segment\n', { status: 400 });
    }

    const provider = parts.shift();
    const base = PROVIDERS[provider];
    if (!base) {
      return new Response('Unknown provider: ' + provider + '\n', { status: 404 });
    }

    const envKey = ENV_KEYS[provider];
    const apiKey = env[envKey] || '';
    if (!apiKey) {
      return new Response('Provider not configured: ' + provider + '\n', { status: 500 });
    }

    // Build target URL
    const targetPath = '/' + parts.join('/');
    const targetUrl = base + targetPath + url.search;

    // Build forwarded headers
    const forwardHeaders = new Headers(request.headers);
    forwardHeaders.delete('X-Relay-Secret');
    forwardHeaders.delete('Host');
    forwardHeaders.delete('CF-Connecting-IP');
    forwardHeaders.delete('CF-Connecting-IPv6');
    forwardHeaders.delete('X-Forwarded-For');
    forwardHeaders.delete('X-Forwarded-Proto');
    forwardHeaders.delete('X-Real-IP');
    forwardHeaders.set('Authorization', 'Bearer ' + apiKey);

    // Forward
    let upstream;
    try {
      upstream = await fetch(targetUrl, {
        method: request.method,
        headers: forwardHeaders,
        body: (request.method === 'GET' || request.method === 'HEAD')
          ? null
          : request.body,
        redirect: 'manual',
      });
    } catch (err) {
      return new Response(
        'Relay upstream error: ' + (err && err.message ? err.message : err) + '\n',
        { status: 502, headers: { 'Content-Type': 'text/plain; charset=utf-8' } }
      );
    }

    // Return the response with a marker header so the PHP side can
    // see in logs that the relay was used.
    const responseHeaders = new Headers(upstream.headers);
    responseHeaders.set('X-Cafe-Relay', provider);

    return new Response(upstream.body, {
      status: upstream.status,
      headers: responseHeaders,
    });
  },
};