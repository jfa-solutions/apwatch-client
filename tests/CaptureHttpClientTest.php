<?php

use Apwatch\Client\EventBuffer;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>  the payload of the single captured call
 */
function capturedCall(): array
{
    $events = array_values(array_filter(
        app(EventBuffer::class)->all(),
        fn (array $e) => $e['type'] === 'http_client',
    ));

    expect($events)->toHaveCount(1);

    return $events[0]['payload'];
}

it('captures outgoing http client responses', function () {
    Http::fake(['example.com/*' => Http::response('ok', 200)]);

    Http::get('https://example.com/widgets');

    expect(capturedCall())->toMatchArray([
        'method' => 'GET',
        'url' => 'https://example.com/widgets',
        'status' => 200,
    ])
        // Read from Guzzle's transfer stats, which Http::fake() never
        // populates — hence 0 here rather than a real timing.
        ->and(capturedCall()['duration_ms'])->toBeInt();
});

it('captures the response body of an outgoing call, decoded when it is json', function () {
    Http::fake(['example.com/*' => Http::response(['id' => 7, 'name' => 'widget'], 200)]);

    Http::get('https://example.com/widgets/7');

    expect(capturedCall()['response']['body'])->toBe(['id' => 7, 'name' => 'widget']);
});

it('captures what was sent as well as what came back', function () {
    Http::fake(['example.com/*' => Http::response(['ok' => true], 201)]);

    Http::post('https://example.com/widgets', ['name' => 'widget', 'client_secret' => 'hunter2']);

    $request = capturedCall()['request'];

    expect($request['body']['name'])->toBe('widget')
        // The same redaction list guards outgoing bodies, so a secret on its
        // way out is masked just like one on its way in.
        ->and($request['body']['client_secret'])->toBe('[REDACTED]');
});

it('redacts credentials from the headers of an outgoing call', function () {
    Http::fake(['example.com/*' => Http::response('ok', 200)]);

    Http::withToken('super-secret-token')
        ->withHeaders(['X-Request-Id' => 'abc-123'])
        ->get('https://example.com/widgets');

    $headers = capturedCall()['request']['headers'];

    expect($headers['Authorization'])->toBe('[REDACTED]')
        ->and($headers['X-Request-Id'])->toBe('abc-123');
});

it('captures no bodies when http client body capture is off', function () {
    config(['apwatch.capture.http_client_body' => false]);

    Http::fake(['example.com/*' => Http::response(['secret' => 'value'], 200)]);

    Http::post('https://example.com/widgets', ['name' => 'widget']);

    expect(capturedCall())->not->toHaveKey('request')
        ->and(capturedCall())->not->toHaveKey('response')
        // The line itself is still recorded — only the bodies are dropped.
        ->and(capturedCall()['status'])->toBe(200);
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
