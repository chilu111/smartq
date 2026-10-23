<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'SmartQueue') · Merry Begg Clinic</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body">
    <aside class="sidebar">
        <a class="brand" href="{{ route('dashboard') }}">
            <span class="brand-mark" aria-hidden="true">S</span>
            <span><strong>SmartQueue</strong><small>Merry Begg Clinic</small></span>
        </a>
        <div class="sidebar-label">WORKSPACE</div>
        @if(auth()->user()->hasRole('system-administrator', 'clinic-manager'))
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'is-active' : '' }}" href="{{ route('dashboard') }}" aria-label="Overview" title="Overview"><span class="nav-icon">▦</span> Overview</a>
        @endif
        <div class="sidebar-label nav-section-label">DEPARTMENTS</div>
        @foreach(auth()->user()->hasRole('system-administrator', 'clinic-manager') ? \App\Models\Department::where('is_active', true)->orderBy('name')->get() : auth()->user()->departments()->where('is_active', true)->orderBy('name')->get() as $navDepartment)
            <a class="nav-link {{ request()->route('department')?->id === $navDepartment->id ? 'is-active' : '' }}" href="{{ route('departments.workspace', $navDepartment) }}" aria-label="{{ $navDepartment->name }}" title="{{ $navDepartment->name }}"><span class="department-dot dot-{{ $loop->index % 5 }}"></span>{{ $navDepartment->name }}</a>
        @endforeach
        @if(auth()->user()->hasRole('system-administrator', 'clinic-manager'))
            <div class="sidebar-label nav-section-label">CONTROL</div>
            <a class="nav-link {{ request()->routeIs('admin.*') ? 'is-active' : '' }}" href="{{ route('admin.index') }}" aria-label="Configuration" title="Configuration"><span class="nav-icon">⚙</span> Configuration</a>
            <a class="nav-link {{ request()->routeIs('admin.audit') ? 'is-active' : '' }}" href="{{ route('admin.audit') }}" aria-label="Audit history" title="Audit history"><span class="nav-icon">≡</span> Audit history</a>
        @endif
        <div class="sidebar-foot">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
            <span class="account-name"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->roles()->value('name') }}</small></span>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="icon-button" type="submit" aria-label="Sign out" title="Sign out">↗</button></form>
        </div>
    </aside>
    <main class="main-shell">
        <header class="topbar">
            <div class="mobile-brand"><span class="brand-mark">S</span><strong>SmartQueue</strong></div>
            <div class="breadcrumb"><span>Merry Begg Clinic</span><span class="crumb-separator">/</span><strong>@yield('crumb', 'Overview')</strong></div>
            <div class="topbar-meta"><span class="live-indicator"><i></i> System online</span><span class="topbar-date">{{ now()->format('D, d M Y') }}</span></div>
        </header>
        <div class="page-content">@yield('content')</div>
    </main>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>