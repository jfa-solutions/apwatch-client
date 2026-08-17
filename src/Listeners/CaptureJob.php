<?php

namespace Apwatch\Client\Listeners;

use Apwatch\Client\Dispatcher;
use Apwatch\Client\EventBuffer;
use Apwatch\Client\TraceContext;
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
        private readonly TraceContext $trace,
    ) {}

    /** @var array<string, int> */
    private array $startedMemory = [];

    public function processing(JobProcessing $event): void
    {
        // A worker handles many jobs in one process, so each one opens its
        // own trace instead of inheriting whatever the previous job left in
        // the singleton — parented to the request that dispatched it, which
        // the provider stamped onto the queue payload.
        $this->trace->restart($this->dispatchedFrom($event->job));

        $this->startedAt[$event->job->getJobId()] = microtime(true);
        $this->startedMemory[$event->job->getJobId()] = memory_get_usage(true);
    }

    /**
     * The trace of whatever queued this job, or null when it was queued by
     * something that had no apwatch client (an older release, another app,
     * or a job pushed straight onto the connection).
     */
    private function dispatchedFrom(Job $job): ?string
    {
        if (! method_exists($job, 'payload')) {
            return null;
        }

        $traceId = $job->payload()['apwatch_trace_id'] ?? null;

        return is_string($traceId) ? $traceId : null;
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
        $jobId = $job->getJobId();
        $start = $this->startedAt[$jobId] ?? microtime(true);
        $startMemory = $this->startedMemory[$jobId] ?? memory_get_usage(true);
        unset($this->startedAt[$jobId], $this->startedMemory[$jobId]);

        $payload = [
            'name' => $job->resolveName(),
            'queue' => $job->getQueue(),
            'connection' => $job->getConnectionName(),
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'attempts' => $job->attempts(),
            // A worker process handles many jobs back to back, so a plain
            // memory_get_peak_usage() would just be the worst peak across
            // *all* jobs so far, not this one — the delta since this job
            // started is what's actually attributable to it.
            'memory_mb' => round((memory_get_usage(true) - $startMemory) / 1024 / 1024, 2),
        ];

        if ($exception !== null) {
            $payload['exception_class'] = get_class($exception);
            $payload['exception_message'] = $exception->getMessage();
        }

        $this->buffer->push('job', $payload);
        $this->dispatcher->flush();
    }
}
