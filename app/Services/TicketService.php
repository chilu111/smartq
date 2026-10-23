<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Patient;
use App\Models\QueueEntry;
use App\Models\QueueEvent;
use App\Models\TicketSequence;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketService
{
    public function __construct(private readonly AuditService $audit) {}

    public function register(array $data, User $actor): Visit
    {
        try {
            return DB::transaction(function () use ($data, $actor) {
                $existing = Visit::query()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    return $existing->load(['patient', 'queueEntries.department']);
                }

                $department = Department::query()->whereKey($data['department_id'])->where('is_active', true)->firstOrFail();
                $servicePoint = null;

                if (! empty($data['service_point_id'])) {
                    $servicePoint = $department->servicePoints()
                        ->whereKey($data['service_point_id'])
                        ->where('is_active', true)
                        ->firstOrFail();
                }

                DB::table('ticket_sequences')->insertOrIgnore([
                    'id' => 1,
                    'next_number' => 1,
                    'prefix' => 'B',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $sequence = TicketSequence::query()->lockForUpdate()->findOrFail(1);
                do {
                    $ticketNumber = $sequence->prefix.str_pad((string) $sequence->next_number, 3, '0', STR_PAD_LEFT);
                    $sequence->increment('next_number');
                    $sequence->refresh();
                } while (Visit::query()->where('ticket_number', $ticketNumber)->exists());

                $patient = Patient::create([
                    'reference' => 'TMP-'.Str::upper(Str::random(10)),
                    'display_name' => $data['display_name'] ?? null,
                    'details_complete' => filled($data['display_name'] ?? null),
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $visit = Visit::create([
                    'patient_id' => $patient->id,
                    'ticket_number' => $ticketNumber,
                    'idempotency_key' => $data['idempotency_key'],
                    'status' => 'active',
                    'registration_complete' => $patient->details_complete,
                    'created_by' => $actor->id,
                ]);

                $entry = QueueEntry::create([
                    'visit_id' => $visit->id,
                    'department_id' => $department->id,
                    'service_point_id' => $servicePoint?->id,
                    'active_visit_key' => 'visit:'.$visit->id,
                    'status' => 'waiting',
                    'queued_at' => now(),
                ]);

                QueueEvent::create([
                    'visit_id' => $visit->id,
                    'queue_entry_id' => $entry->id,
                    'department_id' => $department->id,
                    'actor_id' => $actor->id,
                    'event_type' => 'visit_registered',
                    'to_status' => 'waiting',
                    'details' => ['ticket_number' => $ticketNumber, 'temporary_registration' => ! $patient->details_complete],
                    'created_at' => now(),
                ]);

                $this->audit->record($actor, 'visit.registered', $visit, ['ticket_number' => $ticketNumber]);

                return $visit->load(['patient', 'queueEntries.department']);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Visit::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing->load(['patient', 'queueEntries.department']);
            }

            throw $exception;
        }
    }

    public function completeRegistration(Patient $patient, array $data, User $actor): Patient
    {
        return DB::transaction(function () use ($patient, $data, $actor) {
            $patient = Patient::query()->lockForUpdate()->findOrFail($patient->id);
            $patient->fill(['display_name' => $data['display_name'], 'details_complete' => true, 'updated_by' => $actor->id]);
            $patient->save();
            $patient->visits()->where('registration_complete', false)->update(['registration_complete' => true]);
            $this->audit->record($actor, 'patient.registration_completed', $patient);

            return $patient;
        });
    }
}
