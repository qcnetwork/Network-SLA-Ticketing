<x-layouts.app :title="$user->exists ? 'Ubah Pengguna' : 'Tambah Pengguna'">
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('users.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
            <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke daftar pengguna
        </a>

        <form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="card space-y-4 p-6">
            @csrf
            @if ($user->exists) @method('PUT') @endif

            <h1 class="text-xl font-bold text-slate-900">{{ $user->exists ? 'Ubah Pengguna' : 'Tambah Pengguna' }}</h1>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="username">Username</label>
                    @if ($user->exists)
                        <input id="username" value="{{ $user->username }}" class="input bg-slate-100" disabled>
                    @else
                        <input id="username" name="username" value="{{ old('username') }}" class="input" required maxlength="50" autocomplete="off">
                        <x-field-error name="username" />
                    @endif
                </div>
                <div>
                    <label class="label" for="name">Nama Lengkap</label>
                    <input id="name" name="name" value="{{ old('name', $user->name) }}" class="input" required maxlength="150">
                    <x-field-error name="name" />
                </div>
                <div>
                    <label class="label" for="role">Peran</label>
                    <select id="role" name="role" class="input" required>
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}" @selected(old('role', $user->role?->value ?? 'CS') === $role->value)>{{ $role->label() }}</option>
                        @endforeach
                    </select>
                    <x-field-error name="role" />
                </div>
                <div>
                    <label class="label" for="phone">No. HP</label>
                    <input id="phone" name="phone" value="{{ old('phone', $user->phone) }}" class="input" maxlength="30" inputmode="tel">
                    <x-field-error name="phone" />
                </div>
                <div>
                    <label class="label" for="email">Email <span class="font-normal text-slate-400">(opsional)</span></label>
                    <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" class="input" maxlength="150">
                    <x-field-error name="email" />
                </div>
                <div>
                    <label class="label" for="password">{{ $user->exists ? 'Password Baru' : 'Password' }}</label>
                    <input id="password" name="password" type="password" class="input" @required(! $user->exists) minlength="8" autocomplete="new-password"
                           placeholder="{{ $user->exists ? 'Kosongkan jika tidak diganti' : 'Minimal 8 karakter, huruf dan angka' }}">
                    <x-field-error name="password" />
                </div>
                @if ($user->exists)
                    <div class="sm:col-span-2">
                        <input type="hidden" name="active" value="0">
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" name="active" value="1" @checked(old('active', $user->active)) class="rounded border-slate-300 text-indigo-600">
                            Akun aktif (bisa login)
                        </label>
                    </div>
                @endif
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <a href="{{ route('users.index') }}" class="btn-secondary">Batal</a>
                <button class="btn-primary"><i data-lucide="save" class="h-4 w-4"></i>Simpan</button>
            </div>
        </form>
    </div>
</x-layouts.app>
