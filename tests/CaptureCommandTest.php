<?php

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Laravel deliberately skips wiring CommandStarting/CommandFinished while
 * `runningUnitTests()` is true (Foundation\Console\Kernel constructor), so
 * `$this->artisan(...)` never fires them here — that's Laravel's own
 * well-defined behavior, not something we're testing. Dispatching the
 * events directly tests what we actually own: how CaptureCommand reacts
 * to them when Laravel does fire them for real.
 */
it('captures artisan command name, exit code, duration and memory', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);

    $input = new ArrayInput([]);
    $output = new NullOutput;

    event(new CommandStarting('apwatch:test-command', $input, $output));
    event(new CommandFinished('apwatch:test-command', $input, $output, 0));

    Http::assertSent(function ($request) {
        $events = array_values(array_filter(
            $request->data()['events'],
            fn (array $e) => $e['type'] === 'command',
        ));

        expect($events)->toHaveCount(1)
            ->and($events[0]['payload']['command'])->toBe('apwatch:test-command')
            ->and($events[0]['payload']['exit_code'])->toBe(0)
            ->and($events[0]['payload']['duration_ms'])->toBeInt()
            ->and($events[0]['payload']['memory_mb'])->toBeFloat();

        return true;
    });
});

it('captures a non-zero exit code', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);

    $input = new ArrayInput([]);
    $output = new NullOutput;

    event(new CommandStarting('apwatch:failing-command', $input, $output));
    event(new CommandFinished('apwatch:failing-command', $input, $output, 1));

    Http::assertSent(function ($request) {
        $events = array_values(array_filter(
            $request->data()['events'],
            fn (array $e) => $e['type'] === 'command',
        ));

        expect($events[0]['payload']['exit_code'])->toBe(1);

        return true;
    });
});
