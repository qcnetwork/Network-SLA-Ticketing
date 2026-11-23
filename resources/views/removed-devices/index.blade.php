@use('App\Enums\RemovedDeviceStatus')
@use('App\Models\RemovedDevice')
<x-layouts.app title="Alat Cabutan">
    @include('warehouses.partials.nav', ['back' => $isTechnician ? null : ['Gudang', route('warehouses.index')]])
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-amber-500 text-white shadow-sm"><i data-lucide="unplug" class="h-6 w-6"></i></span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">{{ $isTechnician ? 'Alat Cabutan Saya' : 'Laporan Alat Cabutan' }}</h1>
                <p class="text-sm text-slate-500">Alat yang dicabut dari pelanggan saat perbaikan. Wajib diserahkan ke Gudang paling lambat {{ RemovedDevice::DAYS_TO_RETURN }} hari, lalu dicek: layak pakai masuk stok lagi, rusak tidak.</p>
            </div>
        </div>
        <a href="{{ route('removed-devices.export', request()->query()) }}" class="btn-secondary shrink-0"><i data-lucide="file-spreadsheet" class="h-4 w-4"></i>Export Excel</a>
    </div>

    @php($link = fn (array $query) => route('removed-devices.index', $query + request()->except(['status', 'page'])))
    @include('warehouses.partials.summary', ['tiles' => [
        ['Dibawa Teknisi', $totals['carried'], 'hand', $totals['carried'] ? 'amber' : 'slate', 'belum diserahkan', $link(['status' => RemovedDeviceStatus::DIBAWA->value])],
        ['Terlambat', $totals['overdue'], 'alert-triangle', $totals['overdue'] ? 'rose' : 'slate', 'lewat '.RemovedDevice::DAYS_TO_RETURN.' hari', $link(['status' => 'terlambat'])],
        ['Menunggu Cek', $totals['waiting'], 'hourglass', $totals['waiting'] ? 'sky' : 'slate', 'sudah di gudang', $link(['status' => RemovedDeviceStatus::MENUNGGU_CEK->value])],
        ['Layak Pakai', $totals['fit'], 'check-circle-2', 'emerald', 'periode ini', $link(['status' => RemovedDeviceStatus::LAYAK->value])],
        ['Rusak', $totals['broken'], 'x-circle', 'slate', 'periode ini', $link(['status' => RemovedDeviceStatus::RUSAK->value])],
    ]])

    <form method="GET" class="card mb-6 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
        <input type="search" name="q" value="{{ $filters['q'] }}" class="input lg:col-span-2" placeholder="Cari alat, SN, pelanggan, no. tiket">
        <select name="status" class="input">
            <option value="">Semua status</option>
            <option value="terlambat" @selected($filters['status'] === 'terlambat')>Terlambat diserahkan</option>
            @foreach (RemovedDeviceStatus::cases() as $status)
                <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
            @endforeach
        </select>
        @unless ($isTechnician)
            <select name="teknisi" class="input">
                <option value="">Semua teknisi</option>
                @foreach ($technicians as $technician)
                    <option value="{{ $technician->id }}" @selected($filters['technician'] === (string) $technician->id)>{{ $technician->name }}</option>
                @endforeach
            </select>
        @endunless
        <div class="flex gap-2 {{ $isTechnician ? 'lg:col-span-2' : '' }}">
            <input type="date" name="dari" value="{{ $filters['from']->toDateString() }}" class="input" title="Dari tanggal">
            <input type="date" name="sampai" value="{{ $filters['to']->toDateString() }}" class="input" title="Sampai tanggal">
        </div>
        <button class="btn-primary"><i data-lucide="filter" class="h-4 w-4"></i>Tampilkan</button>
        <p class="text-xs text-slate-400 sm:col-span-2 lg:col-span-6">Periode berlaku untuk alat yang sudah selesai. Alat yang masih dibawa teknisi atau menunggu cek selalu tampil.</p>
    </form>

    @if ($perTechnician->isNotEmpty())
        <section class="card mb-6 overflow-hidden">
            <h2 class="flex items-center gap-2 border-b border-slate-100 px-5 py-3 font-semibold text-slate-800"><i data-lucide="users" class="h-4 w-4"></i>Per Teknisi</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                        <tr>
                            <th class="px-5 py-2.5">Teknisi</th>
                            <th class="px-4 py-2.5 text-right">Cabutan periode ini</th>
                            <th class="px-4 py-2.5 text-right">Masih dibawa</th>
                            <th class="px-4 py-2.5 text-right">Terlambat</th>
                            <th class="px-4 py-2.5">Batas paling lama</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($perTechnician as $row)
                            <tr>
                                <td class="px-5 py-2.5"><a href="{{ route('removed-devices.index', ['teknisi' => $row->technician_id] + request()->except(['teknisi', 'page'])) }}" class="font-medium text-indigo-700 hover:underline">{{ $row->technician?->name ?? '-' }}</a></td>
                                <td class="px-4 py-2.5 text-right">{{ $row->period_total }}</td>
                                <td class="px-4 py-2.5 text-right {{ $row->carried ? 'font-semibold text-amber-700' : 'text-slate-400' }}">{{ $row->carried }}</td>
                                <td class="px-4 py-2.5 text-right {{ $row->overdue ? 'font-bold text-rose-600' : 'text-slate-400' }}">{{ $row->overdue }}</td>
                                <td class="px-4 py-2.5 text-xs {{ $row->overdue ? 'text-rose-600' : 'text-slate-500' }}">{{ $row->oldest_due ? Illuminate\Support\Carbon::parse($row->oldest_due)->format('d/m/Y H:i') : '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="space-y-3">
        @forelse ($devices as $device)
            <div class="card p-4 {{ $device->isOverdue() ? 'border-rose-300' : '' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-semibold text-slate-800">{{ $device->name }}{{ $device->quantity > 1 ? " × {$device->quantity}" : '' }}</p>
                        <p class="text-xs text-slate-500">
                            {{ collect([$device->serial_number ? "SN {$device->serial_number}" : 'Tanpa SN', $device->formattedMac() ? 'MAC '.$device->formattedMac() : null, 'Kondisi saat dicabut: '.$device->fieldConditionLabel()])->filter()->implode(' · ') }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            @if ($device->ticket)
                                <a href="{{ route('tickets.show', $device->ticket) }}" class="font-mono font-semibold text-indigo-700 hover:underline">{{ $device->ticket->ticket_number }}</a> ·
                            @endif
                            {{ $device->customer_name ?? '-' }}{{ $device->customer_code ? " ({$device->customer_code})" : '' }}
                            · dicabut {{ $device->created_at->format('d/m/Y H:i') }} · dibawa <span class="font-medium text-slate-700">{{ $device->technician?->name ?? '-' }}</span>
                        </p>
                        @if ($device->notes)
                            <p class="text-xs text-slate-400">{{ $device->notes }}</p>
                        @endif
                    </div>
                    <div class="text-right text-xs">
                        <span class="badge {{ $device->status->badgeClasses() }}">{{ $device->status->label() }}</span>
                        @if ($device->isOverdue())
                            <p class="mt-1 font-bold text-rose-600">Terlambat {{ $device->due_at->diffForHumans(now(), true) }}</p>
                            <p class="text-slate-400">batas {{ $device->due_at->format('d/m/Y H:i') }}</p>
                        @elseif ($device->status === RemovedDeviceStatus::DIBAWA)
                            <p class="mt-1 text-slate-500">Serahkan sebelum {{ $device->due_at->format('d/m/Y H:i') }}</p>
                        @elseif ($device->received_at)
                            <p class="mt-1 text-slate-500">Diterima {{ $device->receiver?->name ?? '-' }} · {{ $device->received_at->format('d/m/Y H:i') }}</p>
                        @endif
                        @if ($device->checked_at)
                            <p class="text-slate-500">{{ $device->status === RemovedDeviceStatus::HILANG ? 'Ditutup' : 'Dicek' }} {{ $device->checker?->name ?? '-' }} · {{ $device->checked_at->format('d/m/Y') }}</p>
                        @endif
                    </div>
                </div>
                @if ($device->item || $device->check_notes)
                    <p class="mt-2 rounded-lg bg-slate-50 px-3 py-1.5 text-xs text-slate-600">
                        {{ collect([$device->item ? "Masuk stok Gudang Utama sebagai {$device->item->code} {$device->item->name} (bekas)" : null, $device->check_notes])->filter()->implode(' · ') }}
                    </p>
                @endif

                @if ($canHandle && $device->status === RemovedDeviceStatus::DIBAWA)
                    <div x-data="{ missing: false }" class="mt-3 border-t border-slate-100 pt-3">
                        <div x-show="! missing" class="flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('removed-devices.receive', $device) }}">
                                @csrf
                                <button class="btn-success text-sm"><i data-lucide="package-check" class="h-4 w-4"></i>Terima di Gudang</button>
                            </form>
                            <button type="button" @click="missing = true" class="btn-secondary text-sm">Tidak kembali…</button>
                        </div>
                        <form x-show="missing" x-cloak method="POST" action="{{ route('removed-devices.missing', $device) }}" class="flex flex-col gap-2 sm:flex-row">
                            @csrf
                            <input name="reason" class="input flex-1 text-sm" required maxlength="255" placeholder="Kenapa tidak kembali? mis. ditinggal di pelanggan, hilang">
                            <div class="flex gap-2">
                                <button class="btn-danger text-sm">Simpan</button>
                                <button type="button" @click="missing = false" class="btn-secondary text-sm">Batal</button>
                            </div>
                        </form>
                    </div>
                @elseif ($canHandle && $device->status === RemovedDeviceStatus::MENUNGGU_CEK)
                    <form method="POST" action="{{ route('removed-devices.check', $device) }}" x-data="{ result: 'layak' }" class="mt-3 space-y-2 border-t border-slate-100 pt-3">
                        @csrf
                        <div class="flex gap-2 text-sm">
                            <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-3 py-1.5 font-medium" :class="result === 'layak' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600'">
                                <input type="radio" name="result" value="layak" x-model="result" class="sr-only">Layak pakai
                            </label>
                            <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-3 py-1.5 font-medium" :class="result === 'rusak' ? 'border-rose-500 bg-rose-50 text-rose-700' : 'border-slate-200 text-slate-600'">
                                <input type="radio" name="result" value="rusak" x-model="result" class="sr-only">Rusak
                            </label>
                        </div>
                        <div x-show="result === 'layak'" class="grid gap-2 sm:grid-cols-3">
                            <input name="item" list="removed-device-items" class="input sm:col-span-3" placeholder="Masuk stok sebagai barang… (cari kode / nama di Master Barang)" :required="result === 'layak'">
                            <input name="serial_number" value="{{ $device->serial_number }}" class="input font-mono" maxlength="100" placeholder="SN (wajib untuk barang ber-SN)">
                            <input name="mac" value="{{ $device->formattedMac() }}" class="input font-mono" maxlength="30" placeholder="MAC (opsional)">
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <input name="check_notes" class="input flex-1 text-sm" maxlength="255" placeholder="Hasil cek, mis. reset pabrik OK / port LOS mati">
                            <button class="btn-primary text-sm"><i data-lucide="save" class="h-4 w-4"></i>Simpan Hasil Cek</button>
                        </div>
                    </form>
                @endif
            </div>
        @empty
            <div class="card py-12 text-center text-slate-400">
                <i data-lucide="unplug" class="mx-auto mb-2 h-8 w-8 text-slate-300"></i>
                Belum ada alat cabutan
            </div>
        @endforelse
        @if ($devices->hasPages())
            <div>{{ $devices->links() }}</div>
        @endif
    </section>

    @if ($canHandle)
        <datalist id="removed-device-items">
            @foreach ($items as $item)
                <option value="{{ $item->code }} · {{ $item->name }}"></option>
            @endforeach
        </datalist>
    @endif
</x-layouts.app>
