<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code', 8)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('service_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 12);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['department_id', 'code']);
        });

        Schema::create('department_user', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['department_id', 'user_id']);
        });

        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('display_name')->nullable();
            $table->boolean('details_complete')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('ticket_number')->unique();
            $table->string('status')->default('active')->index();
            $table->boolean('registration_complete')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->string('prefix', 8)->default('B');
            $table->timestamps();
        });

        Schema::create('queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_point_id')->nullable()->constrained()->nullOnDelete();
            $table->string('active_visit_key')->nullable()->unique();
            $table->string('service_point_active_key')->nullable()->unique();
            $table->string('status')->default('waiting')->index();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->text('priority_reason')->nullable();
            $table->foreignId('priority_set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('priority_set_at')->nullable();
            $table->timestamp('queued_at')->index();
            $table->timestamp('first_called_at')->nullable();
            $table->timestamp('last_called_at')->nullable();
            $table->timestamp('service_started_at')->nullable();
            $table->timestamp('service_completed_at')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->unsignedInteger('held_seconds')->default(0);
            $table->timestamp('absent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['department_id', 'status', 'priority', 'queued_at', 'id'], 'queue_selection_index');
            $table->index(['visit_id', 'created_at']);
        });

        Schema::create('queue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->foreignId('queue_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['visit_id', 'created_at']);
        });

        Schema::create('queue_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['department_id', 'key']);
        });

        Schema::create('operational_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('target_wait_minutes')->nullable();
            $table->unsignedSmallInteger('target_service_minutes')->nullable();
            $table->timestamps();
            $table->unique('department_id');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('operational_targets');
        Schema::dropIfExists('queue_settings');
        Schema::dropIfExists('queue_events');
        Schema::dropIfExists('queue_entries');
        Schema::dropIfExists('ticket_sequences');
        Schema::dropIfExists('visits');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('department_user');
        Schema::dropIfExists('service_points');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
