<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Permission;
use App\Models\QueueEntry;
use App\Models\Role;
use App\Models\ServicePoint;
use App\Models\TicketSequence;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(): View
    {
        return view('admin.index', [
            'departments' => Department::withTrashed()->with(['servicePoints', 'operationalTarget'])->withCount([
                'servicePoints',
                'queueEntries as waiting_count' => fn ($query) => $query->where('status', 'waiting'),
            ])->with('operationalTarget')->orderBy('name')->get(),
            'roles' => Role::query()->with('permissions')->orderBy('name')->get(),
            'users' => User::query()->with(['roles', 'departments', 'servicePoints'])->orderBy('name')->get(),
            'ticketSequence' => TicketSequence::query()->find(1),
            'permissions' => Permission::query()->orderBy('name')->get(),
        ]);
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:8', 'alpha_dash', 'unique:departments,code'],
        ]);
        $slug = Str::slug($data['name']);
        if (Department::withTrashed()->where('slug', $slug)->exists()) {
            return back()->withErrors(['name' => 'A department with this name already exists.']);
        }

        $department = Department::create(['name' => $data['name'], 'slug' => $slug, 'code' => strtoupper($data['code']), 'is_active' => true]);
        $this->audit($request, 'department.created', $department);

        return back()->with('status', 'Department created.');
    }

    public function toggleDepartment(Department $department, Request $request): RedirectResponse
    {
        if ($department->is_active && $department->queueEntries()->whereIn('status', QueueEntry::ACTIVE_STATUSES)->exists()) {
            return back()->withErrors(['department' => 'Finish, transfer, or cancel active queue stages before deactivating this department.']);
        }

        $department->update(['is_active' => ! $department->is_active]);
        $this->audit($request, 'department.activation_changed', $department, ['is_active' => $department->is_active]);

        return back()->with('status', 'Department status updated.');
    }

    public function archiveDepartment(Department $department, Request $request): RedirectResponse
    {
        if ($department->is_active || $department->queueEntries()->whereIn('status', QueueEntry::ACTIVE_STATUSES)->exists()) {
            return back()->withErrors(['department' => 'Deactivate the department and resolve active queue stages before archiving it.']);
        }

        $department->delete();
        $this->audit($request, 'department.archived', $department);

        return back()->with('status', 'Department archived. Historical queue records remain available.');
    }

    public function restoreDepartment(int $department, Request $request): RedirectResponse
    {
        $department = Department::withTrashed()->findOrFail($department);
        abort_unless($department->trashed(), 422);
        $department->restore();
        $department->update(['is_active' => false]);
        $this->audit($request, 'department.restored', $department);

        return back()->with('status', 'Department restored as inactive.');
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:8', 'alpha_dash', 'unique:departments,code,'.$department->id],
        ]);
        $slug = Str::slug($data['name']);
        abort_if(Department::withTrashed()->where('slug', $slug)->whereKeyNot($department->id)->exists(), 422, 'A department with this name already exists.');
        $department->update(['name' => $data['name'], 'slug' => $slug, 'code' => strtoupper($data['code'])]);
        $this->audit($request, 'department.updated', $department, ['name' => $data['name'], 'code' => strtoupper($data['code'])]);

        return back()->with('status', 'Department updated.');
    }

    public function storeServicePoint(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:12', 'alpha_dash'],
        ]);
        $department = Department::query()->whereKey($data['department_id'])->where('is_active', true)->firstOrFail();
        $request->validate(['code' => ['unique:service_points,code,NULL,id,department_id,'.$department->id]]);
        $point = ServicePoint::create(array_merge($data, ['code' => strtoupper($data['code']), 'is_active' => true]));
        $this->audit($request, 'service_point.created', $point);

        return back()->with('status', 'Service point created.');
    }

    public function toggleServicePoint(ServicePoint $servicePoint, Request $request): RedirectResponse
    {
        if ($servicePoint->is_active && $servicePoint->queueEntries()->whereNotNull('service_point_active_key')->exists()) {
            return back()->withErrors(['service_point' => 'Complete or move the current ticket before deactivating this service point.']);
        }

        $servicePoint->update(['is_active' => ! $servicePoint->is_active]);
        $this->audit($request, 'service_point.activation_changed', $servicePoint, ['is_active' => $servicePoint->is_active]);

        return back()->with('status', 'Service point status updated.');
    }

    public function updateServicePoint(Request $request, ServicePoint $servicePoint): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:12', 'alpha_dash', 'unique:service_points,code,'.$servicePoint->id.',id,department_id,'.$servicePoint->department_id],
        ]);
        $servicePoint->update(['name' => $data['name'], 'code' => strtoupper($data['code'])]);
        $this->audit($request, 'service_point.updated', $servicePoint, ['name' => $data['name'], 'code' => strtoupper($data['code'])]);

        return back()->with('status', 'Service point updated.');
    }

    public function updateTarget(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate([
            'target_wait_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'target_service_minutes' => ['nullable', 'integer', 'between:1,1440'],
        ]);
        $department->operationalTarget()->updateOrCreate([], $data);
        $this->audit($request, 'department.targets_updated', $department, $data);

        return back()->with('status', 'Operational targets updated.');
    }

    public function updateTicketNumbering(Request $request): RedirectResponse
    {
        $data = $request->validate(['prefix' => ['required', 'string', 'alpha_num', 'min:1', 'max:4']]);
        $sequence = TicketSequence::query()->firstOrCreate(['id' => 1], ['next_number' => 1, 'prefix' => 'B']);
        $sequence->update(['prefix' => strtoupper($data['prefix'])]);
        $this->audit($request, 'ticket.prefix_updated', null, ['prefix' => strtoupper($data['prefix'])]);

        return back()->with('status', 'Ticket format updated.');
    }

    public function updateRolePermissions(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);
        $role->permissions()->sync($data['permission_ids'] ?? []);
        $this->audit($request, 'role.permissions_updated', $role, ['permission_ids' => $data['permission_ids'] ?? []]);

        return back()->with('status', 'Role permissions updated.');
    }

    public function storeStaff(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:200'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'distinct', 'exists:departments,id'],
            'service_point_ids' => ['nullable', 'array'],
            'service_point_ids.*' => ['integer', 'distinct', 'exists:service_points,id'],
        ]);
        $departmentIds = $data['department_ids'] ?? [];
        $servicePoints = ServicePoint::query()->whereIn('id', $data['service_point_ids'] ?? [])->get();
        abort_if($servicePoints->contains(fn (ServicePoint $point) => ! in_array($point->department_id, $departmentIds)), 422, 'Each selected service point must belong to an assigned department.');

        $user = DB::transaction(function () use ($data, $departmentIds, $servicePoints) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'is_active' => true,
            ]);
            $user->roles()->sync([$data['role_id']]);
            $user->departments()->sync($departmentIds);
            $user->servicePoints()->sync($servicePoints->modelKeys());

            return $user;
        });
        app(AuditService::class)->record($request->user(), 'staff.created', $user, ['role_id' => $data['role_id']]);

        return back()->with('status', 'Staff account created.');
    }

    public function updateStaff(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'distinct', 'exists:departments,id'],
            'service_point_ids' => ['nullable', 'array'],
            'service_point_ids.*' => ['integer', 'distinct', 'exists:service_points,id'],
        ]);
        $departmentIds = $data['department_ids'] ?? [];
        $servicePoints = ServicePoint::query()->whereIn('id', $data['service_point_ids'] ?? [])->get();
        abort_if($servicePoints->contains(fn (ServicePoint $point) => ! in_array($point->department_id, $departmentIds)), 422, 'Each selected service point must belong to an assigned department.');
        $role = Role::query()->findOrFail($data['role_id']);
        abort_if($user->is($request->user()) && ! $user->roles()->whereKey($role->id)->exists(), 422, 'You cannot remove your own administrator role.');

        DB::transaction(function () use ($user, $data, $departmentIds, $servicePoints) {
            $user->update(['name' => $data['name']]);
            $user->roles()->sync([$data['role_id']]);
            $user->departments()->sync($departmentIds);
            $user->servicePoints()->sync($servicePoints->modelKeys());
        });
        $this->audit($request, 'staff.assignments_updated', $user, [
            'role_id' => $data['role_id'],
            'department_ids' => $departmentIds,
            'service_point_ids' => $servicePoints->modelKeys(),
        ]);

        return back()->with('status', 'Staff assignments updated.');
    }

    public function toggleStaff(User $user, Request $request): RedirectResponse
    {
        abort_if($user->is($request->user()), 422, 'You cannot deactivate your own account.');
        $user->update(['is_active' => ! $user->is_active]);
        app(AuditService::class)->record($request->user(), 'staff.activation_changed', $user, ['is_active' => $user->is_active]);

        return back()->with('status', 'Staff account status updated.');
    }

    private function audit(Request $request, string $action, mixed $subject = null, array $details = []): void
    {
        app(AuditService::class)->record($request->user(), $action, $subject, $details);
    }
}
