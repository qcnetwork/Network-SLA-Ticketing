<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · QCNet Ticketing</title>
    <link rel="icon" href="/icons/logo-mark.png" type="image/png">
    @include('partials.pwa-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-gradient-to-br from-indigo-700 via-indigo-600 to-sky-500 px-4 font-sans antialiased">
    <div class="w-full max-w-md">
        <div class="mb-6 text-center text-white">
            <img src="/icons/ticketing.jpg" alt="QCNet Ticketing" class="mx-auto mb-3 h-28 w-28 rounded-2xl shadow-lg">
            <h1 class="text-2xl font-bold">QCNet Ticketing</h1>
            <p class="text-sm text-indigo-100">Sistem Ticketing CS · Helpdesk · Teknisi</p>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="card space-y-4 p-6 sm:p-8">
            @csrf
            <h2 class="text-lg font-semibold text-slate-800">Masuk ke akun Anda</h2>

            @if ($errors->any())
                <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $errors->first() }}</div>
            @endif

            <div>
                <label for="username" class="label">Username</label>
                <input id="username" name="username" type="text" value="{{ old('username') }}" class="input" required autofocus autocomplete="username">
            </div>
            <div>
                <label for="password" class="label">Password</label>
                <input id="password" name="password" type="password" class="input" required autocomplete="current-password">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-indigo-600"> Ingat saya
            </label>
            <button class="btn-primary w-full py-2.5"><i data-lucide="log-in" class="h-4 w-4"></i>Masuk</button>
        </form>
    </div>
</body>
</html>
