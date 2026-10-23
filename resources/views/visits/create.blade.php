@extends('layouts.app')
@section('title', 'Register patient')
@section('crumb', 'Quick registration')
@section('content')
<section class="page-heading"><div><span class="eyebrow">QUICK ENTRY · {{ strtoupper($department->name) }}</span><h1>Register a visit</h1><p>Issue a queue ticket now. Complete remaining registration details later.</p></div><a class="button button-secondary" href="{{ route('departments.workspace', $department) }}">← Back to queue</a></section>
<div class="registration-grid">
    <section class="panel registration-panel">
        <div class="panel-heading"><div><h2>New patient visit</h2><p>Only the destination is required by this demonstration setup.</p></div><span class="registration-step">01 / 01</span></div>
        <form class="form-stack registration-form" data-register-form action="{{ route('departments.visits.store', $department) }}">
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <label>Patient name <span class="optional-label">Optional</span><input name="display_name" autocomplete="off" maxlength="120" placeholder="Enter a name if available"><small>A temporary private reference is generated when left blank.</small></label>
            <label>Starting department<select name="department_id" class="select-control" disabled><option>{{ $department->name }}</option></select></label>
            <label>Service point<select name="service_point_id" class="select-control"><option value="">Assign when called</option>@foreach($servicePoints as $point)<option value="{{ $point->id }}">{{ $point->name }}</option>@endforeach</select></label>
            @if($errors->any())<div class="form-alert">{{ $errors->first() }}</div>@endif
            <button class="button button-primary button-wide" type="submit" data-submit-button>Issue queue ticket <span>→</span></button>
        </form>
    </section>
    <aside class="ticket-result panel" data-ticket-result hidden aria-live="polite">
        <span class="result-check">✓</span><span class="eyebrow">VISIT CREATED</span><h2>Ticket issued</h2><p class="muted">Direct this patient to {{ $department->name }}.</p><div class="ticket-slip"><small>QUEUE TICKET</small><strong data-issued-ticket>—</strong><span>{{ $department->name }}</span></div><a class="button button-secondary button-wide" href="{{ route('departments.workspace', $department) }}">Return to queue</a>
    </aside>
    <aside class="registration-note"><span class="note-icon">i</span><div><strong>Queue identity stays separate</strong><p>Tickets identify a visit in the workflow. Private patient references are not shown on public displays.</p></div></aside>
</div>
@endsection