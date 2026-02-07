<?php

namespace HeyBug;

use HeyBug\Commands\TestCommand;
use HeyBug\Http\Client;
use HeyBug\Logger\HeyBugHandler;
use HeyBug\Queue\JobEventSubscriber;
use HeyBug\Support\Dsn;
use Illuminate\Log\LogManager;
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

        $this->registerLogDriver();
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

            if ($dsn && Dsn::isValid($dsn)) {
                $parsed = Dsn::make($dsn);

                return new Client(
                    $parsed->getApiKey(),
                    $parsed->getProjectId(),
                    $parsed->getServer()
                );
            }

            return new Client(
                config('heybug.api_key', ''),
                config('heybug.project_id', ''),
                config('heybug.server', 'https://api.heybug.io')
            );
        });

        $this->app->singleton('heybug', function ($app) {
            return new HeyBug($app[Client::class]);
        });

        $this->app->alias('heybug', HeyBug::class);
    }
}
