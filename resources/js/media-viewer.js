/**
 * Full-screen photo/video viewer in the style of Telegram's, opened by a `media-viewer`
 * window event from an attachment grid. Media is letterboxed rather than cropped,
 * arrows and horizontal swipes move between items, and Esc, a tap beside the media,
 * a swipe down, or the Android back button closes it.
 */
export default () => ({
    open: false,
    items: [],
    index: 0,
    touch: null,

    get current() {
        return this.items[this.index] ?? null;
    },

    show({ items, index }) {
        this.items = items;
        this.index = index;
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
        }
    },

    onTouchStart(event) {
        const point = event.touches[0];
        // Leave the bottom of a video alone so dragging along its seek bar is not read as a swipe.
        if (event.target.tagName === 'VIDEO' && point.clientY > event.target.getBoundingClientRect().bottom - 80) {
            this.touch = null;
            return;
        }
        this.touch = { x: point.clientX, y: point.clientY };
    },

    onTouchEnd(event) {
        if (!this.touch) {
            return;
        }
        const point = event.changedTouches[0];
        const dx = point.clientX - this.touch.x;
        const dy = point.clientY - this.touch.y;
        this.touch = null;

        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
            this.step(dx < 0 ? 1 : -1);
        } else if (dy > 80 && dy > Math.abs(dx)) {
            this.close();
        }
    },
});
