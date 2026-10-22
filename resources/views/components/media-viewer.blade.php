<div x-data="mediaViewer" x-show="open" x-cloak
     @media-viewer.window="show($event.detail)" @popstate.window="onPopState()"
     @keydown.escape.window="close()" @keydown.arrow-left.window="open && step(-1)" @keydown.arrow-right.window="open && step(1)"
     @mousemove.window="onMouseMove($event)" @mouseup.window="onMouseUp()"
     role="dialog" aria-modal="true" class="fixed inset-0 z-50 flex flex-col bg-black/95 text-white">
    <div class="relative z-10 flex items-center gap-2 bg-black/60 px-3 py-2 sm:px-4">
        <span class="min-w-0 flex-1 truncate text-sm" x-text="current?.name"></span>
        <span x-show="items.length > 1" class="shrink-0 text-sm text-white/70" x-text="`${index + 1} / ${items.length}`"></span>
        <a :href="current?.url" :download="current?.name" title="Unduh" class="shrink-0 rounded-full p-2 hover:bg-white/10"><i data-lucide="download" class="h-5 w-5"></i></a>
        <button type="button" @click="close()" title="Tutup" class="shrink-0 rounded-full p-2 hover:bg-white/10"><i data-lucide="x" class="h-6 w-6"></i></button>
    </div>

    <div class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden px-2 pb-4"
         :class="current && !current.video && 'touch-none'"
         @click.self="onBackdropClick()" @wheel="onWheel($event)"
         @touchstart="onTouchStart($event)" @touchmove="onTouchMove($event)" @touchend="onTouchEnd($event)">
        <template x-if="open && current?.video">
            <video :src="current.url" controls autoplay playsinline class="h-full w-full object-contain"></video>
        </template>
        <template x-if="open && current && !current.video">
            <img :src="current.url" :alt="current.name" data-zoomable draggable="false"
                 :style="imageStyle" :class="zoomed ? 'cursor-grab' : 'cursor-zoom-in'"
                 @dblclick="toggleZoom($event.clientX, $event.clientY)" @mousedown="onMouseDown($event)"
                 class="max-h-full max-w-full object-contain select-none">
        </template>

        <button type="button" x-show="index > 0" @click="step(-1)" title="Sebelumnya"
                class="absolute top-1/2 left-2 hidden -translate-y-1/2 rounded-full bg-white/10 p-2 hover:bg-white/20 sm:block">
            <i data-lucide="chevron-left" class="h-6 w-6"></i>
        </button>
        <button type="button" x-show="index < items.length - 1" @click="step(1)" title="Berikutnya"
                class="absolute top-1/2 right-2 hidden -translate-y-1/2 rounded-full bg-white/10 p-2 hover:bg-white/20 sm:block">
            <i data-lucide="chevron-right" class="h-6 w-6"></i>
        </button>
    </div>
</div>
