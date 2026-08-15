<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\Dispatcher;
use Apwatch\Client\EventBuffer;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

/**
 * Queue workers have no HTTP response for defer() to hook into, so job
 * events are flushed immediately after each job — that's already
 * background work, so there's no user-facing request to slow down.
 */
class CaptureJob
{
    /** @var array<string, float> */
    private array $startedAt = [];

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function processing(JobProcessing $event): void
    {
        $this->startedAt[$event->job->getJobId()] = microtime(true);
    }

    public function processed(JobProcessed $event): void
    {
        $this->record($event->job, 'processed');
    }

    public function failed(JobFailed $event): void
    {
        $this->record($event->job, 'failed', $event->exception);
    }

    private function record(Job $job, string $status, ?Throwable $exception = null): void
    {
        $start = $this->startedAt[$job->getJobId()] ?? microtime(true);
        unset($this->startedAt[$job->getJobId()]);

        $payload = [
            'name' => $job->resolveName(),
            'queue' => $job->getQueue(),
            'connection' => $job->getConnectionName(),
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'attempts' => $job->attempts(),
        ];

        if ($exception !== null) {
            $payload['exception_class'] = get_class($exception);
            $payload['exception_message'] = $exception->getMessage();
        }

        $this->buffer->push('job', $payload);
        $this->dispatcher->flush();
    }
}
