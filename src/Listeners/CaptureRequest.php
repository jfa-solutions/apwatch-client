<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\EventBuffer;
use Apwatch\Client\Support\BodyCapture;
use Apwatch\Client\Support\InputRedactor;
use Apwatch\Client\Support\StatusList;
use Apwatch\Client\UserContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

        if ($this->shouldCaptureResponse($response->getStatusCode())) {
            $payload['response'] = $this->capturedResponse($response);
        }

        // Set before the event is pushed, but read at flush time, so it lands
        // on every event this request produced — not only on this one.
        if (config('apwatch.capture.user')) {
            $this->users->set($this->resolveUser());
        }

        $this->buffer->push('request', $payload);

        return $response;
    }

    private function shouldCaptureInput(int $status): bool
    {
        return (bool) config('apwatch.capture.request_input')
            && StatusList::matches((string) config('apwatch.request_input.statuses', ''), $status);
    }

    private function shouldCaptureResponse(int $status): bool
    {
        return (bool) config('apwatch.capture.request_response')
            && StatusList::matches((string) config('apwatch.response.statuses', ''), $status);
    }

    /**
     * What the app actually answered. Streamed and file responses are
     * reported as skipped rather than read: their content only exists while
     * it is being sent, and forcing it into memory here would defeat the
     * point of streaming it.
     *
     * @return array<string, mixed>
     */
    private function capturedResponse(Response $response): array
    {
        $body = ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse)
            ? false
            : $response->getContent();

        $capture = new BodyCapture(
            InputRedactor::fromConfig(
                (int) config('apwatch.response.max_length'),
                (int) config('apwatch.response.max_value_length'),
            ),
            (int) config('apwatch.response.max_length'),
        );

        return $capture->capture($body, (string) $response->headers->get('Content-Type', ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function redactedInput(Request $request): array
    {
        $redactor = InputRedactor::fromConfig(
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
