<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove the global settings table added in the previous iteration
        Schema::dropIfExists('taskbridge_settings');

        Schema::table('taskbridge_jobs', function (Blueprint $table) {
            // Null = fall back to config('taskbridge.eventbridge.retry_policy.*')
            $table->unsignedInteger('retry_maximum_event_age_seconds')->nullable()->after('cron_override');
            $table->unsignedSmallInteger('retry_maximum_retry_attempts')->nullable()->after('retry_maximum_event_age_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('taskbridge_jobs', function (Blueprint $table) {
            $table->dropColumn(['retry_maximum_event_age_seconds', 'retry_maximum_retry_attempts']);
        });
    }
};
