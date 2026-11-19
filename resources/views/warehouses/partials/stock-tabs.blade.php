{{-- Stock and its latest movements share one spot: a tab switch instead of two long blocks stacked. --}}
<div x-data="{ view: 'stok' }" class="space-y-3">
    <div class="inline-flex rounded-lg bg-slate-100 p-1 text-sm font-semibold">
        <button type="button" @click="view = 'stok'" :class="view === 'stok' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'" class="flex items-center gap-1.5 rounded-md px-4 py-1.5">
            <i data-lucide="package" class="h-4 w-4"></i>Stok
        </button>
        <button type="button" @click="view = 'riwayat'" :class="view === 'riwayat' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'" class="flex items-center gap-1.5 rounded-md px-4 py-1.5">
            <i data-lucide="history" class="h-4 w-4"></i>Riwayat <span class="text-xs font-normal opacity-70">{{ $movements->count() }}</span>
        </button>
    </div>
    <div x-show="view === 'stok'">
        @include('warehouses.partials.stock-table', ['title' => $title, 'showLow' => $showLow ?? false])
    </div>
    <div x-show="view === 'riwayat'" x-cloak>
        @include('warehouses.partials.movements')
    </div>
</div>
