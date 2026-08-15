<?php

use Apwatch\Client\EventBuffer;
use Illuminate\Support\Facades\DB;

it('captures executed queries', function () {
    DB::select('select 1 as one');

    $events = array_values(array_filter(
        app(EventBuffer::class)->all(),
        fn (array $e) => $e['type'] === 'query',
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]['payload']['sql'])->toContain('select 1')
        ->and($events[0]['payload']['connection'])->toBe('testing')
        ->and($events[0]['payload']['time_ms'])->toBeNumeric();
});
