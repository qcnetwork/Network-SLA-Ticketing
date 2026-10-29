@props(['id' => 'photos'])
@php($limits = App\Services\DocumentationPhotoStore::limits())
<div x-data="photoInput(5, @js($limits))">
    <label class="label" for="{{ $id }}">Foto/video dokumentasi <span class="font-normal text-slate-400">(maks. 5 file · foto {{ intdiv($limits['photo'], 1024 ** 2) }} MB, video {{ intdiv($limits['video'], 1024 ** 2) }} MB)</span></label>
    <input id="{{ $id }}" x-ref="input" type="file" name="photos[]" accept="image/*,video/mp4,video/quicktime,video/webm,video/3gpp" multiple class="sr-only" @change="onChoose($event)">

    <div tabindex="0" @dragover.prevent @drop.prevent="onDrop($event)"
        class="flex flex-col items-center gap-1 rounded-lg border-2 border-dashed border-slate-300 px-3 py-4 text-center text-sm text-slate-500 hover:border-indigo-400 focus:border-indigo-500 focus:bg-indigo-50/50 focus:outline-none">
        <i data-lucide="clipboard-paste" class="h-5 w-5 text-indigo-500"></i>
        <span>Klik di sini lalu <span class="font-semibold text-slate-700">tempel (Ctrl+V)</span> foto dari WhatsApp</span>
        <button type="button" @click="$refs.input.click()" class="text-xs font-semibold text-indigo-600 hover:underline">atau pilih foto/video</button>
    </div>

    <div x-show="photos.length" x-cloak class="mt-2 grid grid-cols-3 gap-2">
        <template x-for="(photo, index) in photos" :key="photo.url">
            <div class="relative">
                <template x-if="photo.isVideo">
                    <video :src="photo.url" muted playsinline preload="metadata" class="aspect-square w-full rounded-lg border border-slate-200 bg-slate-900 object-cover"></video>
                </template>
                <template x-if="!photo.isVideo">
                    <img :src="photo.url" :alt="photo.file.name" class="aspect-square w-full rounded-lg border border-slate-200 object-cover">
                </template>
                <span x-show="photo.isVideo" class="pointer-events-none absolute bottom-1 left-1 rounded bg-slate-900/70 px-1.5 py-0.5 text-[10px] font-semibold text-white">VIDEO</span>
                <button type="button" @click="remove(index)" class="absolute top-1 right-1 flex h-6 w-6 items-center justify-center rounded-full bg-slate-900/70 text-sm text-white hover:bg-rose-600" aria-label="Hapus lampiran">&times;</button>
            </div>
        </template>
    </div>
    <p x-show="message" x-text="message" x-cloak class="mt-1 text-xs text-rose-600"></p>
    <x-field-error name="photos" />
    <x-field-error name="photos.*" />
</div>
