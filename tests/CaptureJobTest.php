<?php

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class ApwatchTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void {}
}

it('captures processed jobs and flushes immediately, without waiting for defer()', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);

    ApwatchTestJob::dispatch();

    Http::assertSent(function ($request) {
        $jobEvents = array_values(array_filter(
            $request->data()['events'],
            fn (array $e) => $e['type'] === 'job',
        ));

        expect($jobEvents)->toHaveCount(1)
            ->and($jobEvents[0]['payload']['name'])->toBe(ApwatchTestJob::class)
            ->and($jobEvents[0]['payload']['status'])->toBe('processed')
            ->and($jobEvents[0]['payload']['duration_ms'])->toBeInt()
            ->and($jobEvents[0]['payload']['memory_mb'])->toBeFloat();

        return true;
    });
});
