<x-layouts.app title="Notifikasi Saya">
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Notifikasi Saya</h1>
                <p class="text-sm text-slate-500">Semua notifikasi untuk akun {{ auth()->user()->name }}. Status dibaca berlaku untuk akun ini saja.</p>
            </div>
            @if ($unread)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button class="btn-secondary text-xs"><i data-lucide="check" class="h-4 w-4"></i>Tandai semua dibaca</button>
                </form>
            @endif
        </div>

        <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 text-sm font-semibold">
            @foreach (['all' => "Semua ({$total})", 'unread' => "Belum dibaca ({$unread})"] as $key => $label)
                <a href="{{ route('notifications.history', $key === 'all' ? [] : ['filter' => $key]) }}"
                    @class(['rounded-md px-3 py-1', 'bg-indigo-600 text-white' => $filter === $key, 'text-slate-600 hover:text-indigo-700' => $filter !== $key])>{{ $label }}</a>
            @endforeach
        </div>

        <div class="card divide-y divide-slate-100">
            @forelse ($notifications as $notification)
                <a href="{{ route('notifications.open', $notification->id) }}" @class(['flex gap-3 px-5 py-4 hover:bg-slate-50', 'bg-indigo-50/40' => ! $notification->read_at])>
                    <i data-lucide="{{ $notification->data['icon'] ?? 'inbox' }}" @class(['mt-0.5 h-5 w-5 shrink-0', 'text-indigo-600' => ! $notification->read_at, 'text-slate-400' => $notification->read_at])></i>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-slate-800">{{ $notification->data['title'] ?? '' }}</span>
                            @unless ($notification->read_at)
                                <span class="badge border-indigo-200 bg-indigo-50 text-indigo-700">Baru</span>
                            @endunless
                        </div>
                        <p class="text-sm text-slate-600">{{ $notification->data['body'] ?? '' }}</p>
                        <p class="mt-1 text-xs text-slate-400" title="{{ $notification->created_at->format('d/m/Y H:i') }}">
                            {{ $notification->created_at->format('d/m/Y H:i') }} · {{ $notification->created_at->diffForHumans() }}
                            @if ($notification->read_at)
                                · dibaca {{ $notification->read_at->format('d/m/Y H:i') }}
                            @endif
                        </p>
                    </div>
                </a>
            @empty
                <p class="px-5 py-10 text-center text-sm text-slate-400">{{ $filter === 'unread' ? 'Tidak ada notifikasi yang belum dibaca.' : 'Belum ada notifikasi.' }}</p>
            @endforelse
        </div>

        {{ $notifications->links() }}
    </div>
</x-layouts.app>
