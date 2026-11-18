{{-- One form for every note that leaves the status alone, on complaints and installations alike.
     Needs $ticket, $actionUrl, $urgencyLevels and the $canUrgency / $canHelpdeskInfo / $canDocument flags. --}}
@php
    // Urgency and helpdesk info share one form: CS posts its urgency note, Helpdesk (and SPV) its info.
    $noteAction = $canHelpdeskInfo ? \App\Enums\TicketAction::CATATAN_INFO_HELPDESK : ($canUrgency ? \App\Enums\TicketAction::CATATAN_URGENSI_CS : null);
    $noteHint = 'Notifikasi ke Helpdesk & CS'.($ticket->technicians->isNotEmpty() ? ' serta teknisi '.$ticket->technicianNames() : '').', juga ke grup Telegram.';
    $noteStyles = [
        'INFO' => ['Info', 'info', 'border-indigo-500 bg-indigo-50 text-indigo-700'],
        'NORMAL' => ['Normal', 'message-square', 'border-sky-500 bg-sky-50 text-sky-700'],
        'MENDESAK' => ['Mendesak', 'siren', 'border-orange-500 bg-orange-50 text-orange-700'],
        'KRITIS' => ['Kritis', 'alert-triangle', 'border-rose-500 bg-rose-50 text-rose-700'],
    ];
    $noteTypes = [];
    foreach ($noteAction ? $urgencyLevels : [] as $level) {
        [$label, $icon, $active] = $noteStyles[$level] ?? [ucfirst(strtolower($level)), 'message-square', 'border-indigo-500 bg-indigo-50 text-indigo-700'];
        $noteTypes[$level] = ['label' => $label, 'icon' => $icon, 'active' => $active, 'url' => $actionUrl($noteAction), 'hint' => $noteHint,
            'button' => 'Kirim '.$label, 'placeholder' => 'Contoh: Pelanggan menanyakan progres / minta teknisi datang setelah jam 2 siang'];
    }
    if ($canDocument) {
        $noteTypes['DOKUMENTASI'] = ['label' => 'Dokumentasi', 'icon' => 'camera', 'active' => 'border-emerald-500 bg-emerald-50 text-emerald-700',
            'url' => $actionUrl(\App\Enums\TicketAction::CATATAN_DOKUMENTASI), 'hint' => 'Disimpan di riwayat tiket beserta foto/video.',
            'button' => 'Simpan Dokumentasi', 'placeholder' => 'Keterangan foto (opsional). Contoh: Foto redaman di ODP sebelum perbaikan'];
    }
    $noteDefault = match (true) {
        isset($noteTypes[old('_note')]) => old('_note'),
        $noteAction === \App\Enums\TicketAction::CATATAN_URGENSI_CS && isset($noteTypes['MENDESAK']) => 'MENDESAK',
        default => array_key_first($noteTypes),
    };
@endphp
<form method="POST" action="{{ $noteTypes[$noteDefault]['url'] }}" :action="types[type].url" enctype="multipart/form-data" class="card space-y-3 p-5"
    x-data="{ type: @js($noteDefault), types: @js($noteTypes) }">
    @csrf
    <input type="hidden" name="_note" :value="type">
    <div>
        <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="message-square-text" class="h-4 w-4 text-indigo-600"></i>Catatan & Info</h3>
        <p class="text-xs text-slate-500">Tidak mengubah status tiket.</p>
    </div>

    @if (count($noteTypes) > 1)
        <div>
            <span class="label">Jenis catatan</span>
            <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Jenis catatan">
                @foreach ($noteTypes as $value => $noteType)
                    <button type="button" role="radio" @click="type = '{{ $value }}'" :aria-checked="type === '{{ $value }}'"
                        :class="type === '{{ $value }}' ? '{{ $noteType['active'] }}' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
                        class="inline-flex items-center gap-1.5 rounded-full border-2 px-3 py-1 text-xs font-semibold transition">
                        <i data-lucide="{{ $noteType['icon'] }}" class="h-3.5 w-3.5"></i>{{ $noteType['label'] }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif
    <p class="flex items-start gap-1.5 rounded-lg bg-slate-50 p-2 text-xs text-slate-600">
        <i data-lucide="send" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400"></i><span x-text="types[type].hint">{{ $noteTypes[$noteDefault]['hint'] }}</span>
    </p>

    {{-- The level is not sent with documentation, and photos only with documentation. --}}
    <input type="hidden" name="urgency_level" :value="type" :disabled="type === 'DOKUMENTASI'">
    <textarea name="notes" rows="3" class="input" maxlength="2000" :required="type !== 'DOKUMENTASI'" :placeholder="types[type].placeholder"
        placeholder="{{ $noteTypes[$noteDefault]['placeholder'] }}">{{ old('_note') ? old('notes') : '' }}</textarea>
    @if (old('_note'))
        <x-field-error name="notes" />
        <x-field-error name="urgency_level" />
    @endif
    @if (isset($noteTypes['DOKUMENTASI']))
        <fieldset x-show="type === 'DOKUMENTASI'" :disabled="type !== 'DOKUMENTASI'" @if ($noteDefault !== 'DOKUMENTASI') x-cloak @endif>
            <x-photo-input id="documentation_photos" />
        </fieldset>
    @endif
    @if ($noteAction)
        <fieldset x-show="type !== 'DOKUMENTASI'" :disabled="type === 'DOKUMENTASI'">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="hidden" name="upgrade_priority" value="0">
                <input type="checkbox" name="upgrade_priority" value="1" class="rounded border-slate-300 text-indigo-600"> Naikkan prioritas ke Tinggi
            </label>
        </fieldset>
    @endif
    <button class="btn-primary w-full"><i data-lucide="send" class="h-4 w-4"></i><span x-text="types[type].button">{{ $noteTypes[$noteDefault]['button'] }}</span></button>
</form>
