/**
 * Material on the repair resume, grouped by whose stock it comes from: pick a warehouse once ("Stok Esa"),
 * then add as many items from it as needed. Taking from another technician ("Stok Fadil") opens a second group;
 * a warehouse appears in one group only. Each item lists only what that warehouse holds (by name, without
 * quantities); an SN-tracked item (a modem, a pole) picks one of the warehouse's units instead of a quantity,
 * and a unit already picked on another line is not offered again. A technician's SNs are fetched the first
 * time a group needs them. The form still posts one flat row per item: materials[n][technician_id, code, ...].
 * A group's warehouse is picked by typing part of the holder's name (arrows + Enter, or click).
 */
const normalize = (text) => String(text).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
// Each group and line gets its own key, so removing one never shifts what the others show.
let nextKey = 0;
const blankLine = () => ({ key: ++nextKey, code: '', quantity: 1, serial: '', notes: '' });

export default ({ lines = [], technicians = [], stock = {}, defaultTechnician = '', unitsUrl = '' }) => ({
    groups: [],
    technicians,
    stock,
    units: {},
    loading: {},

    init() {
        // Lines brought back after a failed submit are regrouped by warehouse, in the order they came.
        lines.forEach((line) => {
            const id = String(line.technician_id ?? '');
            let group = this.groups.find((g) => g.technician_id === id);
            if (!group) {
                group = { key: ++nextKey, technician_id: id, lines: [], query: '', open: false, active: 0 };
                this.groups.push(group);
            }
            group.lines.push({ key: ++nextKey, code: line.code ?? '', quantity: line.quantity ?? 1, serial: line.serial ?? '', notes: line.notes ?? '' });
        });
        this.groups.forEach((group) => this.loadUnits(group.technician_id));
    },

    // Warehouses not yet used by another group (the group's own one stays pickable).
    freeTechnicians(group = null) {
        const used = this.groups.filter((g) => g !== group).map((g) => g.technician_id);

        return this.technicians.filter((crew) => !used.includes(String(crew.id)));
    },

    addGroup() {
        const free = this.freeTechnicians();
        if (!free.length) {
            return;
        }
        const preferred = free.find((crew) => String(crew.id) === String(defaultTechnician)) || free[0];
        const id = this.groups.length ? '' : String(preferred.id);
        const group = { key: ++nextKey, technician_id: id, lines: [blankLine()], query: '', open: false, active: 0 };
        this.groups.push(group);
        this.loadUnits(id);
        // Another technician's stock: go straight to searching for whose.
        if (!id) {
            this.$nextTick(() => this.$root.querySelector(`[data-tech-search="${group.key}"]`)?.focus());
        }
    },

    techMatches(group) {
        const words = normalize(group.query).split(' ').filter(Boolean);

        return this.freeTechnicians(group).filter((crew) => words.every((word) => normalize(crew.name).includes(word)));
    },

    stockCount(crew) {
        return (this.stock[crew.id] || []).length;
    },

    openTech(group) {
        group.open = true;
        group.active = 0;
    },

    closeTech(group) {
        group.open = false;
        group.query = '';
    },

    moveTech(group, step) {
        if (!group.open) {
            this.openTech(group);
            return;
        }
        const count = this.techMatches(group).length;
        if (count) {
            group.active = (group.active + step + count) % count;
        }
    },

    // Enter takes the highlighted holder and must not submit the resume.
    acceptTech(group, event) {
        if (!group.open) {
            return;
        }
        event.preventDefault();
        const crew = this.techMatches(group)[group.active];
        if (crew) {
            this.pickTechnician(group, crew);
        }
    },

    pickTechnician(group, crew) {
        const id = String(crew.id);
        if (group.technician_id !== id) {
            group.technician_id = id;
            this.changeTechnician(group);
        }
        this.closeTech(group);
    },

    removeGroup(index) {
        this.groups.splice(index, 1);
    },

    changeTechnician(group) {
        group.lines = group.lines.map(() => blankLine());
        this.loadUnits(group.technician_id);
    },

    addLine(group) {
        group.lines.push(blankLine());
    },

    removeLine(groupIndex, lineIndex) {
        const group = this.groups[groupIndex];
        group.lines.splice(lineIndex, 1);
        if (!group.lines.length) {
            this.groups.splice(groupIndex, 1);
        }
    },

    // The posted row number of a line: lines are numbered straight through every group.
    rowIndex(groupIndex, lineIndex) {
        return this.groups.slice(0, groupIndex).reduce((sum, group) => sum + group.lines.length, 0) + lineIndex;
    },

    get lineCount() {
        return this.groups.reduce((sum, group) => sum + group.lines.length, 0);
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

    itemsOf(group) {
        return this.stock[group.technician_id] || [];
    },

    itemOf(group, line) {
        return this.itemsOf(group).find((item) => item.code === line.code);
    },

    isSerial(group, line) {
        return Boolean(this.itemOf(group, line)?.serial);
    },

    unitsFor(group, line) {
        const taken = group.lines.filter((other) => other !== line).map((other) => other.serial);

        return (this.units[group.technician_id]?.[line.code] || []).filter((unit) => !taken.includes(unit.sn));
    },

    itemLabel(item) {
        return `${item.code} · ${item.name}`;
    },

    technicianLabel(crew) {
        return crew.name;
    },

    technicianName(group) {
        return this.technicians.find((crew) => String(crew.id) === String(group.technician_id))?.name || '';
    },
});
