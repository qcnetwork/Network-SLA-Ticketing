@if (session('success'))
    <div class="mb-4 flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" x-data="{ show: true }" x-show="show">
        <i data-lucide="check-circle-2" class="mt-0.5 h-4 w-4 shrink-0"></i>
        <span class="flex-1">{{ session('success') }}</span>
        <button type="button" @click="show = false" class="text-emerald-600 hover:text-emerald-900" aria-label="Tutup">&times;</button>
    </div>
@endif
@if ($errors->any())
    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
        <div class="flex items-center gap-2 font-semibold"><i data-lucide="alert-triangle" class="h-4 w-4"></i>Terjadi kesalahan</div>
        <ul class="mt-1 list-disc pl-6">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
