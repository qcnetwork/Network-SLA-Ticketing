/**
 * The sale form's cart. Material is picked from the main warehouse's stock and given a quantity;
 * a device is added by scanning its SN (checked with the server, so only units sitting in the main
 * warehouse go in), and its line counts the scanned units. Every line carries its own price.
 *
 * `catalog` rows are {code, name, unit, serial, category, held, price}; `price` is what it last sold for.
 */
const normalize = (text) => String(text).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
const rupiah = new Intl.NumberFormat('id-ID');

export default ({ catalog, lookupUrl, warehouseId, old = [] }) => ({
    catalog,
    warehouseId,
    lines: [],
    query: '',
    open: false,
    active: 0,
    sn: '',
    scanError: '',
    checking: false,

    init() {
        // Back from a failed save: rebuild the cart from what was posted.
        old.forEach((line) => {
            const item = this.find(line.item);
            if (item) {
                this.lines.push({
                    ...item,
                    quantity: item.serial ? 0 : Number(line.quantity) || 1,
                    price: line.price ?? '',
                    serials: Array.isArray(line.serials) ? line.serials : [],
                    notes: line.notes ?? '',
                });
            }
        });
    },

    find(code) {
        const wanted = String(code ?? '').split('·')[0].trim().toUpperCase();

        return this.catalog.find((item) => item.code === wanted) || null;
    },

    get matches() {
        const words = normalize(this.query).split(' ').filter(Boolean);

        return this.catalog
            .filter((item) => words.every((word) => normalize(`${item.code} ${item.name} ${item.category}`).includes(word)))
            .slice(0, 60);
    },

    get total() {
        return this.lines.reduce((sum, line) => sum + this.subtotal(line), 0);
    },

    count(line) {
        return line.serial ? line.serials.length : Number(line.quantity) || 0;
    },

    subtotal(line) {
        return this.count(line) * (Number(line.price) || 0);
    },

    money(amount) {
        return `Rp ${rupiah.format(amount || 0)}`;
    },

    startsGroup(index) {
        return index === 0 || this.matches[index - 1].category !== this.matches[index].category;
    },

    move(step) {
        this.open = true;
        const count = this.matches.length;
        if (count) {
            this.active = (this.active + step + count) % count;
            this.$nextTick(() => this.$refs.list?.querySelector(`[data-index="${this.active}"]`)?.scrollIntoView({ block: 'nearest' }));
        }
    },

    pickActive() {
        if (this.open && this.matches[this.active]) {
            this.add(this.matches[this.active]);
        }
    },

    /**
     * Put an item in the cart, or bring its line forward when it is already there.
     */
    add(item) {
        this.query = '';
        this.open = false;
        let line = this.lines.find((other) => other.code === item.code);
        if (!line) {
            line = { ...item, quantity: item.serial ? 0 : 1, price: item.price ?? '', serials: [], notes: '' };
            this.lines.push(line);
        }
        this.$nextTick(() => {
            window.renderIcons?.();
            // A device is counted by scanning, so the cursor goes back to the scan box.
            const target = item.serial ? this.$refs.scan : this.$root.querySelector(`[data-line="${item.code}"] [data-quantity]`);
            target?.focus();
        });

        return line;
    },

    remove(index) {
        this.lines.splice(index, 1);
    },

    removeSerial(line, sn) {
        line.serials = line.serials.filter((other) => other !== sn);
    },

    /**
     * Scan any device: the server says which item it is and confirms it is in the main warehouse.
     */
    async scan(value = this.sn) {
        const sn = String(value).trim().toUpperCase().replace(/\s+/g, '');
        this.sn = '';
        this.scanError = '';
        if (!sn || this.checking) {
            return;
        }
        if (this.lines.some((line) => line.serials.includes(sn))) {
            this.fail(`SN ${sn} sudah ada di daftar`);

            return;
        }

        this.checking = true;
        try {
            const params = new URLSearchParams({ sn, warehouse: this.warehouseId });
            const response = await fetch(`${lookupUrl}?${params}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok) {
                this.fail(data.message || `SN ${sn} ditolak`);

                return;
            }
            const item = this.find(data.item);
            if (!item) {
                this.fail(`SN ${sn} (${data.item}) tidak ada di stok gudang ini`);

                return;
            }
            const line = this.add(item);
            line.serials.push(data.sn || sn);
            this.$nextTick(() => this.$refs.scan?.focus());
        } catch {
            this.fail('Gagal mengecek SN, coba scan lagi');
        } finally {
            this.checking = false;
        }
    },

    fail(message) {
        this.scanError = message;
        this.$nextTick(() => this.$refs.scan?.focus());
    },
});
