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

    document.querySelectorAll('[data-hotspot]').forEach((button) => {
        button.addEventListener('click', () => {
            map.flyTo([Number(button.dataset.lat), Number(button.dataset.lng)], 16);
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });
}
