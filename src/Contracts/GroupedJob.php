<?php

namespace CodeTechNL\TaskBridge\Contracts;

interface GroupedJob
{
    public function group(): string;
}
