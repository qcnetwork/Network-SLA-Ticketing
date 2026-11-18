/**
 * The dashboard's live parts. The notification log follows the navbar bell's polls (no extra
 * requests), and the figures marked [data-live] are re-fetched when something new arrives and once
 * a minute, so the page can stay open on a desk screen without reloading.
 */
const REFRESH_MS = 60000;

export default () => ({
    items: [],
    unread: 0,
    loaded: false,
    updatedAt: null,
    flash: new Set(),
    timer: null,

    init() {
        if (window.latestNotifications) {
            this.take(window.latestNotifications, []);
        }
        window.addEventListener('notifications:updated', (event) => {
            this.take(event.detail, event.detail.fresh);
            if (event.detail.fresh.length) {
                this.refresh();
            }
        });
        this.timer = setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.refresh();
            }
        }, REFRESH_MS);
    },

    take({ items, unread }, fresh) {
        this.items = items;
        this.unread = unread;
        this.loaded = true;
        this.updatedAt = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
        // Newly arrived rows glow for a moment so the eye finds them.
        fresh.forEach((item) => this.flash.add(item.id));
        if (fresh.length) {
            setTimeout(() => {
                fresh.forEach((item) => this.flash.delete(item.id));
                this.flash = new Set(this.flash);
            }, 6000);
        }
        this.$nextTick(() => window.renderIcons?.());
    },

    /**
     * Swap in the latest figures by fetching the page again and replacing each [data-live] block.
     */
    async refresh() {
        let html;
        try {
            const response = await fetch(window.location.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok || response.redirected) {
                return;
            }
            html = await response.text();
        } catch {
            return; // Offline for a moment; the next round tries again.
        }

        const next = new DOMParser().parseFromString(html, 'text/html');
        document.querySelectorAll('[data-live]').forEach((block) => {
            const replacement = next.querySelector(`[data-live="${block.dataset.live}"]`);
            if (replacement) {
                block.innerHTML = replacement.innerHTML;
            }
        });
        window.renderIcons?.();
    },

    destroy() {
        clearInterval(this.timer);
    },
});
