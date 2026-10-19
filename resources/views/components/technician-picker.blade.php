@props(['technicians', 'selected' => []])
@php
    $initial = collect(old('technician_ids', $selected))->filter()->map(fn ($id) => (string) $id)->values()->all() ?: [''];
@endphp
<div>
    @if ($technicians->isEmpty())
        <p class="text-xs text-rose-600">Belum ada akun teknisi aktif. Minta supervisor menambahkannya.</p>
    @else
        <div x-data="{
                rows: @js($initial).map((id, key) => ({ key, id })),
                nextKey: {{ count($initial) }},
                max: {{ $technicians->count() }},
                add() {
                    this.rows.push({ key: this.nextKey++, id: '' });
                    this.$nextTick(() => window.renderIcons?.());
                },
                remove(row) {
                    this.rows = this.rows.filter((other) => other !== row);
                },
                isTaken(id, row) {
                    return this.rows.some((other) => other !== row && other.id === id);
                },
            }" x-init="$nextTick(() => window.renderIcons?.())" class="space-y-2">
            <template x-for="(row, index) in rows" :key="row.key">
                <div class="flex items-center gap-2">
                    <select name="technician_ids[]" x-model="row.id" class="input flex-1" :required="index === 0">
                        <option value="">Pilih teknisi</option>
                        @foreach ($technicians as $technician)
                            <option value="{{ $technician->id }}" :disabled="isTaken('{{ $technician->id }}', row)">{{ $technician->name }}</option>
                        @endforeach
                    </select>
                    <button type="button" x-show="rows.length > 1" @click="remove(row)" class="btn-secondary shrink-0 px-2.5" title="Hapus teknisi">
                        <i data-lucide="x" class="h-4 w-4"></i>
                    </button>
                    <button type="button" x-show="index === rows.length - 1 && rows.length < max" @click="add()" class="btn-secondary shrink-0 px-2.5" title="Tambah teknisi">
                        <i data-lucide="plus" class="h-4 w-4"></i>
                    </button>
                </div>
            </template>
        </div>
    @endif
    <x-field-error name="technician_ids" />
</div>
