<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\EventBuffer;
use Throwable;

class CaptureException
{
    public function __construct(private readonly EventBuffer $buffer) {}

    public function __invoke(Throwable $e): void
    {
        $this->buffer->push('exception', [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
