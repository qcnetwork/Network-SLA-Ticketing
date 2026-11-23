{{-- The warehouse area's own menu, one tab per page, for the warehouse team. Everyone else gets a way back to the
     warehouse instead. Pass $back = [label, url] to change where that link goes. --}}
@php
    $manager = auth()->user()?->canAccess(App\Enums\Menu::WAREHOUSE_MANAGE);
    $tabs = [
        ['Stok', 'warehouses.index', 'warehouse', ['warehouses.index', 'warehouses.show', 'warehouses.category']],
        ['Cari SN', 'tracking.index', 'scan-barcode', ['tracking.*']],
        ['Gudang Pelanggan', 'customer-materials.index', 'house', ['customer-materials.*']],
        ['Pemakaian Alat', 'usages.index', 'clipboard-list', ['usages.*']],
        ['Penjualan', 'sales.index', 'receipt', ['sales.*']],
        ['Mitra', 'partners.index', 'handshake', ['partners.*']],
        ['Master Barang', 'items.index', 'boxes', ['items.*']],
    ];
    // Manual stock correction is for the warehouse team and supervisors only, whatever menus are granted.
    if (auth()->user()?->hasRole(App\Enums\Role::GUDANG, App\Enums\Role::SPV)) {
        $tabs[] = ['Adjust Stok', 'stock-adjustments.index', 'sliders-horizontal', ['stock-adjustments.*']];
    }
@endphp
@if ($manager)
    <nav class="mb-5 overflow-x-auto border-b border-slate-200" aria-label="Menu gudang">
        <div class="flex min-w-max gap-1 text-sm font-semibold">
            @foreach ($tabs as [$label, $route, $icon, $active])
                @php($on = request()->routeIs(...$active))
                <a href="{{ route($route) }}" @if ($on) aria-current="page" @endif
                   class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2.5 transition {{ $on ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800' }}">
                    <i data-lucide="{{ $icon }}" class="h-4 w-4"></i>{{ $label }}
                </a>
            @endforeach
        </div>
    </nav>
@elseif (isset($back))
    <a href="{{ $back[1] }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
        <i data-lucide="arrow-left" class="h-4 w-4"></i>{{ $back[0] }}
    </a>
@endif
