<x-layouts.app title="Feed Telegram">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900">Feed Telegram</h1>
        <p class="text-sm text-slate-500">Pesan bot ke grup Helpdesk. {{ App\Models\TelegramLog::KEEP }} pesan terakhir disimpan.</p>
    </div>

    <div class="mx-auto max-w-3xl space-y-3">
        @forelse ($logs as $log)
            <article class="card p-4">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs">
                    <span class="text-slate-500">{{ $log->created_at->format('d/m/Y H:i:s') }}</span>
                    @if ($log->simulated)
                        <span class="badge border-slate-300 bg-slate-100 text-slate-600">Simulator</span>
                    @elseif ($log->is_real_sent)
                        <span class="badge border-sky-300 bg-sky-50 text-sky-700">Terkirim ke Telegram</span>
                    @else
                        <span class="badge border-rose-300 bg-rose-50 text-rose-700" title="{{ $log->error }}">Gagal: {{ \Illuminate\Support\Str::limit($log->error, 60) }}</span>
                    @endif
                </div>
                {{-- Messages use Telegram's HTML subset; strip everything except the tags Telegram supports. --}}
                <div class="rounded-lg bg-sky-50 p-3 text-sm leading-relaxed whitespace-pre-line wrap-break-word text-slate-800">{!! strip_tags($log->message, '<b><i><code><u><s>') !!}</div>
            </article>
        @empty
            <div class="card p-10 text-center text-slate-400">Belum ada pesan.</div>
        @endforelse

        {{ $logs->links() }}
    </div>
</x-layouts.app>
