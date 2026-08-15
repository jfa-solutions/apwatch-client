<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

it('captures method, path, status, duration, ip, user agent and memory for every request', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::get('/ping', fn () => response('pong', 201));

    $this->get('/ping', ['User-Agent' => 'ApwatchTestAgent/1.0']);

    Http::assertSent(function ($request) {
        $events = $request->data()['events'];

        expect($events)->toHaveCount(1)
            ->and($events[0]['type'])->toBe('request')
            ->and($events[0]['payload'])->toMatchArray([
                'method' => 'GET',
                'path' => '/ping',
                'status' => 201,
                'user_agent' => 'ApwatchTestAgent/1.0',
            ])
            ->and($events[0]['payload']['duration_ms'])->toBeInt()
            ->and($events[0]['payload']['ip'])->toBeString()
            ->and($events[0]['payload']['memory_mb'])->toBeFloat()
            ->and($events[0]['payload'])->not->toHaveKey('headers');

        return true;
    });
});

it('includes redacted headers only when request_headers capture is enabled', function () {
    config(['apwatch.capture.request_headers' => true]);

    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::get('/ping', fn () => 'pong');

    $this->get('/ping', [
        'Authorization' => 'Bearer super-secret-token',
        'X-Custom-Header' => 'visible-value',
    ]);

    Http::assertSent(function ($request) {
        $headers = $request->data()['events'][0]['payload']['headers'];

        expect($headers['authorization'])->toBe('[REDACTED]')
            ->and($headers['x-custom-header'])->toBe('visible-value');

        return true;
    });
});
