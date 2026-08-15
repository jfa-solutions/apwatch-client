<?php

use Apwatch\Client\EventBuffer;
use Illuminate\Contracts\Debug\ExceptionHandler;

it('captures reported exceptions', function () {
    app(ExceptionHandler::class)->report(new RuntimeException('boom'));

    // The default handler also logs the exception, which CaptureLog picks
    // up separately — filter down to what CaptureException produced.
    $events = array_values(array_filter(
        app(EventBuffer::class)->all(),
        fn (array $event) => $event['type'] === 'exception',
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]['payload']['class'])->toBe(RuntimeException::class)
        ->and($events[0]['payload']['message'])->toBe('boom');
});
