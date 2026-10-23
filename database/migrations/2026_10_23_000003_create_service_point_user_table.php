<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_point_user', function (Blueprint $table) {
            $table->foreignId('service_point_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['service_point_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_point_user');
    }
};
