<?php

namespace App\Http\Middleware;

use App\Models\Department;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DepartmentAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $department = $request->route('department');
        $department = $department instanceof Department ? $department : Department::query()->where('slug', $department)->firstOrFail();
        $user = $request->user();
        $administrator = $user->hasRole('system-administrator', 'clinic-manager');

        abort_if(! $department->is_active && ! $administrator, 404);

        abort_unless(
            $administrator ||
            $user->departments()->whereKey($department->id)->exists(),
            403,
        );

        return $next($request);
    }
}
