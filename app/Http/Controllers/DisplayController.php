<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DisplaySetting;
use App\Services\QueueDisplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DisplayController extends Controller
{
    public function show(Department $department): View
    {
        abort_unless($department->is_active, 404);

        return view('display.show', compact('department'));
    }

    public function state(Department $department, QueueDisplayService $display): JsonResponse
    {
        abort_unless($department->is_active, 404);

        return response()->json($display->state($department))->header('Cache-Control', 'no-store, public, max-age=0');
    }

    public function enableAudio(Request $request, Department $department): JsonResponse
    {
        abort_unless($department->is_active, 404);
        $data = $request->validate(['source_id' => ['required', 'uuid']]);

        $setting = DB::transaction(function () use ($department, $data) {
            DB::table('display_settings')->insertOrIgnore([
                'id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $setting = DisplaySetting::query()->lockForUpdate()->findOrFail(1);
            $setting->update(['audio_department_id' => $department->id, 'audio_source_id' => $data['source_id']]);

            return $setting;
        }, attempts: 3);

        return response()->json(['enabled' => true, 'department_id' => $setting->audio_department_id, 'source_id' => $setting->audio_source_id]);
    }
}
