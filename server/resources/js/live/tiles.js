/**
 * TILES, COUNTERS AND BADGES (M11 Part C, design §4.4).
 *
 *   GET /live/tiles?keys[]=duress_open&keys[]=open_maintenance
 *   → {"tiles": {"duress_open": 2, "open_maintenance": 14}}
 *
 * Each key is a tile the server registers, with its own gate and an audience key, and the
 * server caches it per audience. A tile event (`TileLive`, entity `tile`, id `<tile key>`)
 * says only that a count moved, never which record moved it; the tile is then asked for
 * again here. A key the viewer may not see comes back missing, and is left as it was.
 */
import { getJson, withQuery } from './rows.js'

export const TILES_URL = '/live/tiles'

/** @returns {Promise<Record<string, unknown>|null>} the tiles answered, or null on failure */
export async function fetchTiles(keys) {
    const wanted = [...new Set((keys ?? []).map(String))]

    if (wanted.length === 0) {
        return {}
    }

    const answer = await getJson(withQuery(TILES_URL, { 'keys[]': wanted }))

    if (!answer.ok || !answer.body || typeof answer.body.tiles !== 'object' || answer.body.tiles === null) {
        return null
    }

    const tiles = {}

    for (const key of wanted) {
        if (Object.prototype.hasOwnProperty.call(answer.body.tiles, key)) {
            tiles[key] = answer.body.tiles[key]
        }
    }

    return tiles
}
