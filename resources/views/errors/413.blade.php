<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>File Terlalu Besar · QCNet Ticketing</title>
    <link rel="icon" href="/icons/logo-mark.png" type="image/png">
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-100 px-4 font-sans antialiased">
    <div class="card w-full max-w-md space-y-4 p-6 text-center">
        <img src="/icons/logo-mark.png" alt="" class="mx-auto h-14 w-14 rounded-xl">
        <h1 class="text-lg font-bold text-slate-800">File terlalu besar</h1>
        <p class="text-sm text-slate-600">
            Total lampiran yang dikirim melebihi batas server ({{ ini_get('post_max_size') }}).
            Kirim lebih sedikit atau lebih kecil, atau minta admin menaikkan <code>post_max_size</code> di php.ini.
        </p>
        <button type="button" onclick="history.back()" class="btn btn-primary w-full">Kembali</button>
    </div>
</body>
</html>
