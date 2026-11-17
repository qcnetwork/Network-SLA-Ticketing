/**
 * A warehouse stock form that starts by picking an item. Picking decides how the rest is filled in:
 * an SN-tracked item (every device category) switches the form to scanning each unit and posts to
 * `serialAction`; anything else asks for a quantity and posts to `countAction`.
 *
 * `catalog` rows are {code, name, unit, serial, category, icon, held}. With `countedOnly`, SN items are
 * left out (they move by scanning alone); with `inStockOnly`, items the warehouse does not hold are too.
 */
const normalize = (text) => String(text).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

export default ({ catalog, serialAction = '', countAction = '', selected = '', countedOnly = false, inStockOnly = false }) => ({
    catalog: catalog.filter((item) => (!countedOnly || !item.serial) && (!inStockOnly || item.held > 0)),
    item: null,
    query: '',
    open: false,
    active: 0,
    quantity: '',

    init() {
        const code = String(selected).split('·')[0].trim().toUpperCase();
        this.item = this.catalog.find((item) => item.code === code) || null;
    },

    get matches() {
        const words = normalize(this.query).split(' ').filter(Boolean);

        return this.catalog
            .filter((item) => {
                const text = normalize(`${item.code} ${item.name} ${item.category}`);

                return words.every((word) => text.includes(word));
            })
            .slice(0, 60);
    },

    get isSerial() {
        return Boolean(this.item?.serial);
    },

    get action() {
        return this.isSerial ? serialAction : countAction;
    },

    // A category header is shown above the first match of each category.
    startsGroup(index) {
        return index === 0 || this.matches[index - 1].category !== this.matches[index].category;
    },

    onInput() {
        this.open = true;
        this.active = 0;
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
        const match = this.matches[this.active];
        if (this.open && match) {
            this.choose(match);
        }
    },

    choose(item) {
        this.item = item;
        this.query = '';
        this.open = false;
        this.quantity = '';
        this.$nextTick(() => {
            window.renderIcons?.();
            (this.isSerial ? this.$root.querySelector('[x-ref="sn"]') : this.$refs.quantity)?.focus();
        });
    },

    clear() {
        this.item = null;
        this.$nextTick(() => this.$refs.search?.focus());
    },
});
