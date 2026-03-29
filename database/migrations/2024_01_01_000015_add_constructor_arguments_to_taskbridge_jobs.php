<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taskbridge_jobs', function (Blueprint $table) {
            $table->json('constructor_arguments')->nullable()->after('cron_override');
        });
    }

    public function down(): void
    {
        Schema::table('taskbridge_jobs', function (Blueprint $table) {
            $table->dropColumn('constructor_arguments');
        });
    }
};
