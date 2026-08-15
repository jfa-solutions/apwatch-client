<?php

use Apwatch\Client\EventBuffer;

it('starts empty', function () {
    expect(new EventBuffer)->isEmpty()->toBeTrue();
});

it('accumulates pushed events with an ISO 8601 timestamp', function () {
    $buffer = new EventBuffer;

    $buffer->push('log', ['level' => 'info', 'message' => 'hello']);

    expect($buffer->isEmpty())->toBeFalse()
        ->and($buffer->all())->toHaveCount(1)
        ->and($buffer->all()[0]['type'])->toBe('log')
        ->and($buffer->all()[0]['payload'])->toBe(['level' => 'info', 'message' => 'hello'])
        ->and($buffer->all()[0]['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});
