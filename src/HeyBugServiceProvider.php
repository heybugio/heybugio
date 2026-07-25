<?php

namespace HeyBug;

use HeyBug\Commands\TestCommand;
use HeyBug\Http\Client;
use HeyBug\Logger\HeyBugHandler;
use HeyBug\Queue\JobEventSubscriber;
use HeyBug\Support\Dsn;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;
use Throwable;

class HeyBugServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class]);

            $this->publishes([
                __DIR__.'/../config/heybug.php' => config_path('heybug.php'),
            ], 'heybug-config');
        }

        if (config('heybug.queue.enabled', false)) {
            $this->app['events']->subscribe(
                new JobEventSubscriber($this->app[Client::class])
            );
        }

        $this->registerContextFlushing();
        $this->registerReportFlushing();
        $this->registerLogDriver();
    }

    /**
     * Deliver buffered reports at the end of each unit of work.
     *
     * There is no single correct flush point, so there is no single
     * listener. Each context ends differently, and picking one boundary
     * silently loses reports in the others:
     *
     * - HTTP ends at terminate(), after the response has been sent. Octane
     *   runs each request against a cloned container and flushes it, so the
     *   callback does not accumulate the way it would in a bare long-lived
     *   process.
     * - Queue workers never call terminate() at all. JobAttempted is
     *   dispatched from a finally block after every attempt, so unlike
     *   JobProcessed (success only) and JobFailed (final failure only) it
     *   also covers the ordinary case of a job that threw and will retry.
     *   Looping catches reports raised between jobs, and WorkerStopping
     *   catches a graceful shutdown.
     * - Console commands end at CommandFinished. A worker is itself a
     *   console command, so for queue:work this fires once, at exit.
     * - register_shutdown_function is the backstop for the fatals none of
     *   the above survive. It cannot save an OOM kill or a SIGKILL.
     */
    protected function registerReportFlushing(): void
    {
        if (! config('heybug.async', false)) {
            return;
        }

        // Resolution happens outside flush()'s own error handling, so it is
        // guarded here. A shutdown function in particular runs after the
        // container may already have been torn down, where make() throws
        // rather than returning anything to flush.
        //
        // An unresolved singleton has never buffered anything, so there is
        // nothing to deliver and nothing to build it for.
        $flush = function (): void {
            try {
                if (! $this->app->resolved('heybug')) {
                    return;
                }

                $this->app->make('heybug')->flush();
            } catch (Throwable) {
                // Nothing safe is left to do at this point.
            }
        };

        $this->app->terminating($flush);

        $events = $this->app['events'];

        foreach ([JobAttempted::class, Looping::class, WorkerStopping::class, CommandFinished::class] as $event) {
            $events->listen($event, $flush);
        }

        register_shutdown_function($flush);
    }

    /**
     * Discard custom context at the start of every unit of work.
     *
     * Under a long-running process (Octane, queue workers) the container and
     * its singletons outlive a single request or job, so context set during
     * one would otherwise stay in memory and attach itself to an unrelated
     * report later on.
     */
    protected function registerContextFlushing(): void
    {
        $events = $this->app['events'];

        $flush = static fn () => HeyBug::clearContext();

        $events->listen(JobProcessing::class, $flush);

        /** @var list<class-string> $octaneEvents */
        $octaneEvents = [
            'Laravel\Octane\Events\RequestReceived',
            'Laravel\Octane\Events\TaskReceived',
            'Laravel\Octane\Events\TickReceived',
        ];

        foreach ($octaneEvents as $event) {
            $events->listen($event, $flush);
        }
    }

    protected function registerLogDriver(): void
    {
        $this->app->make(LogManager::class)->extend('heybug', function ($app, array $config) {
            $handler = new HeyBugHandler(
                $app['heybug'],
                $config['level'] ?? 'error'
            );

            return new Logger('heybug', [$handler]);
        });
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/heybug.php', 'heybug');

        $this->app->singleton(Client::class, function () {
            $dsn = config('heybug.dsn');
            $verifySsl = config('heybug.verify_ssl', true);

            if ($dsn && Dsn::isValid($dsn)) {
                $parsed = Dsn::make($dsn);

                return new Client(
                    $parsed->getApiKey(),
                    $parsed->getProjectId(),
                    $parsed->getServer(),
                    $verifySsl
                );
            }

            return new Client(
                config('heybug.api_key', ''),
                config('heybug.project_id', ''),
                config('heybug.server', 'https://api.heybug.io'),
                $verifySsl
            );
        });

        $this->app->singleton('heybug', function ($app) {
            return new HeyBug($app[Client::class]);
        });

        $this->app->alias('heybug', HeyBug::class);
    }
}
