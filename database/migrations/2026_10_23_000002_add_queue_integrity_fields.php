<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->unique();
        });

        Schema::table('queue_entries', function (Blueprint $table) {
            $table->timestamp('service_pause_started_at')->nullable();
            $table->unsignedInteger('service_paused_seconds')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->dropColumn(['service_pause_started_at', 'service_paused_seconds']);
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
