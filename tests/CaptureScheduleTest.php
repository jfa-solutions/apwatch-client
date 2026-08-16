<?php

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Support\Facades\Http;

/**
 * ScheduledTaskStarting/Finished/Failed are dispatched by
 * Illuminate\Console\Scheduling\ScheduleRunCommand (wrapping $event->run()),
 * not by Event::run() itself — so, same reasoning as CaptureCommandTest,
 * dispatching them directly tests what we own (CaptureSchedule's reaction)
 * without depending on actually running the scheduler.
 */
it('captures a finished scheduled task', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);

    $task = new CallbackEvent(app(EventMutex::class), fn () => true);
    $task->description('apwatch test task');

    event(new ScheduledTaskStarting($task));
    // The float here (Laravel's own reported runtime) is intentionally
    // ignored by CaptureSchedule — see the comment in the listener — so it
    // doesn't affect what gets captured.
    event(new ScheduledTaskFinished($task, 0.12));

    Http::assertSent(function ($request) {
        $events = array_values(array_filter(
            $request->data()['events'],
            fn (array $e) => $e['type'] === 'schedule',
        ));

        expect($events)->toHaveCount(1)
            ->and($events[0]['payload']['task'])->toBe('apwatch test task')
            ->and($events[0]['payload']['status'])->toBe('finished')
            ->and($events[0]['payload']['duration_ms'])->toBeInt()
            ->and($events[0]['payload']['duration_ms'])->toBeGreaterThanOrEqual(0)
            ->and($events[0]['payload']['memory_mb'])->toBeFloat();

        return true;
    });
});

it('captures a failed scheduled task with its exception', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);

    $task = new CallbackEvent(app(EventMutex::class), fn () => true);
    $task->description('apwatch failing task');

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFailed($task, new RuntimeException('scheduled task boom')));

    Http::assertSent(function ($request) {
        $events = array_values(array_filter(
            $request->data()['events'],
            fn (array $e) => $e['type'] === 'schedule',
        ));

        expect($events)->toHaveCount(1)
            ->and($events[0]['payload']['task'])->toBe('apwatch failing task')
            ->and($events[0]['payload']['status'])->toBe('failed')
            ->and($events[0]['payload']['exception_class'])->toBe(RuntimeException::class)
            ->and($events[0]['payload']['duration_ms'])->toBeInt();

        return true;
    });
});
