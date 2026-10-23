<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(): View
    {
        return view('admin.audit', [
            'logs' => AuditLog::query()->with('actor')->latest('created_at')->paginate(40),
        ]);
    }
}
