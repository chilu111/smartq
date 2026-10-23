<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · SmartQueue</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
    <main class="login-frame">
        <section class="login-intro">
            <div class="login-brand"><span class="brand-mark">S</span><strong>SmartQueue</strong></div>
            <div class="login-message"><span class="eyebrow">MERRY BEGG CLINIC</span><h1>Care moves<br>with clarity.</h1><p>One connected view of the patient journey, from first ticket to final service.</p></div>
            <div class="login-footer"><span class="status-pip"></span> Queue operations workspace <span>·</span> Nexware Systems</div>
        </section>
        <section class="login-form-wrap">
            <div class="login-form-inner">
                <span class="eyebrow">STAFF ACCESS</span><h2>Welcome back</h2><p class="muted">Sign in to continue to your workspace.</p>
                <form method="POST" action="{{ route('login.store') }}" class="form-stack">
                    @csrf
                    <label>Email address<input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus placeholder="name@clinic.org"></label>
                    @error('email')<span class="field-error">{{ $message }}</span>@enderror
                    <label>Password<input type="password" name="password" autocomplete="current-password" required placeholder="Enter your password"></label>
                    <label class="checkbox-label"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
                    <button class="button button-primary button-wide" type="submit">Sign in <span aria-hidden="true">→</span></button>
                </form>
                @if(app()->environment(['local', 'testing']))<p class="demo-note">Local demo account details are listed in the setup guide.</p>@endif
            </div>
        </section>
    </main>
</body>
</html>