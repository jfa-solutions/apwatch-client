<?php

namespace Apwatch\Client;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends the buffered events for the current request to the apwatch server.
 * Always called from a defer() callback, i.e. after the response has
 * already reached the browser — a slow or unreachable server must never
 * come back to bite the request that triggered it, hence the aggressive
 * timeouts and the blanket try/catch.
 */
class Dispatcher
{
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly UserContext $users,
    ) {}

    public function flush(): void
    {
        if ($this->buffer->isEmpty()) {
            return;
        }

        $apiKey = config('apwatch.api_key');
        $endpoint = config('apwatch.endpoint');

        if (! config('apwatch.enabled') || ! $apiKey || ! $endpoint) {
            return;
        }

        // In a long-running queue worker the buffer is flushed explicitly
        // after every job rather than via defer(), so it must always be
        // cleared here — otherwise a down apwatch server would make it grow
        // without bound for the lifetime of the worker process.
        try {
            Http::withToken($apiKey)
                ->connectTimeout((int) config('apwatch.http.connect_timeout'))
                ->timeout((int) config('apwatch.http.timeout'))
                ->post(rtrim($endpoint, '/').'/api/ingest', [
                    'events' => $this->withUser($this->buffer->all()),
                ]);
        } catch (Throwable) {
            // Swallow: a monitoring outage must never surface to the host app.
        } finally {
            $this->buffer->clear();
            $this->users->clear();
        }
    }

    /**
     * Stamps the request's authenticated user onto every event in the batch.
     * A queue worker has no such user, so this is a no-op there.
     *
     * @param  array<int, array{type: string, occurred_at: string, payload: array<string, mixed>}>  $events
     * @return array<int, array{type: string, occurred_at: string, payload: array<string, mixed>}>
     */
    private function withUser(array $events): array
    {
        $user = $this->users->get();

        if ($user === null) {
            return $events;
        }

        return array_map(function (array $event) use ($user): array {
            $event['payload']['user'] = $user;

            return $event;
        }, $events);
    }
}
