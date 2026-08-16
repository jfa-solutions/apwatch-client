<?php

namespace Apwatch\Client;

use Apwatch\Client\Listeners\CaptureCommand;
use Apwatch\Client\Listeners\CaptureEvent;
use Apwatch\Client\Listeners\CaptureException;
use Apwatch\Client\Listeners\CaptureHttpClient;
use Apwatch\Client\Listeners\CaptureJob;
use Apwatch\Client\Listeners\CaptureLog;
use Apwatch\Client\Listeners\CaptureMail;
use Apwatch\Client\Listeners\CaptureQuery;
use Apwatch\Client\Listeners\CaptureRequest;
use Apwatch\Client\Listeners\CaptureSchedule;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Throwable;

class ApwatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/apwatch.php', 'apwatch');

        $this->app->singleton(EventBuffer::class);
        $this->app->singleton(Dispatcher::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/apwatch.php' => config_path('apwatch.php'),
        ], 'apwatch-config');

        if (! config('apwatch.enabled')) {
            return;
        }

        if (config('apwatch.capture.requests')) {
            $this->app[Kernel::class]->pushMiddleware(CaptureRequest::class);
        }

        if (config('apwatch.capture.exceptions')) {
            $this->app->afterResolving(ExceptionHandler::class, function ($handler): void {
                if (method_exists($handler, 'reportable')) {
                    $handler->reportable(fn (Throwable $e) => $this->app->make(CaptureException::class)($e));
                }
            });
        }

        if (config('apwatch.capture.logs')) {
            $this->app['events']->listen(MessageLogged::class, CaptureLog::class);
        }

        if (config('apwatch.capture.queries')) {
            $this->app['events']->listen(QueryExecuted::class, CaptureQuery::class);
        }

        if (config('apwatch.capture.mails')) {
            $this->app['events']->listen(MessageSent::class, CaptureMail::class);
        }

        if (config('apwatch.capture.http_clients')) {
            $this->app['events']->listen(ResponseReceived::class, CaptureHttpClient::class);
        }

        if (config('apwatch.capture.events')) {
            $this->app['events']->listen('*', CaptureEvent::class);
        }

        if (config('apwatch.capture.jobs')) {
            // Resolved once and shared across all three listeners (rather
            // than a class-string per event) so the same CaptureJob
            // instance tracks a job's start time between "processing" and
            // "processed"/"failed" regardless of container scoping.
            $captureJob = $this->app->make(CaptureJob::class);
            $this->app['events']->listen(JobProcessing::class, [$captureJob, 'processing']);
            $this->app['events']->listen(JobProcessed::class, [$captureJob, 'processed']);
            $this->app['events']->listen(JobFailed::class, [$captureJob, 'failed']);
        }

        if (config('apwatch.capture.commands')) {
            $captureCommand = $this->app->make(CaptureCommand::class);
            $this->app['events']->listen(CommandStarting::class, [$captureCommand, 'starting']);
            $this->app['events']->listen(CommandFinished::class, [$captureCommand, 'finished']);
        }

        if (config('apwatch.capture.schedule')) {
            $captureSchedule = $this->app->make(CaptureSchedule::class);
            $this->app['events']->listen(ScheduledTaskStarting::class, [$captureSchedule, 'starting']);
            $this->app['events']->listen(ScheduledTaskFinished::class, [$captureSchedule, 'finished']);
            $this->app['events']->listen(ScheduledTaskFailed::class, [$captureSchedule, 'failed']);
        }

        // `always: true` because deferred callbacks are skipped by default on
        // error responses (status >= 400) — but capturing exceptions is
        // exactly the case where we most need the flush to still happen.
        defer(fn () => $this->app->make(Dispatcher::class)->flush(), 'apwatch-flush', always: true);
    }
}
