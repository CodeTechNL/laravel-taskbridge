<?php

namespace CodeTechNL\TaskBridge\Testing;

/**
 * In-memory stand-in for Aws\Scheduler\SchedulerClient.
 *
 * Holds the schedules of one schedule group, records every create, update
 * and delete call, and pages listSchedules() through NextToken the way the
 * real API does, so the driver can be exercised without AWS. TaskBridgeFake
 * installs an empty one; a test that needs pre-existing schedules builds its
 * own and hands it to EventBridgeDriver::setClient().
 */
class FakeSchedulerClient
{
    /** @var array<string, array<string, mixed>> schedules keyed by name, in creation order */
    private array $schedules = [];

    /** @var list<array<string, mixed>> the arguments of every listSchedules() call, in order */
    public array $listCalls = [];

    /** @var list<array<string, mixed>> the payload of every createSchedule() call, in order */
    public array $created = [];

    /** @var list<array<string, mixed>> the payload of every updateSchedule() call, in order */
    public array $updated = [];

    /** @var list<array<string, mixed>> the arguments of every deleteSchedule() call, in order */
    public array $deleted = [];

    /**
     * @param  list<string>  $scheduleNames  Schedules that already exist in the group.
     * @param  int  $pageSize  Schedules per listSchedules() page; 100 is the API maximum.
     */
    public function __construct(array $scheduleNames = [], private readonly int $pageSize = 100)
    {
        foreach ($scheduleNames as $name) {
            $this->schedules[$name] = ['Name' => $name, 'State' => 'ENABLED'];
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function listSchedules(array $args): array
    {
        $this->listCalls[] = $args;

        $offset = isset($args['NextToken']) ? (int) $args['NextToken'] : 0;
        $result = ['Schedules' => array_slice(array_values($this->schedules), $offset, $this->pageSize)];

        if ($offset + $this->pageSize < count($this->schedules)) {
            $result['NextToken'] = (string) ($offset + $this->pageSize);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function createSchedule(array $args): void
    {
        $this->created[] = $args;
        $this->schedules[$args['Name']] = ['Name' => $args['Name'], 'State' => $args['State'] ?? 'ENABLED'];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function updateSchedule(array $args): void
    {
        $this->updated[] = $args;
        $this->schedules[$args['Name']] = ['Name' => $args['Name'], 'State' => $args['State'] ?? 'ENABLED'];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function deleteSchedule(array $args): void
    {
        $this->deleted[] = $args;
        unset($this->schedules[$args['Name']]);
    }

    /**
     * Names of the schedules currently in the group.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->schedules);
    }

    /** @return list<string> */
    public function createdNames(): array
    {
        return array_column($this->created, 'Name');
    }

    /** @return list<string> */
    public function updatedNames(): array
    {
        return array_column($this->updated, 'Name');
    }

    /** @return list<string> */
    public function deletedNames(): array
    {
        return array_column($this->deleted, 'Name');
    }
}
