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

    private FakeSchedulerClient $schedulerClient;

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

        // In-memory client — no real AWS calls during tests
        $this->schedulerClient = new FakeSchedulerClient;
        $driver->setClient($this->schedulerClient);

        parent::__construct($driver);
    }

    /**
     * The in-memory scheduler client behind the fake's driver, for asserting
     * which EventBridge schedules were created, updated or deleted.
     */
    public function schedulerClient(): FakeSchedulerClient
    {
        return $this->schedulerClient;
    }

    /**
     * Swap the container's TaskBridge for this fake.
     *
     * Named fake() like Bus::fake(): a static enable() would collide with the
     * inherited TaskBridge::enable(string $jobClass) and make the class fail
     * to load.
     */
    public static function fake(): self
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
