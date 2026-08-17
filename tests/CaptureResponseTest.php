<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;

function capturedResponse(): array
{
    $captured = [];

    Http::assertSent(function ($request) use (&$captured) {
        foreach ($request->data()['events'] as $event) {
            if ($event['type'] === 'request') {
                $captured = $event['payload']['response'] ?? [];
            }
        }

        return true;
    });

    return $captured;
}

it('captures a json response decoded, so it reads as a document and not as a blob', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::get('/orders/1', fn () => response()->json(['id' => 1, 'total' => '99.90']));

    $this->get('/orders/1');

    expect(capturedResponse())->toMatchArray([
        'body' => ['id' => 1, 'total' => '99.90'],
    ])->and(capturedResponse()['content_type'])->toContain('application/json');
});

it('redacts sensitive keys in a response the same way it does in request input', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::post('/login', fn () => response()->json([
        'user' => ['email' => 'felipe@example.com'],
        'access_token' => 'super-secret-jwt',
    ]));

    $this->post('/login');

    $body = capturedResponse()['body'];

    expect($body['access_token'])->toBe('[REDACTED]')
        ->and($body['user']['email'])->toBe('felipe@example.com');
});

it('keeps a non-json response as the string it was', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::get('/health', fn () => response('OK', 200)->header('Content-Type', 'text/plain'));

    $this->get('/health');

    expect(capturedResponse()['body'])->toBe('OK');
});

it('reports why an oversized response was not stored instead of silently dropping it', function () {
    config(['apwatch.response.max_length' => 64]);

    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::get('/report', fn () => response(str_repeat('x', 500)));

    $this->get('/report');

    $response = capturedResponse();

    expect($response)->not->toHaveKey('body')
        ->and($response['_skipped'])->toContain('500 bytes')
        ->and($response['_skipped'])->toContain('64 byte cap');
});

it('does not read the body of a streamed response, which only exists while it is sent', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::get('/download', fn () => new StreamedResponse(fn () => print 'chunk'));

    $this->get('/download');

    expect(capturedResponse()['_skipped'])->toContain('streamed');
});

it('captures no response when the status is outside the configured list', function () {
    config(['apwatch.response.statuses' => '5xx']);

    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::get('/ping', fn () => 'pong');

    $this->get('/ping');

    expect(capturedResponse())->toBe([]);
});

it('captures no response at all when the capture flag is off', function () {
    config(['apwatch.capture.request_response' => false]);

    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::get('/ping', fn () => 'pong');

    $this->get('/ping');

    expect(capturedResponse())->toBe([]);
});
