<?php

namespace Apwatch\Client;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Accumulates events during a single request lifecycle. Bound as a
 * container singleton, so it lives exactly as long as the request that
 * created it.
 */
class EventBuffer
{
    /** @var array<int, array{type: string, occurred_at: string, payload: array<string, mixed>}> */
    private array $events = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function push(string $type, array $payload, ?DateTimeInterface $occurredAt = null): void
    {
        $this->events[] = [
            'type' => $type,
            'occurred_at' => ($occurredAt ?? new DateTimeImmutable)->format(DateTimeInterface::ATOM),
            'payload' => $payload,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    public function clear(): void
    {
        $this->events = [];
    }

    /**
     * @return array<int, array{type: string, occurred_at: string, payload: array<string, mixed>}>
     */
    public function all(): array
    {
        return $this->events;
    }
}
