<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\Dispatcher;
use Apwatch\Client\EventBuffer;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Throwable;

/**
 * One `schedule:run` invocation can run several due tasks in the same
 * process — same "flush right after, no defer()" reasoning as CaptureJob
 * and CaptureCommand. Skips ScheduledTaskSkipped on purpose: a skipped
 * task never actually ran, so there's nothing meaningful to report.
 */
class CaptureSchedule
{
    /** @var array<int, float> */
    private array $startedAt = [];

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function starting(ScheduledTaskStarting $event): void
    {
        $this->startedAt[spl_object_id($event->task)] = microtime(true);
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        // Not $event->runtime: Laravel only tracks it to 2 decimal places
        // of a second (10ms resolution), which rounds anything under 5ms
        // down to a flat 0 — our own microtime tracking (same approach as
        // CaptureJob/CaptureCommand) doesn't have that ceiling.
        $this->record($event->task, 'finished', $this->elapsedMs($event->task));
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->record($event->task, 'failed', $this->elapsedMs($event->task), $event->exception);
    }

    private function record(ScheduledEvent $task, string $status, int $durationMs, ?Throwable $exception = null): void
    {
        $payload = [
            'task' => $task->getSummaryForDisplay(),
            'status' => $status,
            'duration_ms' => $durationMs,
            'memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ];

        if ($exception !== null) {
            $payload['exception_class'] = get_class($exception);
            $payload['exception_message'] = $exception->getMessage();
        }

        $this->buffer->push('schedule', $payload);
        $this->dispatcher->flush();
    }

    private function elapsedMs(ScheduledEvent $task): int
    {
        $key = spl_object_id($task);
        $start = $this->startedAt[$key] ?? microtime(true);
        unset($this->startedAt[$key]);

        return (int) round((microtime(true) - $start) * 1000);
    }
}
