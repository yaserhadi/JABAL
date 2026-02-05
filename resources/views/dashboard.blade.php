<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,600&display=swap" rel="stylesheet" />
    <style>
        body { font-family: Figtree, ui-sans-serif, system-ui, sans-serif; margin: 0; min-height: 100vh; background: #f3f4f6; }
        .header { background: white; padding: 1rem 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center; }
        .main { padding: 2rem; max-width: 64rem; margin: 0 auto; }
        h1 { margin: 0 0 1rem; }
        form { display: inline; }
        button { padding: 0.5rem 1rem; background: #6b7280; color: white; border: none; border-radius: 0.375rem; cursor: pointer; }
        button:hover { background: #4b5563; }
    </style>
</head>
<body>
    <header class="header">
        <span>Dashboard — {{ auth()->user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Log out</button>
        </form>
    </header>
    <main class="main">
        <h1>Dashboard</h1>
        <p>You are logged in. Tenant context: {{ optional(\App\Support\Context\TenantContext::getInstance()->get())->name ?? 'None' }}</p>
    </main>
</body>
</html>
