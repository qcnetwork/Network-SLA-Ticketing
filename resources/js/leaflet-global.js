/**
 * leaflet.heat extends a global `L`, so Leaflet has to be on window before the plugin loads.
 * ES module imports run in order, which is why this sits in its own module.
 */
import L from 'leaflet';

window.L = L;

export default L;
