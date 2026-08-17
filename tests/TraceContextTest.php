<?php

use Apwatch\Client\EventBuffer;
use Apwatch\Client\TraceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

class ApwatchTracedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Log::info('working');
    }
}

it('keeps one trace id for the life of a request and reuses it on every read', function () {
    $trace = new TraceContext;

    expect($trace->id())->toBe($trace->id())
        ->and($trace->id())->toMatch('/^[0-9a-f-]{36}$/')
        ->and($trace->parentId())->toBeNull();
});

it('opens a new trace under a parent when a unit of work restarts', function () {
    $trace = new TraceContext;
    $first = $trace->id();

    $trace->restart('parent-trace');

    expect($trace->id())->not->toBe($first)
        ->and($trace->parentId())->toBe('parent-trace');
});

it('treats an empty parent as no parent at all', function () {
    $trace = new TraceContext;
    $trace->restart('');

    expect($trace->parentId())->toBeNull();
});

it('gives every event a unique id so each one can be linked to directly', function () {
    $buffer = new EventBuffer;

    $buffer->push('log', ['message' => 'one']);
    $buffer->push('log', ['message' => 'two']);

    $events = $buffer->all();

    expect($events[0]['id'])->toMatch('/^[0-9a-f-]{36}$/')
        ->and($events[0]['id'])->not->toBe($events[1]['id']);
});

it('stamps one trace id on every event a single request produced', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::get('/ping', function () {
        Log::info('hello');
        Log::warning('and again');

        return 'pong';
    });

    $this->get('/ping');

    Http::assertSent(function ($request) {
        $events = $request->data()['events'];
        $traceIds = array_unique(array_column($events, 'trace_id'));

        // A request, plus the two log lines it wrote — one trace between them.
        expect($events)->toHaveCount(3)
            ->and($traceIds)->toHaveCount(1)
            ->and($traceIds[0])->toMatch('/^[0-9a-f-]{36}$/')
            ->and($events[0])->not->toHaveKey('parent_trace_id');

        return true;
    });
});

it('links a queued job back to the trace that dispatched it', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);

    // Stands in for the request doing the dispatching: reading the id is
    // exactly what Queue::createPayloadUsing does when the job is pushed.
    $dispatchingTrace = app(TraceContext::class)->id();

    ApwatchTracedJob::dispatch();

    Http::assertSent(function ($request) use ($dispatchingTrace) {
        $events = $request->data()['events'];
        $jobEvents = array_values(array_filter($events, fn (array $e) => $e['type'] === 'job'));

        expect($jobEvents)->toHaveCount(1)
            ->and($jobEvents[0]['parent_trace_id'])->toBe($dispatchingTrace)
            // Its own unit of work, not a continuation of the request's.
            ->and($jobEvents[0]['trace_id'])->not->toBe($dispatchingTrace);

        // Whatever the job logged belongs to the job's trace, not the
        // request's — that is what groups them together on the detail page.
        $logEvents = array_values(array_filter($events, fn (array $e) => $e['type'] === 'log'));

        expect($logEvents)->not->toBeEmpty()
            ->and($logEvents[0]['trace_id'])->toBe($jobEvents[0]['trace_id']);

        return true;
    });
});
