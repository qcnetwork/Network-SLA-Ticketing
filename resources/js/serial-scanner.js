/**
 * Scanning serial numbers with a barcode scanner. A USB or Bluetooth scanner types the code like a
 * very fast keyboard, so a burst of keystrokes is taken as a finished scan and checked on its own; a
 * person typing by hand presses Enter or Tab instead. Enter never submits the form.
 * With `withMac`, each SN is followed by its MAC label: the cursor jumps to the MAC box after the SN,
 * and Tab there seals SN + MAC as one unit and returns to the SN box for the next one. The form may
 * let the person switch the MAC off (devices without a MAC label, such as an SFP).
 * Every SN is checked with the server as it is scanned (`lookupUrl` plus `query`), so a unit that is
 * already registered, or sitting in another warehouse, is flagged at the scanner rather than on save.
 */

// A scanner types a whole code in a few milliseconds per character; people are far slower.
const SCANNER_MS_PER_CHAR = 35;
const SCAN_IDLE_MS = 150;
const MIN_SCAN_LENGTH = 4;

export default ({ lookupUrl, query = {}, withMac = false }) => ({
    withMac,
    units: [],
    sn: '',
    mac: '',
    pending: null,
    error: '',
    checking: false,
    burst: { start: 0, length: 0, timer: null },

    normalize(value) {
        return value.trim().toUpperCase().replace(/\s+/g, '');
    },

    focusSn() {
        this.$nextTick(() => this.$refs.sn?.focus());
    },

    // Called on every keystroke in the SN box: a scanner-speed burst is checked as soon as it stops.
    snInput(event) {
        clearTimeout(this.burst.timer);
        const value = event.target.value;
        const added = value.length - this.burst.length;
        this.burst.length = value.length;
        if (added <= 0) {
            return;
        }
        const now = performance.now();
        if (value.length === added) {
            this.burst.start = now;
        }
        const msPerChar = (now - this.burst.start) / Math.max(value.length - 1, 1);
        if (value.trim().length >= MIN_SCAN_LENGTH && (added >= MIN_SCAN_LENGTH || msPerChar < SCANNER_MS_PER_CHAR)) {
            this.burst.timer = setTimeout(() => this.scanSn(), SCAN_IDLE_MS);
        }
    },

    resetSn() {
        clearTimeout(this.burst.timer);
        this.sn = '';
        this.burst.length = 0;
    },

    async scanSn() {
        clearTimeout(this.burst.timer);
        const sn = this.normalize(this.sn);
        if (!sn || this.checking || this.pending !== null) {
            return;
        }
        this.resetSn();
        this.error = '';
        if (this.units.some((unit) => unit.sn === sn)) {
            this.fail(`SN ${sn} sudah discan`);

            return;
        }

        this.checking = true;
        try {
            const params = new URLSearchParams({ ...query, sn });
            const response = await fetch(`${lookupUrl}?${params}`, { headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) {
                this.fail(data.message || `SN ${sn} ditolak`);

                return;
            }
            if (this.withMac) {
                // The SN stays visible beside the MAC box until Tab seals the pair.
                this.sn = sn;
                this.pending = sn;
                this.beep(true);
                this.$nextTick(() => this.$refs.mac?.focus());

                return;
            }
            this.units.unshift({ sn, mac: data.mac || '', item: data.item || '' });
            this.beep(true);
            this.focusSn();
        } catch {
            this.fail('Gagal mengecek SN, coba scan lagi');
        } finally {
            this.checking = false;
        }
    },

    // Tab seals the SN + MAC pair as one unit and moves on to the next one.
    scanMac() {
        if (this.pending === null) {
            return;
        }
        const mac = this.mac.toUpperCase().replace(/[^0-9A-F]/g, '');
        if (mac && mac.length !== 12) {
            this.mac = '';
            this.error = 'MAC harus 12 digit hex, scan ulang MAC-nya';
            this.beep(false);
            this.$nextTick(() => this.$refs.mac?.focus());

            return;
        }
        if (mac && this.units.some((unit) => unit.mac === mac)) {
            this.mac = '';
            this.error = `MAC ${mac} sudah discan untuk unit lain`;
            this.beep(false);
            this.$nextTick(() => this.$refs.mac?.focus());

            return;
        }
        this.units.unshift({ sn: this.pending, mac, item: '' });
        this.pending = null;
        this.mac = '';
        this.resetSn();
        this.error = '';
        this.beep(true);
        this.focusSn();
    },

    // Tab on the SN box (typed by hand) checks it like a scan; an empty box lets Tab move on as usual.
    tabSn(event) {
        if (event.shiftKey || !this.sn.trim()) {
            return;
        }
        event.preventDefault();
        this.scanSn();
    },

    tabMac(event) {
        if (event.shiftKey) {
            return;
        }
        event.preventDefault();
        this.scanMac();
    },

    cancelPending() {
        this.pending = null;
        this.mac = '';
        this.resetSn();
        this.focusSn();
    },

    remove(index) {
        this.units.splice(index, 1);
        this.focusSn();
    },

    clearAll() {
        if (!window.confirm(`Hapus semua ${this.units.length} unit yang sudah discan? Unit harus discan ulang.`)) {
            return;
        }
        this.units = [];
        this.focusSn();
    },

    fail(message) {
        this.error = message;
        this.beep(false);
        this.focusSn();
    },

    // A short tone tells the person scanning whether the scan went in without looking at the screen.
    beep(ok) {
        try {
            const context = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = context.createOscillator();
            oscillator.frequency.value = ok ? 880 : 220;
            oscillator.connect(context.destination);
            oscillator.start();
            oscillator.stop(context.currentTime + (ok ? 0.08 : 0.3));
        } catch {
            // No audio available; the on-screen list is enough.
        }
    },
});
