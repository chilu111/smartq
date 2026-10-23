<?php

namespace App\Http\Controllers;

use App\Models\QueueEvent;
use App\Services\AnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, AnalyticsService $analytics): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($filters['from'] ?? $filters['to'] ?? today());
        $to = Carbon::parse($filters['to'] ?? $filters['from'] ?? today());
        $report = $analytics->report($from, $to);

        return view('dashboard', [
            'from' => $from,
            'to' => $to,
            'report' => $report,
            'recentEvents' => QueueEvent::query()->with(['visit', 'department', 'actor'])
                ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->latest('created_at')->limit(8)->get(),
        ]);
    }
}
