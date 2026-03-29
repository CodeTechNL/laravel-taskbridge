<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taskbridge_job_runs', function (Blueprint $table) {
            $table->ulid('scheduled_job_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('taskbridge_job_runs', function (Blueprint $table) {
            $table->ulid('scheduled_job_id')->nullable(false)->change();
        });
    }
};
