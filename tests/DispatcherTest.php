<?php

use Apwatch\Client\Dispatcher;
use Apwatch\Client\EventBuffer;
use Illuminate\Support\Facades\Http;

it('does nothing when the buffer is empty', function () {
    Http::fake();

    app(Dispatcher::class)->flush();

    Http::assertNothingSent();
});

it('posts the buffered events to the configured endpoint with the api key as bearer token', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);

    app(EventBuffer::class)->push('log', ['level' => 'info', 'message' => 'hi']);
    app(Dispatcher::class)->flush();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://apwatch.test/api/ingest'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && count($request->data()['events']) === 1
            && $request->data()['events'][0]['type'] === 'log';
    });
});

it('never throws when the server is unreachable', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

    app(EventBuffer::class)->push('log', ['level' => 'info', 'message' => 'hi']);

    app(Dispatcher::class)->flush();
})->throwsNoExceptions();

it('does not send anything when disabled', function () {
    config(['apwatch.enabled' => false]);
    Http::fake();

    app(EventBuffer::class)->push('log', ['level' => 'info', 'message' => 'hi']);
    app(Dispatcher::class)->flush();

    Http::assertNothingSent();
});

it('does not send anything without an api key', function () {
    config(['apwatch.api_key' => null]);
    Http::fake();

    app(EventBuffer::class)->push('log', ['level' => 'info', 'message' => 'hi']);
    app(Dispatcher::class)->flush();

    Http::assertNothingSent();
});
