/**
 * Material lines on the repair resume. Each line picks a technician's warehouse and then only what
 * that warehouse holds (by name, without quantities); an SN-tracked item (a modem) picks one of the
 * units in that warehouse instead of a quantity, and a unit already picked on another line is not
 * offered again. A technician's SNs are fetched the first time one of their lines needs them.
 */
export default ({ lines = [], technicians = [], stock = {}, defaultTechnician = '', unitsUrl = '' }) => ({
    lines,
    technicians,
    stock,
    units: {},
    loading: {},

    init() {
        // Lines brought back after a failed submit still need their technician's SNs.
        new Set(this.lines.map((line) => line.technician_id).filter(Boolean)).forEach((id) => this.loadUnits(id));
    },

    add() {
        this.lines.push({ technician_id: defaultTechnician, code: '', quantity: 1, serial: '', notes: '' });
        this.loadUnits(defaultTechnician);
    },

    async loadUnits(technicianId) {
        if (!technicianId || this.units[technicianId] || this.loading[technicianId]) {
            return;
        }
        this.loading[technicianId] = true;
        try {
            const response = await fetch(`${unitsUrl}?technician=${encodeURIComponent(technicianId)}`, { headers: { Accept: 'application/json' } });
            if (response.ok) {
                this.units[technicianId] = await response.json();
            }
        } finally {
            this.loading[technicianId] = false;
        }
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

        return (this.units[line.technician_id]?.[line.code] || []).filter((unit) => !taken.includes(unit.sn));
    },

    itemLabel(item) {
        return `${item.code} · ${item.name}`;
    },

    technicianLabel(crew) {
        return crew.name;
    },
});
