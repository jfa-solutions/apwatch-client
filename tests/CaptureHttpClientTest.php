<?php

use Apwatch\Client\EventBuffer;
use Illuminate\Support\Facades\Http;

it('captures outgoing http client responses', function () {
    Http::fake(['example.com/*' => Http::response('ok', 200)]);

    Http::get('https://example.com/widgets');

    $events = array_values(array_filter(
        app(EventBuffer::class)->all(),
        fn (array $e) => $e['type'] === 'http_client',
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]['payload'])->toMatchArray([
            'method' => 'GET',
            'url' => 'https://example.com/widgets',
            'status' => 200,
        ]);
});

it('does not capture calls to the apwatch ingest endpoint itself', function () {
    Http::fake(['apwatch.test/*' => Http::response(status: 202)]);

    Http::post('https://apwatch.test/api/ingest', ['events' => []]);

    $events = array_values(array_filter(
        app(EventBuffer::class)->all(),
        fn (array $e) => $e['type'] === 'http_client',
    ));

    expect($events)->toBeEmpty();
});
