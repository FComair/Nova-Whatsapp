<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('team.invitation.title') }}</title>
    <style>
        :root { --bg: #f6f7f9; --card: #fff; --text: #14181f; --muted: #5b6573; --line: #d6dbe3; --accent: #0b6e4f; --accent-text: #fff; --danger: #b42318; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f1318; --card: #171c23; --text: #eef1f5; --muted: #a2adbb; --line: #2b3440; --accent: #3fbf94; --accent-text: #08130e; --danger: #ff8a80; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, 'Segoe UI', sans-serif; }
        main { width: 100%; max-width: 28rem; background: var(--card); border: 1px solid var(--line); border-radius: .75rem; padding: 1.5rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
        p { margin: 0 0 1rem; color: var(--muted); }
        label { display: block; font-weight: 600; margin-bottom: .25rem; }
        input { width: 100%; padding: .6rem .7rem; border: 1px solid var(--line); border-radius: .5rem; background: transparent; color: inherit; font: inherit; margin-bottom: 1rem; }
        input:focus-visible, button:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        button { width: 100%; padding: .7rem 1rem; border: 0; border-radius: .5rem; background: var(--accent); color: var(--accent-text); font: inherit; font-weight: 600; cursor: pointer; }
        .error { color: var(--danger); margin: -.5rem 0 1rem; font-size: .9rem; }
        ul.rules { margin: 0 0 1rem; padding-left: 1.2rem; color: var(--muted); font-size: .9rem; }
    </style>
</head>
<body>
<main>
    @if ($agent === null)
        <h1>{{ __('team.invitation.invalid_title') }}</h1>
        <p role="alert">{{ __('team.invitation.invalid') }}</p>
    @else
        <h1>{{ __('team.invitation.heading', ['name' => $agent->getFilamentName()]) }}</h1>
        <p>{{ __('team.invitation.intro') }}</p>
        <form method="post" action="{{ route('admin.invitation.store', ['admin' => $admin, 'token' => $token]) }}" novalidate>
            @csrf
            <label for="password">{{ __('team.invitation.password') }}</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required minlength="16" aria-describedby="rules{{ $errors->has('password') ? ' password-error' : '' }}" @if ($errors->has('password')) aria-invalid="true" @endif>
            @error('password')<p class="error" id="password-error" role="alert">{{ $message }}</p>@enderror
            <ul class="rules" id="rules"><li>{{ __('team.invitation.rules') }}</li></ul>
            <label for="password_confirmation">{{ __('team.invitation.confirm') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            <button type="submit">{{ __('team.invitation.submit') }}</button>
        </form>
    @endif
</main>
</body>
</html>
