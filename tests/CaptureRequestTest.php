<?php

use Illuminate\Foundation\Auth\User as Authenticatable;
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

it('does not capture input for a status outside the configured list', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::post('/orders', fn () => response('created', 201));

    $this->post('/orders', ['nome' => 'João']);

    Http::assertSent(function ($request) {
        expect($request->data()['events'][0]['payload'])->not->toHaveKey('input');

        return true;
    });
});

it('captures body and query separately for a status in the configured list', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::post('/orders', fn () => response('nope', 422));

    $this->post('/orders?page=2', ['nome' => 'João']);

    Http::assertSent(function ($request) {
        $input = $request->data()['events'][0]['payload']['input'];

        expect($input['body'])->toBe(['nome' => 'João'])
            ->and($input['query'])->toBe(['page' => '2']);

        return true;
    });
});

it('matches exact status codes as well as classes', function () {
    config(['apwatch.request_input.statuses' => '201']);

    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::post('/orders', fn () => response('created', 201));

    $this->post('/orders', ['nome' => 'João']);

    Http::assertSent(function ($request) {
        expect($request->data()['events'][0]['payload']['input']['body'])->toBe(['nome' => 'João']);

        return true;
    });
});

it('redacts sensitive keys at any depth and leaves the rest readable', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::post('/login', fn () => response('nope', 422));

    $this->post('/login', [
        'email' => 'felipe@example.com',
        'senha_atual' => 'hunter2',
        'cliente' => ['cpf' => '12345678900', 'nome' => 'João'],
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data()['events'][0]['payload']['input']['body'];

        expect($body['email'])->toBe('felipe@example.com')
            ->and($body['senha_atual'])->toBe('[REDACTED]')
            ->and($body['cliente']['cpf'])->toBe('[REDACTED]')
            ->and($body['cliente']['nome'])->toBe('João');

        return true;
    });
});

it('truncates oversized values instead of shipping them whole', function () {
    config(['apwatch.request_input.max_value_length' => 10]);

    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::post('/orders', fn () => response('nope', 422));

    $this->post('/orders', ['obs' => str_repeat('a', 500)]);

    Http::assertSent(function ($request) {
        $body = $request->data()['events'][0]['payload']['input']['body'];

        expect($body['obs'])->toStartWith('aaaaaaaaaa...')
            ->and($body['obs'])->toContain('truncated 500 bytes');

        return true;
    });
});

it('drops the whole input when it exceeds the total size cap', function () {
    config(['apwatch.request_input.max_length' => 100]);

    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::post('/orders', fn () => response('nope', 422));

    $this->post('/orders', ['itens' => range(1, 200)]);

    Http::assertSent(function ($request) {
        expect($request->data()['events'][0]['payload']['input']['_truncated'])->toBeTrue();

        return true;
    });
});

it('attaches the authenticated user to every event in the batch', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);

    $user = new class extends Authenticatable
    {
        protected $attributes = ['id' => 7, 'email' => 'felipe@example.com', 'name' => 'Felipe'];
    };

    Route::get('/painel', function () {
        logger()->info('dentro do painel');

        return 'ok';
    });

    $this->actingAs($user)->get('/painel');

    Http::assertSent(function ($request) {
        $events = $request->data()['events'];

        // Both the log written mid-request and the request row itself carry
        // the user — that is what makes "filter by email" useful beyond
        // request rows.
        foreach ($events as $event) {
            expect($event['payload']['user'])->toMatchArray([
                'id' => '7',
                'email' => 'felipe@example.com',
                'name' => 'Felipe',
            ]);
        }

        return true;
    });
});

it('sends no user key for a guest request', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::get('/ping', fn () => 'pong');

    $this->get('/ping');

    Http::assertSent(function ($request) {
        expect($request->data()['events'][0]['payload'])->not->toHaveKey('user');

        return true;
    });
});
