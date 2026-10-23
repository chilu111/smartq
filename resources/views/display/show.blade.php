<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $department->name }} · Merry Begg Clinic Queue</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="display-body" data-display data-display-slug="{{ $department->slug }}" data-display-endpoint="{{ route('display.state', $department) }}" data-audio-endpoint="{{ route('display.audio', $department) }}">
    <header class="display-header"><div class="display-brand"><span class="brand-mark">S</span><span><strong>Merry Begg Clinic</strong><small>SMARTQUEUE · PATIENT FLOW</small></span></div><div class="display-department"><span class="department-dot dot-{{ $department->id % 5 }}"></span>{{ $department->name }}</div><div class="display-clock"><strong data-clock>--:--</strong><small data-date>---</small></div></header>
    <main class="display-main"><section class="display-call"><div class="display-overline"><span class="live-indicator"><i></i> NOW CALLING</span><span class="display-connection" data-display-connection><i></i> Connecting</span></div><div class="display-ticket" data-display-current>—</div><div class="display-destination" data-display-point>Welcome</div><div class="display-caption">Please proceed to the indicated service point</div></section><aside class="display-recent"><div class="recent-heading"><span class="eyebrow">RECENT CALLS</span><span class="recent-count" data-recent-count>0</span></div><div class="recent-list" data-recent-list><div class="recent-empty">Tickets called here will appear on this screen.</div></div><div class="display-audio"><button type="button" class="button button-display" data-enable-audio>Enable voice announcements</button></div></aside></main>
    <footer class="display-footer"><span>Thank you for your patience</span><span class="display-updated" data-display-updated>Waiting for live data</span><span>Merry Begg Clinic · Queue display</span></footer>
</body>
</html>