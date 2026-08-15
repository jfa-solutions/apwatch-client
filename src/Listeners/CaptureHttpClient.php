<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\EventBuffer;
use Illuminate\Http\Client\Events\ResponseReceived;

class CaptureHttpClient
{
    public function __construct(private readonly EventBuffer $buffer) {}

    public function handle(ResponseReceived $event): void
    {
        $url = (string) $event->request->url();

        // Never capture our own calls to the apwatch ingest endpoint —
        // it's noise at best and a feedback loop at worst.
        if ($this->isApwatchEndpoint($url)) {
            return;
        }

        $this->buffer->push('http_client', [
            'method' => $event->request->method(),
            'url' => $url,
            'status' => $event->response->status(),
        ]);
    }

    private function isApwatchEndpoint(string $url): bool
    {
        $endpoint = config('apwatch.endpoint');

        return $endpoint && str_starts_with($url, rtrim($endpoint, '/'));
    }
}
