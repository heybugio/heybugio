<?php

namespace HeyBug;

use HeyBug\Commands\TestCommand;
use HeyBug\Http\Client;
use HeyBug\Logger\HeyBugHandler;
use HeyBug\Queue\JobEventSubscriber;
use HeyBug\Support\Dsn;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;

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
        $this->registerLogDriver();
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
