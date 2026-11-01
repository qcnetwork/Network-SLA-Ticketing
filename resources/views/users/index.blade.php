<x-layouts.app title="Pengguna">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Manajemen Pengguna</h1>
            <p class="text-sm text-slate-500">Akun CS, Helpdesk, Teknisi, dan Supervisor.</p>
        </div>
        <a href="{{ route('users.create') }}" class="btn-primary"><i data-lucide="user-plus" class="h-4 w-4"></i>Tambah Pengguna</a>
    </div>

    {{-- Phones get stacked cards; the table needs more width than a phone has. --}}
    <div class="space-y-3 md:hidden">
        @foreach ($users as $user)
            <div class="card p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-semibold text-slate-800">
                            {{ $user->name }}
                            @if ($user->is(auth()->user()))<span class="ml-1 text-xs font-normal text-indigo-600">(Anda)</span>@endif
                        </div>
                        <div class="text-xs text-slate-500"><span class="font-mono">{{ $user->username }}</span> · {{ $user->role->label() }}</div>
                        <div class="text-xs text-slate-500">{{ $user->phone ?: '-' }}</div>
                    </div>
                    @if ($user->active)
                        <span class="badge border-emerald-300 bg-emerald-50 text-emerald-700">Aktif</span>
                    @else
                        <span class="badge border-slate-300 bg-slate-100 text-slate-500">Nonaktif</span>
                    @endif
                </div>
                <div class="mt-3 flex gap-2">
                    @unless ($user->isPrimarySupervisor() && ! auth()->user()->isPrimarySupervisor())
                        <a href="{{ route('users.edit', $user) }}" class="btn-secondary flex-1 py-1.5 text-xs"><i data-lucide="pencil" class="h-3.5 w-3.5"></i>Ubah</a>
                    @endunless
                    @unless ($user->isPrimarySupervisor() || $user->is(auth()->user()))
                        <form method="POST" action="{{ route('users.destroy', $user) }}" class="flex-1" data-confirm="{{ 'Hapus pengguna '.$user->name.'?' }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn-danger w-full py-1.5 text-xs"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i>Hapus</button>
                        </form>
                    @endunless
                </div>
            </div>
        @endforeach
    </div>

    <div class="card hidden overflow-x-auto md:block">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                <tr>
                    <th class="px-4 py-3">Nama</th>
                    <th class="px-4 py-3">Username</th>
                    <th class="px-4 py-3">Peran</th>
                    <th class="px-4 py-3">No. HP</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($users as $user)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium">
                            {{ $user->name }}
                            @if ($user->is(auth()->user()))<span class="ml-1 text-xs text-indigo-600">(Anda)</span>@endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $user->username }}</td>
                        <td class="px-4 py-3">{{ $user->role->label() }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $user->phone ?: '-' }}</td>
                        <td class="px-4 py-3">
                            @if ($user->active)
                                <span class="badge border-emerald-300 bg-emerald-50 text-emerald-700">Aktif</span>
                            @else
                                <span class="badge border-slate-300 bg-slate-100 text-slate-500">Nonaktif</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                @unless ($user->isPrimarySupervisor() && ! auth()->user()->isPrimarySupervisor())
                                    <a href="{{ route('users.edit', $user) }}" class="btn-secondary px-3 py-1.5 text-xs"><i data-lucide="pencil" class="h-3.5 w-3.5"></i>Ubah</a>
                                @endunless
                                @unless ($user->isPrimarySupervisor() || $user->is(auth()->user()))
                                    <form method="POST" action="{{ route('users.destroy', $user) }}" data-confirm="{{ 'Hapus pengguna '.$user->name.'?' }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn-danger px-3 py-1.5 text-xs"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i>Hapus</button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.app>
