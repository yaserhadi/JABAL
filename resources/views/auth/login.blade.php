<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,600&display=swap" rel="stylesheet" />
    <style>
        body { font-family: Figtree, ui-sans-serif, system-ui, sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f3f4f6; }
        .card { background: white; padding: 2rem; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); width: 100%; max-width: 24rem; }
        h1 { margin: 0 0 1.5rem; font-size: 1.25rem; }
        label { display: block; margin-bottom: 0.25rem; font-size: 0.875rem; }
        input { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 0.375rem; margin-bottom: 1rem; box-sizing: border-box; }
        .error { color: #dc2626; font-size: 0.875rem; margin-bottom: 0.5rem; }
        button { width: 100%; padding: 0.5rem 1rem; background: #2563eb; color: white; border: none; border-radius: 0.375rem; cursor: pointer; font-size: 1rem; }
        button:hover { background: #1d4ed8; }
        .link { margin-top: 1rem; text-align: center; }
        .link a { color: #2563eb; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Log in</h1>
        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif
        <form method="POST" action="{{ route('login') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
            <label><input type="checkbox" name="remember"> Remember me</label>
            <button type="submit">Log in</button>
        </form>
        <div class="link"><a href="{{ route('register') }}">Register</a></div>
    </div>
</body>
</html>
