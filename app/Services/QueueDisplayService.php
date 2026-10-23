<?php

namespace App\Services;

use App\Models\Department;
use App\Models\DisplaySetting;
use App\Models\QueueEntry;
use App\Models\QueueEvent;

class QueueDisplayService
{
    public function state(Department $department): array
    {
        $events = QueueEvent::query()->with([
            'visit:id,ticket_number',
            'queueEntry.servicePoint:id,name',
        ])->where('department_id', $department->id)
            ->whereIn('event_type', ['ticket_called', 'ticket_recalled'])
            ->latest('created_at')->limit(5)->get();

        $calls = $events->map(fn (QueueEvent $event) => [
            'ticket' => $event->visit->ticket_number,
            'service_point' => $event->queueEntry?->servicePoint?->name,
            'called_at' => $event->created_at?->toIso8601String(),
        ])->values();
        $displaySetting = DisplaySetting::query()->find(1);

        return [
            'department' => $department->name,
            'department_id' => $department->id,
            'updated_at' => now()->toIso8601String(),
            'current' => $this->currentCall($department),
            'recent' => $calls,
            'audio_department_id' => $displaySetting?->audio_department_id,
            'audio_source_id' => $displaySetting?->audio_source_id,
        ];
    }

    private function currentCall(Department $department): ?array
    {
        $entry = QueueEntry::query()->with(['visit:id,ticket_number', 'servicePoint:id,name'])
            ->where('department_id', $department->id)
            ->whereIn('status', ['called', 'serving'])
            ->orderByDesc('last_called_at')->orderByDesc('id')->first();

        return $entry ? [
            'ticket' => $entry->visit->ticket_number,
            'service_point' => $entry->servicePoint?->name,
            'status' => $entry->status,
            'called_at' => $entry->last_called_at?->toIso8601String(),
        ] : null;
    }
}
