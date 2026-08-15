<?php

use Apwatch\Client\EventBuffer;
use Illuminate\Support\Facades\Mail;

it('captures sent mail', function () {
    Mail::raw('hello', function ($message) {
        $message->to('user@example.com')->subject('Test Subject');
    });

    $events = array_values(array_filter(
        app(EventBuffer::class)->all(),
        fn (array $e) => $e['type'] === 'mail',
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]['payload']['to'])->toBe(['user@example.com'])
        ->and($events[0]['payload']['subject'])->toBe('Test Subject');
});
