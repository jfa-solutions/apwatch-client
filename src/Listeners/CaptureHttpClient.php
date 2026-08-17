<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\EventBuffer;
use Apwatch\Client\Support\BodyCapture;
use Apwatch\Client\Support\InputRedactor;
use Apwatch\Client\Support\StatusList;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;

class CaptureHttpClient
{
    // Same reasoning as CaptureRequest's list: an outgoing call's own auth
    // header is exactly the kind of credential that must not be stored, but
    // knowing it was sent is useful.
    private const SENSITIVE_HEADERS = [
        'authorization', 'cookie', 'set-cookie', 'proxy-authorization', 'x-api-key',
    ];

    public function __construct(private readonly EventBuffer $buffer) {}

    public function handle(ResponseReceived $event): void
    {
        $url = (string) $event->request->url();

        // Never capture our own calls to the apwatch ingest endpoint —
        // it's noise at best and a feedback loop at worst.
        if ($this->isApwatchEndpoint($url)) {
            return;
        }

        $status = $event->response->status();

        $payload = [
            'method' => $event->request->method(),
            'url' => $url,
            'status' => $status,
            'duration_ms' => $this->durationMs($event->response),
        ];

        if (config('apwatch.capture.http_client_body')) {
            $payload['request'] = $this->capturedRequest($event->request);
        }

        if ($this->shouldCaptureResponse($status)) {
            $payload['response'] = $this->capturedResponse($event->response);
        }

        $this->buffer->push('http_client', $payload);
    }

    /**
     * Read from Guzzle's transfer stats, which is the only place the wall
     * time of the call is recorded — ResponseReceived carries no timing, and
     * pairing it with RequestSending is not possible since neither event
     * identifies which call it belongs to. Faked responses have no stats, so
     * this reports 0 under Http::fake().
     */
    private function durationMs(Response $response): int
    {
        $totalSeconds = $response->handlerStats()['total_time'] ?? 0;

        return (int) round(((float) $totalSeconds) * 1000);
    }

    /**
     * @return array<string, mixed>
     */
    private function capturedRequest(Request $request): array
    {
        $captured = $this->bodyCapture()->capture(
            $request->body(),
            $this->firstHeader($request->headers(), 'Content-Type'),
        );

        $captured['headers'] = $this->redactedHeaders($request->headers());

        return $captured;
    }

    /**
     * @return array<string, mixed>
     */
    private function capturedResponse(Response $response): array
    {
        $captured = $this->bodyCapture()->capture(
            $response->body(),
            $this->firstHeader($response->headers(), 'Content-Type'),
        );

        $captured['headers'] = $this->redactedHeaders($response->headers());

        return $captured;
    }

    private function bodyCapture(): BodyCapture
    {
        $maxLength = (int) config('apwatch.response.max_length');

        return new BodyCapture(
            InputRedactor::fromConfig($maxLength, (int) config('apwatch.response.max_value_length')),
            $maxLength,
        );
    }

    private function shouldCaptureResponse(int $status): bool
    {
        return (bool) config('apwatch.capture.http_client_body')
            && StatusList::matches((string) config('apwatch.response.statuses', ''), $status);
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    private function redactedHeaders(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $name => $values) {
            $redacted[$name] = in_array(strtolower($name), self::SENSITIVE_HEADERS, true)
                ? InputRedactor::REDACTED
                : implode(', ', (array) $values);
        }

        return $redacted;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     */
    private function firstHeader(array $headers, string $name): string
    {
        foreach ($headers as $header => $values) {
            if (strcasecmp($header, $name) === 0) {
                return (string) (((array) $values)[0] ?? '');
            }
        }

        return '';
    }

    private function isApwatchEndpoint(string $url): bool
    {
        $endpoint = config('apwatch.endpoint');

        return $endpoint && str_starts_with($url, rtrim($endpoint, '/'));
    }
}
