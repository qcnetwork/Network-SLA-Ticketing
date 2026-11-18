import saleForm from './sale-form';

/**
 * The Pemakaian Alat form: the sale form's cart (scan a device, search material) without prices, drawing
 * on the warehouse picked in "Stok dari". Switching that warehouse empties the cart, since each warehouse
 * holds different stock and devices.
 */
export default ({ catalogs, source, lookupUrl, old = [] }) => {
    const base = saleForm({ catalog: catalogs[source] ?? [], lookupUrl, warehouseId: source, old });

    // defineProperties (not spread) keeps the base's getters live.
    return Object.defineProperties(base, Object.getOwnPropertyDescriptors({
        catalogs,
        source: String(source),

        switchSource() {
            if (this.lines.length && !window.confirm('Ganti sumber stok? Daftar barang yang sudah diisi akan dikosongkan.')) {
                this.$nextTick(() => {
                    this.source = String(this.warehouseId);
                });

                return;
            }
            this.warehouseId = Number(this.source);
            this.catalog = this.catalogs[this.source] ?? [];
            this.lines = [];
            this.scanError = '';
        },
    }));
};
