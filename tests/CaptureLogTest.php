<?php

use Apwatch\Client\EventBuffer;
use Illuminate\Support\Facades\Log;

it('captures log messages', function () {
    Log::info('something happened');

    $events = app(EventBuffer::class)->all();

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('log')
        ->and($events[0]['payload'])->toBe(['level' => 'info', 'message' => 'something happened']);
});
