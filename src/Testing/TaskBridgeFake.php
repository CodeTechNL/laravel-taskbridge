<?php

namespace CodeTechNL\TaskBridge\Testing;

use CodeTechNL\TaskBridge\Drivers\EventBridgeDriver;
use CodeTechNL\TaskBridge\Support\SyncResult;
use CodeTechNL\TaskBridge\TaskBridge;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Assert;

class TaskBridgeFake extends TaskBridge
{
    /** @var string[] */
    private array $synced = [];

    /** @var string[] */
    private array $ran = [];

    /** @var string[] */
    private array $skipped = [];

    /** @var string[] */
    private array $disabled = [];

    /** @var array<string, string> class => cron */
    private array $cronOverrides = [];

    public function __construct()
    {
        $driver = new EventBridgeDriver([
            'region' => 'us-east-1',
            'prefix' => 'test',
            'schedule_group' => 'default',
            'role_arn' => '',
            'retry_policy' => [
                'maximum_event_age_seconds' => 86400,
                'maximum_retry_attempts' => 185,
            ],
        ]);

        // Null client — no real AWS calls during tests
        $driver->setClient(new class
        {
            public function listSchedules(array $args): array
            {
                return ['Schedules' => []];
            }

            public function createSchedule(array $args): void {}

            public function updateSchedule(array $args): void {}

            public function deleteSchedule(array $args): void {}
        });

        parent::__construct($driver);
    }

    public static function enable(): self
    {
        $fake = new self;
        App::instance(TaskBridge::class, $fake);

        return $fake;
    }

    public function sync(): SyncResult
    {
        foreach ($this->getRegisteredClasses() as $class) {
            $this->synced[] = $class;
        }

        return SyncResult::empty();
    }

    public function disable(string $jobClass): void
    {
        $this->disabled[] = $jobClass;
    }

    public function assertSynced(string $class, ?string $message = null): void
    {
        Assert::assertContains(
            $class,
            $this->synced,
            $message ?? "Failed asserting that [{$class}] was synced."
        );
    }

    public function assertDisabled(string $class, ?string $message = null): void
    {
        Assert::assertContains(
            $class,
            $this->disabled,
            $message ?? "Failed asserting that [{$class}] was disabled."
        );
    }

    public function assertCron(string $class, string $expectedCron, ?string $message = null): void
    {
        Assert::assertEquals(
            $expectedCron,
            $this->cronOverrides[$class] ?? null,
            $message ?? "Failed asserting that [{$class}] has cron [{$expectedCron}]."
        );
    }

    public function assertRan(string $class, ?string $message = null): void
    {
        Assert::assertContains(
            $class,
            $this->ran,
            $message ?? "Failed asserting that [{$class}] was run."
        );
    }

    public function assertSkipped(string $class, ?string $message = null): void
    {
        Assert::assertContains(
            $class,
            $this->skipped,
            $message ?? "Failed asserting that [{$class}] was skipped."
        );
    }

    public function assertNothingSynced(?string $message = null): void
    {
        Assert::assertEmpty(
            $this->synced,
            $message ?? 'Failed asserting that nothing was synced.'
        );
    }
}
