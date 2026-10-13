<?php

namespace CodeTechNL\TaskBridge\Tests;

use CodeTechNL\TaskBridge\TaskBridgeServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            TaskBridgeServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Migrate without registering a rollback: the in-memory database is
        // recreated for every test, and running down() anyway fails as soon as a
        // test leaves rows the older schema cannot hold (a pending run without
        // started_at, a one-time job without cron_expression).
        Artisan::call('migrate', ['--path' => __DIR__.'/../database/migrations', '--realpath' => true]);
    }
}
