@extends('layouts.app')
@section('title', 'Overview')
@section('crumb', 'Overview')
@section('content')
<section class="page-heading">
    <div><span class="eyebrow">OPERATIONS · {{ $from->format('d M Y') }}{{ $from->isSameDay($to) ? '' : ' – '.$to->format('d M Y') }}</span><h1>Queue overview</h1><p>Live patient flow across Merry Begg Clinic.</p></div>
    <form method="GET" class="date-filter"><label for="from">From</label><input id="from" type="date" name="from" value="{{ $from->format('Y-m-d') }}"><label for="to">To</label><input id="to" type="date" name="to" value="{{ $to->format('Y-m-d') }}"><button class="button button-secondary" type="submit">Apply</button></form>
</section>
<section class="metric-grid" aria-label="Today's queue metrics">
    <article class="metric-card metric-featured"><div class="metric-top"><span>Visits created</span><span class="metric-glyph">↗</span></div><strong>{{ number_format($report['visits_created']) }}</strong><small>For selected period</small></article>
    <article class="metric-card"><div class="metric-top"><span>Waiting now</span><span class="metric-glyph green">◷</span></div><strong>{{ number_format($report['waiting_now']) }}</strong><small>Across active departments</small></article>
    <article class="metric-card"><div class="metric-top"><span>In service</span><span class="metric-glyph blue">◎</span></div><strong>{{ number_format($report['serving_now']) }}</strong><small>Currently with staff</small></article>
    <article class="metric-card"><div class="metric-top"><span>Visits completed</span><span class="metric-glyph coral">✓</span></div><strong>{{ number_format($report['visits_completed']) }}</strong><small>For selected period</small></article>
</section>
<div class="content-grid dashboard-grid">
    <section class="panel department-panel">
        <div class="panel-heading"><div><h2>Department flow</h2><p>Current queue state by service area</p></div><span class="live-tag"><i></i> Live</span></div>
        <div class="table-wrap"><table><thead><tr><th>Department</th><th>Waiting</th><th>Serving</th><th>Held / absent</th><th>Completed</th><th>Avg wait / target</th><th>Avg service</th><th></th></tr></thead><tbody>
        @forelse($report['departments'] as $stats)
            @php($department = $stats['department'])
            <tr><td><span class="department-cell"><span class="department-dot dot-{{ $loop->index % 5 }}"></span><strong>{{ $department->name }}</strong></span></td><td><span class="count-pill {{ $department->waiting_count ? 'count-waiting' : '' }}">{{ $department->waiting_count }}</span></td><td>{{ $department->serving_count }}</td><td>{{ $department->held_count }}</td><td>{{ $stats['completed_count'] }}</td><td>{{ $stats['average_wait_minutes'] === null ? '—' : $stats['average_wait_minutes'].' min' }} <small class="target-value">{{ $stats['target_wait_minutes'] ? '/ '.$stats['target_wait_minutes'].' target' : '/ no target' }}</small></td><td>{{ $stats['average_service_minutes'] === null ? '—' : $stats['average_service_minutes'].' min' }}</td><td><a class="table-link" href="{{ route('departments.workspace', $department) }}">Open queue <span>→</span></a></td></tr>
        @empty<tr><td colspan="8" class="empty-cell">No active departments configured.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    <aside class="side-stack">
        <section class="panel wait-panel"><div class="panel-heading"><div><h2>Visit timings</h2><p>Measured from recorded timestamps</p></div></div><div class="wait-value">{{ $report['average_wait_minutes'] === null ? '—' : $report['average_wait_minutes'] }}<span>{{ $report['average_wait_minutes'] === null ? '' : 'min wait' }}</span></div><div class="wait-foot">{{ $report['average_wait_minutes'] === null ? 'No completed wait samples for this period' : 'Average time to first call' }}</div><div class="wait-secondary">Service <strong>{{ $report['average_service_minutes'] === null ? '—' : $report['average_service_minutes'].' min' }}</strong><span>Visit duration</span><strong>{{ $report['average_visit_minutes'] === null ? '—' : $report['average_visit_minutes'].' min' }}</strong></div></section>
        <section class="panel activity-panel"><div class="panel-heading"><div><h2>Recent activity</h2><p>Recorded queue events</p></div></div><ul class="activity-list">
            @forelse($recentEvents as $event)<li><span class="activity-mark"></span><div><strong>{{ str($event->event_type)->replace('_', ' ')->title() }}</strong><small>{{ $event->visit?->ticket_number ?? 'Visit' }} · {{ $event->department?->name ?? 'Clinic' }}</small></div><time>{{ $event->created_at->format('H:i') }}</time></li>
            @empty<li class="empty-activity">No queue activity recorded yet.</li>@endforelse
        </ul></section>
    </aside>
</div>
<section class="panel throughput-panel"><div class="panel-heading"><div><h2>Visit volume by hour</h2><p>Visits created during the selected period</p></div><span class="data-source-tag">Database records</span></div><div class="hourly-chart" role="img" aria-label="Visits created by hour">
    @foreach($report['hourly_visits'] as $hour => $count)<div class="hour-column" title="{{ $hour }}:00 · {{ $count }} visits"><span class="hour-bar" style="--bar-height: {{ $count ? max(5, (int) round($count / $report['peak_hour_count'] * 100)) : 0 }}%"></span><small>{{ $hour % 3 === 0 ? sprintf('%02d', $hour) : '' }}</small></div>@endforeach
</div><div class="hour-axis"><span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>23:00</span></div></section>
<p class="data-note"><span class="data-note-icon">i</span> Live counts reflect current queue state. Historical measures use stored visit and queue timestamps.</p>
@endsection