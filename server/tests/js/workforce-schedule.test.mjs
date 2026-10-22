/**
 * M30: the Guard workforce tab bar's two views (§1.3), and the Schedule's line about clocks (§4.5).
 * M35 §1: the tabs are named Roster and Sites & geofences and open the new view, and one line swaps them back.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { CLASSIC_PAGES, ROSTER_HREF, SITES_HREF, TABS_OPEN, isCurrent, workforceTabs } from '../../resources/js/lib/workforceTabs.js'
import { offsetMinutes, span, zoneSentence } from '../../resources/js/lib/scheduleTime.js'

const BAR = (roster, sites) => [
    ['Directory', '/guards'],
    ['On duty', '/guards/on-duty'],
    ['Roster', roster],
    ['Standing orders', '/guards/standing-orders'],
    ['Gate activity', '/guards/activity'],
    ['Compliance', '/guards/compliance'],
    ['Supervisors', '/guards/supervisors'],
    ['Incidents', '/guards/incidents'],
    ['Sites & geofences', sites],
]

test('every page names the tabs Roster and Sites & geofences, and they open the new Schedule and Places', () => {
    assert.equal(TABS_OPEN, 'new')
    assert.equal(ROSTER_HREF, '/guards/schedule')
    assert.equal(SITES_HREF, '/guards/places')

    for (const active of ['directory', 'on-duty', 'schedule', 'places', 'compliance', 'roster', 'sites']) {
        assert.deepEqual(workforceTabs(active).items.map((i) => [i.label, i.href]), BAR('/guards/schedule', '/guards/places'))
    }
})

test('the Roster tab is the current one on either view\'s roster page, and Sites & geofences on either view\'s map', () => {
    for (const [active, label] of [['schedule', 'Roster'], ['roster', 'Roster'], ['places', 'Sites & geofences'], ['sites', 'Sites & geofences'], ['directory', 'Directory']]) {
        assert.deepEqual(workforceTabs(active).items.filter((i) => isCurrent(i, active)).map((i) => i.label), [label])
    }
})

test('the link at the end of the bar crosses views: "Classic view" from the new pages, "New view" from the two classic ones', () => {
    for (const active of ['schedule', 'directory', 'on-duty', 'compliance']) {
        assert.deepEqual(workforceTabs(active).switchLink, { label: 'Classic view', href: '/guards/roster' })
    }

    assert.deepEqual(workforceTabs('places').switchLink, { label: 'Classic view', href: '/guards/sites' })
    assert.deepEqual(CLASSIC_PAGES, ['roster', 'sites'])
    assert.deepEqual(workforceTabs('roster').switchLink, { label: 'New view', href: '/guards/schedule' })
    assert.deepEqual(workforceTabs('sites').switchLink, { label: 'New view', href: '/guards/places' })
})

test('one line gives the client the classic screens back: change TABS_OPEN to classic and every tab opens them', async () => {
    const source = readFileSync(new URL('../../resources/js/lib/workforceTabs.js', import.meta.url), 'utf8')

    // The line, on its own, and the file saying which line it is.
    assert.match(source, /^export const TABS_OPEN = 'new'$/m)
    assert.match(source, /CHANGE THIS ONE LINE TO 'classic'/)

    const swapped = source.replace("export const TABS_OPEN = 'new'", "export const TABS_OPEN = 'classic'")
    assert.notEqual(swapped, source)

    const classic = await import('data:text/javascript,' + encodeURIComponent(swapped))
    assert.equal(classic.ROSTER_HREF, '/guards/roster')

    for (const active of ['directory', 'schedule', 'roster']) {
        assert.deepEqual(classic.workforceTabs(active).items.map((i) => [i.label, i.href]), BAR('/guards/roster', '/guards/sites'))
    }

    // Each view still links across to the other, and the rest of the module to the view the tabs no longer open.
    assert.deepEqual(classic.workforceTabs('roster').switchLink, { label: 'New view', href: '/guards/schedule' })
    assert.deepEqual(classic.workforceTabs('schedule').switchLink, { label: 'Classic view', href: '/guards/roster' })
    assert.deepEqual(classic.workforceTabs('directory').switchLink, { label: 'New view', href: '/guards/schedule' })
})

test('the offset between two zones is computed, both sides of a clock change', () => {
    const july = new Date('2026-07-01T12:00:00Z')
    const january = new Date('2026-01-15T12:00:00Z')

    assert.equal(offsetMinutes('America/Jamaica', july), -300)
    assert.equal(offsetMinutes('Asia/Karachi', july), 300)
    // New York moves; Jamaica does not.
    assert.equal(offsetMinutes('America/New_York', july), -240)
    assert.equal(offsetMinutes('America/New_York', january), -300)
    assert.equal(offsetMinutes('Asia/Kolkata', july), 330)
})

test('the line under the week says whose time the grid is in, and how far the viewer is from it', () => {
    const at = new Date('2026-10-05T12:00:00Z')
    const base = { client: 'Phoenix Park Village 1', estateZone: 'America/Jamaica', estateTag: 'JA', shownTag: 'JA', mine: false }

    assert.equal(zoneSentence({ ...base, viewerZone: 'Asia/Karachi' }, at),
        "All times are Phoenix Park Village 1's time (JA). Your time is 10 hours ahead.")
    assert.equal(zoneSentence({ ...base, viewerZone: 'America/Jamaica' }, at), "All times are Phoenix Park Village 1's time (JA).")
    assert.equal(zoneSentence({ ...base, viewerZone: null }, at), "All times are Phoenix Park Village 1's time (JA).")
    assert.equal(zoneSentence({ ...base, viewerZone: 'America/Los_Angeles' }, at),
        "All times are Phoenix Park Village 1's time (JA). Your time is 2 hours behind.")
    assert.equal(zoneSentence({ ...base, viewerZone: 'Asia/Kolkata' }, at),
        "All times are Phoenix Park Village 1's time (JA). Your time is 10 hours 30 minutes ahead.")

    // "My time": the grid is on the viewer's clock, and the line says so.
    assert.equal(zoneSentence({ ...base, mine: true, shownTag: 'KA', viewerZone: 'Asia/Karachi' }, at),
        "All times are your time (KA). Phoenix Park Village 1's time is 10 hours behind.")
})

test('a span is said in hours and minutes', () => {
    assert.equal(span(600), '10 hours')
    assert.equal(span(-60), '1 hour')
    assert.equal(span(330), '5 hours 30 minutes')
})
