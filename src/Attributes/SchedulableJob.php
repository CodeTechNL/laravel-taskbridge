<?php

namespace CodeTechNL\TaskBridge\Attributes;

use Attribute;

/**
 * Mark a job as discoverable by TaskBridge when discovery_mode is 'attribute'.
 *
 * All parameters are optional. When provided they take precedence over the
 * equivalent contract interfaces (HasCustomLabel, HasGroup, HasPredefinedCronExpression).
 *
 * Usage:
 *   #[SchedulableJob]
 *   #[SchedulableJob(name: 'Send Report', group: 'Reporting', cron: '0 6 * * *')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
class SchedulableJob
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $group = null,
        public readonly ?string $cron = null,
    ) {}
}
