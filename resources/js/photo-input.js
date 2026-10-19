/**
 * Photo/video picker for documentation notes. Files can be chosen from a file dialog,
 * dropped onto the zone, or pasted (Ctrl+V) anywhere inside the surrounding form,
 * e.g. an image copied straight from WhatsApp. Every file is synced back into the
 * real <input type="file"> so the form submits normally.
 */
export default (max = 5) => ({
    max,
    photos: [],
    message: '',

    init() {
        this.$el.closest('form')?.addEventListener('paste', (event) => this.onPaste(event));
    },

    onPaste(event) {
        const items = Array.from(event.clipboardData?.items ?? []);
        const images = items.filter((item) => item.kind === 'file' && item.type.startsWith('image/')).map((item) => item.getAsFile());

        if (!images.length) {
            return;
        }

        // Keep normal text pasting into the notes field working when the clipboard has both.
        if (!items.some((item) => item.type === 'text/plain')) {
            event.preventDefault();
        }

        Promise.all(images.map((file, i) => this.compress(file, `tempel-${Date.now()}-${i + 1}.jpg`))).then((files) => this.add(files));
    },

    onChoose(event) {
        this.add(Array.from(event.target.files));
    },

    onDrop(event) {
        this.add(Array.from(event.dataTransfer.files));
    },

    add(files) {
        this.message = '';
        const media = files.filter((file) => file.type.startsWith('image/') || file.type.startsWith('video/'));

        if (media.length < files.length) {
            this.message = 'Hanya foto atau video yang bisa dilampirkan.';
        }

        for (const file of media) {
            if (this.photos.length >= this.max) {
                this.message = `Maksimal ${this.max} foto/video per catatan.`;
                break;
            }

            const isVideo = file.type.startsWith('video/');
            if (file.size > (isVideo ? 50 : 5) * 1024 * 1024) {
                this.message = isVideo ? `Video ${file.name} melebihi 50 MB.` : `Foto ${file.name} melebihi 5 MB.`;
                continue;
            }

            this.photos.push({ file, isVideo, url: URL.createObjectURL(file) });
        }

        this.sync();
    },

    remove(index) {
        URL.revokeObjectURL(this.photos[index].url);
        this.photos.splice(index, 1);
        this.message = '';
        this.sync();
    },

    sync() {
        const transfer = new DataTransfer();
        this.photos.forEach((photo) => transfer.items.add(photo.file));
        this.$refs.input.files = transfer.files;
    },

    /**
     * Clipboard images arrive as large PNGs; re-encode them as JPEG so they stay under the upload limit.
     */
    async compress(file, name) {
        try {
            const bitmap = await createImageBitmap(file);
            const scale = Math.min(1, 1920 / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));

            return blob ? new File([blob], name, { type: 'image/jpeg' }) : file;
        } catch {
            return file;
        }
    },
});
