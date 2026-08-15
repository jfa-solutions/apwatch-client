<?php

use Apwatch\Client\EventBuffer;

class ApwatchTestDomainEvent
{
    //
}

it('captures dispatched domain events', function () {
    event(new ApwatchTestDomainEvent);

    $events = array_values(array_filter(
        app(EventBuffer::class)->all(),
        fn (array $e) => $e['type'] === 'event',
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]['payload']['name'])->toBe(ApwatchTestDomainEvent::class);
});

it('does not capture framework-internal class-based events', function () {
    \Illuminate\Support\Facades\Log::info('should not show up as a generic event');

    $events = array_values(array_filter(
        app(EventBuffer::class)->all(),
        fn (array $e) => $e['type'] === 'event',
    ));

    expect($events)->toBeEmpty();
});

it('does not capture framework-internal string-named events', function () {
    // Laravel fires these during its own bootstrap (e.g. "bootstrapped:
    // Illuminate\Foundation\Bootstrap\BootProviders") — a plain
    // "Illuminate\" *prefix* check misses them since the string itself
    // starts with "bootstrapped:", not "Illuminate\".
    event('bootstrapped: Illuminate\Foundation\Bootstrap\BootProviders');
    event('creating: welcome');

    $events = array_values(array_filter(
        app(EventBuffer::class)->all(),
        fn (array $e) => $e['type'] === 'event',
    ));

    expect($events)->toBeEmpty();
});
