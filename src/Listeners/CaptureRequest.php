<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\EventBuffer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureRequest
{
    // Redacted rather than dropped — the header still shows up so it's
    // clear it was present, just not its value.
    private const SENSITIVE_HEADERS = [
        'authorization', 'cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token', 'proxy-authorization',
    ];

    public function __construct(private readonly EventBuffer $buffer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $payload = [
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            // Reflects the host app's own TrustProxies config — if it isn't
            // configured for a reverse-proxied app, this will be the proxy's
            // IP rather than the real client's.
            'ip' => (string) $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ];

        if (config('apwatch.capture.request_headers')) {
            $payload['headers'] = $this->redactedHeaders($request);
        }

        $this->buffer->push('request', $payload);

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function redactedHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = in_array(strtolower($name), self::SENSITIVE_HEADERS, true)
                ? '[REDACTED]'
                : implode(', ', $values);
        }

        return $headers;
    }
}
