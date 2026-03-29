<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taskbridge_jobs', function (Blueprint $table) {
            $table->timestamp('run_once_at')->nullable()->after('constructor_arguments');
            $table->string('run_once_schedule_name')->nullable()->unique()->after('run_once_at');
        });
    }

    public function down(): void
    {
        Schema::table('taskbridge_jobs', function (Blueprint $table) {
            $table->dropColumn(['run_once_at', 'run_once_schedule_name']);
        });
    }
};
