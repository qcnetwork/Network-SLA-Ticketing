<x-layouts.app title="Profil Saya">
    <div class="mx-auto max-w-xl space-y-6">
        <section class="card p-6">
            <h1 class="mb-4 text-xl font-bold text-slate-900">Profil Saya</h1>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div><dt class="text-xs text-slate-500">Nama</dt><dd class="font-medium">{{ $user->name }}</dd></div>
                <div><dt class="text-xs text-slate-500">Username</dt><dd class="font-mono">{{ $user->username }}</dd></div>
                <div><dt class="text-xs text-slate-500">Peran</dt><dd class="font-medium">{{ $user->role->label() }}</dd></div>
                <div><dt class="text-xs text-slate-500">No. HP</dt><dd class="font-medium">{{ $user->phone ?: '-' }}</dd></div>
            </dl>
        </section>

        <form method="POST" action="{{ route('profile.password') }}" class="card space-y-4 p-6">
            @csrf
            @method('PUT')
            <h2 class="font-semibold">Ganti Password</h2>
            <div>
                <label class="label" for="current_password">Password Saat Ini</label>
                <input id="current_password" name="current_password" type="password" class="input" required autocomplete="current-password">
                <x-field-error name="current_password" />
            </div>
            <div>
                <label class="label" for="password">Password Baru</label>
                <input id="password" name="password" type="password" class="input" required minlength="6" autocomplete="new-password">
                <x-field-error name="password" />
            </div>
            <div>
                <label class="label" for="password_confirmation">Ulangi Password Baru</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="input" required autocomplete="new-password">
            </div>
            <div class="flex justify-end">
                <button class="btn-primary"><i data-lucide="key-round" class="h-4 w-4"></i>Simpan Password</button>
            </div>
        </form>
    </div>
</x-layouts.app>
