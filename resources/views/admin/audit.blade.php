@extends('layouts.app')
@section('title', 'Audit history')
@section('crumb', 'Audit history')
@section('content')
<section class="page-heading"><div><span class="eyebrow">GOVERNANCE · READ ONLY</span><h1>Audit history</h1><p>Administrative and queue changes recorded by the application.</p></div><a class="button button-secondary" href="{{ route('admin.index') }}">← Configuration</a></section>
<section class="panel"><div class="panel-heading"><div><h2>Recorded actions</h2><p>Newest actions first · {{ $logs->total() }} total records</p></div></div><div class="table-wrap"><table><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Record</th><th>Reference</th></tr></thead><tbody>
@forelse($logs as $log)<tr><td>{{ $log->created_at->format('d M Y · H:i:s') }}</td><td>{{ $log->actor?->name ?? 'System' }}</td><td><span class="audit-action">{{ str($log->action)->replace(['.', '_'], ' ')->title() }}</span></td><td>{{ class_basename($log->subject_type ?? 'System') }}</td><td>{{ $log->subject_id ?? '—' }}</td></tr>
@empty<tr><td colspan="5" class="empty-cell">No audit records have been written.</td></tr>@endforelse
</tbody></table></div><div class="pagination-wrap">{{ $logs->links() }}</div></section>
@endsection