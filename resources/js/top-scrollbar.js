/**
 * A horizontal scrollbar above a wide area (the kanban board), so it can be scrolled without
 * first going down to the bottom. The bar and the area move together; the bar hides itself
 * when everything already fits.
 */
export default () => ({
    width: 0,
    overflows: false,
    syncing: false,

    init() {
        this.measure();
        new ResizeObserver(() => this.measure()).observe(this.$refs.body);
        new ResizeObserver(() => this.measure()).observe(this.$refs.body.firstElementChild);
    },

    measure() {
        const body = this.$refs.body;
        this.width = body.scrollWidth;
        this.overflows = body.scrollWidth > body.clientWidth + 1;
    },

    /**
     * Mirror one side's position onto the other; the guard stops the echo from bouncing back.
     */
    sync(from) {
        if (this.syncing) {
            this.syncing = false;
            return;
        }
        const [source, target] = from === 'bar' ? [this.$refs.bar, this.$refs.body] : [this.$refs.body, this.$refs.bar];
        if (target.scrollLeft !== source.scrollLeft) {
            this.syncing = true;
            target.scrollLeft = source.scrollLeft;
        }
    },
});
