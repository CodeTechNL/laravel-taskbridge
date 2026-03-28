<?php

namespace CodeTechNL\TaskBridge\Data;

/**
 * Structured output produced by a scheduled job run.
 *
 * Usage inside a job:
 *
 *   $this->reportOutput(JobOutput::success('Processed 42 records', [
 *       'processed' => 42,
 *       'skipped'   => 3,
 *   ]));
 *
 *   $this->reportOutput(JobOutput::error('Connection timed out'));
 */
final class JobOutput
{
    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly array $metadata = [],
    ) {}

    public static function success(string $message = '', array $metadata = []): self
    {
        return new self('success', $message, $metadata);
    }

    public static function error(string $message = '', array $metadata = []): self
    {
        return new self('error', $message, $metadata);
    }

    public static function warning(string $message = '', array $metadata = []): self
    {
        return new self('warning', $message, $metadata);
    }

    public static function info(string $message = '', array $metadata = []): self
    {
        return new self('info', $message, $metadata);
    }

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'message' => $this->message ?: null,
            'metadata' => $this->metadata ?: null,
        ], fn ($v) => $v !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            status: $data['status'] ?? 'info',
            message: $data['message'] ?? '',
            metadata: $data['metadata'] ?? [],
        );
    }

    public function color(): string
    {
        return match ($this->status) {
            'success' => 'success',
            'error' => 'danger',
            'warning' => 'warning',
            'info' => 'info',
            default => 'gray',
        };
    }

    public function label(): string
    {
        return ucfirst($this->status);
    }
}
