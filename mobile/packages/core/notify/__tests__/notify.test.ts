import { readFileSync } from 'node:fs'
import path from 'node:path'
import { dedupeKey } from '../dedupe'
import { guardDigest, type Pull } from '../guardDigest'
import { CATEGORY, URGENT, linkFor, resolveIncoming, sanitisePath } from '../links'

const AT = new Date('2026-09-04T14:41:00Z')
const when = (iso: string) => `on ${iso.slice(0, 10)}`

describe('a notification opens one of the app\'s own screens, and only that', () => {
  it('guard types', () => {
    expect(linkFor('guard', { type: 'alertness_check', id: 7 })).toBe('/alertness')
    expect(linkFor('guard', { type: 'message' })).toBe('/messages/dispatch')
    expect(linkFor('guard', { type: 'broadcast' })).toBe('/messages/broadcast')
    expect(linkFor('guard', { type: 'request_decided', kind: 'equipment' })).toBe('/equipment')
    expect(linkFor('guard', { type: 'request_decided', kind: 'leave' })).toBe('/leave')
    expect(linkFor('guard', { type: 'claim_decided' })).toBe('/leave?tab=open')
    expect(linkFor('guard', { type: 'payslip_ready' })).toBe('/(tabs)/payslips')
  })

  /*
   * M10 §1.3, and the s1-progress unit's question 3: the officer DISPATCHED is pushed `site_alert` with
   * `kind: dispatched`, and it has to open THAT alert — where the En route / On scene buttons are — not
   * Home, which has no alert on it. A panic pushed to every officer on shift opens it too: nothing in a
   * panic waits on the dispatcher, and an officer who was not sent may take it.
   */
  it('a site alert opens that alert: the one the officer was dispatched to, and one they were simply told of', () => {
    expect(linkFor('guard', { type: 'site_alert', id: '41', kind: 'dispatched' })).toBe('/alert/41')
    expect(linkFor('guard', { type: 'site_alert', id: 41, kind: 'panic' })).toBe('/alert/41')
    expect(linkFor('guard', { type: 'site_alert', id: '42', kind: 'duress' })).toBe('/alert/42')
    // With no usable id there is no alert to open: Home, as before — never a path built from a bad id.
    expect(linkFor('guard', { type: 'site_alert', kind: 'dispatched' })).toBe('/(tabs)')
    expect(linkFor('guard', { type: 'site_alert', id: '../../duress', kind: 'dispatched' })).toBe('/(tabs)')
  })

  /*
   * The call-style ring (GUARD_RING_DESIGN §6.4, §8.4). `site_ring` and `ring_stop` are handled natively when they
   * arrive, so these rows matter for a tap on the server's visible stop, and for any copy that reaches JS: both open
   * the alert, like the push they follow. `site_ring` is urgent; a stop is not, and cannot be switched off either.
   */
  it('a ring and its stop open that alert; the ring is urgent, and neither can be switched off', () => {
    expect(linkFor('guard', { type: 'site_ring', id: '4812', kind: 'medical' })).toBe('/alert/4812')
    expect(linkFor('guard', { type: 'ring_stop', id: 4812 })).toBe('/alert/4812')
    expect(linkFor('guard', { type: 'ring_stop', id: '../x' })).toBe('/(tabs)')
    expect(linkFor('resident', { type: 'site_ring', id: '4812' })).toBeNull()
    expect(URGENT).toContain('site_ring')
    expect(URGENT).not.toContain('ring_stop')
    expect(CATEGORY.site_ring).toBeNull()
    expect(CATEGORY.ring_stop).toBeNull()
  })

  it('resident types carry their id into the path', () => {
    expect(linkFor('resident', { type: 'approval_request', id: 41 })).toBe('/visitors/approval/41')
    expect(linkFor('resident', { type: 'notice', id: '12' })).toBe('/notices/12')
    expect(linkFor('resident', { type: 'election_results', id: 3 })).toBe('/elections/3/results')
    expect(linkFor('resident', { type: 'panic_update' })).toBe('/panic')
  })

  it('a type that needs an id and has none, or a bad one, opens nothing', () => {
    expect(linkFor('resident', { type: 'notice' })).toBeNull()
    expect(linkFor('resident', { type: 'notice', id: '../../settings' })).toBeNull()
    expect(linkFor('resident', { type: 'notice', id: '12?x=1' })).toBeNull()
    expect(linkFor('resident', { type: 'notice', id: { $ne: null } })).toBeNull()
  })

  it('one app\'s types mean nothing to the other; unknown and hostile types open nothing', () => {
    expect(linkFor('resident', { type: 'payslip_ready' })).toBeNull()
    expect(linkFor('guard', { type: 'payment_recorded' })).toBeNull()
    expect(linkFor('guard', { type: 'constructor' })).toBeNull()
    expect(linkFor('guard', { type: '__proto__' })).toBeNull()
    expect(linkFor('guard', { type: 42 })).toBeNull()
    expect(linkFor('guard', null)).toBeNull()
  })

  it('a payload never chooses the route: there is no url field to honour', () => {
    const source = readFileSync(path.resolve(__dirname, '..', 'links.ts'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
    expect(source).not.toMatch(/payload\.(url|path|route|href)|p\.(url|path|route|href)/)
  })

  it('safety types cannot be switched off; a guard has no money category', () => {
    for (const type of ['duress_update', 'alertness_check', 'approval_request', 'panic_update'] as const) expect(CATEGORY[type]).toBeNull()
    const guardTypes = ['duress_update', 'alertness_check', 'message', 'broadcast', 'order_published', 'shift_changed', 'claim_decided', 'request_decided', 'payslip_ready'] as const
    for (const type of guardTypes) expect(String(CATEGORY[type])).not.toMatch(/payment|dues|invoice/)
  })
})

describe('an incoming scheme link', () => {
  it('keeps a plain in-app path', () => {
    expect(sanitisePath('gsguard://leave?tab=open')).toBe('/leave?tab=open')
    expect(sanitisePath('gsresident:///visitors/pass/41')).toBe('/visitors/pass/41')
    expect(sanitisePath('/(tabs)/payslips')).toBe('/(tabs)/payslips')
  })
  it('drops traversal, other origins, long or odd input', () => {
    expect(sanitisePath('gsguard://../../etc')).toBeNull()
    expect(sanitisePath('gsguard://messages//dispatch')).toBeNull()
    expect(sanitisePath('gsguard://leave?next=https://evil.example')).toBeNull()
    expect(sanitisePath('gsguard://leave?a=1&b=2&c=3&d=4&e=5')).toBeNull()
    expect(sanitisePath(`gsguard://${'a'.repeat(200)}`)).toBeNull()
    expect(sanitisePath('gsguard://visitors/<script>')).toBeNull()
    expect(sanitisePath('')).toBeNull()
  })
})

describe('what the OS hands the app', () => {
  it('the resident app\'s published short links become their screens', () => {
    expect(resolveIncoming('resident', 'gs://pass/41')).toBe('/visitors/pass/41')
    expect(resolveIncoming('resident', 'gs://ticket/1042')).toBe('/maintenance/1042')
    expect(resolveIncoming('resident', 'gs://election/3')).toBe('/elections/3/ballot-a')
    expect(resolveIncoming('guard', 'gsguard://pass/41')).toBe('/pass/41') // not a guard alias: left alone for the router to refuse
  })
  it('an ordinary launch, and Expo Go\'s own address, open the start screen', () => {
    expect(resolveIncoming('guard', '')).toBe('/')
    expect(resolveIncoming('guard', '/')).toBe('/')
    expect(resolveIncoming('guard', 'exp://192.168.18.119:8081')).toBe('/')
    expect(resolveIncoming('guard', 'exp://192.168.18.119:8081/--/leave?tab=open')).toBe('/leave?tab=open')
  })
  it('a hostile link opens the start screen: it never throws and never navigates', () => {
    for (const bad of ['gs://../../x', 'gs://pass/41/../../settings', 'gs://notices/<img>', 'javascript:alert(1)', 'gs://pass/41?next=//evil', null, undefined]) expect(resolveIncoming('resident', bad as string)).toBe('/')
  })
})

describe('what one pull means to a guard', () => {
  const pull: Pull = {
    alertness_checks: [{ check_id: 5, respond_by: '2026-09-04T14:42:30Z' }, { check_id: 4, respond_by: '2026-09-04T14:40:00Z' }],
    messages: [{ id: 9, direction: 'outbound', body: 'Contractor  at\nGate 2 around 10', sent_at: '2026-09-04T14:40:00Z' }, { id: 10, direction: 'broadcast', body: 'x'.repeat(300), sent_at: '2026-09-04T14:40:30Z' }],
    orders: [{ set_id: 2, version_id: 31, title: 'Main Gate orders', requires_acknowledgement: true }],
    shifts: [
      { id: 1, status: 'on_duty', post: { name: 'Main Gate' }, rostered_start: '2026-09-04T12:00:00Z', rostered_end: '2026-09-05T00:00:00Z' },
      { id: 2, status: 'rostered', post: { name: 'Service Gate' }, rostered_start: '2026-09-06T00:00:00Z', rostered_end: '2026-09-06T12:00:00Z' },
    ],
    requests: [{ id: 3, kind: 'leave', status: 'approved' }, { id: 4, kind: 'equipment', status: 'info_requested' }],
    claims: [{ claim_id: 8, status: 'declined' }],
    resident_reports: [{ report_id: 77, title: 'Two men climbing the back wall', location: 'Back wall by the pool' }],
  }
  const notes = guardDigest(pull, AT, when)

  it('an open check is urgent; one already closed is not announced', () => {
    const checks = notes.filter((n) => n.type === 'alertness_check')
    expect(checks).toHaveLength(1)
    expect(checks[0]).toMatchObject({ key: 'alertness:5', urgent: true })
  })

  it('messages are one line, clipped, and say who they are from', () => {
    expect(notes.find((n) => n.key === 'message:9')).toMatchObject({ title: 'Control room', body: 'Contractor at Gate 2 around 10', type: 'message' })
    const broadcast = notes.find((n) => n.key === 'message:10')!
    expect(broadcast.title).toBe('Site broadcast')
    expect(broadcast.body.length).toBeLessThanOrEqual(110)
  })

  it('the guard\'s own clock-in is not "your roster changed"; a future shift is', () => {
    const shifts = notes.filter((n) => n.type === 'shift_changed')
    expect(shifts.map((n) => n.key)).toEqual(['shift:2:2026-09-06T00:00:00Z'])
    expect(shifts[0]!.body).toBe('Service Gate · on 2026-09-06')
  })

  it('decisions are said plainly', () => {
    expect(notes.find((n) => n.key === 'request:3:approved')!.body).toMatch(/was approved/)
    expect(notes.find((n) => n.key === 'request:4:info_requested')).toMatchObject({ title: 'Your equipment request', data: { kind: 'equipment' } })
    expect(notes.find((n) => n.key === 'claim:8:declined')!.body).toMatch(/not yours/)
    expect(notes.find((n) => n.type === 'order_published')!.body).toMatch(/acknowledge/)
  })

  it('what a resident says is happening now is a place and what they said, and does not ring like a panic', () => {
    const report = notes.find((n) => n.type === 'resident_report')!
    expect(report).toMatchObject({ key: 'resident-report:77', title: 'A resident reports something happening now', urgent: false, data: { type: 'resident_report', id: '77' } })
    expect(report.body).toBe('Two men climbing the back wall · Back wall by the pool')
    // It opens the read-only view of that report and nothing else.
    expect(linkFor('guard', report.data)).toBe('/report/77')
    // It cannot be switched off: site safety is not a preference.
    expect(CATEGORY.resident_report).toBeNull()
  })

  it('every note has a stable key and a payload with a type and no url', () => {
    expect(new Set(notes.map((n) => n.key)).size).toBe(notes.length)
    for (const n of notes) { expect(n.data.type).toBe(n.type); expect(JSON.stringify(n.data)).not.toMatch(/https?:|\/\//) }
  })

  it('an empty pull is silence', () => expect(guardDigest({}, AT, when)).toEqual([]))

  /*
   * FOUND ON A TEST PHONE, 2026-09-22: a resident's panic never reached the guard on duty with the app
   * open, because the only road was a push and the build had no push token. The pull now carries it.
   */
  it('an emergency at the site comes FIRST, rings as urgent, and is keyed like the push so one phone shows one banner', () => {
    const alerts = guardDigest({ ...pull, alerts: [{ alert_id: 41, kind: 'panic', where: 'Lot 1', raised_at: '2026-09-04T14:41:00Z' }, { alert_id: 42, kind: 'duress', where: null, raised_at: '2026-09-04T14:41:30Z' }] }, AT, when)
    expect(alerts.slice(0, 2)).toEqual([
      // WHERE, because a guard cannot respond to "somewhere" — the same words the server's push puts on the same lock screen.
      { key: 'site-alert:41', type: 'site_alert', title: 'Panic alert', body: 'At Lot 1. Dispatch has it: respond as your orders say.', data: { type: 'site_alert', id: '41', kind: 'panic' }, urgent: true },
      // Another officer's duress names nobody at all.
      { key: 'site-alert:42', type: 'site_alert', title: 'Officer duress alert', body: 'An officer at your site needs help. Dispatch has it: respond as your orders say.', data: { type: 'site_alert', id: '42', kind: 'duress' }, urgent: true },
    ])
    // The raise push's payload is { type: 'site_alert', id, kind } (PushAnnouncer::alertRaised): the same dedupe key,
    // so push-then-pull is one banner — and "You are dispatched" (kind: dispatched) is not the same key (B134).
    expect(dedupeKey(alerts[0]!.data)).toBe(dedupeKey({ type: 'site_alert', id: 41, kind: 'panic' }))
    expect(dedupeKey(alerts[0]!.data)).not.toBe(dedupeKey({ type: 'site_alert', id: 41, kind: 'dispatched' }))
    // It cannot be switched off.
    expect(CATEGORY.site_alert).toBeNull()
    // And the tap opens that alert.
    expect(linkFor('guard', alerts[0]!.data)).toBe('/alert/41')
  })

  it('a DRILL says so before anything else, as the server\'s own push title does, and only a drill', () => {
    const notes = guardDigest({ alerts: [
      { alert_id: 51, kind: 'panic', where: 'Lot 9', raised_at: '2026-09-04T14:41:00Z', is_drill: true },
      { alert_id: 52, kind: 'fire', where: 'Lot 3', raised_at: '2026-09-04T14:41:10Z', is_drill: false },
      { alert_id: 53, kind: 'duress', where: null, raised_at: '2026-09-04T14:41:20Z' },
    ] }, AT, when)
    expect(notes.map((n) => n.title)).toEqual(['DRILL: Panic alert', 'Fire reported', 'Officer duress alert'])
    // Still urgent, still the same key as the push: a drill reaches real officers exactly as a panic does.
    expect(notes[0]).toMatchObject({ key: 'site-alert:51', urgent: true, data: { type: 'site_alert', id: '51', kind: 'panic' } })
  })

  it('nothing on a lock screen names money, a resident or a unit', () => {
    expect(JSON.stringify(notes)).not.toMatch(/\$|J\$|arrears|balance|Unit \d|Lot \d/)
  })
})

describe('the runtime keeps a notification\'s words to itself', () => {
  it('never logs a title or a body', () => {
    const source = readFileSync(path.resolve(__dirname, '..', 'runtime.ts'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
    expect(source).not.toMatch(/console\.|Sentry|logger/)
  })
})
