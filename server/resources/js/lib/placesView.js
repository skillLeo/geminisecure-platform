/**
 * Whether a point is a place on the map. A missing number is not 0 (`Number(null)` is): an unplaced checkpoint must
 * never be drawn, or opened on, in the sea off West Africa. 0, 0 itself is what an empty field becomes, never a gate.
 */
export function isPlaced(p) {
    if (p?.lat === null || p?.lat === undefined || p?.lng === null || p?.lng === undefined || p.lat === '' || p.lng === '') return false

    const lat = Number(p.lat)
    const lng = Number(p.lng)

    return Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 && !(Math.abs(lat) < 0.0001 && Math.abs(lng) < 0.0001)
}

/**
 * WHERE THE PLACES MAP OPENS (M30 §7.3): never on the world.
 *
 * On whatever is placed: one thing, close in on it; several, all of them in view. Nothing placed: `home`, a city at a
 * city's zoom (Kingston). Never a continent, never the globe.
 *
 * @returns {{center: [number, number], zoom: number} | {bounds: [number, number][]}}
 */
export function openingView(points, home) {
    const placed = points.filter(isPlaced)

    if (placed.length === 1) {
        return { center: [Number(placed[0].lat), Number(placed[0].lng)], zoom: 17 }
    }

    if (placed.length > 1) {
        return { bounds: placed.map((p) => [Number(p.lat), Number(p.lng)]) }
    }

    return { center: [Number(home.lat), Number(home.lng)], zoom: Number(home.zoom) || 12 }
}
