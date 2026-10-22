<x-layouts.app :title="$page['title']">
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <a href="{{ route('settings.edit') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke Pengaturan
            </a>
            <h1 class="text-2xl font-bold text-slate-900">{{ $page['title'] }}</h1>
            <p class="text-sm text-slate-500">{{ $page['description'] }}</p>
        </div>

        <form method="POST" action="{{ route($page['route'].'.store') }}" class="card flex flex-col gap-3 p-5 sm:flex-row sm:items-end">
            @csrf
            <div class="flex-1">
                <label class="label" for="new_option">{{ ucfirst($page['noun']) }} baru</label>
                <input id="new_option" name="name" value="{{ old('name') }}" class="input" required maxlength="100" placeholder="{{ $page['placeholder'] }}">
            </div>
            <button class="btn-primary"><i data-lucide="plus" class="h-4 w-4"></i>Tambah</button>
        </form>

        <div class="card divide-y divide-slate-100">
            @forelse ($options as $option)
                @php($used = $counts[$option->name] ?? 0)
                <div class="p-4" x-data="{ editing: false }">
                    <div class="flex flex-wrap items-center justify-between gap-3" x-show="! editing">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span @class(['font-medium', 'text-slate-800' => $option->active, 'text-slate-400 line-through' => ! $option->active])>{{ $option->name }}</span>
                                @unless ($option->active)
                                    <span class="badge border-slate-300 bg-slate-100 text-slate-500">Nonaktif</span>
                                @endunless
                            </div>
                            <div class="text-xs text-slate-500">Urutan {{ $option->sort_order }} · dipakai {{ $used }} tiket</div>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" @click="editing = true" class="btn-secondary px-3 py-1.5 text-xs"><i data-lucide="pencil" class="h-3.5 w-3.5"></i>Ubah</button>
                            @if ($used === 0)
                                <form method="POST" action="{{ route($page['route'].'.destroy', $option->id) }}" data-confirm="{{ 'Hapus '.$page['noun'].' '.$option->name.'?' }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn-danger px-3 py-1.5 text-xs"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i>Hapus</button>
                                </form>
                            @endif
                        </div>
                    </div>

                    <form method="POST" action="{{ route($page['route'].'.update', $option->id) }}" x-show="editing" x-cloak class="space-y-3">
                        @csrf
                        @method('PUT')
                        <div class="grid gap-3 sm:grid-cols-[1fr_7rem]">
                            <div>
                                <label class="label" for="name_{{ $option->id }}">Nama {{ $page['noun'] }}</label>
                                <input id="name_{{ $option->id }}" name="name" value="{{ $option->name }}" class="input" required maxlength="100">
                            </div>
                            <div>
                                <label class="label" for="sort_{{ $option->id }}">Urutan</label>
                                <input id="sort_{{ $option->id }}" name="sort_order" type="number" min="0" value="{{ $option->sort_order }}" class="input" required>
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="hidden" name="active" value="0">
                            <input type="checkbox" name="active" value="1" @checked($option->active) class="rounded border-slate-300 text-indigo-600"> Aktif (muncul di pilihan)
                        </label>
                        @if ($used > 0)
                            <p class="text-xs text-slate-500">Mengganti nama juga mengganti {{ $page['noun'] }} di {{ $used }} tiket yang memakainya.</p>
                        @endif
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="editing = false" class="btn-secondary">Batal</button>
                            <button class="btn-primary"><i data-lucide="save" class="h-4 w-4"></i>Simpan</button>
                        </div>
                    </form>
                </div>
            @empty
                <p class="p-6 text-center text-sm text-slate-500">Belum ada {{ $page['noun'] }}.</p>
            @endforelse
        </div>
    </div>
</x-layouts.app>
