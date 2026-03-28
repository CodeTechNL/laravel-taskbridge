<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taskbridge_jobs', function (Blueprint $table) {
            $table->string('queue_connection')->nullable()->after('identifier');
        });
    }

    public function down(): void
    {
        Schema::table('taskbridge_jobs', function (Blueprint $table) {
            $table->dropColumn('queue_connection');
        });
    }
};
