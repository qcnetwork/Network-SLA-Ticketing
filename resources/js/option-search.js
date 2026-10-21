/**
 * Searchable pick-list that only accepts one of its options (e.g. the complaint category).
 * Typing filters and ranks the options, Tab or Enter takes the best match, and anything that
 * does not match an option is rejected, so the hidden input only ever holds a listed value.
 */
const normalize = (text) => text.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

/**
 * How well an option matches the typed text; 0 means it does not match at all.
 */
const score = (option, query) => {
    const name = normalize(option);
    if (!query) {
        return 1;
    }
    if (name === query) {
        return 1000;
    }
    if (name.startsWith(query)) {
        return 500;
    }
    if (name.split(' ').some((word) => word.startsWith(query))) {
        return 300;
    }
    if (name.includes(query)) {
        return 200;
    }
    // Every typed word somewhere in the name, in any order ("putus kabel").
    if (query.split(' ').every((word) => name.includes(word))) {
        return 150;
    }
    // Letters in order with gaps, for abbreviations like "los mrh"; tighter matches rank higher.
    let at = -1;
    for (const char of query.replace(/ /g, '')) {
        at = name.indexOf(char, at + 1);
        if (at === -1) {
            return 0;
        }
    }

    return 100 - Math.min(at, 99);
};

export default (options, selected = '', message = 'Pilih dari daftar yang tersedia') => ({
    options,
    value: options.includes(selected) ? selected : '',
    query: options.includes(selected) ? selected : '',
    open: false,
    active: 0,
    error: '',

    init() {
        // The hidden input is not checked by the browser's own "required", and the visible text may not
        // be a chosen option yet (Enter pressed on a typo), so guard the submit here.
        this.$el.closest('form')?.addEventListener('submit', (event) => {
            this.leave();
            // A reverted typo (error set) must not quietly submit the previous choice.
            if (!this.value || this.error) {
                event.preventDefault();
                this.error = message;
                this.$refs.input.focus();
            }
        });
    },

    get matches() {
        const query = normalize(this.query === this.value ? '' : this.query);

        return this.options
            .map((option) => ({ option, score: score(option, query) }))
            .filter((match) => match.score > 0)
            .sort((a, b) => b.score - a.score)
            .map((match) => match.option);
    },

    onInput() {
        this.open = true;
        this.active = 0;
        this.error = '';
    },

    move(step) {
        this.open = true;
        const count = this.matches.length;
        if (count) {
            this.active = (this.active + step + count) % count;
            this.$nextTick(() => this.$refs.list?.children[this.active]?.scrollIntoView({ block: 'nearest' }));
        }
    },

    /**
     * Enter and Tab take the highlighted match. Enter must not submit the form while the list is open.
     */
    accept(event) {
        if (!this.open || this.query === this.value) {
            return;
        }
        const match = this.matches[this.active];
        if (match) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
            this.choose(match);
        }
    },

    choose(option) {
        this.value = option;
        this.query = option;
        this.open = false;
        this.error = '';
    },

    close() {
        this.open = false;
    },

    /**
     * Leaving the field keeps only a real option: an exact (case-insensitive) match is taken, a cleared
     * field clears the choice, and anything else goes back to the last valid choice.
     */
    leave() {
        this.open = false;
        if (this.query === this.value) {
            return;
        }
        const exact = this.options.find((option) => normalize(option) === normalize(this.query));
        if (exact) {
            this.choose(exact);
        } else if (this.query.trim() === '') {
            this.value = '';
            this.query = '';
        } else {
            this.query = this.value;
            this.error = message;
        }
    },
});
