/**
 * The geofence editor's position field (M12 §1.5, M25 §3.2): what a Director pastes from Google Maps, from Apple Maps,
 * or straight out of the message a person at a gate sent — read exactly, or refused in words, never guessed.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { parsePosition } from '../../resources/js/lib/coordinates.js'

const near = (got, lat, lng) => {
    assert.equal(got.error, undefined, got.error)
    assert.ok(Math.abs(got.lat - lat) < 1e-6, `lat ${got.lat} is not ${lat}`)
    assert.ok(Math.abs(got.lng - lng) < 1e-6, `lng ${got.lng} is not ${lng}`)
}

test('the two numbers Google Maps shows for a dropped pin, with a comma, a space or a semicolon', () => {
    near(parsePosition('18.012345, -76.956789'), 18.012345, -76.956789)
    near(parsePosition('18.012345 -76.956789'), 18.012345, -76.956789)
    near(parsePosition('18.012345;-76.956789'), 18.012345, -76.956789)
})

test('degrees, minutes and seconds, and Google Maps addresses, as before', () => {
    near(parsePosition('18°01\'04.4"N 76°48\'35.6"W'), 18 + 1 / 60 + 4.4 / 3600, -(76 + 48 / 60 + 35.6 / 3600))
    near(parsePosition('https://www.google.com/maps/place/X/@18.1,-76.9,17z/data=!3d18.0179!4d-76.8099'), 18.0179, -76.8099)
    near(parsePosition('https://maps.google.com/?q=18.0179,-76.8099'), 18.0179, -76.8099)
    near(parsePosition('https://www.google.com/maps/@18.0179,-76.8099,17z'), 18.0179, -76.8099)
})

test('decimal degrees with the hemisphere letter, as Apple Maps shows them (M25)', () => {
    near(parsePosition('18.01234° N, 76.95678° W'), 18.01234, -76.95678)
    near(parsePosition('18.01234 N 76.95678 W'), 18.01234, -76.95678)
    near(parsePosition('18.01234°S 76.95678°E'), -18.01234, 76.95678)
})

test('the pair inside the message a person at a gate sends (GATE_POSITIONS.md)', () => {
    near(parsePosition('Back Gate: 18.01234, -76.95678'), 18.01234, -76.95678)
    near(parsePosition('Gate 5 18.01234,-76.95678 (by the barrier)'), 18.01234, -76.95678)
    near(parsePosition('Main Gate — 18.012345, -76.956789. Photo attached.'), 18.012345, -76.956789)

    // GATE_POSITIONS.md's own worked example, word for word, and the same line copied out of WhatsApp with its stamp.
    near(parsePosition('Back Gate, 18.01234, -76.95678'), 18.01234, -76.95678)
    near(parsePosition('[9:14 AM, 10/2/2026] Gate team: Back Gate, 18.01234, -76.95678'), 18.01234, -76.95678)
})

test('refuses, in words, what is not one position', () => {
    assert.match(parsePosition('Back Gate 18.01234, -76.95678 and Main Gate 18.02000, -76.96000').error, /more than one position/)
    assert.match(parsePosition('Back Gate at 7.30').error, /could not be read/)
    assert.match(parsePosition('0, 0').error, /0, 0/)
    assert.match(parsePosition('95.1, -76.9').error, /not a position/)
    assert.match(parsePosition('https://maps.app.goo.gl/abc123').error, /short link/)
    assert.match(parsePosition('').error, /Type or paste/)
})
