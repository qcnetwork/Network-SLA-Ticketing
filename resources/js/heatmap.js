/**
 * Complaint map on the reports page: a marker per customer location (tikor) showing how many
 * complaints came from it, with a popup listing who complained, and the busiest areas drawn as
 * the ±1 km grid cells the list below counts (an area's number is the sum of its markers).
 * Picking an area in the list zooms to its cell and fades the markers outside it; a customer
 * name flies to their tikor. Loaded only on that page.
 */
import 'leaflet/dist/leaflet.css';
import L from 'leaflet';

const element = document.getElementById('complaint-map');
const data = JSON.parse(document.getElementById('complaint-map-data')?.textContent || '{}');

const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]));

// 1 complaint amber, 2–3 orange, 4+ red: repeat complainers stand out at a glance.
const tone = (count) => (count >= 4 ? '#e11d48' : count >= 2 ? '#f97316' : '#f59e0b');

function icon(count) {
    const size = count >= 10 ? 34 : count >= 4 ? 30 : 26;

    return L.divIcon({
        className: '',
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
        popupAnchor: [0, -size / 2],
        html: `<div style="width:${size}px;height:${size}px;background:${tone(count)};border:2px solid #fff;border-radius:9999px;box-shadow:0 1px 4px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;color:#fff;font:700 12px/1 system-ui,sans-serif">${count}</div>`,
    });
}

function popup(marker) {
    const ticketUrl = (number) => data.ticketUrl.replace('__TICKET__', encodeURIComponent(number));
    const customers = marker.customers.map((customer) => {
        const more = customer.count - customer.tickets.length;
        const tickets = customer.tickets.map((ticket) => `
            <li><a href="${escape(ticketUrl(ticket.number))}" style="font-family:monospace">${escape(ticket.number)}</a>
                · ${escape(ticket.category)} · ${escape(ticket.date)}</li>`).join('');

        return `
            <div style="margin-top:6px">
                <div style="display:flex;justify-content:space-between;gap:8px;font-weight:600">
                    <span>${escape(customer.name)}${customer.customer_id ? ` <span style="font-weight:400;color:#64748b">(${escape(customer.customer_id)})</span>` : ''}</span>
                    <span style="color:${tone(customer.count)};white-space:nowrap">${customer.count}× komplain</span>
                </div>
                <ul style="margin:2px 0 0 12px;padding:0;list-style:disc;font-size:11px">${tickets}</ul>
                ${more > 0 ? `<div style="font-size:11px;color:#64748b">+${more} tiket lainnya</div>` : ''}
            </div>`;
    }).join('');

    return `
        <div style="min-width:220px;max-height:260px;overflow:auto">
            <div style="font-weight:700">${marker.count} komplain dari titik ini</div>
            ${marker.address ? `<div style="font-size:11px;color:#64748b">${escape(marker.address)}</div>` : ''}
            <div style="font-size:11px;color:#64748b">${marker.latitude}, ${marker.longitude}</div>
            ${customers}
        </div>`;
}

if (element && data.markers?.length) {
    const map = L.map(element, { scrollWheelZoom: false });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    const byPosition = new Map();
    const pins = [];
    data.markers.forEach((spot) => {
        const names = spot.customers.map((customer) => customer.name).join(', ');
        const marker = L.marker([spot.latitude, spot.longitude], { icon: icon(spot.count), zIndexOffset: spot.count * 10 })
            .bindTooltip(`${escape(names)} · ${spot.count} komplain`)
            .bindPopup(popup(spot), { maxWidth: 320 })
            .addTo(map);
        byPosition.set(`${spot.latitude},${spot.longitude}`, marker);
        pins.push(marker);
    });

    // The areas of the list, numbered as there. Dashed so the markers stay the main thing; they sit in
    // Leaflet's overlay pane, under the markers, so a marker on top of an area still opens its popup.
    const areaStyle = { color: '#be123c', weight: 2, dashArray: '6 4', fillColor: '#f43f5e', fillOpacity: 0.08 };
    const areas = (data.areas || []).map((area, index) => L.rectangle(area.bounds, areaStyle)
        .bindTooltip(`#${index + 1} · ${area.count} komplain · ${area.points} titik`, { direction: 'top', sticky: true })
        .on('click', () => focusArea(index))
        .addTo(map));

    let focused = null;
    function resetFocus() {
        focused = null;
        areas.forEach((rectangle) => rectangle.setStyle(areaStyle));
        pins.forEach((marker) => marker.setOpacity(1));
        document.querySelectorAll('[data-area]').forEach((button) => button.classList.remove('text-rose-700'));
    }
    function focusArea(index) {
        if (focused === index) {
            resetFocus();
            return;
        }
        resetFocus();
        focused = index;
        const bounds = areas[index].getBounds();
        areas[index].setStyle({ weight: 3, dashArray: null, fillOpacity: 0.12 });
        // Only the markers this area adds up stay bright.
        pins.forEach((marker) => marker.setOpacity(bounds.contains(marker.getLatLng()) ? 1 : 0.25));
        document.querySelector(`[data-area="${index}"]`)?.classList.add('text-rose-700');
        map.flyToBounds(bounds, { padding: [32, 32], maxZoom: 17 });
    }

    // Frame where the complaints are, leaving out pins the server flagged as mistyped (far off the area).
    const strays = new Set(data.strays || []);
    const framed = data.markers.filter((spot) => ! strays.has(`${spot.latitude},${spot.longitude}`));
    map.fitBounds(L.latLngBounds((framed.length ? framed : data.markers).map((spot) => [spot.latitude, spot.longitude])), { padding: [24, 24], maxZoom: 16 });

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

    // Area names light up their cell; customer names fly to their tikor and open its popup.
    document.querySelectorAll('[data-area]').forEach((button) => {
        button.addEventListener('click', () => {
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
            focusArea(Number(button.dataset.area));
        });
    });
    document.querySelectorAll('[data-hotspot]').forEach((button) => {
        button.addEventListener('click', () => {
            const position = [Number(button.dataset.lat), Number(button.dataset.lng)];
            const marker = byPosition.get(`${button.dataset.lat},${button.dataset.lng}`);
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (marker && marker.options.opacity < 1) resetFocus();
            map.flyTo(position, marker ? 17 : 16);
            if (marker) map.once('moveend', () => marker.openPopup());
        });
    });
}
