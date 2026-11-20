/**
 * Searchable pick of one person (the technician or helpdesk receiving stock). Typing filters by name or role,
 * arrows + Enter or a click picks, Esc closes. The id posts through a hidden input; since the browser cannot
 * check a hidden "required", submitting without a pick is stopped here with a message.
 *
 * `people` rows are {id, name, role}.
 */
const normalize = (text) => String(text).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

export default ({ people, selected = '', message = 'Pilih penerima dulu' }) => ({
    people,
    picked: people.find((person) => String(person.id) === String(selected)) || null,
    query: '',
    open: false,
    active: 0,
    error: '',

    init() {
        this.$el.closest('form')?.addEventListener('submit', (event) => {
            if (!this.picked) {
                event.preventDefault();
                this.error = message;
                this.$refs.input.focus();
            }
        });
    },

    get matches() {
        const words = normalize(this.query).split(' ').filter(Boolean);

        return this.people.filter((person) => {
            const text = normalize(`${person.name} ${person.role}`);

            return words.every((word) => text.includes(word));
        });
    },

    // A role header is shown above the first match of each role.
    startsGroup(index) {
        return index === 0 || this.matches[index - 1].role !== this.matches[index].role;
    },

    onInput() {
        this.open = true;
        this.active = 0;
    },

    move(step) {
        if (!this.open) {
            this.open = true;

            return;
        }
        const count = this.matches.length;
        if (count) {
            this.active = (this.active + step + count) % count;
            this.$nextTick(() => this.$refs.list?.querySelector(`[data-index="${this.active}"]`)?.scrollIntoView({ block: 'nearest' }));
        }
    },

    // Enter takes the highlighted person and must not submit the form.
    pickActive() {
        const person = this.matches[this.active];
        if (this.open && person) {
            this.choose(person);
        }
    },

    choose(person) {
        this.picked = person;
        this.query = '';
        this.open = false;
        this.error = '';
    },

    clear() {
        this.picked = null;
        this.$nextTick(() => this.$refs.input?.focus());
    },
});
