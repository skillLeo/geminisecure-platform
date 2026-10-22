/**
 * ONE BANNER FOR ONE THING — AND NEVER ONE BANNER FOR TWO THINGS (B134).
 *
 * The de-duplication collapsed a remote push onto a local banner whenever they shared (type, id). But one alert
 * is several events: the raise ("Panic alert"), and minutes later "You are dispatched" — the one push built to
 * tell an officer they were sent — carries the SAME type and id with `kind: dispatched`. A guard whose app had
 * already shown the local raise banner got no banner, no sound, nothing, for the next thirty minutes. The same
 * collapse dropped a ticket's or a booking's LATER status: the server's push for "Completed." was taken for the
 * local banner that had said "Assigned to a technician.".
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { createDedupe, dedupeKey } from '../dedupe'

describe('a different event about the same thing is news', () => {
  it('"You are dispatched" is shown after the app already showed the panic it is about', () => {
    let t = 0
    const d = createDedupe(() => t)
    expect(d.localAllowed({ type: 'site_alert', id: '16', kind: 'panic' }, { title: 'Panic alert', body: 'At Lot 1. Dispatch has it: respond as your orders say.' })).toBe(true)
    t += 90_000
    expect(d.remoteArrived({ type: 'site_alert', id: '16', kind: 'dispatched' }, { title: 'You are dispatched', body: 'You are dispatched to Lot 1, Phoenix Park. A resident pressed panic.' })).toBe(true)
  })

  it('…and without the words too: the kind alone tells them apart', () => {
    const d = createDedupe()
    d.localAllowed({ type: 'site_alert', id: 16, kind: 'panic' })
    expect(d.remoteArrived({ type: 'site_alert', id: '16', kind: 'dispatched' })).toBe(true)
  })

  it('the raise push for the SAME alert, in the same words, is still one banner', () => {
    const d = createDedupe()
    const words = { title: 'Panic alert', body: 'At Lot 1. Dispatch has it: respond as your orders say.' }
    expect(d.localAllowed({ type: 'site_alert', id: 16, kind: 'panic' }, words)).toBe(true)
    expect(d.remoteArrived({ type: 'site_alert', id: '16', kind: 'panic' }, words)).toBe(false)
  })

  it('a ticket that moved on again is announced; the same move is not announced twice', () => {
    const d = createDedupe()
    expect(d.localAllowed({ type: 'ticket_update', id: 41 }, { title: 'Request #12', body: 'Assigned to a technician.' })).toBe(true)
    expect(d.remoteArrived({ type: 'ticket_update', id: '41' }, { title: 'Request #12', body: 'Completed.' })).toBe(true)
    expect(d.localAllowed({ type: 'ticket_update', id: 41 }, { title: 'Request #12', body: 'Completed.' })).toBe(false)
  })

  it('a booking answered, then cancelled, is two banners', () => {
    const d = createDedupe()
    d.remoteArrived({ type: 'booking_decided', id: 7 }, { title: 'Your booking', body: 'Pool: confirmed.' })
    expect(d.localAllowed({ type: 'booking_decided', id: '7' }, { title: 'Your booking', body: 'Pool: cancelled.' })).toBe(true)
    expect(d.localAllowed({ type: 'booking_decided', id: '7' }, { title: 'Your booking', body: 'Pool: confirmed.' })).toBe(false)
  })

  it('the key is the type, the id and, when there is one, the kind', () => {
    expect(dedupeKey({ type: 'site_alert', id: 16 })).toBe('site_alert:16')
    expect(dedupeKey({ type: 'site_alert', id: '16', kind: 'dispatched' })).toBe('site_alert:16:dispatched')
    expect(dedupeKey({ type: 'site_alert', id: '16', kind: '../x' })).toBe('site_alert:16') // a kind that is not a slug is ignored, never trusted
  })
})

describe('the runtime hands over the words it is about to show', () => {
  const runtime = readFileSync(path.join(__dirname, '..', 'runtime.ts'), 'utf8')
  it('a remote push is compared by what it says, and so is a local banner', () => {
    expect(runtime).toMatch(/dedupe\.remoteArrived\(notification\.request\.content\.data as Payload \| undefined, notification\.request\.content\)/)
    expect(runtime).toMatch(/dedupe\.localAllowed\(input\.data, input\)/)
  })
})
