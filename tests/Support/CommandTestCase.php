<?php

namespace CodeTechNL\TaskBridge\Tests\Support;

use CodeTechNL\TaskBridge\TaskBridgeServiceProvider;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Test case for artisan command tests.
 *
 * Manages the database schema manually instead of running the full migration
 * chain, to avoid the known SQLite limitation with dropColumn + unique indexes
 * (migrations 000005 and 000016).
 */
class CommandTestCase extends Orchestra
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
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('taskbridge_jobs');
        Schema::dropIfExists('taskbridge_job_runs');

        parent::tearDown();
    }

    private function createSchema(): void
    {
        Schema::create('taskbridge_jobs', function ($table) {
            $table->ulid('id')->primary();
            $table->string('class');
            $table->string('identifier')->unique();
            $table->string('queue_connection')->nullable();
            $table->string('group')->nullable();
            $table->text('description')->nullable();
            $table->string('cron_expression')->nullable();
            $table->string('cron_override')->nullable();
            $table->json('constructor_arguments')->nullable();
            $table->timestamp('run_once_at')->nullable();
            $table->string('run_once_schedule_name')->nullable()->unique();
            $table->integer('retry_maximum_event_age_seconds')->nullable();
            $table->integer('retry_maximum_retry_attempts')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status')->nullable();
            $table->timestamps();
        });

        Schema::create('taskbridge_job_runs', function ($table) {
            $table->ulid('id')->primary();
            $table->string('scheduled_job_id')->nullable();
            $table->string('status');
            $table->string('triggered_by')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->integer('jobs_dispatched')->nullable();
            $table->string('skipped_reason')->nullable();
            $table->json('output')->nullable();
            $table->timestamps();
        });
    }
}
