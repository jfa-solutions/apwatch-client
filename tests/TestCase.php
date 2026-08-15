<?php

namespace Apwatch\Client\Tests;

use Apwatch\Client\ApwatchServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ApwatchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('apwatch.enabled', true);
        $app['config']->set('apwatch.api_key', 'test-api-key');
        $app['config']->set('apwatch.endpoint', 'https://apwatch.test');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $app['config']->set('mail.default', 'array');

        // Opt-in capture types default to false in the shipped config, but
        // the whole suite exercises them, so turn them all on here.
        foreach (['queries', 'jobs', 'mails', 'http_clients', 'events'] as $flag) {
            $app['config']->set("apwatch.capture.{$flag}", true);
        }
    }
}
