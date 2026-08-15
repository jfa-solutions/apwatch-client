<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\EventBuffer;
use Illuminate\Database\Events\QueryExecuted;

class CaptureQuery
{
    public function __construct(private readonly EventBuffer $buffer) {}

    public function handle(QueryExecuted $event): void
    {
        $this->buffer->push('query', [
            'sql' => $event->sql,
            // Coerced defensively: bindings can contain DateTime/binary
            // values. json_encode (unlike a string cast) never throws, so a
            // capture listener can never crash the host app's request.
            'bindings' => array_map(
                fn ($binding) => is_scalar($binding) || $binding === null
                    ? $binding
                    : (json_encode($binding) ?: '[unserializable]'),
                $event->bindings,
            ),
            'time_ms' => $event->time,
            'connection' => $event->connectionName,
        ]);
    }
}
