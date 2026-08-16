<?php

namespace Apwatch\Client\Tests;

use Apwatch\Client\ApwatchServiceProvider;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\EventMutex;
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

        // Not auto-bound by Testbench's minimal app — needed to construct
        // a Illuminate\Console\Scheduling\CallbackEvent directly in tests.
        $app->bind(EventMutex::class, CacheEventMutex::class);

        // Opt-in capture types default to false in the shipped config, but
        // the whole suite exercises them, so turn them all on here.
        foreach (['queries', 'jobs', 'mails', 'http_clients', 'events', 'commands', 'schedule'] as $flag) {
            $app['config']->set("apwatch.capture.{$flag}", true);
        }
    }
}
