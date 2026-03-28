<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taskbridge_job_runs', function (Blueprint $table) {
            $table->enum('triggered_by', ['scheduler', 'manual', 'dry_run'])
                ->default('scheduler')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('taskbridge_job_runs', function (Blueprint $table) {
            $table->dropColumn('triggered_by');
        });
    }
};
