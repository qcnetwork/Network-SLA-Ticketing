/**
 * Photo/video picker for documentation notes. Files can be chosen from a file dialog,
 * dropped onto the zone, or pasted (Ctrl+V) anywhere inside the surrounding form,
 * e.g. an image copied straight from WhatsApp. Every file is synced back into the
 * real <input type="file"> so the form submits normally.
 *
 * `limits` are the byte limits from DocumentationPhotoStore::limits(), already lowered to
 * PHP's upload settings, so an oversized file is refused here before the typed note is lost.
 */
const MB = 1024 * 1024;

export default (max = 5, limits = { photo: 5 * MB, video: 50 * MB, total: 0 }) => ({
    max,
    limits,
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
            const limit = isVideo ? this.limits.video : this.limits.photo;
            if (file.size > limit) {
                this.message = `${isVideo ? 'Video' : 'Foto'} ${file.name} melebihi ${Math.floor(limit / MB)} MB.`;
                continue;
            }

            // Leave headroom for the note and other form fields sent in the same request.
            const total = this.photos.reduce((sum, photo) => sum + photo.file.size, file.size);
            if (this.limits.total && total > this.limits.total - MB) {
                this.message = `Total lampiran melebihi ${Math.floor(this.limits.total / MB)} MB per kiriman. Kirim sisanya di catatan berikutnya.`;
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
