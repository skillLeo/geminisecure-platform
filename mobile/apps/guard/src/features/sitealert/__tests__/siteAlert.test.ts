/**
 * An alert at this site, for the officer who may respond (M10 §1.3) — the pure half.
 *
 *   THE BUTTONS ARE THE SERVER'S: drawn only on `can_en_route` / `can_on_scene` exactly `true`.
 *   EVERY REFUSAL IS A SENTENCE: each code the three endpoints can answer, in words for the officer.
 *   A PLACE, NEVER A PERSON: the screen says where, and who is RESPONDING — never who pressed it.
 *   A DRILL SAYS SO: the flag `sync.pull` brought is kept, because `alerts.show` does not carry it.
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { ENDPOINTS } from '@gs/core/api'
import { canQueue } from '@gs/core/offline'
import {
  KEEP_HOURS,
  KEEP_MOST_RECENT,
  actLine,
  alertId,
  isDrillAlert,
  keepAnswer,
  keptAlert,
  mergeKept,
  refusalSentence,
  rememberAlerts,
  responderLine,
  responseActions,
  responseSteps,
  staleLine,
  toKept,
  whereLine,
  type AlertView,
  type KeptAlert,
} from '../siteAlert'

const AT = new Date('2026-09-24T15:00:00.000Z')
const when = (iso: string) => `at ${iso.slice(11, 16)}`

const view = (over: Partial<AlertView> = {}): AlertView => ({
  alert_id: 41,
  kind: 'panic',
  status: 'open',
  where: 'Lot 47',
  raised_at: '2026-09-24T14:41:02Z',
  acknowledged_at: null,
  dispatched_at: null,
  en_route_at: null,
  on_scene_at: null,
  resolved_at: null,
  responder: null,
  responder_is_you: false,
  dispatched_to_you: false,
  can_en_route: true,
  can_on_scene: true,
  reason: null,
  is_drill: false,
  ...over,
})

/** An answer as a phone kept it BEFORE the server sent `is_drill`. */
const older = (over: Partial<AlertView> = {}): AlertView => {
  const a: Partial<AlertView> = view(over)
  delete a.is_drill
  return a as AlertView
}

describe('the buttons are drawn only when the server says this handset may record them', () => {
  it('both, when both are allowed', () => {
    expect(responseActions(view())).toEqual({ enRoute: true, onScene: true, reason: null })
  })

  it('on the way already recorded: only "On scene" is left', () => {
    expect(responseActions(view({ can_en_route: false, can_on_scene: true, en_route_at: '2026-09-24T14:42:30Z' }))).toEqual({ enRoute: false, onScene: true, reason: null })
  })

  it('neither, and the server\'s reason shown as given, when it may not', () => {
    const reason = 'Andre Brown is the officer responding to this alert, so only their handset can record it. If you are going too, tell dispatch by radio.'
    expect(responseActions(view({ can_en_route: false, can_on_scene: false, reason }))).toEqual({ enRoute: false, onScene: false, reason })
  })

  it('ONLY a true draws a button: anything else the server might send is a no', () => {
    for (const notTrue of [false, null, undefined, 'true', 1, 'yes']) {
      const a = responseActions(view({ can_en_route: notTrue as unknown as boolean, can_on_scene: notTrue as unknown as boolean }))
      expect([notTrue, a.enRoute, a.onScene]).toEqual([notTrue, false, false])
    }
  })

  it('never on a closed alert, whatever a stale answer says', () => {
    expect(responseActions(view({ status: 'resolved', resolved_at: '2026-09-24T14:58:00Z' }))).toMatchObject({ enRoute: false, onScene: false })
  })

  it('a tap already on its way up is not offered twice; "on scene" on its way hides "en route" too', () => {
    expect(responseActions(view(), { en_route: true })).toMatchObject({ enRoute: false, onScene: true })
    expect(responseActions(view(), { on_scene: true })).toMatchObject({ enRoute: false, onScene: false })
  })

  it('no answer, no buttons', () => {
    expect(responseActions(null)).toEqual({ enRoute: false, onScene: false, reason: null })
  })

  it('a blank reason is no reason', () => {
    expect(responseActions(view({ can_on_scene: false, reason: '   ' })).reason).toBeNull()
  })
})

describe('every refusal is a plain sentence', () => {
  const CODES = ['own_alert', 'not_on_shift', 'another_officer_responding', 'not_found', 'alert_closed', 'already_en_route', 'already_on_scene', 'wrong_app'] as const

  it('covers every code the catalogue lists for the three endpoints', () => {
    const listed = new Set([...ENDPOINTS['alerts.show'].errors, ...ENDPOINTS['alerts.en_route'].errors, ...ENDPOINTS['alerts.on_scene'].errors])
    for (const code of listed) expect(CODES).toContain(code)
  })

  it('each one is a sentence, on each endpoint, and never the bare code', () => {
    for (const code of CODES) {
      for (const step of ['show', 'en_route', 'on_scene'] as const) {
        const sentence = refusalSentence({ status: 409, code, message: '' }, step)
        expect(sentence).toMatch(/^[A-Z"].+[.]$/)
        expect(sentence).not.toContain(code)
      }
    }
  })

  it('keeps the server\'s sentence where it carries the name or the time', () => {
    expect(refusalSentence({ code: 'another_officer_responding', message: 'Andre Brown is the officer responding to this alert, so only their handset can record it.' }, 'en_route')).toMatch(/^Andre Brown is the officer responding/)
    expect(refusalSentence({ code: 'alert_closed', message: 'This alert was resolved at 2:58 PM, so there is nothing left to respond to.' }, 'on_scene')).toMatch(/2:58 PM/)
    expect(refusalSentence({ code: 'already_en_route', message: 'You were already recorded as on the way at 2:42 PM.' }, 'en_route')).toMatch(/2:42 PM/)
    expect(refusalSentence({ code: 'already_on_scene', message: 'You were already recorded at the scene at 2:45 PM.' }, 'on_scene')).toMatch(/2:45 PM/)
  })

  it('replaces a sentence written for a developer: the officer is sent to their Duress screen, not to GET /duress', () => {
    const own = refusalSentence({ status: 403, code: 'own_alert', message: 'This is your own alert. Follow it with GET /duress/41.' }, 'show')
    expect(own).toMatch(/Duress screen/)
    expect(own).not.toMatch(/GET|\/duress/)
    expect(refusalSentence({ status: 409, code: 'something_new', message: 'SQLSTATE[23000]: Integrity constraint' }, 'en_route')).toBe('The server did not accept this. Radio dispatch.')
  })

  it('says "not on shift" as the reason it was not sent to them', () => {
    expect(refusalSentence({ status: 403, code: 'not_on_shift' }, 'en_route')).toMatch(/not sent to you.*not on shift/)
  })

  it('no signal is not a refusal: it is saved, and dispatch is radioed meanwhile', () => {
    expect(refusalSentence({ status: 'network', code: 'network_unreachable' }, 'en_route')).toMatch(/saved on this phone.*radio dispatch/i)
    expect(refusalSentence({ status: 'timeout', code: 'timeout' }, 'show')).toMatch(/No signal/)
  })
})

describe('what happened to a tap', () => {
  it('saved, sending, recorded, or refused with its sentence', () => {
    expect(actLine({ state: 'pending', attempts: 0, error: null }, 'en_route', true)).toEqual({ tone: 'info', text: '"On my way" is saved on this phone: sending now.' })
    expect(actLine({ state: 'syncing', attempts: 1, error: null }, 'on_scene', true)?.text).toBe('Sending "I am there"…')
    expect(actLine({ state: 'confirmed', attempts: 1, error: null }, 'en_route', true)).toMatchObject({ tone: 'ok' })
    expect(actLine({ state: 'refused', attempts: 1, error: { status: 409, code: 'alert_closed', message: 'This alert was resolved at 2:58 PM, so there is nothing left to respond to.' } }, 'on_scene', true)).toEqual({ tone: 'bad', text: 'This alert was resolved at 2:58 PM, so there is nothing left to respond to.' })
  })

  it('with no signal it says the tap is kept and sent when signal returns, never that it went', () => {
    for (const line of [actLine({ state: 'pending', attempts: 0, error: null }, 'en_route', false), actLine({ state: 'pending', attempts: 2, error: { status: 'network', code: 'network_unreachable', message: 'x' } }, 'en_route', true)]) {
      expect(line?.text).toMatch(/saved on this phone and sent the moment signal returns/)
      expect(line?.text).not.toMatch(/recorded|sent to/i)
    }
  })

  it('nothing tapped, nothing said', () => expect(actLine(null, 'en_route', true)).toBeNull())
})

describe('a place, never a person', () => {
  it('says where, as a lot', () => {
    expect(whereLine('panic', 'Lot 47')).toBe('At Lot 47')
    expect(whereLine('duress', null)).toMatch(/An officer at your site needs help/)
    expect(whereLine('fire', '  ')).toMatch(/not given/)
  })

  it('names the officer RESPONDING, or "you", and nobody else', () => {
    expect(responderLine(view({ responder: 'Andre Brown' }))).toBe('Andre Brown is the officer responding.')
    expect(responderLine(view({ responder: 'Andre Brown', responder_is_you: true, dispatched_to_you: true }))).toBe('You were dispatched to this alert.')
    expect(responderLine(view({ responder: 'Andre Brown', responder_is_you: true }))).toBe('You are the officer responding to this alert.')
    expect(responderLine(view())).toBe('No officer is recorded as responding yet.')
  })

  it('the screen and its hook draw no resident\'s name: the catalogue sends none, and nothing here asks for one', () => {
    const dir = path.resolve(__dirname, '..')
    const source = ['siteAlert.ts', 'useSiteAlert.ts', path.join('screens', 'SiteAlertScreen.tsx')].map((f) => readFileSync(path.join(dir, f), 'utf8')).join('\n')
    expect(source).not.toMatch(/resident_name|raised_by|full_name|household|principal\?\.name/)
  })
})

describe('the response so far, with the same honesty as the resident\'s screen', () => {
  it('ticks only recorded steps; a skipped one is "Not recorded"; the close only once it happened', () => {
    const steps = responseSteps(view({ status: 'responding', acknowledged_at: '2026-09-24T14:41:16Z', on_scene_at: '2026-09-24T14:45:00Z', responder: 'Andre Brown' }), when)
    expect(steps.map((s) => [s.key, s.done, s.detail])).toEqual([
      ['raised', true, 'at 14:41'],
      ['acknowledged', true, 'at 14:41'],
      ['dispatched', false, 'Not recorded'],
      ['en_route', false, 'Not recorded'],
      ['on_scene', true, 'at 14:45'],
    ])
  })

  it('"You were dispatched" when the control room sent this guard', () => {
    const steps = responseSteps(view({ status: 'responding', acknowledged_at: '2026-09-24T14:41:16Z', dispatched_at: '2026-09-24T14:42:10Z', dispatched_to_you: true, responder_is_you: true }), when)
    expect(steps.find((s) => s.key === 'dispatched')).toMatchObject({ title: 'You were dispatched', done: true })
    expect(steps.find((s) => s.key === 'en_route')).toMatchObject({ done: false, detail: null })
  })

  it('a closed alert shows its close', () => {
    const steps = responseSteps(view({ status: 'false_alarm', acknowledged_at: '2026-09-24T14:41:16Z', resolved_at: '2026-09-24T14:50:00Z' }), when)
    expect(steps[steps.length - 1]).toMatchObject({ key: 'resolved', title: 'Closed as a false alarm', done: true })
  })
})

describe('an old answer says how old it is', () => {
  it('only when it is not fresh', () => {
    expect(staleLine('2026-09-24T14:56:00Z', AT, true)).toBeNull()
    expect(staleLine('2026-09-24T14:56:00Z', AT, false)).toBe('Last updated 4 minutes ago: this may be out of date. The response moves on without this handset.')
    expect(staleLine('2026-09-24T14:59:40Z', AT, false)).toMatch(/^Last updated less than a minute ago/)
    expect(staleLine('2026-09-24T12:59:00Z', AT, false)).toMatch(/^Last updated 2 hours ago/)
    expect(staleLine(null, AT, false)).toBeNull()
  })
})

describe('what is kept on the phone', () => {
  const row = (id: string, raisedAt: string, over: Partial<KeptAlert> = {}): KeptAlert => ({ id, kind: 'panic', where: null, raisedAt, drill: null, answer: null, answeredAt: null, ...over })

  it('a pulled row keeps its DRILL flag: the one thing alerts.show does not say', () => {
    expect(toKept({ alert_id: 51, kind: 'panic', where: 'Lot 9', raised_at: '2026-09-24T14:41:00Z', is_drill: true })).toMatchObject({ id: '51', where: 'Lot 9', drill: true })
    expect(toKept({ alert_id: 52, kind: 'panic', raised_at: '2026-09-24T14:41:00Z' })?.drill).toBeNull()
    expect(toKept({ alert_id: '../x', raised_at: '2026-09-24T14:41:00Z' })).toBeNull()
    expect(toKept({ alert_id: 53, raised_at: 'yesterday' })).toBeNull()
  })

  it('a drill stays a drill, and an answer is never lost, however often it is pulled again', () => {
    const answered = row('51', '2026-09-24T14:41:00Z', { drill: true, answer: view({ alert_id: 51, is_drill: true }), answeredAt: '2026-09-24T14:50:00Z' })
    const merged = mergeKept([answered], [row('51', '2026-09-24T14:41:00Z', { drill: null })], AT)
    expect(merged).toHaveLength(1)
    expect(merged[0]).toMatchObject({ drill: true, answeredAt: '2026-09-24T14:50:00Z' })
    expect(merged[0]!.answer?.alert_id).toBe(51)
  })

  it('a day, and twenty, and no more', () => {
    const old = new Date(AT.getTime() - (KEEP_HOURS * 3_600_000 + 60_000)).toISOString()
    expect(mergeKept([row('old', old), row('new', '2026-09-24T14:59:00Z')], [], AT).map((r) => r.id)).toEqual(['new'])
    const many = Array.from({ length: KEEP_MOST_RECENT + 5 }, (_, i) => row(String(i), new Date(AT.getTime() - i * 60_000).toISOString()))
    expect(mergeKept(many, [], AT)).toHaveLength(KEEP_MOST_RECENT)
  })

  it('what the poller pulled and what the server answered meet under one id', () => {
    rememberAlerts([{ alert_id: 61, kind: 'panic', where: 'Lot 2', raised_at: '2026-09-24T14:55:00Z', is_drill: true }], AT)
    // An answer that says nothing about DRILL (kept before the field existed) does not erase what the pull said.
    const kept = keepAnswer('61', older({ alert_id: 61, where: 'Lot 2', raised_at: '2026-09-24T14:55:00Z' }), AT)
    expect(kept).toMatchObject({ id: '61', drill: true, answeredAt: AT.toISOString() })
    expect(isDrillAlert(kept)).toBe(true)
    expect(keptAlert('61', AT)?.answer?.where).toBe('Lot 2')
  })
})

/*
 * The server now answers `is_drill` on alerts.show, en_route and on_scene (8c95dfe), so the officer sent to
 * a drill from the "DRILL — You are dispatched" push sees DRILL on the alert itself, with nothing pulled.
 */
describe('DRILL is the answer\'s own word', () => {
  it('opened straight from the dispatched push, before any pull: the answer alone says DRILL', () => {
    const kept = keepAnswer('71', view({ alert_id: 71, raised_at: '2026-09-24T14:58:00Z', is_drill: true }), AT)
    expect(kept.drill).toBe(true)
    expect(isDrillAlert(kept)).toBe(true)
    expect(isDrillAlert({ drill: null, answer: view({ is_drill: true }) })).toBe(true)
  })

  it('the answer outranks the pull, both ways', () => {
    expect(isDrillAlert({ drill: true, answer: view({ is_drill: false }) })).toBe(false)
    expect(isDrillAlert({ drill: false, answer: view({ is_drill: true }) })).toBe(true)
    // …and the newest word is what is kept.
    rememberAlerts([{ alert_id: 72, kind: 'panic', raised_at: '2026-09-24T14:56:00Z', is_drill: true }], AT)
    expect(keepAnswer('72', view({ alert_id: 72, raised_at: '2026-09-24T14:56:00Z', is_drill: false }), AT).drill).toBe(false)
  })

  it('only an answer with no is_drill at all falls back to the pull; only a true is a drill', () => {
    expect(isDrillAlert({ drill: true, answer: older() })).toBe(true)
    expect(isDrillAlert({ drill: null, answer: older() })).toBe(false)
    expect(isDrillAlert({ drill: null, answer: view({ is_drill: 'true' as unknown as boolean }) })).toBe(false)
    expect(isDrillAlert({ drill: true, answer: null })).toBe(true)
    expect(isDrillAlert(null)).toBe(false)
    // Opened from a push before any pull, with an answer that predates the field: honestly unknown, so no label.
    expect(keepAnswer('73', older({ alert_id: 73, raised_at: '2026-09-24T14:58:00Z' }), AT).drill).toBeNull()
  })

  it('the screen draws DRILL from that rule, and from nothing else', () => {
    const hook = readFileSync(path.resolve(__dirname, '..', 'useSiteAlert.ts'), 'utf8')
    expect(hook).toMatch(/drill: isDrillAlert\(kept\),/)
    const screen = readFileSync(path.resolve(__dirname, '..', 'screens', 'SiteAlertScreen.tsx'), 'utf8')
    // DRILL before the kind, the place, the steps and the buttons.
    const drillAt = screen.indexOf('{DRILL_LABEL}')
    for (const later of ['{m.where}', 's.timeline', "m.record('en_route')"]) expect(drillAt).toBeLessThan(screen.indexOf(later))
  })
})

describe('an id from a payload is checked before it is asked about', () => {
  it('digits and short slugs only', () => {
    expect(alertId('41')).toBe('41')
    expect(alertId(41)).toBe('41')
    for (const bad of ['', '../41', '41?x=1', {}, null, undefined]) expect(alertId(bad)).toBeNull()
  })
})

describe('the two writes go through the queue the catalogue allows them in', () => {
  it('queueable, batchable, and a write (Idempotency-Key and X-Device-Time fixed at the tap)', () => {
    for (const name of ['alerts.en_route', 'alerts.on_scene'] as const) {
      expect(ENDPOINTS[name]).toMatchObject({ method: 'POST', write: true, batch: true, apps: ['guard'] })
      expect(canQueue(name)).toBe(true)
    }
  })

  it('the hook sends them through submit(), with the alert as a path parameter and no body', () => {
    const hook = readFileSync(path.resolve(__dirname, '..', 'useSiteAlert.ts'), 'utf8')
    expect(hook).toMatch(/await submit\(\{\s*endpoint: ENDPOINT\[step\],\s*params: \{ alert: id \},\s*body: null,/)
    expect(hook).not.toMatch(/useAlertsEnRouteMutation|useAlertsOnSceneMutation/)
  })
})
