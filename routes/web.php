<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisplayController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\VisitController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    $user = auth()->user();
    if ($user->hasRole('system-administrator', 'clinic-manager')) {
        return redirect()->route('dashboard');
    }

    $department = $user->departments()->where('is_active', true)->orderBy('name')->first();

    return $department ? redirect()->route('departments.workspace', $department) : abort(403);
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
});

Route::get('/display/{department:slug}', [DisplayController::class, 'show'])->name('display.show');
Route::get('/display/{department:slug}/state', [DisplayController::class, 'state'])->middleware('throttle:120,1')->name('display.state');
Route::post('/display/{department:slug}/audio', [DisplayController::class, 'enableAudio'])->middleware('throttle:5,1')->name('display.audio');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('role:system-administrator,clinic-manager')->name('dashboard');

    Route::middleware(['role:system-administrator,clinic-manager'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::post('/departments', [AdminController::class, 'storeDepartment'])->name('departments.store');
        Route::patch('/departments/{department}/toggle', [AdminController::class, 'toggleDepartment'])->name('departments.toggle');
        Route::delete('/departments/{department}/archive', [AdminController::class, 'archiveDepartment'])->name('departments.archive');
        Route::patch('/departments/{department}/restore', [AdminController::class, 'restoreDepartment'])->withTrashed()->name('departments.restore');
        Route::put('/departments/{department}', [AdminController::class, 'updateDepartment'])->name('departments.update');
        Route::post('/service-points', [AdminController::class, 'storeServicePoint'])->name('service-points.store');
        Route::patch('/service-points/{servicePoint}/toggle', [AdminController::class, 'toggleServicePoint'])->name('service-points.toggle');
        Route::put('/service-points/{servicePoint}', [AdminController::class, 'updateServicePoint'])->name('service-points.update');
        Route::put('/departments/{department}/targets', [AdminController::class, 'updateTarget'])->name('departments.targets');
        Route::post('/staff', [AdminController::class, 'storeStaff'])->name('staff.store');
        Route::put('/staff/{user}', [AdminController::class, 'updateStaff'])->name('staff.update');
        Route::patch('/staff/{user}/toggle', [AdminController::class, 'toggleStaff'])->name('staff.toggle');
        Route::put('/ticket-numbering', [AdminController::class, 'updateTicketNumbering'])->name('ticket-numbering');
        Route::put('/roles/{role}/permissions', [AdminController::class, 'updateRolePermissions'])->name('roles.permissions');
        Route::get('/audit', [AuditController::class, 'index'])->name('audit');
    });

    Route::prefix('departments/{department:slug}')->middleware('department.access')->name('departments.')->group(function () {
        Route::get('/', [QueueController::class, 'index'])->name('workspace');
        Route::get('/state', [QueueController::class, 'state'])->middleware('throttle:120,1')->name('state');
        Route::get('/register', [VisitController::class, 'create'])->name('visits.create');
        Route::post('/register', [VisitController::class, 'store'])->middleware('throttle:30,1')->name('visits.store');
        Route::post('/call-next', [QueueController::class, 'callNext'])->middleware('throttle:30,1')->name('call-next');
        Route::post('/entries/{entry}/action', [QueueController::class, 'action'])->middleware('throttle:60,1')->name('entries.action');
    });

    Route::patch('/patients/{patient}/registration', [VisitController::class, 'completeRegistration'])->name('patients.registration');
    Route::post('/visits/{visit}/complete', [VisitController::class, 'complete'])->name('visits.complete');
    Route::post('/visits/{visit}/cancel', [VisitController::class, 'cancel'])->name('visits.cancel');
});
