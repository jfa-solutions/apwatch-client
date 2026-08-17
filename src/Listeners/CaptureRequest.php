<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\EventBuffer;
use Apwatch\Client\Support\InputRedactor;
use Apwatch\Client\UserContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CaptureRequest
{
    // Redacted rather than dropped — the header still shows up so it's
    // clear it was present, just not its value.
    private const SENSITIVE_HEADERS = [
        'authorization', 'cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token', 'proxy-authorization',
    ];

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly UserContext $users,
    ) {}

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

        if ($this->shouldCaptureInput($response->getStatusCode())) {
            $payload['input'] = $this->redactedInput($request);
        }

        // Set before the event is pushed, but read at flush time, so it lands
        // on every event this request produced — not only on this one.
        if (config('apwatch.capture.user')) {
            $this->users->set($this->resolveUser());
        }

        $this->buffer->push('request', $payload);

        return $response;
    }

    /**
     * Statuses are configured as a comma-separated mix of classes ('4xx') and
     * exact codes ('422'), so an app can capture everything that failed
     * validation without paying for every other error.
     */
    private function shouldCaptureInput(int $status): bool
    {
        if (! config('apwatch.capture.request_input')) {
            return false;
        }

        $configured = (string) config('apwatch.request_input.statuses', '');

        foreach (explode(',', $configured) as $token) {
            $token = strtolower(trim($token));

            if ($token === '') {
                continue;
            }

            $matches = str_ends_with($token, 'xx')
                ? (int) substr($token, 0, 1) === intdiv($status, 100)
                : (int) $token === $status;

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function redactedInput(Request $request): array
    {
        $redactor = new InputRedactor(
            (array) config('apwatch.request_input.redact', []),
            (int) config('apwatch.request_input.max_length'),
            (int) config('apwatch.request_input.max_value_length'),
        );

        // Body and query are kept apart rather than merged: which one a value
        // arrived in is often the whole point when debugging a 4xx.
        $body = $request->isJson()
            ? (array) $request->json()->all()
            : $request->post();

        return $redactor->redact(array_filter([
            'body' => $body,
            'query' => $request->query(),
            'files' => $request->allFiles(),
        ], fn (array $section) => $section !== []));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveUser(): ?array
    {
        try {
            $user = Auth::guard(config('apwatch.user.guard'))->user();
        } catch (Throwable) {
            // A misconfigured or unavailable guard must not break the request
            // it was only meant to describe.
            return null;
        }

        if ($user === null) {
            return null;
        }

        $read = function (string $configKey) use ($user): ?string {
            $attribute = config("apwatch.user.{$configKey}");

            $value = $attribute && method_exists($user, 'getAttribute')
                ? $user->getAttribute($attribute)
                : ($user->{$attribute} ?? null);

            return is_scalar($value) ? (string) $value : null;
        };

        return array_filter([
            'id' => $read('id_attribute') ?? (string) $user->getAuthIdentifier(),
            'email' => $read('email_attribute'),
            'name' => $read('name_attribute'),
        ], fn (?string $value) => $value !== null && $value !== '');
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
