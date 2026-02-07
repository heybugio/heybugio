<?php

namespace HeyBug\Tests;

use HeyBug\HeyBugServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            HeyBugServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('heybug.dsn', 'https://test-api-key:test-project-id@api.heybug.io');
        $app['config']->set('heybug.environments', ['testing']);
    }
}
