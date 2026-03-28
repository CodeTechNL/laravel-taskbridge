<?php

namespace CodeTechNL\TaskBridge\Support;

class SyncResult
{
    public function __construct(
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $removed = 0,
        public readonly int $unchanged = 0,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function withCreated(int $created): self
    {
        return new self($created, $this->updated, $this->removed, $this->unchanged);
    }

    public function withUpdated(int $updated): self
    {
        return new self($this->created, $updated, $this->removed, $this->unchanged);
    }

    public function withRemoved(int $removed): self
    {
        return new self($this->created, $this->updated, $removed, $this->unchanged);
    }

    public function withUnchanged(int $unchanged): self
    {
        return new self($this->created, $this->updated, $this->removed, $unchanged);
    }

    public function merge(self $other): self
    {
        return new self(
            $this->created + $other->created,
            $this->updated + $other->updated,
            $this->removed + $other->removed,
            $this->unchanged + $other->unchanged,
        );
    }

    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'removed' => $this->removed,
            'unchanged' => $this->unchanged,
        ];
    }
}
