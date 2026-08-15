<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\EventBuffer;
use Illuminate\Log\Events\MessageLogged;

class CaptureLog
{
    public function __construct(private readonly EventBuffer $buffer) {}

    public function handle(MessageLogged $event): void
    {
        $this->buffer->push('log', [
            'level' => $event->level,
            'message' => $event->message,
        ]);
    }
}
