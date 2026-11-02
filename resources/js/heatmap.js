/**
 * Complaint heatmap on the reports page: a heat layer of every located ticket, a circle per
 * hotspot, and the hotspot list flies the map to its area. Loaded only on that page.
 */
import 'leaflet/dist/leaflet.css';
import L from './leaflet-global';
import 'leaflet.heat';

const element = document.getElementById('complaint-map');
const data = JSON.parse(document.getElementById('complaint-map-data')?.textContent || '{}');

if (element && data.points?.length) {
    const map = L.map(element, { scrollWheelZoom: false });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    L.heatLayer(data.points, { radius: 22, blur: 18, maxZoom: 15, minOpacity: 0.35 }).addTo(map);

    const busiest = data.hotspots[0]?.count || 1;
    data.hotspots.forEach((spot, index) => {
        L.circleMarker([spot.latitude, spot.longitude], {
            radius: 6 + (spot.count / busiest) * 10,
            color: '#be123c',
            weight: 2,
            fillColor: '#f43f5e',
            fillOpacity: 0.25,
        })
            .bindTooltip(`#${index + 1} · ${spot.count} komplain`)
            .addTo(map);
    });

    map.fitBounds(L.latLngBounds(data.points), { padding: [24, 24], maxZoom: 15 });

    // Ctrl + scroll zooms around the cursor; a plain scroll keeps scrolling the page and shows a hint.
    // Trackpad pinches arrive as ctrl + small wheel deltas, so they're summed into whole zoom steps.
    const hint = document.getElementById('complaint-map-hint');
    let hintTimer;
    let wheelDelta = 0;
    element.addEventListener('wheel', (event) => {
        if (!event.ctrlKey && !event.metaKey) {
            hint?.classList.replace('opacity-0', 'opacity-100');
            clearTimeout(hintTimer);
            hintTimer = setTimeout(() => hint?.classList.replace('opacity-100', 'opacity-0'), 1200);
            return;
        }

        event.preventDefault(); // stop the browser's own page zoom
        hint?.classList.replace('opacity-100', 'opacity-0');
        wheelDelta += event.deltaMode === WheelEvent.DOM_DELTA_LINE ? event.deltaY * 40 : event.deltaY;
        if (Math.abs(wheelDelta) < 60) return;

        const zoom = map.getZoom() - Math.sign(wheelDelta);
        wheelDelta = 0;
        map.setZoomAround(map.mouseEventToContainerPoint(event), zoom);
    }, { passive: false });

    document.querySelectorAll('[data-hotspot]').forEach((button) => {
        button.addEventListener('click', () => {
            map.flyTo([Number(button.dataset.lat), Number(button.dataset.lng)], 16);
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });
}
