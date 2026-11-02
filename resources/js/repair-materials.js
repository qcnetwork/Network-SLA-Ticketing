/**
 * Material lines on the repair resume. Each line picks a crew member's warehouse and then only what
 * that warehouse holds; an SN-tracked item (a modem) picks one of the units in that warehouse instead
 * of a quantity, and a unit already picked on another line is not offered again.
 */
export default ({ lines = [], technicians = [], stock = {}, defaultTechnician = '' }) => ({
    lines,
    technicians,
    stock,

    add() {
        this.lines.push({ technician_id: defaultTechnician, code: '', quantity: 1, serial: '', notes: '' });
    },

    itemsOf(line) {
        return this.stock[line.technician_id] || [];
    },

    itemOf(line) {
        return this.itemsOf(line).find((item) => item.code === line.code);
    },

    isSerial(line) {
        return Boolean(this.itemOf(line)?.serial);
    },

    unitsFor(line, index) {
        const taken = this.lines.filter((other, i) => i !== index).map((other) => other.serial);

        return (this.itemOf(line)?.units || []).filter((unit) => !taken.includes(unit.sn));
    },

    itemLabel(item) {
        return `${item.code} · ${item.name} (sisa ${item.quantity}${item.serial ? ' unit' : ''})`;
    },

    technicianLabel(crew) {
        return `${crew.name} (${(this.stock[crew.id] || []).length} jenis barang)`;
    },
});
