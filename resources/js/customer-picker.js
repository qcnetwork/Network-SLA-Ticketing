/**
 * Customer lookup on the new-ticket form. Searching by ID, name or WhatsApp number and picking a
 * customer fills their details; typing a known ID straight into the ID field fills whatever is still
 * empty. The picked customer's ticket count and any still-open ticket are shown, so CS can spot a
 * duplicate complaint before saving.
 */
export default (url, initial = null) => ({
    query: '',
    results: [],
    open: false,
    active: 0,
    loading: false,
    picked: initial,
    timer: null,

    onInput() {
        this.open = true;
        this.active = 0;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.search(), 250);
    },

    async search() {
        const term = this.query.trim();
        if (term.length < 2) {
            this.results = [];

            return;
        }
        this.loading = true;
        try {
            this.results = await this.fetch(term);
        } finally {
            this.loading = false;
        }
    },

    async fetch(term) {
        const response = await fetch(`${url}?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } });

        return response.ok ? response.json() : [];
    },

    move(step) {
        const count = this.results.length;
        if (count) {
            this.open = true;
            this.active = (this.active + step + count) % count;
        }
    },

    /**
     * Enter takes the highlighted match; it never submits the form from the search box.
     */
    accept() {
        const match = this.open && this.results[this.active];
        if (match) {
            this.choose(match);
        }
    },

    choose(customer) {
        this.fill(customer, true);
        this.query = '';
        this.results = [];
        this.open = false;
    },

    /**
     * A known ID typed by hand: fill what CS has not typed yet, never overwrite it.
     */
    async lookupId() {
        const code = this.$refs.id.value.trim();
        if (!code || code.toLowerCase() === this.picked?.code.toLowerCase()) {
            return;
        }
        const exact = (await this.fetch(code)).find((customer) => customer.code.toLowerCase() === code.toLowerCase());
        if (exact) {
            this.fill(exact, false);
        } else {
            this.picked = null;
        }
    },

    fill(customer, overwrite) {
        const set = (ref, value) => {
            if (value && (overwrite || !this.$refs[ref].value.trim())) {
                this.$refs[ref].value = value;
            }
        };
        this.$refs.id.value = customer.code;
        set('name', customer.name);
        set('phone', customer.phone);
        set('address', customer.address);
        this.picked = customer;
    },

    clear() {
        this.picked = null;
        for (const ref of ['id', 'name', 'phone', 'address']) {
            this.$refs[ref].value = '';
        }
        this.$refs.search.focus();
    },
});
