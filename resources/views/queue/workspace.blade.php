@extends('layouts.app')
@section('title', $department->name.' queue')
@section('crumb', $department->name)
@section('content')
<section class="page-heading staff-heading">
    <div><span class="eyebrow">STAFF WORKSPACE · <span class="live-tag"><i></i> LIVE QUEUE</span></span><h1>{{ $department->name }}</h1><p>Manage the current queue and keep each visit moving.</p></div>
    @if(auth()->user()->hasPermission('visits.register'))<a class="button button-primary" href="{{ route('departments.visits.create', $department) }}"><span>＋</span> Register patient</a>@endif
</section>
<div class="staff-toolbar">
    <label for="service-point">Service point</label>
    <select id="service-point" class="select-control" data-service-point>
        @foreach($servicePoints as $point)<option value="{{ $point->id }}">{{ $point->name }}</option>@endforeach
    </select>
    <a class="quiet-link" href="{{ route('display.show', $department) }}" target="_blank" rel="noopener">↗ Open public display</a>
    <span class="refresh-label" data-queue-status><i></i> Updating</span>
</div>
<section class="staff-focus-grid">
    <article class="current-ticket panel">
        <div class="current-top"><span class="eyebrow">NOW CALLING</span><span class="serving-badge" data-current-status>No active ticket</span></div>
        <div class="current-number" data-current-ticket>—</div>
        <div class="current-point" data-current-point>Select a service point to begin</div>
        <div class="current-actions"><button class="button button-primary call-button" type="button" data-call-next {{ $servicePoints->isEmpty() ? 'disabled' : '' }}><span aria-hidden="true">↗</span> CALL NEXT</button><span class="key-hint">Next eligible patient</span></div>
    </article>
    <div class="stat-stack">
        <article class="mini-stat"><span class="stat-icon stat-icon-wait">◷</span><div><small>Waiting</small><strong data-count="waiting">—</strong></div><span class="stat-tail">in queue</span></article>
        <article class="mini-stat"><span class="stat-icon stat-icon-service">◎</span><div><small>In service</small><strong data-count="serving">—</strong></div><span class="stat-tail">right now</span></article>
        <article class="mini-stat"><span class="stat-icon stat-icon-done">✓</span><div><small>Completed</small><strong data-count="completed">—</strong></div><span class="stat-tail">today</span></article>
        <article class="mini-stat"><span class="stat-icon stat-icon-hold">Ⅱ</span><div><small>Needs attention</small><strong data-count="held">—</strong></div><span class="stat-tail">held / absent</span></article>
    </div>
</section>
<section class="panel queue-panel">
    <div class="panel-heading queue-panel-heading"><div><h2>Queue list</h2><p>Ordered by approved priority, then server-recorded arrival time</p></div><span class="queue-total" data-queue-total>0 entries</span></div>
    <div class="table-wrap"><table class="queue-table"><thead><tr><th>Ticket</th><th>State</th><th>Waiting</th><th>Priority</th><th class="action-heading">Actions</th></tr></thead><tbody data-queue-rows><tr><td colspan="5" class="empty-cell">Connecting to the queue…</td></tr></tbody></table></div>
</section>
<section class="panel ready-close-panel" data-ready-close-panel hidden>
    <div class="panel-heading"><div><h2>Visits ready to close</h2><p>All queue stages have ended. Complete or cancel the visit record.</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Ticket</th><th>Visit state</th><th class="action-heading">Actions</th></tr></thead><tbody data-ready-close-rows></tbody></table></div>
</section>
<dialog class="modal" data-transfer-dialog>
    <form method="dialog" class="modal-close-form"><button class="icon-button" aria-label="Close transfer dialog">×</button></form>
    <span class="eyebrow">PATIENT FLOW</span><h2>Transfer ticket <span data-transfer-ticket></span></h2><p class="muted">The visit ticket stays the same. A new queue stage will be created at the destination.</p>
    <label>Destination department<select class="select-control" data-transfer-department required><option value="">Choose a department</option>@foreach(\App\Models\Department::where('is_active', true)->whereKeyNot($department->id)->orderBy('name')->get() as $destination)<option value="{{ $destination->id }}">{{ $destination->name }}</option>@endforeach</select></label>
    <label class="move-point-field" data-move-point-field hidden>Service point<select class="select-control" data-move-point required><option value="">Choose a service point</option>@foreach($servicePoints as $point)<option value="{{ $point->id }}">{{ $point->name }}</option>@endforeach</select></label>
    <div class="modal-actions"><button class="button button-secondary" type="button" data-close-transfer>Cancel</button><button class="button button-primary" type="button" data-confirm-transfer>Transfer ticket</button></div>
</dialog>
<div data-queue-endpoint="{{ route('departments.state', $department) }}" data-call-endpoint="{{ route('departments.call-next', $department) }}" data-action-template="{{ route('departments.entries.action', [$department, 'ENTRY_ID']) }}" data-complete-visit-template="{{ route('visits.complete', 'VISIT_ID') }}" data-cancel-visit-template="{{ route('visits.cancel', 'VISIT_ID') }}" hidden></div>
@endsection