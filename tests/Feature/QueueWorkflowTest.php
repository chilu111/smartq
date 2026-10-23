<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\QueueEntry;
use App\Models\Role;
use App\Models\ServicePoint;
use App\Models\TicketSequence;
use App\Models\User;
use App\Models\Visit;
use App\Services\AnalyticsService;
use App\Services\QueueService;
use App\Services\QueueTimingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QueueWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_and_staff_can_sign_in(): void
    {
        [$user, $department] = $this->staffWithDepartment();
        $user->update(['email' => 'staff@example.test', 'password' => 'password']);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->flushSession();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/departments/'.$department->slug);
        $this->assertAuthenticatedAs($user);
    }

    public function test_staff_root_route_opens_an_assigned_department_workspace(): void
    {
        [$user, $department] = $this->staffWithDepartment();

        $this->actingAs($user)->get('/')->assertRedirect('/departments/'.$department->slug);
    }

    public function test_staff_must_be_assigned_to_the_department_and_service_point(): void
    {
        [$user, $department, $point] = $this->staffWithDepartment();
        $other = Department::create(['name' => 'Laboratory', 'slug' => 'laboratory', 'code' => 'LAB', 'is_active' => true]);
        $other->servicePoints()->create(['name' => 'Laboratory 1', 'code' => 'LAB-1', 'is_active' => true]);

        $this->actingAs($user)->get('/departments/laboratory')->assertForbidden();
        $user->servicePoints()->detach($point->id);
        $this->actingAs($user)->post('/departments/'.$department->slug.'/call-next', ['service_point_id' => $point->id])->assertForbidden();
    }

    public function test_fast_registration_issues_a_stable_ticket_and_retries_safely(): void
    {
        [$user, $department] = $this->staffWithDepartment('receptionist', ['visits.register']);
        $key = (string) Str::uuid();
        $payload = ['idempotency_key' => $key, 'display_name' => '', 'service_point_id' => null];

        $this->actingAs($user)->postJson('/departments/'.$department->slug.'/register', $payload)
            ->assertCreated()->assertJsonPath('ticket', 'B001');
        $this->postJson('/departments/'.$department->slug.'/register', $payload)
            ->assertCreated()->assertJsonPath('ticket', 'B001');

        $this->assertDatabaseCount('visits', 1);
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseHas('patients', ['details_complete' => 0]);
        $this->assertDatabaseHas('queue_entries', ['status' => 'waiting', 'active_visit_key' => 'visit:1']);
    }

    public function test_call_next_uses_fifo_and_refuses_a_second_claim_at_the_same_point(): void
    {
        [$user, $department, $point] = $this->staffWithDepartment();
        $first = $this->createVisit($department, 'B101', now()->subMinutes(5));
        $this->createVisit($department, 'B102', now()->subMinutes(2));

        $this->actingAs($user)->postJson('/departments/'.$department->slug.'/call-next', ['service_point_id' => $point->id])
            ->assertOk()->assertJsonPath('entry.ticket', 'B101');
        $this->postJson('/departments/'.$department->slug.'/call-next', ['service_point_id' => $point->id])
            ->assertUnprocessable();

        $this->assertSame('B101', $first->fresh()->ticket_number);
        $this->assertDatabaseHas('queue_entries', ['visit_id' => $first->id, 'status' => 'called']);
    }

    public function test_approved_priority_precedes_older_ordinary_patients(): void
    {
        [$user, $department, $point] = $this->staffWithDepartment('triage-staff', ['queue.call', 'queue.manage', 'queue.set-priority']);
        $ordinary = $this->createVisit($department, 'B201', now()->subMinutes(10));
        $priority = $this->createVisit($department, 'B202', now()->subMinutes(2));
        $entry = $priority->queueEntries()->first();

        $this->actingAs($user)->postJson('/departments/'.$department->slug.'/entries/'.$entry->id.'/action', [
            'action' => 'priority', 'priority' => 1, 'reason' => 'Approved triage priority',
        ])->assertOk();
        $this->postJson('/departments/'.$department->slug.'/call-next', ['service_point_id' => $point->id])
            ->assertOk()->assertJsonPath('entry.ticket', 'B202');

        $this->assertSame('waiting', $ordinary->queueEntries()->first()->fresh()->status);
    }

    public function test_recall_does_not_reset_first_call_time_and_service_pause_is_tracked(): void
    {
        [$user, $department, $point] = $this->staffWithDepartment();
        $visit = $this->createVisit($department, 'B301', now()->subMinutes(3));
        $entry = $visit->queueEntries()->first();
        $this->actingAs($user)->postJson('/departments/'.$department->slug.'/call-next', ['service_point_id' => $point->id])->assertOk();
        $firstCall = $entry->fresh()->first_called_at;

        Carbon::setTestNow(now()->addMinute());
        $this->postJson('/departments/'.$department->slug.'/entries/'.$entry->id.'/action', ['action' => 'recall'])->assertOk();
        $this->assertTrue($entry->fresh()->first_called_at->equalTo($firstCall));

        $this->postJson('/departments/'.$department->slug.'/entries/'.$entry->id.'/action', ['action' => 'start'])->assertOk();
        $this->postJson('/departments/'.$department->slug.'/entries/'.$entry->id.'/action', ['action' => 'hold'])->assertOk();
        Carbon::setTestNow(now()->addMinutes(2));
        $this->postJson('/departments/'.$department->slug.'/entries/'.$entry->id.'/action', ['action' => 'resume'])->assertOk();

        $entry->refresh();
        $this->assertSame('serving', $entry->status);
        $this->assertGreaterThanOrEqual(120, $entry->service_paused_seconds);
        $this->assertNotNull($entry->service_started_at);
        Carbon::setTestNow();
    }

    public function test_waiting_hold_time_is_excluded_from_adjusted_wait(): void
    {
        [$user, $department] = $this->staffWithDepartment();
        $start = now();
        $visit = $this->createVisit($department, 'B302', $start->copy()->subMinutes(5));
        $entry = $visit->queueEntries()->first();
        $queues = app(QueueService::class);
        $queues->hold($entry, $user);
        Carbon::setTestNow($start->copy()->addMinutes(3));
        $entry = $queues->resume($entry, $user);

        $this->assertGreaterThanOrEqual(180, $entry->waiting_paused_seconds);
        $this->assertSame(300, app(QueueTimingService::class)->elapsedWaitingSeconds($entry, now()));
        Carbon::setTestNow();
    }

    public function test_transfer_preserves_ticket_identity_and_records_both_stages(): void
    {
        [$user, $department] = $this->staffWithDepartment('receptionist', ['queue.call', 'queue.manage', 'queue.transfer']);
        $destination = Department::create(['name' => 'Triage', 'slug' => 'triage', 'code' => 'TRI', 'is_active' => true]);
        $visit = $this->createVisit($department, 'B401', now()->subMinute());
        $entry = $visit->queueEntries()->first();

        $response = $this->actingAs($user)->postJson('/departments/'.$department->slug.'/entries/'.$entry->id.'/action', [
            'action' => 'transfer', 'department_id' => $destination->id,
        ]);
        $response->assertOk()->assertJsonPath('entry.ticket', 'B401');
        $this->assertSame('B401', $visit->fresh()->ticket_number);
        $this->assertDatabaseHas('queue_entries', ['id' => $entry->id, 'status' => 'completed']);
        $this->assertDatabaseHas('queue_entries', ['visit_id' => $visit->id, 'department_id' => $destination->id, 'status' => 'waiting']);
        $this->assertDatabaseHas('queue_events', ['event_type' => 'department_transfer_out']);
        $this->assertDatabaseHas('queue_events', ['event_type' => 'department_transfer_in']);
    }

    public function test_queue_cancel_and_visit_cancel_are_distinct_and_visit_can_complete(): void
    {
        [$user, $department] = $this->staffWithDepartment('receptionist', ['queue.call', 'queue.manage', 'visits.cancel', 'visits.complete']);
        $visit = $this->createVisit($department, 'B501', now());
        $entry = $visit->queueEntries()->first();
        $this->actingAs($user)->postJson('/departments/'.$department->slug.'/entries/'.$entry->id.'/action', ['action' => 'cancel'])->assertOk();
        $this->assertSame('active', $visit->fresh()->status);

        $this->postJson('/visits/'.$visit->id.'/complete')->assertOk();
        $this->assertSame('completed', $visit->fresh()->status);

        $second = $this->createVisit($department, 'B502', now());
        $this->postJson('/visits/'.$second->id.'/cancel')->assertOk();
        $this->assertSame('cancelled', $second->fresh()->status);
    }

    public function test_public_display_payload_contains_only_ticket_and_service_point_data(): void
    {
        [$user, $department, $point] = $this->staffWithDepartment();
        $visit = $this->createVisit($department, 'B601', now());
        $this->actingAs($user)->postJson('/departments/'.$department->slug.'/call-next', ['service_point_id' => $point->id])->assertOk();

        $this->getJson('/display/'.$department->slug.'/state')->assertOk()
            ->assertJsonPath('current.ticket', 'B601')
            ->assertJsonMissingPath('current.patient')
            ->assertJsonMissingPath('current.patient_name')
            ->assertJsonMissingPath('current.priority_reason');
        $entry = $visit->queueEntries()->first();
        app(QueueService::class)->startService($entry, $user);
        app(QueueService::class)->completeService($entry, $user);
        $this->getJson('/display/'.$department->slug.'/state')->assertOk()
            ->assertJsonPath('current', null)
            ->assertJsonPath('recent.0.ticket', 'B601');
        $this->get('/display/'.$department->slug)->assertOk()->assertDontSee($visit->patient->reference);
    }

    public function test_invalid_queue_transitions_are_rejected(): void
    {
        [$user, $department] = $this->staffWithDepartment();
        $visit = $this->createVisit($department, 'B701', now());
        $entry = $visit->queueEntries()->first();

        $this->actingAs($user)->postJson('/departments/'.$department->slug.'/entries/'.$entry->id.'/action', ['action' => 'complete'])
            ->assertUnprocessable();
        $this->assertSame('waiting', $entry->fresh()->status);
    }

    public function test_analytics_calculate_wait_service_and_volume_from_saved_timestamps(): void
    {
        [, $department] = $this->staffWithDepartment();
        $visit = $this->createVisit($department, 'B801', now()->subMinutes(10));
        $entry = $visit->queueEntries()->first();
        $entry->forceFill([
            'status' => 'completed',
            'first_called_at' => now()->subMinutes(5),
            'service_started_at' => now()->subMinutes(4),
            'service_completed_at' => now()->subMinutes(2),
            'service_paused_seconds' => 60,
        ])->save();

        $report = app(AnalyticsService::class)->report(today(), today());

        $this->assertSame(1, $report['visits_created']);
        $this->assertSame(5, $report['average_wait_minutes']);
        $this->assertSame(1, $report['average_service_minutes']);
        $this->assertSame(1, $report['departments']->first()['completed_count']);
    }

    public function test_administrator_can_configure_departments_counters_and_staff_assignments(): void
    {
        $adminRole = Role::create(['name' => 'System Administrator', 'slug' => 'system-administrator']);
        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole->id);
        $this->actingAs($admin);

        $this->post('/admin/departments', ['name' => 'Imaging', 'code' => 'IMG'])->assertSessionHasNoErrors();
        $department = Department::where('slug', 'imaging')->firstOrFail();
        $this->put('/admin/departments/'.$department->id, ['name' => 'Radiology', 'code' => 'RAD'])->assertSessionHasNoErrors();
        $this->post('/admin/service-points', ['department_id' => $department->id, 'name' => 'Room 2', 'code' => 'RAD-2'])->assertSessionHasNoErrors();
        $point = ServicePoint::where('code', 'RAD-2')->firstOrFail();
        $staffRole = Role::create(['name' => 'Doctor', 'slug' => 'doctor']);
        $this->post('/admin/staff', [
            'name' => 'Demo Clinician', 'email' => 'clinician@example.test', 'password' => 'temporary-password-123',
            'role_id' => $staffRole->id, 'department_ids' => [$department->id], 'service_point_ids' => [$point->id],
        ])->assertSessionHasNoErrors();

        $staff = User::where('email', 'clinician@example.test')->firstOrFail();
        $this->assertTrue($staff->departments()->whereKey($department->id)->exists());
        $this->assertTrue($staff->servicePoints()->whereKey($point->id)->exists());
        $this->assertSame('Radiology', $department->fresh()->name);
        $this->put('/admin/departments/'.$department->id.'/targets', ['target_wait_minutes' => 20, 'target_service_minutes' => 15])->assertSessionHasNoErrors();
        $this->put('/admin/ticket-numbering', ['prefix' => 'Q'])->assertSessionHasNoErrors();
        $this->assertSame(20, $department->operationalTarget()->value('target_wait_minutes'));
        $this->assertSame('Q', TicketSequence::query()->find(1)->prefix);
        $permission = Permission::firstOrCreate(['slug' => 'queue.manage'], ['name' => 'Manage queue stages']);
        $this->put('/admin/roles/'.$staffRole->id.'/permissions', ['permission_ids' => [$permission->id]])->assertSessionHasNoErrors();
        $this->assertTrue($staffRole->fresh()->permissions()->whereKey($permission->id)->exists());
        $this->get('/admin')->assertOk()->assertSee('Staff accounts');
        $this->get('/admin/audit')->assertOk()->assertSee('Audit history');
    }

    public function test_department_can_be_archived_and_restored_without_losing_queue_history(): void
    {
        $adminRole = Role::create(['name' => 'System Administrator', 'slug' => 'system-administrator']);
        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole->id);
        $department = Department::create(['name' => 'Reception', 'slug' => 'reception', 'code' => 'REC', 'is_active' => false]);
        $visit = $this->createVisit($department, 'B991', now()->subMinutes(3));
        $visit->queueEntries()->update(['status' => 'completed', 'active_visit_key' => null]);

        $this->actingAs($admin)->delete('/admin/departments/'.$department->id.'/archive')->assertSessionHasNoErrors();
        $this->assertSoftDeleted('departments', ['id' => $department->id]);
        $this->assertDatabaseHas('queue_entries', ['visit_id' => $visit->id, 'department_id' => $department->id]);

        $this->patch('/admin/departments/'.$department->id.'/restore')->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted('departments', ['id' => $department->id]);
        $this->assertFalse($department->fresh()->is_active);
    }

    public function test_invalid_transfer_rolls_back_without_closing_source_stage(): void
    {
        [$user, $department] = $this->staffWithDepartment();
        $destination = Department::create(['name' => 'Triage', 'slug' => 'triage', 'code' => 'TRI', 'is_active' => true]);
        $wrongPoint = ServicePoint::create(['department_id' => $department->id, 'name' => 'Wrong counter', 'code' => 'REC-2', 'is_active' => true]);
        $visit = $this->createVisit($department, 'B901', now());
        $entry = $visit->queueEntries()->first();

        try {
            app(QueueService::class)->transfer($entry, $destination, $wrongPoint, $user);
            $this->fail('A cross-department service point must be rejected.');
        } catch (ValidationException) {
            $this->assertSame('waiting', $entry->fresh()->status);
            $this->assertSame(1, $visit->queueEntries()->count());
        }
    }

    public function test_only_one_display_source_is_enabled_for_spoken_announcements(): void
    {
        $reception = Department::create(['name' => 'Reception', 'slug' => 'reception', 'code' => 'REC', 'is_active' => true]);
        $triage = Department::create(['name' => 'Triage', 'slug' => 'triage', 'code' => 'TRI', 'is_active' => true]);
        $firstSource = (string) Str::uuid();
        $secondSource = (string) Str::uuid();

        $this->postJson('/display/reception/audio', ['source_id' => $firstSource])->assertOk();
        $this->getJson('/display/reception/state')->assertJsonPath('audio_source_id', $firstSource);
        $this->postJson('/display/triage/audio', ['source_id' => $secondSource])->assertOk();
        $this->getJson('/display/reception/state')->assertJsonPath('audio_source_id', $secondSource);
        $this->getJson('/display/triage/state')->assertJsonPath('audio_department_id', $triage->id);
    }

    public function test_inactive_accounts_cannot_authenticate(): void
    {
        User::factory()->create(['email' => 'inactive@example.test', 'password' => 'password', 'is_active' => false]);

        $this->post('/login', ['email' => 'inactive@example.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_dashboard_displays_database_analytics_for_selected_period(): void
    {
        [$user, $department] = $this->staffWithDepartment();
        $managerRole = Role::create(['name' => 'Clinic Manager', 'slug' => 'clinic-manager']);
        $manager = User::factory()->create();
        $manager->roles()->attach($managerRole->id);
        $this->createVisit($department, 'B990', now()->subMinutes(2));

        $this->actingAs($manager)->get('/dashboard?from='.today()->toDateString().'&to='.today()->toDateString())
            ->assertOk()->assertSee('Queue overview')->assertSee('Reception')->assertSee('Visits created');
    }

    private function staffWithDepartment(string $roleSlug = 'receptionist', array $permissionSlugs = ['queue.call', 'queue.manage', 'queue.transfer', 'visits.register']): array
    {
        $role = Role::create(['name' => ucfirst($roleSlug), 'slug' => $roleSlug]);
        foreach ($permissionSlugs as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $role->permissions()->attach($permission->id);
        }
        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        $department = Department::create(['name' => 'Reception', 'slug' => 'reception', 'code' => 'REC', 'is_active' => true]);
        $point = ServicePoint::create(['department_id' => $department->id, 'name' => 'Counter 1', 'code' => 'REC-1', 'is_active' => true]);
        $user->departments()->attach($department->id);
        $user->servicePoints()->attach($point->id);

        return [$user, $department, $point];
    }

    private function createVisit(Department $department, string $ticket, Carbon $queuedAt): Visit
    {
        $patient = Patient::create(['reference' => 'TMP-'.Str::upper(Str::random(10)), 'details_complete' => false]);
        $visit = Visit::create(['patient_id' => $patient->id, 'ticket_number' => $ticket, 'status' => 'active']);
        QueueEntry::create([
            'visit_id' => $visit->id,
            'department_id' => $department->id,
            'active_visit_key' => 'visit:'.$visit->id,
            'status' => 'waiting',
            'queued_at' => $queuedAt,
        ]);

        return $visit;
    }
}
