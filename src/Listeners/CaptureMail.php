<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\EventBuffer;
use Illuminate\Mail\Events\MessageSent;

class CaptureMail
{
    public function __construct(private readonly EventBuffer $buffer) {}

    public function handle(MessageSent $event): void
    {
        $message = $event->message;

        $this->buffer->push('mail', [
            'to' => array_map(
                fn ($address) => $address->getAddress(),
                $message->getTo(),
            ),
            'subject' => (string) $message->getSubject(),
        ]);
    }
}
