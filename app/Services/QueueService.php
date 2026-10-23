<?php

namespace App\Services;

use App\Models\Department;
use App\Models\QueueEntry;
use App\Models\QueueEvent;
use App\Models\ServicePoint;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QueueService
{
    public function __construct(private readonly AuditService $audit) {}

    public function callNext(Department $department, ServicePoint $point, User $actor): ?QueueEntry
    {
        return DB::transaction(function () use ($department, $point, $actor) {
            Department::query()->whereKey($department->id)->lockForUpdate()->firstOrFail();
            ServicePoint::query()->whereKey($point->id)->lockForUpdate()->firstOrFail();

            if (! $department->is_active || ! $point->is_active || $point->department_id !== $department->id) {
                throw ValidationException::withMessages(['service_point_id' => 'Choose an active service point in this department.']);
            }

            $pointKey = 'point:'.$point->id;
            $current = QueueEntry::query()->where('service_point_active_key', $pointKey)->lockForUpdate()->first();
            if ($current) {
                throw ValidationException::withMessages(['queue' => 'Complete or recall the current ticket before calling another.']);
            }

            $entry = QueueEntry::query()
                ->where('department_id', $department->id)
                ->where('status', 'waiting')
                ->orderByDesc('priority')
                ->orderBy('queued_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $entry) {
                return null;
            }

            $before = $entry->status;
            $entry->update([
                'status' => 'called',
                'service_point_id' => $point->id,
                'service_point_active_key' => $pointKey,
                'first_called_at' => $entry->first_called_at ?? now(),
                'last_called_at' => now(),
            ]);

            $this->event($entry, $actor, 'ticket_called', $before, 'called', ['service_point_id' => $point->id]);
            $this->audit->record($actor, 'queue.ticket_called', $entry, ['department_id' => $department->id]);

            return $entry->fresh(['visit', 'servicePoint']);
        }, attempts: 3);
    }

    public function recall(QueueEntry $entry, User $actor): QueueEntry
    {
        return $this->transition($entry, $actor, ['called', 'serving'], 'ticket_recalled', function ($entry) {
            $entry->last_called_at = now();
        });
    }

    public function startService(QueueEntry $entry, User $actor): QueueEntry
    {
        return $this->transition($entry, $actor, ['called'], 'service_started', function ($entry) {
            $entry->status = 'serving';
            $entry->service_started_at = now();
        });
    }

    public function completeService(QueueEntry $entry, User $actor): QueueEntry
    {
        return $this->transition($entry, $actor, ['serving'], 'service_completed', function ($entry) {
            $entry->status = 'completed';
            $entry->service_completed_at = now();
            $entry->completed_at = now();
            $entry->active_visit_key = null;
            $entry->service_point_active_key = null;
        });
    }

    public function hold(QueueEntry $entry, User $actor): QueueEntry
    {
        return $this->transition($entry, $actor, ['waiting', 'called', 'serving'], 'queue_held', function ($entry) {
            if ($entry->status === 'serving') {
                $entry->service_pause_started_at = now();
            }
            $entry->status = 'held';
            $entry->held_at = now();
            $entry->service_point_active_key = null;
        });
    }

    public function resume(QueueEntry $entry, User $actor): QueueEntry
    {
        return DB::transaction(function () use ($entry, $actor) {
            $entry = QueueEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->status !== 'held') {
                $this->invalidTransition($entry, 'queue_resumed');
            }

            $before = $entry->status;
            if (! $entry->first_called_at && $entry->held_at) {
                $entry->waiting_paused_seconds += max(0, $entry->held_at->diffInSeconds(now()));
            }
            $entry->held_seconds += $entry->held_at ? max(0, $entry->held_at->diffInSeconds(now())) : 0;
            $entry->held_at = null;
            if ($entry->service_pause_started_at && $entry->service_point_id) {
                $point = ServicePoint::query()->whereKey($entry->service_point_id)->lockForUpdate()->first();
                if (! $point || ! $point->is_active || QueueEntry::query()->where('service_point_active_key', 'point:'.$point->id)->exists()) {
                    throw ValidationException::withMessages(['queue' => 'The original service point is not available. Choose another action or try again.']);
                }
                $entry->service_paused_seconds += max(0, $entry->service_pause_started_at->diffInSeconds(now()));
                $entry->service_pause_started_at = null;
                $entry->service_point_active_key = 'point:'.$point->id;
                $entry->status = 'serving';
            } else {
                $entry->status = 'waiting';
                $entry->service_point_id = null;
            }
            $entry->save();
            $this->event($entry, $actor, 'queue_resumed', $before, $entry->status);
            $this->audit->record($actor, 'queue.queue_resumed', $entry);

            return $entry->fresh(['visit', 'department', 'servicePoint']);
        });
    }

    public function markAbsent(QueueEntry $entry, User $actor): QueueEntry
    {
        return $this->transition($entry, $actor, ['called'], 'patient_absent', function ($entry) {
            $entry->status = 'absent';
            $entry->absent_at = now();
            $entry->service_point_active_key = null;
        });
    }

    public function returnAbsent(QueueEntry $entry, User $actor): QueueEntry
    {
        return $this->transition($entry, $actor, ['absent'], 'patient_returned', function ($entry) {
            $entry->status = 'waiting';
            $entry->queued_at = now();
            $entry->first_called_at = null;
            $entry->last_called_at = null;
            $entry->absent_at = null;
            $entry->service_point_id = null;
        });
    }

    public function returnToQueue(QueueEntry $entry, User $actor): QueueEntry
    {
        return $this->transition($entry, $actor, ['called'], 'returned_to_queue', function ($entry) {
            $entry->status = 'waiting';
            $entry->service_point_active_key = null;
            $entry->service_point_id = null;
        });
    }

    public function moveToServicePoint(QueueEntry $entry, ServicePoint $point, User $actor): QueueEntry
    {
        return DB::transaction(function () use ($entry, $point, $actor) {
            $entry = QueueEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $point = ServicePoint::query()->whereKey($point->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'called' || $point->department_id !== $entry->department_id || ! $point->is_active) {
                throw ValidationException::withMessages(['service_point_id' => 'Choose an active service point in this department for a called ticket.']);
            }
            if (QueueEntry::query()->where('service_point_active_key', 'point:'.$point->id)->whereKeyNot($entry->id)->exists()) {
                throw ValidationException::withMessages(['service_point_id' => 'That service point is already serving another ticket.']);
            }

            $previousPoint = $entry->service_point_id;
            $entry->update(['service_point_id' => $point->id, 'service_point_active_key' => 'point:'.$point->id]);
            $this->event($entry, $actor, 'service_point_changed', 'called', 'called', [
                'from_service_point_id' => $previousPoint,
                'to_service_point_id' => $point->id,
            ]);
            $this->audit->record($actor, 'queue.service_point_changed', $entry, ['from_service_point_id' => $previousPoint, 'to_service_point_id' => $point->id]);

            return $entry->fresh(['visit', 'department', 'servicePoint']);
        }, attempts: 3);
    }

    public function cancelEntry(QueueEntry $entry, User $actor): QueueEntry
    {
        return $this->transition($entry, $actor, ['waiting', 'called', 'held', 'absent'], 'queue_cancelled', function ($entry) {
            $entry->status = 'cancelled';
            $entry->cancelled_at = now();
            $entry->active_visit_key = null;
            $entry->service_point_active_key = null;
        });
    }

    public function setPriority(QueueEntry $entry, int $priority, string $reason, User $actor): QueueEntry
    {
        return $this->transition($entry, $actor, ['waiting'], 'priority_changed', function ($entry) use ($priority, $reason, $actor) {
            $entry->priority = $priority;
            $entry->priority_reason = $reason;
            $entry->priority_set_by = $actor->id;
            $entry->priority_set_at = now();
        }, ['priority' => $priority, 'reason' => $reason]);
    }

    public function transfer(QueueEntry $entry, Department $destination, ?ServicePoint $point, User $actor): QueueEntry
    {
        return DB::transaction(function () use ($entry, $destination, $point, $actor) {
            $entry = QueueEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $destination = Department::query()->lockForUpdate()->findOrFail($destination->id);

            if (! in_array($entry->status, QueueEntry::ACTIVE_STATUSES, true)) {
                $this->invalidTransition($entry, 'transferred');
            }
            if (! $destination->is_active || $destination->id === $entry->department_id) {
                throw ValidationException::withMessages(['department_id' => 'Choose a different active department.']);
            }
            if ($point && ($point->department_id !== $destination->id || ! $point->is_active)) {
                throw ValidationException::withMessages(['service_point_id' => 'Choose an active service point in the destination department.']);
            }

            $before = $entry->status;
            if (! $entry->first_called_at && $entry->held_at) {
                $entry->waiting_paused_seconds += max(0, $entry->held_at->diffInSeconds(now()));
            }
            if ($entry->service_pause_started_at) {
                $entry->service_paused_seconds += max(0, $entry->service_pause_started_at->diffInSeconds(now()));
                $entry->service_pause_started_at = null;
            }
            $entry->update([
                'status' => 'completed',
                'completed_at' => now(),
                'service_completed_at' => $entry->service_started_at ? now() : $entry->service_completed_at,
                'active_visit_key' => null,
                'service_point_active_key' => null,
            ]);
            $this->event($entry, $actor, 'department_transfer_out', $before, 'completed', ['destination_department_id' => $destination->id]);

            $target = QueueEntry::create([
                'visit_id' => $entry->visit_id,
                'department_id' => $destination->id,
                'service_point_id' => $point?->id,
                'active_visit_key' => 'visit:'.$entry->visit_id,
                'status' => 'waiting',
                'queued_at' => now(),
            ]);
            $this->event($target, $actor, 'department_transfer_in', null, 'waiting', ['source_department_id' => $entry->department_id]);
            $this->audit->record($actor, 'queue.department_transferred', $target, [
                'source_department_id' => $entry->department_id,
                'destination_department_id' => $destination->id,
            ]);

            return $target->fresh(['visit', 'department']);
        }, attempts: 3);
    }

    public function cancelVisit(Visit $visit, User $actor): Visit
    {
        return DB::transaction(function () use ($visit, $actor) {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            if ($visit->status !== 'active') {
                throw ValidationException::withMessages(['visit' => 'This visit is no longer active.']);
            }

            $entries = $visit->queueEntries()->active()->lockForUpdate()->get();
            foreach ($entries as $entry) {
                $before = $entry->status;
                $entry->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'active_visit_key' => null,
                    'service_point_active_key' => null,
                ]);
                $this->event($entry, $actor, 'visit_cancelled', $before, 'cancelled');
            }

            $visit->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->audit->record($actor, 'visit.cancelled', $visit);

            return $visit;
        }, attempts: 3);
    }

    public function completeVisit(Visit $visit, User $actor): Visit
    {
        return DB::transaction(function () use ($visit, $actor) {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            if ($visit->status !== 'active' || $visit->queueEntries()->active()->exists()) {
                throw ValidationException::withMessages(['visit' => 'Complete or cancel all active queue stages before completing this visit.']);
            }

            $visit->update(['status' => 'completed', 'completed_at' => now()]);
            $this->audit->record($actor, 'visit.completed', $visit);

            return $visit;
        }, attempts: 3);
    }

    private function transition(QueueEntry $entry, User $actor, array $allowed, string $event, callable $change, array $details = []): QueueEntry
    {
        return DB::transaction(function () use ($entry, $actor, $allowed, $event, $change, $details) {
            $entry = QueueEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if (! in_array($entry->status, $allowed, true)) {
                $this->invalidTransition($entry, $event);
            }

            $before = $entry->status;
            $change($entry);
            $entry->save();
            $this->event($entry, $actor, $event, $before, $entry->status, $details);
            $this->audit->record($actor, 'queue.'.$event, $entry, $details);

            return $entry->fresh(['visit', 'department', 'servicePoint']);
        }, attempts: 3);
    }

    private function event(QueueEntry $entry, User $actor, string $type, ?string $from, ?string $to, array $details = []): void
    {
        QueueEvent::create([
            'visit_id' => $entry->visit_id,
            'queue_entry_id' => $entry->id,
            'department_id' => $entry->department_id,
            'actor_id' => $actor->id,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'details' => $details,
            'created_at' => now(),
        ]);
    }

    private function invalidTransition(QueueEntry $entry, string $action): never
    {
        throw ValidationException::withMessages([
            'queue' => "Cannot perform {$action} while this queue stage is {$entry->status}.",
        ]);
    }
}
