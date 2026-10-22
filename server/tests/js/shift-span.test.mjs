/**
 * What a shift's three fields say, as they are typed (M35 §3) — `lib/shiftSpan.js`.
 *
 * A night shift crossing midnight is the most common shift there is: its end rolls to the next day by itself, the form
 * says so in one quiet line, and its length is said on the estate's clock and on the viewer's.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { shiftSpan } from '../../resources/js/lib/shiftSpan.js'

const AT = { date: '2026-10-20', estateZone: 'America/Jamaica', estateTag: 'JA' }

test('6 PM to 7 AM is thirteen hours ending the next morning, said in the estate\'s time and in the viewer\'s', () => {
    const s = shiftSpan({ ...AT, starts: '18:00', ends: '07:00', viewerZone: 'Asia/Karachi' })

    assert.equal(s.minutes, 13 * 60)
    assert.equal(s.nextDay, true)
    assert.equal(s.endDate, '2026-10-21')
    assert.equal(s.endsLine, 'Ends 21 Oct, 7:00 AM JA — 13 hours')
    assert.equal(s.durationLine, '13 hours, 6:00 PM to 7:00 AM JA')
    // Karachi is ten hours ahead of Jamaica: 23:00 UTC is 4 AM there, and 12:00 UTC 5 PM.
    assert.equal(s.viewerLine, '4:00 AM to 5:00 PM your time')
})

test('a viewer on the estate\'s own clock is told nothing twice', () => {
    assert.equal(shiftSpan({ ...AT, starts: '18:00', ends: '07:00', viewerZone: 'America/Jamaica' }).viewerLine, null)
    assert.equal(shiftSpan({ ...AT, starts: '18:00', ends: '07:00', viewerZone: null }).viewerLine, null)
    // Another zone on the same offset that day (Panama keeps Jamaica's time all year).
    assert.equal(shiftSpan({ ...AT, starts: '18:00', ends: '07:00', viewerZone: 'America/Panama' }).viewerLine, null)
})

test('a day shift ends the same day and says only its length', () => {
    const s = shiftSpan({ ...AT, starts: '06:00', ends: '18:00' })

    assert.equal(s.minutes, 12 * 60)
    assert.equal(s.nextDay, false)
    assert.equal(s.endsLine, null)
    assert.equal(s.durationLine, '12 hours, 6:00 AM to 6:00 PM JA')
})

test('no shift is longer than 24 hours: the longest ends at its own start time, the next day', () => {
    const s = shiftSpan({ ...AT, starts: '07:00', ends: '07:00' })

    assert.equal(s.minutes, 24 * 60)
    assert.equal(s.endsLine, 'Ends 21 Oct, 7:00 AM JA — 24 hours')

    for (const [starts, ends] of [['00:00', '23:45'], ['23:45', '00:00'], ['12:30', '12:15'], ['05:59', '07:00']]) {
        const m = shiftSpan({ ...AT, starts, ends }).minutes
        assert.ok(m > 0 && m <= 24 * 60, `${starts}–${ends}: ${m} minutes`)
    }
})

test('the shift from the screenshot, 5:59 PM to 7:00 AM, is thirteen hours and a minute, ending the next day', () => {
    const s = shiftSpan({ ...AT, starts: '17:59', ends: '07:00' })

    assert.equal(s.minutes, 13 * 60 + 1)
    assert.equal(s.endsLine, 'Ends 21 Oct, 7:00 AM JA — 13 hours 1 minute')
})

test('midnight and noon are written the way a person reads them, and a month end rolls into the next month', () => {
    const s = shiftSpan({ ...AT, date: '2026-10-31', starts: '12:00', ends: '00:30' })

    assert.equal(s.durationLine, '12 hours 30 minutes, 12:00 PM to 12:30 AM JA')
    assert.equal(s.endsLine, 'Ends 1 Nov, 12:30 AM JA — 12 hours 30 minutes')
})

test('says nothing until the three fields are there', () => {
    assert.equal(shiftSpan({ ...AT, date: '', starts: '18:00', ends: '07:00' }), null)
    assert.equal(shiftSpan({ ...AT, starts: '', ends: '07:00' }), null)
    assert.equal(shiftSpan({ ...AT, starts: '18:00', ends: '7' }), null)
})
