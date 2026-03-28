<?php

use CodeTechNL\TaskBridge\Data\JobOutput;

describe('JobOutput', function () {
    describe('factory methods', function () {
        it('creates a success output with message and metadata', function () {
            $output = JobOutput::success('Done', ['count' => 10]);

            expect($output->status)->toBe('success')
                ->and($output->message)->toBe('Done')
                ->and($output->metadata)->toBe(['count' => 10]);
        });

        it('creates an error output', function () {
            $output = JobOutput::error('Connection refused');

            expect($output->status)->toBe('error')
                ->and($output->message)->toBe('Connection refused');
        });

        it('creates a warning output', function () {
            $output = JobOutput::warning('Quota almost reached');

            expect($output->status)->toBe('warning');
        });

        it('creates an info output', function () {
            $output = JobOutput::info('Nothing to do');

            expect($output->status)->toBe('info');
        });

        it('defaults message and metadata to empty values', function () {
            $output = JobOutput::success();

            expect($output->message)->toBe('')
                ->and($output->metadata)->toBe([]);
        });
    });

    describe('toArray', function () {
        it('omits empty message and metadata', function () {
            $arr = JobOutput::success()->toArray();

            expect($arr)->toBe(['status' => 'success'])
                ->and($arr)->not->toHaveKey('message')
                ->and($arr)->not->toHaveKey('metadata');
        });

        it('includes message when non-empty', function () {
            $arr = JobOutput::error('oops')->toArray();

            expect($arr)->toHaveKey('message', 'oops');
        });

        it('includes metadata when non-empty', function () {
            $arr = JobOutput::success('ok', ['rows' => 5])->toArray();

            expect($arr)->toHaveKey('metadata')
                ->and($arr['metadata'])->toBe(['rows' => 5]);
        });

        it('always includes status', function () {
            foreach (['success', 'error', 'warning', 'info'] as $status) {
                expect((new JobOutput($status))->toArray())->toHaveKey('status', $status);
            }
        });
    });

    describe('fromArray', function () {
        it('round-trips through toArray', function () {
            $original = JobOutput::success('hello', ['x' => 1]);
            $restored = JobOutput::fromArray($original->toArray());

            expect($restored->status)->toBe($original->status)
                ->and($restored->message)->toBe($original->message)
                ->and($restored->metadata)->toBe($original->metadata);
        });

        it('defaults to info status when status key is missing', function () {
            $output = JobOutput::fromArray([]);

            expect($output->status)->toBe('info');
        });

        it('defaults message to empty string when missing', function () {
            $output = JobOutput::fromArray(['status' => 'success']);

            expect($output->message)->toBe('');
        });

        it('defaults metadata to empty array when missing', function () {
            $output = JobOutput::fromArray(['status' => 'success']);

            expect($output->metadata)->toBe([]);
        });
    });

    describe('color', function () {
        it('maps success → success', function () {
            expect(JobOutput::success()->color())->toBe('success');
        });

        it('maps error → danger', function () {
            expect(JobOutput::error()->color())->toBe('danger');
        });

        it('maps warning → warning', function () {
            expect(JobOutput::warning()->color())->toBe('warning');
        });

        it('maps info → info', function () {
            expect(JobOutput::info()->color())->toBe('info');
        });

        it('maps unknown status → gray', function () {
            expect((new JobOutput('custom'))->color())->toBe('gray');
        });
    });

    describe('label', function () {
        it('returns ucfirst of the status', function () {
            expect(JobOutput::success()->label())->toBe('Success')
                ->and(JobOutput::error()->label())->toBe('Error')
                ->and(JobOutput::warning()->label())->toBe('Warning')
                ->and(JobOutput::info()->label())->toBe('Info');
        });
    });
});
