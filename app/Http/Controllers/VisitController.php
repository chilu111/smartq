<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\QueueService;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VisitController extends Controller
{
    public function create(Department $department): View
    {
        abort_unless(request()->user()->hasPermission('visits.register'), 403);

        return view('visits.create', [
            'department' => $department,
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(),
            'servicePoints' => $department->servicePoints()->where('is_active', true)
                ->when(! request()->user()->hasRole('system-administrator', 'clinic-manager'), fn ($query) => $query->whereHas('users', fn ($users) => $users->whereKey(request()->user()->id)))
                ->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, Department $department, TicketService $tickets): JsonResponse
    {
        abort_unless($request->user()->hasPermission('visits.register'), 403);
        $validated = $request->validate([
            'display_name' => ['nullable', 'string', 'max:120'],
            'service_point_id' => ['nullable', 'integer'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $validated['department_id'] = $department->id;

        if (! empty($validated['service_point_id'])) {
            $point = $department->servicePoints()->whereKey($validated['service_point_id'])->firstOrFail();
            abort_unless($request->user()->hasRole('system-administrator', 'clinic-manager') || $point->users()->whereKey($request->user()->id)->exists(), 403);
        }

        $visit = $tickets->register($validated, $request->user());

        return response()->json([
            'message' => 'Ticket issued.',
            'ticket' => $visit->ticket_number,
            'visit_id' => $visit->id,
            'department' => $department->name,
        ], 201);
    }

    public function completeRegistration(Request $request, Patient $patient, TicketService $tickets): JsonResponse
    {
        abort_unless($request->user()->hasRole('system-administrator', 'clinic-manager', 'receptionist'), 403);
        $validated = $request->validate(['display_name' => ['required', 'string', 'max:120']]);
        $patient = $tickets->completeRegistration($patient, $validated, $request->user());

        return response()->json(['message' => 'Patient registration updated.', 'patient' => $patient->only('id', 'display_name', 'details_complete')]);
    }

    public function complete(Visit $visit, Request $request, QueueService $queues): JsonResponse
    {
        abort_unless($request->user()->hasPermission('visits.complete'), 403);

        return response()->json(['message' => 'Visit completed.', 'visit' => $queues->completeVisit($visit, $request->user())->only('id', 'status', 'completed_at')]);
    }

    public function cancel(Visit $visit, Request $request, QueueService $queues): JsonResponse
    {
        abort_unless($request->user()->hasPermission('visits.cancel'), 403);

        return response()->json(['message' => 'Visit cancelled.', 'visit' => $queues->cancelVisit($visit, $request->user())->only('id', 'status', 'cancelled_at')]);
    }
}
