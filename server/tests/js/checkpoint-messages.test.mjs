/**
 * The installers' messages, matched to the estate's checkpoints (M27 §4.3) — `resources/js/lib/checkpointMessages.js`.
 *
 * Whoever mounts a tag sends its name and the two numbers; the Director pastes them all into Sites & geofences. Each
 * line must reach the checkpoint it names and no other, and anything that cannot be read is said to be so, line by
 * line, before anything is saved.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { matchPlacements } from '../../resources/js/lib/checkpointMessages.js'

const route = [
    { id: 1, label: 'Basketball court' },
    { id: 2, label: 'Pool deck' },
    { id: 3, label: 'Pool deck gate' },
    { id: 4, label: 'Phase 3 roundabout' },
]

test('matches each message to the checkpoint it names, and reads its two numbers', () => {
    const got = matchPlacements('Basketball court, 18.01234, -76.95678\n[9:14 AM, 10/2/2026] Installer: Phase 3 roundabout 18.02000,-76.96000', route)

    assert.equal(got.length, 2)
    assert.deepEqual([got[0].status, got[0].checkpoint.id, got[0].lat, got[0].lng], ['ok', 1, 18.01234, -76.95678])
    assert.deepEqual([got[1].status, got[1].checkpoint.id, got[1].lat, got[1].lng], ['ok', 4, 18.02, -76.96])
})

test('takes the longest name that fits, so "Pool deck gate" is never placed as "Pool deck"', () => {
    const got = matchPlacements('pool deck gate, 18.01234, -76.95678\nPool deck, 18.01300, -76.95700', route)

    assert.deepEqual(got.map((p) => p.checkpoint.id), [3, 2])
})

test('says, line by line, what it could not read — and never places a checkpoint twice', () => {
    const got = matchPlacements([
        'Tennis court, 18.01234, -76.95678',
        'Basketball court, no signal here',
        'Basketball court 18.01234, -76.95678',
        'Basketball court 18.01300, -76.95700',
    ].join('\n'), route)

    assert.deepEqual(got.map((p) => p.status), ['no_match', 'no_position', 'ok', 'duplicate'])
    assert.match(got[0].why, /No checkpoint/)
    assert.match(got[3].why, /earlier line/)
})
