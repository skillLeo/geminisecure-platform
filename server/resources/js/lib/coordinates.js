/**
 * A POSITION TYPED OR PASTED INTO THE GEOFENCE EDITOR (M12 §1.5: "click the map, or type coordinates, or paste from
 * Google Maps"). Returns `{lat, lng}`, or `{error}` in words the Director can act on — never a guess.
 *
 *   18.0179, -76.8099                      typed, with a comma or a space
 *   18°01'04.4"N 76°48'35.6"W              degrees, minutes and seconds, as Google Maps shows them
 *   …/maps/place/…/@18.0179,-76.8099,17z   a Google Maps address from the browser bar (the place's own !3d/!4d
 *                                          numbers win over the view's @, which is where the map was LOOKING)
 *   …?q=18.0179,-76.8099 · ll= · query=    a shared search link
 *   18.0179° N, 76.8099° W                 decimal degrees with the hemisphere's letter, as Apple Maps and many
 *                                          phones' own screens show them (M25)
 *   Back Gate: 18.0179, -76.8099           the two numbers inside a line of words, as a person at a gate sends them
 *                                          (GATE_POSITIONS.md); taken only when the line holds exactly one pair
 *
 * A short link (maps.app.goo.gl/…) cannot be opened from here — the server that expands it is Google's — so it is
 * named as such, with what to do instead.
 */

const inRange = (lat, lng) => Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180

function pair(lat, lng) {
    if (!inRange(lat, lng)) return { error: 'Those numbers are not a position: a latitude is between -90 and 90, a longitude between -180 and 180.' }
    if (Math.abs(lat) < 0.0001 && Math.abs(lng) < 0.0001) return { error: 'That is 0, 0 — the sea off West Africa. Paste the position of the post itself.' }
    return { lat, lng }
}

function dms(text) {
    const part = /(\d{1,3})\s*°\s*(\d{1,2})\s*['′]\s*(\d{1,2}(?:\.\d+)?)\s*(?:"|″|'')?\s*([NSEW])/gi
    const found = [...text.matchAll(part)]
    if (found.length !== 2) return null
    const value = (m) => (Number(m[1]) + Number(m[2]) / 60 + Number(m[3]) / 3600) * (/[SW]/i.test(m[4]) ? -1 : 1)
    const lat = found.find((m) => /[NS]/i.test(m[4]))
    const lng = found.find((m) => /[EW]/i.test(m[4]))
    return lat && lng ? pair(value(lat), value(lng)) : null
}

/** "18.0179° N, 76.8099° W": decimal degrees, each with its hemisphere's letter in place of a sign. */
function hemispheres(text) {
    const part = /(\d{1,3}(?:\.\d+)?)\s*°?\s*([NSEW])(?![a-z])/gi
    const found = [...text.matchAll(part)]
    if (found.length !== 2) return null
    const value = (m) => Number(m[1]) * (/[SW]/i.test(m[2]) ? -1 : 1)
    const lat = found.find((m) => /[NS]/i.test(m[2]))
    const lng = found.find((m) => /[EW]/i.test(m[2]))
    return lat && lng ? pair(value(lat), value(lng)) : null
}

/**
 * The two numbers inside a line of words — "Back Gate: 18.0179, -76.8099 (by the barrier)". Only a pair with decimal
 * places counts, so a post's name or a time of day is never read as a position, and a line with two such pairs is
 * refused rather than guessed at.
 */
function within(text) {
    const pairs = [...text.matchAll(/(-?\d{1,3}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})/g)]
    if (pairs.length === 0) return null
    if (pairs.length > 1) return { error: 'That holds more than one position. Paste the two numbers for this post only.' }
    return pair(Number(pairs[0][1]), Number(pairs[0][2]))
}

export function parsePosition(input) {
    const text = String(input ?? '').trim()
    if (text === '') return { error: 'Type or paste a position, or click where it stands on the map.' }

    if (/^(https?:\/\/)?(maps\.app\.goo\.gl|goo\.gl\/maps)\//i.test(text)) {
        return { error: 'That is a Google Maps short link, which only Google can open. Open it, then copy the address from the browser bar — or right-click the place on Google Maps and copy the two numbers it shows.' }
    }

    const place = /!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/.exec(text)
    if (place) return pair(Number(place[1]), Number(place[2]))

    const query = /[?&](?:q|ll|query|center|destination)=(-?\d+(?:\.\d+)?)(?:,|%2C)\s*(-?\d+(?:\.\d+)?)/i.exec(text)
    if (query) return pair(Number(query[1]), Number(query[2]))

    const view = /@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/.exec(text)
    if (view) return pair(Number(view[1]), Number(view[2]))

    const degrees = dms(text)
    if (degrees) return degrees

    const plain = /^(-?\d+(?:\.\d+)?)\s*[,\s;]\s*(-?\d+(?:\.\d+)?)$/.exec(text)
    if (plain) return pair(Number(plain[1]), Number(plain[2]))

    const lettered = hemispheres(text)
    if (lettered) return lettered

    const inLine = within(text)
    if (inLine) return inLine

    return { error: 'That could not be read as a position. Paste two numbers like 18.0179, -76.8099, or a Google Maps address.' }
}
