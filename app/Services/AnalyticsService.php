<?php

namespace App\Services;

use App\Models\Department;
use App\Models\QueueEntry;
use App\Models\Visit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class AnalyticsService
{
    public function __construct(private readonly QueueTimingService $timing) {}

    public function report(CarbonInterface $from, CarbonInterface $to): array
    {
        $visits = Visit::query()->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get(['id', 'created_at']);
        $completedVisits = Visit::query()->where('status', 'completed')
            ->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get(['id', 'created_at', 'completed_at']);
        $stages = QueueEntry::query()->whereBetween('queued_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get(['id', 'department_id', 'status', 'queued_at', 'first_called_at', 'waiting_paused_seconds', 'service_started_at', 'service_completed_at', 'service_paused_seconds']);

        $waits = $stages->map(fn (QueueEntry $entry) => $this->timing->waitingSeconds($entry))->filter(fn ($seconds) => $seconds !== null);
        $services = $stages->map(fn (QueueEntry $entry) => $this->timing->serviceSeconds($entry))->filter(fn ($seconds) => $seconds !== null);
        $hourly = collect(range(0, 23))->mapWithKeys(fn ($hour) => [$hour => 0]);
        foreach ($visits as $visit) {
            $hour = $visit->created_at->hour;
            $hourly->put($hour, $hourly->get($hour) + 1);
        }

        $departmentStats = Department::query()->where('is_active', true)->withCount([
            'queueEntries as waiting_count' => fn ($query) => $query->where('status', 'waiting'),
            'queueEntries as serving_count' => fn ($query) => $query->where('status', 'serving'),
            'queueEntries as held_count' => fn ($query) => $query->whereIn('status', ['held', 'absent']),
        ])->with('operationalTarget')->orderBy('name')->get()->map(function (Department $department) use ($stages) {
            $departmentStages = $stages->where('department_id', $department->id);
            $departmentWaits = $departmentStages->map(fn ($entry) => $this->timing->waitingSeconds($entry))->filter(fn ($seconds) => $seconds !== null);
            $departmentServices = $departmentStages->map(fn ($entry) => $this->timing->serviceSeconds($entry))->filter(fn ($seconds) => $seconds !== null);

            return [
                'department' => $department,
                'completed_count' => $departmentStages->where('status', 'completed')->count(),
                'average_wait_minutes' => $this->averageMinutes($departmentWaits),
                'average_service_minutes' => $this->averageMinutes($departmentServices),
                'target_wait_minutes' => $department->operationalTarget?->target_wait_minutes,
                'target_service_minutes' => $department->operationalTarget?->target_service_minutes,
            ];
        });

        return [
            'visits_created' => $visits->count(),
            'visits_completed' => $completedVisits->count(),
            'waiting_now' => QueueEntry::query()->where('status', 'waiting')->count(),
            'serving_now' => QueueEntry::query()->where('status', 'serving')->count(),
            'held_absent_now' => QueueEntry::query()->whereIn('status', ['held', 'absent'])->count(),
            'average_wait_minutes' => $this->averageMinutes($waits),
            'average_service_minutes' => $this->averageMinutes($services),
            'average_visit_minutes' => $this->averageMinutes($completedVisits->map(fn (Visit $visit) => $this->timing->visitSeconds($visit))),
            'hourly_visits' => $hourly,
            'peak_hour_count' => max(1, (int) $hourly->max()),
            'departments' => $departmentStats,
        ];
    }

    private function averageMinutes(Collection $seconds): ?int
    {
        return $seconds->isEmpty() ? null : (int) round($seconds->avg() / 60);
    }
}
