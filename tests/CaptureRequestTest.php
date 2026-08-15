<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

it('captures method, path, status and duration for every request', function () {
    Http::fake(['apwatch.test/api/ingest' => Http::response(status: 202)]);
    Route::get('/ping', fn () => response('pong', 201));

    $this->get('/ping');

    Http::assertSent(function ($request) {
        $events = $request->data()['events'];

        expect($events)->toHaveCount(1)
            ->and($events[0]['type'])->toBe('request')
            ->and($events[0]['payload'])->toMatchArray([
                'method' => 'GET',
                'path' => '/ping',
                'status' => 201,
            ])
            ->and($events[0]['payload']['duration_ms'])->toBeInt();

        return true;
    });
});
