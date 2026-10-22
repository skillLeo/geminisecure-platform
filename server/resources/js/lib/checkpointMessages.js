/**
 * THE INSTALLERS' MESSAGES, MATCHED TO THE ESTATE'S CHECKPOINTS (M27 §4.3, the coordinate route).
 *
 * Whoever mounts a tag stands at the exact spot, so they send its name and the two numbers from Google Maps, one
 * message a tag, as GATE_POSITIONS.md says: "Basketball court, 18.01234, -76.95678". The Director pastes the lot into
 * Sites & geofences. Each line is matched here to the checkpoint whose name it carries (the longest name that fits,
 * so "Pool deck gate" is not taken for "Pool deck") and its position read by the same rule as a gate's
 * (`parsePosition`). Nothing is saved from this: the page shows every line and what it was read as, and only the
 * lines the Director confirms are sent.
 *
 *   ok          the line names one checkpoint and holds one position
 *   no_match    no checkpoint's name is in the line
 *   ambiguous   two checkpoints' names fit it equally well
 *   no_position the name is there and the numbers are not, or cannot be read
 *   duplicate   an earlier line already placed that checkpoint
 */
import { parsePosition } from './coordinates.js'

const fold = (s) => String(s ?? '').toLowerCase().replace(/\s+/g, ' ').trim()

/**
 * @param {string} text  the pasted messages, one or more lines each
 * @param {Array<{id: number, label: string}>} checkpoints  the estate's active checkpoints
 * @returns {Array<{line: string, checkpoint: {id: number, label: string}|null, lat: number|null, lng: number|null, status: string, why: string|null}>}
 */
export function matchPlacements(text, checkpoints) {
    const seen = new Set()

    return String(text ?? '')
        .split(/\r?\n/)
        .map((raw) => raw.trim())
        .filter((line) => line !== '')
        .map((line) => {
            const folded = fold(line)
            const fits = checkpoints
                .filter((c) => fold(c.label) !== '' && folded.includes(fold(c.label)))
                .sort((a, b) => fold(b.label).length - fold(a.label).length)

            if (fits.length === 0) {
                return { line, checkpoint: null, lat: null, lng: null, status: 'no_match', why: 'No checkpoint\'s name is in this line.' }
            }

            if (fits.length > 1 && fold(fits[0].label).length === fold(fits[1].label).length) {
                return { line, checkpoint: null, lat: null, lng: null, status: 'ambiguous', why: `This could be ${fits[0].label} or ${fits[1].label}.` }
            }

            const checkpoint = { id: fits[0].id, label: fits[0].label }
            const got = parsePosition(line)

            if (got.error) {
                return { line, checkpoint, lat: null, lng: null, status: 'no_position', why: got.error }
            }

            if (seen.has(checkpoint.id)) {
                return { line, checkpoint, lat: got.lat, lng: got.lng, status: 'duplicate', why: `${checkpoint.label} is placed by an earlier line.` }
            }

            seen.add(checkpoint.id)

            return { line, checkpoint, lat: got.lat, lng: got.lng, status: 'ok', why: null }
        })
}
