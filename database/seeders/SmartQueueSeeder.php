<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServicePoint;
use App\Models\TicketSequence;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class SmartQueueSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demonstration accounts cannot be seeded in production.');
        }

        $permissionNames = [
            'queue.call' => 'Call queue tickets',
            'queue.manage' => 'Manage queue stages',
            'queue.transfer' => 'Transfer visits between departments',
            'queue.set-priority' => 'Set queue priority',
            'visits.register' => 'Register visits',
            'visits.cancel' => 'Cancel visits',
            'visits.complete' => 'Complete visits',
            'analytics.view' => 'View management analytics',
            'departments.manage' => 'Manage departments and service points',
            'users.manage' => 'Manage staff assignments',
        ];
        $permissions = collect($permissionNames)->mapWithKeys(fn ($name, $slug) => [
            $slug => Permission::updateOrCreate(['slug' => $slug], ['name' => $name]),
        ]);

        $roleNames = [
            'system-administrator' => 'System Administrator',
            'clinic-manager' => 'Clinic Manager',
            'receptionist' => 'Receptionist',
            'triage-staff' => 'Triage Staff',
            'doctor' => 'Doctor',
            'laboratory-staff' => 'Laboratory Staff',
            'pharmacy-staff' => 'Pharmacy Staff',
        ];
        $roles = collect($roleNames)->mapWithKeys(fn ($name, $slug) => [
            $slug => Role::updateOrCreate(['slug' => $slug], ['name' => $name]),
        ]);

        $allPermissions = $permissions->pluck('id')->all();
        $roles['system-administrator']->permissions()->syncWithoutDetaching($allPermissions);
        $roles['clinic-manager']->permissions()->syncWithoutDetaching($allPermissions);
        $roles['receptionist']->permissions()->syncWithoutDetaching($permissions->only(['visits.register', 'queue.manage', 'queue.call', 'queue.transfer'])->pluck('id'));
        foreach (['triage-staff', 'doctor', 'laboratory-staff', 'pharmacy-staff'] as $roleSlug) {
            $roles[$roleSlug]->permissions()->syncWithoutDetaching($permissions->only(['queue.manage', 'queue.call', 'queue.set-priority', 'queue.transfer'])->pluck('id'));
        }

        $departments = collect([
            ['Reception', 'reception', 'REC'],
            ['Triage', 'triage', 'TRI'],
            ['Doctor', 'doctor', 'DOC'],
            ['Laboratory', 'laboratory', 'LAB'],
            ['Pharmacy', 'pharmacy', 'PHA'],
        ])->mapWithKeys(function ($values) {
            [$name, $slug, $code] = $values;
            $department = Department::withTrashed()->updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'code' => $code,
                'is_active' => true,
                'deleted_at' => null,
            ]);
            $department->restore();
            ServicePoint::updateOrCreate(
                ['department_id' => $department->id, 'code' => $code.'-1'],
                ['name' => $name.' 1', 'is_active' => true],
            );

            return [$slug => $department];
        });

        TicketSequence::firstOrCreate(['id' => 1], ['next_number' => 1, 'prefix' => env('QUEUE_TICKET_PREFIX', 'B')]);

        $password = env('DEMO_USER_PASSWORD') ?: 'SmartQueueDemo!2026';
        $accounts = [
            ['System Administrator', 'admin@smartqueue.test', 'system-administrator', null],
            ['Clinic Manager', 'manager@smartqueue.test', 'clinic-manager', null],
            ['Reception Staff', 'reception@smartqueue.test', 'receptionist', 'reception'],
            ['Triage Staff', 'triage@smartqueue.test', 'triage-staff', 'triage'],
            ['Doctor Staff', 'doctor@smartqueue.test', 'doctor', 'doctor'],
            ['Laboratory Staff', 'laboratory@smartqueue.test', 'laboratory-staff', 'laboratory'],
            ['Pharmacy Staff', 'pharmacy@smartqueue.test', 'pharmacy-staff', 'pharmacy'],
        ];

        foreach ($accounts as [$name, $email, $roleSlug, $departmentSlug]) {
            if ($roleSlug === 'system-administrator') {
                $email = env('DEMO_ADMIN_EMAIL', $email);
            }
            $user = User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make($password),
            ]);
            $user->roles()->syncWithoutDetaching([$roles[$roleSlug]->id]);
            if ($departmentSlug) {
                $user->departments()->syncWithoutDetaching([$departments[$departmentSlug]->id]);
                $user->servicePoints()->syncWithoutDetaching([
                    $departments[$departmentSlug]->servicePoints()->where('is_active', true)->value('id'),
                ]);
            }
        }
    }
}
