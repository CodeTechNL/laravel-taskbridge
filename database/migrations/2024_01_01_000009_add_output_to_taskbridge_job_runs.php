<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taskbridge_job_runs', function (Blueprint $table) {
            $table->json('output')->nullable()->after('jobs_dispatched');
        });
    }

    public function down(): void
    {
        Schema::table('taskbridge_job_runs', function (Blueprint $table) {
            $table->dropColumn('output');
        });
    }
};
