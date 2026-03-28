<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taskbridge_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('class');
            $table->string('identifier')->unique();
            $table->string('group')->nullable();
            $table->string('cron_expression');
            $table->string('cron_override')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->enum('last_status', ['succeeded', 'failed', 'skipped'])->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taskbridge_jobs');
    }
};
