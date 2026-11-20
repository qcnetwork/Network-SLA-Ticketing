/**
 * Pick SN units from a list instead of knowing or scanning each one. Sits inside a stockForm: when the picked
 * item tracks SN, the units of that item in the warehouse are fetched (oldest first) and shown as a checklist.
 * The box above the list filters by SN or MAC; Enter on an exact SN (a scanner works too) ticks that unit.
 * "Ambil N" ticks the N oldest units not yet ticked, handy for rolls or poles where any unit will do.
 * Ticked units post as serials[i][sn].
 */
const clean = (text) => String(text || '').toUpperCase().replace(/\s+/g, '');

export default ({ url, warehouse }) => ({
    units: [],
    selected: [],
    filter: '',
    take: '',
    loading: false,
    error: '',
    loadedFor: null,

    // Called from x-effect whenever the parent form's item changes.
    async load(item) {
        const code = item?.serial ? item.code : null;
        if (code === this.loadedFor) {
            return;
        }
        this.loadedFor = code;
        this.units = [];
        this.selected = [];
        this.filter = '';
        this.error = '';
        if (!code) {
            return;
        }
        this.loading = true;
        try {
            const response = await fetch(`${url}?item=${encodeURIComponent(code)}&warehouse=${encodeURIComponent(warehouse)}`, { headers: { Accept: 'application/json' } });
            if (this.loadedFor === code) {
                this.units = response.ok ? await response.json() : [];
                if (!response.ok) {
                    this.error = 'Gagal memuat daftar SN, coba muat ulang halaman';
                }
            }
        } finally {
            this.loading = false;
        }
    },

    get shown() {
        const needle = clean(this.filter).replace(/[:-]/g, '');
        if (!needle) {
            return this.units;
        }

        return this.units.filter((unit) => unit.sn.includes(needle) || clean(unit.mac).replace(/:/g, '').includes(needle));
    },

    isChecked(unit) {
        return this.selected.includes(unit.sn);
    },

    toggle(unit) {
        this.error = '';
        this.selected = this.isChecked(unit) ? this.selected.filter((sn) => sn !== unit.sn) : [...this.selected, unit.sn];
    },

    // Enter in the filter box: an exact SN (typed or scanned) or the only match left is ticked.
    scan() {
        const sn = clean(this.filter);
        if (!sn) {
            return;
        }
        const unit = this.units.find((candidate) => candidate.sn === sn) || (this.shown.length === 1 ? this.shown[0] : null);
        if (!unit) {
            this.error = `SN ${sn} tidak ada di daftar stok barang ini`;

            return;
        }
        if (!this.isChecked(unit)) {
            this.selected = [...this.selected, unit.sn];
        }
        this.error = '';
        this.filter = '';
    },

    selectShown() {
        this.selected = [...new Set([...this.selected, ...this.shown.map((unit) => unit.sn)])];
    },

    takeOldest() {
        const count = parseInt(this.take, 10);
        if (!(count > 0)) {
            return;
        }
        const free = this.units.filter((unit) => !this.isChecked(unit)).slice(0, count);
        this.selected = [...this.selected, ...free.map((unit) => unit.sn)];
        this.take = '';
    },

    clearAll() {
        this.selected = [];
    },
});
