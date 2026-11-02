<x-layouts.app title="Peta Area Komplain">
    @php
        $m = $map;
        $coverage = $m['total'] > 0 ? (int) round($m['located'] / $m['total'] * 100) : 0;
        $presets = [30 => '30 hari', 90 => '90 hari', 365 => '1 tahun'];
    @endphp

    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Peta Area Komplain</h1>
            <p class="text-sm text-slate-500">Sebaran komplain {{ \Illuminate\Support\Carbon::parse($m['from'])->format('d/m/Y') }} – {{ \Illuminate\Support\Carbon::parse($m['to'])->format('d/m/Y') }}{{ $m['category'] ? ', kategori '.$m['category'] : '' }}, berdasarkan titik lokasi di tiket.</p>
            <div class="mt-3 inline-flex rounded-lg border border-slate-200 bg-white p-1 text-sm font-semibold">
                <a href="{{ route('reports.index', ['period' => 'daily']) }}" class="rounded-md px-3 py-1 text-slate-600 hover:text-indigo-700">Harian</a>
                <a href="{{ route('reports.index') }}" class="rounded-md px-3 py-1 text-slate-600 hover:text-indigo-700">Bulanan</a>
                <a href="{{ route('reports.heatmap') }}" class="rounded-md bg-indigo-600 px-3 py-1 text-white">Peta Area</a>
            </div>
        </div>
        <form method="GET" action="{{ route('reports.heatmap') }}" class="flex flex-wrap items-end gap-2">
            <div class="grow sm:grow-0">
                <label class="label" for="from">Dari</label>
                <input type="date" id="from" name="from" value="{{ $m['from'] }}" max="{{ now()->toDateString() }}" class="input">
            </div>
            <div class="grow sm:grow-0">
                <label class="label" for="to">Sampai</label>
                <input type="date" id="to" name="to" value="{{ $m['to'] }}" max="{{ now()->toDateString() }}" class="input">
            </div>
            <div class="basis-full sm:basis-auto">
                <label class="label" for="category">Kategori</label>
                <select id="category" name="category" class="input">
                    <option value="">Semua kategori</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected($m['category'] === $category)>{{ $category }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn-secondary grow sm:grow-0">Tampilkan</button>
        </form>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-xs">
        <span class="text-slate-500">Cepat:</span>
        @foreach ($presets as $days => $label)
            <a href="{{ route('reports.heatmap', array_filter(['from' => now()->subDays($days - 1)->toDateString(), 'to' => now()->toDateString(), 'category' => $m['category']])) }}"
               class="rounded-full border border-slate-200 bg-white px-3 py-1 font-medium text-slate-600 hover:border-indigo-300 hover:text-indigo-700">{{ $label }}</a>
        @endforeach
    </div>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="card p-4">
            <div class="flex items-center gap-1 text-xs font-medium text-slate-500"><i data-lucide="inbox" class="h-3.5 w-3.5"></i>Total Tiket</div>
            <div class="mt-1 text-2xl font-bold text-slate-800">{{ $m['total'] }}</div>
        </div>
        <div class="card p-4">
            <div class="flex items-center gap-1 text-xs font-medium text-slate-500"><i data-lucide="map-pin" class="h-3.5 w-3.5"></i>Punya Titik Lokasi</div>
            <div class="mt-1 flex items-baseline gap-1.5">
                <span class="text-2xl font-bold text-indigo-600">{{ $m['located'] }}</span>
                <span class="text-sm font-semibold text-slate-500">{{ $coverage }}%</span>
            </div>
        </div>
        <div class="card p-4">
            <div class="flex items-center gap-1 text-xs font-medium text-slate-500"><i data-lucide="target" class="h-3.5 w-3.5"></i>Area Terpadat</div>
            <div class="mt-1 text-2xl font-bold text-rose-600">{{ $m['hotspots'][0]['count'] ?? 0 }} <span class="text-sm font-semibold text-slate-500">komplain</span></div>
        </div>
        <div class="card p-4">
            <div class="flex items-center gap-1 text-xs font-medium text-slate-500"><i data-lucide="alert-triangle" class="h-3.5 w-3.5"></i>Tanpa Lokasi</div>
            <div class="mt-1 text-2xl font-bold text-amber-600">{{ $m['total'] - $m['located'] }}</div>
        </div>
    </div>

    @if ($m['total'] > 0 && $coverage < 50)
        <p class="mb-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            <i data-lucide="alert-triangle" class="mt-0.5 h-4 w-4 shrink-0"></i>
            <span>Baru {{ $coverage }}% tiket yang punya titik lokasi, jadi peta belum menggambarkan semua area. Minta shareloc pelanggan dan tempel di kolom alamat supaya peta makin akurat.</span>
        </p>
    @endif

    <section class="card mb-6 overflow-hidden">
        @if ($m['located'] > 0)
            {{-- isolate: Leaflet's panes use z-index 400+, which would cover the sticky header. --}}
            <div class="relative">
                <div id="complaint-map" class="isolate h-[28rem] w-full sm:h-[34rem]" role="img" aria-label="Peta sebaran komplain"></div>
                <div id="complaint-map-hint" class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center bg-slate-900/40 text-sm font-semibold text-white opacity-0 transition-opacity duration-300">
                    Tahan Ctrl + scroll untuk zoom peta
                </div>
            </div>
            <script type="application/json" id="complaint-map-data">@json(['points' => $m['points'], 'hotspots' => $m['hotspots']])</script>
            @vite('resources/js/heatmap.js')
        @else
            <div class="flex h-64 flex-col items-center justify-center gap-2 text-sm text-slate-400">
                <i data-lucide="map-pin" class="h-8 w-8"></i>
                Belum ada tiket dengan titik lokasi pada periode ini.
            </div>
        @endif
    </section>

    <section class="card overflow-x-auto">
        <div class="px-5 pt-5">
            <h2 class="font-semibold">Area Paling Sering Komplain</h2>
            <p class="text-xs text-slate-500">Titik dikelompokkan per area ±1 km. Klik area untuk melihatnya di peta.</p>
        </div>
        <ul class="mt-3 divide-y divide-slate-100 border-t border-slate-200">
            @forelse ($m['hotspots'] as $i => $spot)
                <li class="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center sm:gap-4">
                    <div class="flex min-w-0 flex-1 items-start gap-3">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-rose-100 text-xs font-bold text-rose-700">{{ $i + 1 }}</span>
                        <div class="min-w-0">
                            <button type="button" data-hotspot data-lat="{{ $spot['latitude'] }}" data-lng="{{ $spot['longitude'] }}"
                                    class="text-left text-sm font-medium text-slate-800 hover:text-indigo-700 hover:underline">
                                {{ $spot['address'] ?? 'Area '.\App\Support\GeoLocation::format($spot['latitude']).', '.\App\Support\GeoLocation::format($spot['longitude']) }}
                            </button>
                            <p class="text-xs text-slate-500">
                                {{ $spot['customers'] }} pelanggan · paling banyak: {{ $spot['top_category'] }} · terakhir {{ $spot['latest']->format('d/m/Y') }}
                            </p>
                            <div class="mt-1 flex flex-wrap gap-x-2 gap-y-1">
                                @foreach ($spot['tickets'] as $number)
                                    <a href="{{ route('tickets.show', $number) }}" class="font-mono text-[11px] text-indigo-700 hover:underline">{{ $number }}</a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 sm:w-48">
                        <div class="h-2 flex-1 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-rose-500" style="width: {{ round($spot['count'] / $m['hotspots'][0]['count'] * 100) }}%"></div></div>
                        <span class="w-20 text-right text-sm font-semibold text-slate-900">{{ $spot['count'] }} komplain</span>
                    </div>
                    <a href="{{ \App\Support\GeoLocation::mapsUrl($spot['latitude'], $spot['longitude']) }}" target="_blank" rel="noopener" class="btn-secondary text-xs">
                        <i data-lucide="map-pin" class="h-4 w-4"></i>Google Maps
                    </a>
                </li>
            @empty
                <li class="px-5 py-8 text-center text-sm text-slate-400">Belum ada area yang bisa dipetakan.</li>
            @endforelse
        </ul>
    </section>
</x-layouts.app>
