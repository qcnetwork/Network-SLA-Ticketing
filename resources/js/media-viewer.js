/**
 * Full-screen photo/video viewer in the style of Telegram's, opened by a `media-viewer`
 * window event from an attachment grid. Media is letterboxed rather than cropped,
 * arrows and horizontal swipes move between items, and Esc, a tap beside the media,
 * a swipe down, or the Android back button closes it.
 *
 * Photos zoom like in a gallery app: pinch, double-tap, or the mouse wheel, then drag
 * to pan. While a photo is zoomed, drags pan it instead of swiping between items.
 */
const MAX_SCALE = 5;
const DOUBLE_TAP_SCALE = 2.5;

export default () => ({
    open: false,
    items: [],
    index: 0,
    zoom: { scale: 1, x: 0, y: 0 },
    gesture: null,
    lastTap: null,
    dragged: false,

    get current() {
        return this.items[this.index] ?? null;
    },

    get zoomed() {
        return this.zoom.scale > 1;
    },

    get imageStyle() {
        const { scale, x, y } = this.zoom;
        return `transform: translate(${x}px, ${y}px) scale(${scale}); transition: ${this.gesture ? 'none' : 'transform .2s ease-out'}`;
    },

    show({ items, index }) {
        this.items = items;
        this.index = index;
        this.resetZoom();
        if (!this.open) {
            this.open = true;
            document.documentElement.classList.add('overflow-hidden');
            // A history entry lets the phone's back button close the viewer instead of leaving the page.
            history.pushState({ mediaViewer: true }, '');
        }
    },

    close() {
        if (this.open) {
            history.back();
        }
    },

    onBackdropClick() {
        // A mouse drag that ends off the photo still fires a click here; it should not close.
        if (this.dragged) {
            this.dragged = false;
            return;
        }
        this.close();
    },

    onPopState() {
        if (this.open) {
            this.open = false;
            document.documentElement.classList.remove('overflow-hidden');
        }
    },

    step(delta) {
        const next = this.index + delta;
        if (next >= 0 && next < this.items.length) {
            this.index = next;
            this.resetZoom();
        }
    },

    image() {
        return this.$root.querySelector('[data-zoomable]');
    },

    resetZoom() {
        this.zoom = { scale: 1, x: 0, y: 0 };
    },

    /**
     * Zoom to `scale` while keeping the point under (clientX, clientY) in place.
     */
    zoomAt(clientX, clientY, scale) {
        const image = this.image();
        if (!image) {
            return;
        }
        scale = Math.min(MAX_SCALE, Math.max(1, scale));
        const rect = image.getBoundingClientRect();
        // The transform scales around the box centre, so the untransformed centre is the
        // drawn centre minus the current translation.
        const centerX = rect.left + rect.width / 2 - this.zoom.x;
        const centerY = rect.top + rect.height / 2 - this.zoom.y;
        const ratio = scale / this.zoom.scale;
        const pointX = clientX - centerX;
        const pointY = clientY - centerY;
        this.zoom = {
            scale,
            x: pointX - (pointX - this.zoom.x) * ratio,
            y: pointY - (pointY - this.zoom.y) * ratio,
        };
        this.clampPan();
    },

    clampPan() {
        const image = this.image();
        if (!image || this.zoom.scale <= 1) {
            this.resetZoom();
            return;
        }
        // Stop panning once a photo edge reaches the edge of its unzoomed box.
        const maxX = (image.offsetWidth * (this.zoom.scale - 1)) / 2;
        const maxY = (image.offsetHeight * (this.zoom.scale - 1)) / 2;
        this.zoom.x = Math.min(maxX, Math.max(-maxX, this.zoom.x));
        this.zoom.y = Math.min(maxY, Math.max(-maxY, this.zoom.y));
    },

    toggleZoom(clientX, clientY) {
        if (this.zoomed) {
            this.resetZoom();
        } else {
            this.zoomAt(clientX, clientY, DOUBLE_TAP_SCALE);
        }
    },

    onWheel(event) {
        if (!this.current || this.current.video) {
            return;
        }
        event.preventDefault();
        this.zoomAt(event.clientX, event.clientY, this.zoom.scale * (event.deltaY < 0 ? 1.2 : 1 / 1.2));
    },

    onMouseDown(event) {
        if (!this.zoomed || event.button !== 0) {
            return;
        }
        event.preventDefault();
        this.dragged = false;
        this.gesture = { type: 'pan', x: event.clientX, y: event.clientY, startX: this.zoom.x, startY: this.zoom.y };
    },

    onMouseMove(event) {
        if (this.gesture?.type !== 'pan') {
            return;
        }
        this.zoom.x = this.gesture.startX + event.clientX - this.gesture.x;
        this.zoom.y = this.gesture.startY + event.clientY - this.gesture.y;
        this.clampPan();
        this.dragged = true;
    },

    onMouseUp() {
        if (this.gesture?.type === 'pan') {
            this.gesture = null;
        }
    },

    onTouchStart(event) {
        const isPhoto = this.current && !this.current.video;
        const [first, second] = event.touches;

        if (second && isPhoto) {
            this.gesture = {
                type: 'pinch',
                distance: Math.hypot(second.clientX - first.clientX, second.clientY - first.clientY),
                scale: this.zoom.scale,
            };
            return;
        }
        if (this.zoomed) {
            this.gesture = { type: 'pan', x: first.clientX, y: first.clientY, startX: this.zoom.x, startY: this.zoom.y, at: Date.now() };
            return;
        }
        // Leave the bottom of a video alone so dragging along its seek bar is not read as a swipe.
        if (event.target.tagName === 'VIDEO' && first.clientY > event.target.getBoundingClientRect().bottom - 80) {
            this.gesture = null;
            return;
        }
        this.gesture = { type: 'swipe', x: first.clientX, y: first.clientY, at: Date.now() };
    },

    onTouchMove(event) {
        const [first, second] = event.touches;

        if (this.gesture?.type === 'pinch' && second) {
            const distance = Math.hypot(second.clientX - first.clientX, second.clientY - first.clientY);
            this.zoomAt((first.clientX + second.clientX) / 2, (first.clientY + second.clientY) / 2, this.gesture.scale * (distance / this.gesture.distance));
        } else if (this.gesture?.type === 'pan') {
            this.zoom.x = this.gesture.startX + first.clientX - this.gesture.x;
            this.zoom.y = this.gesture.startY + first.clientY - this.gesture.y;
            this.clampPan();
        }
    },

    onTouchEnd(event) {
        const gesture = this.gesture;

        // Lifting one finger of a pinch carries on as a pan with the other.
        if (gesture?.type === 'pinch' && event.touches.length === 1 && this.zoomed) {
            const finger = event.touches[0];
            this.gesture = { type: 'pan', x: finger.clientX, y: finger.clientY, startX: this.zoom.x, startY: this.zoom.y };
            return;
        }
        if (event.touches.length) {
            return;
        }
        this.gesture = null;

        if (gesture?.type !== 'swipe' && gesture?.type !== 'pan') {
            return;
        }
        const point = event.changedTouches[0];
        const dx = point.clientX - gesture.x;
        const dy = point.clientY - gesture.y;

        // A short touch that barely moved is a tap, whether or not the photo is zoomed.
        if (Math.abs(dx) < 10 && Math.abs(dy) < 10 && Date.now() - gesture.at < 300) {
            if (event.target.matches('[data-zoomable]')) {
                this.onPhotoTap(event, point);
            }
        } else if (gesture.type === 'pan') {
            this.lastTap = null;
        } else if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
            this.step(dx < 0 ? 1 : -1);
        } else if (dy > 80 && dy > Math.abs(dx)) {
            this.close();
        }
    },

    onPhotoTap(event, point) {
        const now = Date.now();
        if (this.lastTap && now - this.lastTap < 300) {
            this.lastTap = null;
            // Stop the browser turning the second tap into a click that would hit the backdrop.
            event.preventDefault();
            this.toggleZoom(point.clientX, point.clientY);
        } else {
            this.lastTap = now;
        }
    },
});
