<?php

namespace CodeTechNL\TaskBridge\Contracts;

interface ConditionalJob
{
    /**
     * Evaluated at runtime, before handle() is called.
     * Return false to skip execution silently.
     */
    public function shouldRun(): bool;
}
