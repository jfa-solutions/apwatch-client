<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\Dispatcher;
use Apwatch\Client\EventBuffer;
use Apwatch\Client\TraceContext;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

/**
 * Artisan commands (including `queue:work`/`horizon`, `schedule:run`) have
 * no HTTP response for defer() to hook into, and the process may exit
 * right after — flush immediately once the command finishes, same
 * reasoning as CaptureJob.
 */
class CaptureCommand
{
    /** @var array<string, float> */
    private array $startedAt = [];

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Dispatcher $dispatcher,
        private readonly TraceContext $trace,
    ) {}

    public function starting(CommandStarting $event): void
    {
        // Opens a trace of its own, so everything the command logs, queries
        // or throws is grouped under it rather than left unattached.
        $this->trace->restart();

        $this->startedAt[$this->key($event->command)] = microtime(true);
    }

    public function finished(CommandFinished $event): void
    {
        $key = $this->key($event->command);
        $start = $this->startedAt[$key] ?? microtime(true);
        unset($this->startedAt[$key]);

        $this->buffer->push('command', [
            'command' => $event->command ?? 'unknown',
            'exit_code' => $event->exitCode,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ]);

        $this->dispatcher->flush();
    }

    private function key(?string $command): string
    {
        return $command ?? 'unknown';
    }
}
