<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\EventBuffer;

/**
 * Wildcard listener for the app's own domain events (OrderPlaced,
 * UserRegistered, ...). Framework-internal events (queries, jobs, mail,
 * outgoing HTTP, logs) are excluded here since they already have their own
 * dedicated, more detailed capture listeners.
 */
class CaptureEvent
{
    // Framework-internal lifecycle events are string-named rather than
    // class-based (e.g. "bootstrapped: Illuminate\Foundation\..."), so a
    // simple "Illuminate\" prefix check isn't enough to exclude them.
    private const IGNORED_PREFIXES = [
        'bootstrapping:', 'bootstrapped:', 'creating:', 'composing:', 'eloquent.', 'locale.',
    ];

    public function __construct(private readonly EventBuffer $buffer) {}

    public function handle(string $eventName, array $payload): void
    {
        if (str_contains($eventName, 'Illuminate\\')) {
            return;
        }

        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($eventName, $prefix)) {
                return;
            }
        }

        $this->buffer->push('event', [
            'name' => $eventName,
        ]);
    }
}
