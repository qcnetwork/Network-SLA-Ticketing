/**
 * Quick SN search on the send form: type a few characters of the SN or MAC on the label (the last 4
 * digits are usually enough) and pick the unit, with no scanner and without picking its item first.
 * Searches every item's units in Gudang Utama; picking one hands it to the parent stockForm's
 * pickSerial, which picks the item and ticks the unit in its list.
 */
export default ({ url }) => ({
    q: '',
    results: [],
    open: false,
    active: 0,
    searching: false,
    searched: '',
    timer: null,

    onInput() {
        clearTimeout(this.timer);
        this.active = 0;
        if (this.needle().length < 3) {
            this.results = [];
            this.searched = '';
            this.open = false;

            return;
        }
        this.timer = setTimeout(() => this.search(), 200);
    },

    needle() {
        return this.q.toUpperCase().replace(/[\s:-]+/g, '');
    },

    async search() {
        const needle = this.needle();
        this.searching = true;
        try {
            const response = await fetch(`${url}?q=${encodeURIComponent(needle)}`, { headers: { Accept: 'application/json' } });
            if (needle === this.needle()) {
                this.results = response.ok ? await response.json() : [];
                this.searched = needle;
                this.open = true;
            }
        } finally {
            this.searching = false;
        }
    },

    move(step) {
        const count = this.results.length;
        if (count) {
            this.active = (this.active + step + count) % count;
        }
    },

    // Enter: the highlighted result, once results for what is typed are in.
    pickActive() {
        if (this.searched === this.needle() && this.results[this.active]) {
            this.pick(this.results[this.active]);
        }
    },

    pick(unit) {
        this.pickSerial(unit);
        this.q = '';
        this.results = [];
        this.searched = '';
        this.open = false;
        this.$nextTick(() => this.$refs.input?.focus());
    },

    // The typed part of the SN, bolded in the result list.
    parts(sn) {
        const needle = this.searched;
        const at = needle ? sn.lastIndexOf(needle) : -1;

        return at < 0 ? [sn, '', ''] : [sn.slice(0, at), needle, sn.slice(at + needle.length)];
    },
});
