<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taskbridge_jobs', function (Blueprint $table) {
            $table->dropColumn('driver');
        });

        Schema::table('taskbridge_job_runs', function (Blueprint $table) {
            $table->dropColumn('driver');
        });
    }

    public function down(): void
    {
        Schema::table('taskbridge_jobs', function (Blueprint $table) {
            $table->string('driver')->nullable()->after('cron_override');
        });

        Schema::table('taskbridge_job_runs', function (Blueprint $table) {
            $table->string('driver')->nullable()->after('triggered_by');
        });
    }
};
