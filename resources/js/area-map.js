/**
 * Map picker for ICP radius searches (wizard "Where" step).
 * Leaflet is loaded on demand so the rest of the app does not pay for it.
 * Saved areas live in Livewire ($wire.draft.areas); only the pending circle is local state.
 */
/** Circle colors follow the app theme (see the light tokens in app.css). */
function themeColor(token, fallback) {
    return getComputedStyle(document.documentElement).getPropertyValue(token).trim() || fallback;
}

let leaflet = null;

async function loadLeaflet() {
    if (!leaflet) {
        const [module] = await Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')]);
        leaflet = module.default;
    }

    return leaflet;
}

export default function areaMap({ center, minRadius, maxRadius, tiles }) {
    // Leaflet objects stay outside Alpine's reactive proxy.
    let map = null;
    let savedLayer = null;
    let pendingCircle = null;
    let pendingDot = null;

    return {
        ready: false,
        pending: null,
        radius: 5,
        label: '',
        minRadius,
        maxRadius,
        areas: [],

        async init() {
            const L = await loadLeaflet();

            map = L.map(this.$refs.map, { zoomControl: true, attributionControl: true })
                .setView([center.lat, center.lng], center.zoom);
            // Tile provider comes from config('services.map_tiles').
            L.tileLayer(tiles.url, { attribution: tiles.attribution, maxZoom: 19 }).addTo(map);
            savedLayer = L.layerGroup().addTo(map);

            map.on('click', (event) => this.pick(event.latlng.lat, event.latlng.lng, ''));

            this.$watch('radius', () => this.drawPending());

            this.ready = true;
            this.drawAreas(this.areas, true);
        },

        destroy() {
            map?.remove();
            map = null;
        },

        /** Called by x-effect whenever Livewire's draft.areas changes. */
        drawAreas(areas, fit = false) {
            this.areas = Array.isArray(areas) ? areas : [];

            if (!map) {
                return;
            }

            savedLayer.clearLayers();

            const signal = themeColor('--color-signal', '#d7ff3c');

            this.areas.forEach((area) => {
                leaflet.circle([area.lat, area.lng], {
                    radius: area.radius_km * 1000,
                    color: signal,
                    weight: 1.5,
                    fillColor: signal,
                    fillOpacity: 0.12,
                }).bindTooltip(area.label || `${area.lat.toFixed(4)}, ${area.lng.toFixed(4)}`).addTo(savedLayer);
            });

            if (fit && this.areas.length) {
                map.fitBounds(savedLayer.getLayers().reduce((b, c) => b.extend(c.getBounds()), leaflet.latLngBounds([])), { padding: [24, 24] });
            }
        },

        pick(lat, lng, label) {
            this.pending = { lat, lng };
            this.label = label || this.label;
            this.drawPending(true);
        },

        drawPending(fly = false) {
            if (!map || !this.pending) {
                return;
            }

            const point = [this.pending.lat, this.pending.lng];
            const meters = Math.min(maxRadius, Math.max(minRadius, Number(this.radius))) * 1000;

            const foreground = themeColor('--color-white', '#ffffff');
            const signal = themeColor('--color-signal', '#d7ff3c');

            pendingCircle ??= leaflet.circle(point, { color: foreground, weight: 1.5, dashArray: '4 4', fillOpacity: 0.06 }).addTo(map);
            pendingDot ??= leaflet.circleMarker(point, { radius: 4, color: foreground, weight: 2, fillColor: signal, fillOpacity: 1 }).addTo(map);

            pendingCircle.setLatLng(point).setRadius(meters);
            pendingDot.setLatLng(point);

            if (fly) {
                map.flyToBounds(pendingCircle.getBounds(), { padding: [24, 24], duration: 0.6 });
            }
        },

        clearPending() {
            pendingCircle?.remove();
            pendingDot?.remove();
            pendingCircle = pendingDot = null;
            this.pending = null;
            this.label = '';
        },

        async add() {
            if (!this.pending) {
                return;
            }

            // addArea() returns true once the area is saved; on a validation error keep the selection.
            if (await this.$wire.addArea(this.pending.lat, this.pending.lng, Number(this.radius), this.label)) {
                this.clearPending();
            }
        },

        focus(index) {
            const area = this.areas[index];

            if (map && area) {
                map.flyTo([area.lat, area.lng], map.getZoom() < 11 ? 12 : map.getZoom(), { duration: 0.6 });
            }
        },
    };
}
