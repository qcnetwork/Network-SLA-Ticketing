/**
 * Scanning serial numbers with a barcode scanner. A USB or Bluetooth scanner types the code and
 * presses Enter like a keyboard, so Enter here adds the scan to the list instead of submitting the
 * form. With `withMac`, each SN is followed by a scan of its MAC label before the unit is added.
 * Every SN is checked with the server as it is scanned (`lookupUrl` plus `query`), so a unit that is
 * already registered, or sitting in another warehouse, is flagged at the scanner rather than on save.
 */
export default ({ lookupUrl, query = {}, withMac = false }) => ({
    units: [],
    sn: '',
    mac: '',
    pending: null,
    error: '',
    checking: false,

    normalize(value) {
        return value.trim().toUpperCase().replace(/\s+/g, '');
    },

    focusSn() {
        this.$nextTick(() => this.$refs.sn?.focus());
    },

    async scanSn() {
        const sn = this.normalize(this.sn);
        this.sn = '';
        this.error = '';
        if (!sn || this.checking) {
            return;
        }
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
            if (withMac) {
                this.pending = sn;
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

    scanMac() {
        const mac = this.mac.toUpperCase().replace(/[^0-9A-F]/g, '');
        this.mac = '';
        if (mac && mac.length !== 12) {
            this.fail('MAC harus 12 digit hex, scan ulang MAC-nya');
            this.$nextTick(() => this.$refs.mac?.focus());

            return;
        }
        if (mac && this.units.some((unit) => unit.mac === mac)) {
            this.fail(`MAC ${mac} sudah discan untuk unit lain`);
            this.$nextTick(() => this.$refs.mac?.focus());

            return;
        }
        this.units.unshift({ sn: this.pending, mac, item: '' });
        this.pending = null;
        this.error = '';
        this.beep(true);
        this.focusSn();
    },

    cancelPending() {
        this.pending = null;
        this.mac = '';
        this.focusSn();
    },

    remove(index) {
        this.units.splice(index, 1);
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
