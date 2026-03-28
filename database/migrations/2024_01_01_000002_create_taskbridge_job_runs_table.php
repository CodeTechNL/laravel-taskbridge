<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taskbridge_job_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('scheduled_job_id')->constrained('taskbridge_jobs')->cascadeOnDelete();
            $table->enum('status', ['pending', 'running', 'succeeded', 'failed', 'skipped'])->default('pending');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('jobs_dispatched')->default(0);
            $table->text('error')->nullable();
            $table->string('skipped_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taskbridge_job_runs');
    }
};
