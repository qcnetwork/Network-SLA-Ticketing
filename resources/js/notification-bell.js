/**
 * The bell in the navbar. It polls for notifications so a ticket reaching your node shows up
 * without a reload, with a short pop-up and a chime. While you are on another tab it also badges
 * the tab icon, blinks the tab title and, where the browser allows it, shows a desktop notification.
 */
const POLL_MS = 15000;
const BLINK_MS = 1200;
const MUTE_KEY = 'netticket.notifications.muted';

/**
 * Desktop notifications only exist in secure contexts (https or localhost); the app is often opened
 * over plain http on the LAN, where Chrome hides the API entirely.
 */
const desktopState = () => {
    if (!('Notification' in window)) {
        return window.isSecureContext ? 'unsupported' : 'insecure';
    }

    return Notification.permission; // 'default' | 'granted' | 'denied'
};

export default (indexUrl, readAllUrl) => ({
    open: false,
    unread: 0,
    items: [],
    toasts: [],
    muted: false,
    desktop: desktopState(),
    seen: new Set(),
    baseTitle: document.title,
    timer: null,
    blinkTimer: null,
    favicon: null,

    init() {
        try {
            this.muted = localStorage.getItem(MUTE_KEY) === '1';
        } catch {
            // Storage can be blocked; the chime then just stays on.
        }
        this.load(true);
        this.timer = setInterval(() => this.load(), POLL_MS);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                // Back on this tab: stop calling for attention and catch up without waiting for the next poll.
                this.stopBlink();
                this.load();
            }
        });
    },

    async load(first = false) {
        let data;
        try {
            const response = await fetch(indexUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            // Signed out (session expired): stop asking until the next page load.
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) {
                clearInterval(this.timer);
                return;
            }
            data = await response.json();
        } catch {
            return; // Network hiccup; the next poll tries again.
        }

        const fresh = data.items.filter((item) => !item.read && !this.seen.has(item.id));
        data.items.forEach((item) => this.seen.add(item.id));
        this.items = data.items;
        this.unread = data.unread;
        this.showCount();

        // The first load only fills the bell; pop-ups are for what arrives while the page is open.
        if (!first && fresh.length) {
            fresh.slice(0, 3).forEach((item) => this.toast(item));
            this.chime();
            if (document.hidden) {
                this.startBlink();
                this.notifyDesktop(fresh.slice(0, 3));
            }
        }
    },

    /**
     * The unread count in the tab title and as a red badge on the tab icon.
     */
    showCount() {
        if (!this.blinkTimer) {
            document.title = this.unread ? `(${this.unread}) ${this.baseTitle}` : this.baseTitle;
        }
        this.badgeFavicon(this.unread);
    },

    startBlink() {
        if (this.blinkTimer) {
            return;
        }
        let alert = false;
        this.blinkTimer = setInterval(() => {
            alert = !alert;
            document.title = alert ? `🔔 ${this.unread} notifikasi baru` : `(${this.unread}) ${this.baseTitle}`;
        }, BLINK_MS);
    },

    stopBlink() {
        clearInterval(this.blinkTimer);
        this.blinkTimer = null;
        this.showCount();
    },

    /**
     * Draw the count onto the app icon, so it shows in the tab strip from any other tab.
     */
    async badgeFavicon(count) {
        const link = document.querySelector('link[rel="icon"]');
        if (!link) {
            return;
        }
        this.favicon ??= { href: link.href, type: link.type, image: null };
        if (!count) {
            link.href = this.favicon.href;
            link.type = this.favicon.type;
            return;
        }

        try {
            this.favicon.image ??= await new Promise((resolve, reject) => {
                const image = new Image();
                image.onload = () => resolve(image);
                image.onerror = reject;
                image.src = this.favicon.href;
            });
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 64;
            const draw = canvas.getContext('2d');
            draw.drawImage(this.favicon.image, 0, 0, 64, 64);
            draw.fillStyle = '#e11d48';
            draw.beginPath();
            draw.arc(44, 20, 20, 0, Math.PI * 2);
            draw.fill();
            draw.fillStyle = '#fff';
            draw.font = 'bold 26px sans-serif';
            draw.textAlign = 'center';
            draw.textBaseline = 'middle';
            draw.fillText(count > 9 ? '9+' : String(count), 44, 21);
            link.type = 'image/png';
            link.href = canvas.toDataURL('image/png');
        } catch {
            // Icon could not be drawn; the count in the title still shows.
        }
    },

    async enableDesktop() {
        if (this.desktop === 'default') {
            this.desktop = await Notification.requestPermission();
        }
    },

    notifyDesktop(items) {
        if (this.desktop !== 'granted') {
            return;
        }
        items.forEach((item) => {
            const notice = new Notification(item.title, { body: item.body, icon: '/favicon.svg', tag: item.id });
            notice.onclick = () => {
                window.focus();
                window.location.href = item.url;
                notice.close();
            };
        });
    },

    toast(item) {
        // Each pop-up is keyed by its notification id, and x-for cannot track two elements with one key.
        if (this.toasts.some((toast) => toast.id === item.id)) {
            return;
        }
        this.toasts.push(item);
        setTimeout(() => {
            this.toasts = this.toasts.filter((toast) => toast.id !== item.id);
        }, 7000);
    },

    dismiss(id) {
        this.toasts = this.toasts.filter((toast) => toast.id !== id);
    },

    async readAll() {
        await fetch(readAllUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
            credentials: 'same-origin',
        });
        this.items = this.items.map((item) => ({ ...item, read: true }));
        this.unread = 0;
        this.showCount();
    },

    toggleMute() {
        this.muted = !this.muted;
        try {
            localStorage.setItem(MUTE_KEY, this.muted ? '1' : '0');
        } catch {
            // Not remembered across pages, but still applies here.
        }
        if (!this.muted) {
            this.chime();
        }
    },

    /**
     * A two-note "ting" made in the browser, so there is no sound file to ship. Browsers only allow
     * audio after the user has clicked somewhere on the page, which a working user always has.
     */
    chime() {
        if (this.muted) {
            return;
        }
        try {
            const audio = new (window.AudioContext || window.webkitAudioContext)();
            [[880, 0], [1320, 0.12]].forEach(([frequency, delay]) => {
                const tone = audio.createOscillator();
                const volume = audio.createGain();
                tone.type = 'sine';
                tone.frequency.value = frequency;
                volume.gain.setValueAtTime(0.0001, audio.currentTime + delay);
                volume.gain.exponentialRampToValueAtTime(0.2, audio.currentTime + delay + 0.02);
                volume.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + delay + 0.35);
                tone.connect(volume).connect(audio.destination);
                tone.start(audio.currentTime + delay);
                tone.stop(audio.currentTime + delay + 0.4);
            });
            setTimeout(() => audio.close(), 1000);
        } catch {
            // No audio available; the pop-up and title still show.
        }
    },
});
