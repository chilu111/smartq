<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\QueueEntry;
use App\Models\ServicePoint;
use App\Models\Visit;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QueueController extends Controller
{
    public function index(Department $department): View
    {
        return view('queue.workspace', [
            'department' => $department,
            'servicePoints' => $department->servicePoints()->where('is_active', true)
                ->when(! request()->user()->hasRole('system-administrator', 'clinic-manager'), fn ($query) => $query->whereHas('users', fn ($users) => $users->whereKey(request()->user()->id)))
                ->orderBy('name')->get(),
        ]);
    }

    public function state(Department $department): JsonResponse
    {
        $entries = QueueEntry::query()->with(['visit:id,ticket_number', 'servicePoint:id,name'])
            ->where('department_id', $department->id)
            ->whereIn('status', ['waiting', 'called', 'serving', 'held', 'absent'])
            ->orderByDesc('priority')->orderBy('queued_at')->orderBy('id')->get();

        return response()->json([
            'updated_at' => now()->toIso8601String(),
            'counts' => [
                'waiting' => $entries->where('status', 'waiting')->count(),
                'serving' => $entries->where('status', 'serving')->count(),
                'completed' => QueueEntry::query()->where('department_id', $department->id)->where('status', 'completed')->whereDate('completed_at', today())->count(),
                'held' => $entries->where('status', 'held')->count(),
                'absent' => $entries->where('status', 'absent')->count(),
            ],
            'current' => $entries->whereIn('status', ['called', 'serving'])->map(fn ($entry) => $this->publicEntry($entry))->values(),
            'next' => $entries->firstWhere('status', 'waiting') ? $this->publicEntry($entries->firstWhere('status', 'waiting')) : null,
            'entries' => $entries->map(fn ($entry) => $this->staffEntry($entry))->values(),
            'ready_to_close' => Visit::query()->where('status', 'active')
                ->whereHas('queueEntries')
                ->whereDoesntHave('queueEntries', fn ($query) => $query->whereIn('status', QueueEntry::ACTIVE_STATUSES))
                ->latest()->limit(8)->get(['id', 'ticket_number']),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function callNext(Request $request, Department $department, QueueService $queues): JsonResponse
    {
        abort_unless($request->user()->hasPermission('queue.call'), 403);
        $data = $request->validate(['service_point_id' => ['required', 'integer']]);
        $point = $department->servicePoints()->whereKey($data['service_point_id'])->where('is_active', true)->firstOrFail();
        abort_unless($request->user()->hasRole('system-administrator', 'clinic-manager') || $point->users()->whereKey($request->user()->id)->exists(), 403);
        $entry = $queues->callNext($department, $point, $request->user());

        return response()->json([
            'message' => $entry ? 'Ticket called.' : 'No patients are waiting.',
            'entry' => $entry ? $this->staffEntry($entry) : null,
        ], $entry ? 200 : 200);
    }

    public function action(Request $request, Department $department, QueueEntry $entry, QueueService $queues): JsonResponse
    {
        abort_unless($entry->department_id === $department->id, 404);
        abort_unless($request->user()->hasPermission('queue.manage'), 403);
        $data = $request->validate([
            'action' => ['required', 'in:recall,start,complete,hold,resume,absent,return,requeue,cancel,transfer,priority,move_point'],
            'department_id' => ['required_if:action,transfer', 'nullable', 'integer', 'exists:departments,id'],
            'service_point_id' => ['required_if:action,move_point', 'nullable', 'integer', 'exists:service_points,id'],
            'priority' => ['required_if:action,priority', 'nullable', 'integer', 'between:0,1'],
            'reason' => ['required_if:action,priority', 'nullable', 'string', 'max:240'],
        ]);

        if ($data['action'] === 'priority') {
            abort_unless($request->user()->hasPermission('queue.set-priority'), 403);
        }
        if ($data['action'] === 'transfer') {
            abort_unless($request->user()->hasPermission('queue.transfer'), 403);
            $destination = Department::query()->findOrFail($data['department_id']);
            if (isset($data['service_point_id'])) {
                $point = ServicePoint::query()->findOrFail($data['service_point_id']);
                abort_unless($request->user()->hasRole('system-administrator', 'clinic-manager') || $point->users()->whereKey($request->user()->id)->exists(), 403);
            }
        }
        if ($data['action'] === 'move_point') {
            $point = ServicePoint::query()->whereKey($data['service_point_id'])->firstOrFail();
            abort_unless($request->user()->hasRole('system-administrator', 'clinic-manager') || $point->users()->whereKey($request->user()->id)->exists(), 403);
        }

        $updated = match ($data['action']) {
            'recall' => $queues->recall($entry, $request->user()),
            'start' => $queues->startService($entry, $request->user()),
            'complete' => $queues->completeService($entry, $request->user()),
            'hold' => $queues->hold($entry, $request->user()),
            'resume' => $queues->resume($entry, $request->user()),
            'absent' => $queues->markAbsent($entry, $request->user()),
            'return' => $queues->returnAbsent($entry, $request->user()),
            'requeue' => $queues->returnToQueue($entry, $request->user()),
            'cancel' => $queues->cancelEntry($entry, $request->user()),
            'transfer' => $queues->transfer(
                $entry,
                Department::query()->findOrFail($data['department_id']),
                isset($data['service_point_id']) ? ServicePoint::query()->findOrFail($data['service_point_id']) : null,
                $request->user(),
            ),
            'priority' => $queues->setPriority($entry, $data['priority'], $data['reason'], $request->user()),
            'move_point' => $queues->moveToServicePoint($entry, ServicePoint::query()->findOrFail($data['service_point_id']), $request->user()),
        };

        return response()->json(['message' => 'Queue updated.', 'entry' => $this->staffEntry($updated)]);
    }

    private function publicEntry(QueueEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'ticket' => $entry->visit->ticket_number,
            'status' => $entry->status,
            'service_point' => $entry->servicePoint?->name,
            'service_point_id' => $entry->service_point_id,
        ];
    }

    private function staffEntry(QueueEntry $entry): array
    {
        return $this->publicEntry($entry) + [
            'priority' => $entry->priority,
            'queued_at' => $entry->queued_at?->toIso8601String(),
            'waiting_minutes' => $entry->first_called_at
                ? max(0, $entry->queued_at->diffInMinutes($entry->first_called_at) - (int) floor($entry->waiting_paused_seconds / 60))
                : max(0, $entry->queued_at->diffInMinutes(now()) - (int) floor($entry->waiting_paused_seconds / 60)),
        ];
    }
}
