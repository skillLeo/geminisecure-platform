/**
 * A DEMONSTRATION SESSION CHANGES NOTHING, AND IS TOLD SO IN ORDINARY WORDS.
 *
 * "Explore a demo community" hands the phone a read-only token, so every write comes back
 * `403 missing_ability` — "This handset's token does not carry the ability this endpoint needs."
 * An App Store reviewer taps Invite visitor and reads a developer's error; worse, nine of these
 * acts are QUEUEABLE, so the screen said "saved" first and the refusal arrived after. That is a
 * faked offline success, which this app does not permit itself.
 *
 * Two things are held here. The sentence, and — the part that actually rots — that every write
 * hook in the app still asks for it. A refusal that one screen forgets is a screen that goes back
 * to showing the token error, and nobody would notice until a reviewer did.
 */

import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { DEMO_CANNOT_WRITE, demoRefusal } from '../demoWrites'

const src = (...parts: string[]) => readFileSync(join(__dirname, '..', '..', '..', ...parts), 'utf8')

describe('the sentence a demonstration session reads', () => {
  it('is about a demonstration, never about tokens, abilities or endpoints', () => {
    expect(DEMO_CANNOT_WRITE).toMatch(/demonstration community/i)
    expect(DEMO_CANNOT_WRITE).not.toMatch(/token|abilit|endpoint|handset|403|permission/i)
  })

  it('says what cannot happen, why it does not matter, and what to do instead', () => {
    expect(DEMO_CANNOT_WRITE).toMatch(/nothing here can be changed/i)
    // The reviewer is not left on a screen with no way on.
    expect(DEMO_CANNOT_WRITE).toMatch(/Leave/)
    expect(DEMO_CANNOT_WRITE).toMatch(/your own estate/i)
  })

  it('never claims the act was saved, queued or sent', () => {
    expect(DEMO_CANNOT_WRITE).not.toMatch(/\bsaved\b|\bqueued\b|\bsent\b|will be sent|try again later/i)
  })

  it('is given for the read-only door and for nothing else', () => {
    expect(demoRefusal(true)).toBe(DEMO_CANNOT_WRITE)
    // A real resident, AND the full demonstration account, which is meant to be able to write:
    // a reviewer signing in with the static code must be able to issue a pass and cast the ballot.
    expect(demoRefusal(false)).toBeNull()
  })
})

/*
 * EVERY WRITE IN THE RESIDENT APP, AND WHERE IT IS REFUSED.
 *
 * One row per act a reviewer can reach. `guard` is what the hook must contain: asking
 * `useDemoRefusal()` and stopping on it. If a new write screen is added without one, the last
 * test in this file fails with its name.
 */
const WRITE_HOOKS: { act: string; file: string[] }[] = [
  { act: 'invite a visitor / send a saved draft', file: ['features', 'visitors', 'useVisitors.ts'] },
  { act: "answer a guard's walk-up request", file: ['features', 'visitors', 'useApproval.ts'] },
  { act: 'cast the ballot', file: ['features', 'elections', 'useReview.ts'] },
  { act: 'reply to a meeting', file: ['features', 'meetings', 'useMeetings.ts'] },
  { act: 'report a security incident', file: ['features', 'incidents', 'useIncidentReport.ts'] },
  { act: 'report a maintenance problem', file: ['features', 'maintenance', 'useMaintenanceRequest.ts'] },
  { act: 'add a member, a vehicle, a contact; change my details', file: ['features', 'profile', 'useHouseholdForms.ts'] },
  { act: 'remove a household member', file: ['features', 'profile', 'useProfile.ts'] },
  { act: "send, resend or cancel a member's invite", file: ['features', 'profile', 'useInvites.ts'] },
  { act: 'how to pay, and AutoPay', file: ['features', 'dues', 'useDues.ts'] },
  { act: 'book an amenity, and cancel a booking', file: ['features', 'amenities', 'useAmenities.ts'] },
  { act: 'raise the emergency alert', file: ['features', 'panic', 'usePanic.ts'] },
  { act: 'mark a notice read', file: ['features', 'notices', 'useNotices.ts'] },
  { act: 'delete the account', file: ['features', 'settings', 'useDeleteAccount.ts'] },
]

describe('every write hook asks before it writes', () => {
  it.each(WRITE_HOOKS)('$act', ({ file }) => {
    const text = src(...file)
    // It asks …
    expect(text).toMatch(/useDemoRefusal\(\)|s\.session\.demo/)
    // … and it STOPS on the answer, rather than reading it and carrying on.
    expect(text).toMatch(/if \(refusal\)|if \(!refusal\)|online && !refusal|deleteProblem\(\{/)
  })

  it('and none of them writes the sentence out for itself', () => {
    for (const { file } of WRITE_HOOKS) {
      expect(src(...file)).not.toMatch(/demonstration community, so nothing here/i)
    }
  })
})

/*
 * THE BACKSTOP. Every queueable act goes through one function, and that function refuses a
 * demonstration session on its own — so a screen that forgets the guard above still cannot leave
 * an undeliverable act sitting on the phone under the word "saved".
 */
describe('nothing is ever captured on the phone in a demonstration session', () => {
  it('submit() refuses before it enqueues anything', () => {
    const runtime = src('offline', 'runtime.ts')
    expect(runtime).toMatch(/if \(store\.getState\(\)\.session\.demo\) throw new DemoWriteRefused\(\)/)
    // Before `outbox.enqueue`, not after it.
    expect(runtime.indexOf('throw new DemoWriteRefused()')).toBeLessThan(runtime.indexOf('outbox.enqueue({ ...input'))
  })

  it('and the emergency alert, which captures outside submit(), is refused before it captures', () => {
    const panic = src('features', 'panic', 'usePanic.ts')
    expect(panic.indexOf('if (refusal) return setQueuedWhy(refusal)')).toBeLessThan(panic.indexOf('raiseAlert('))
  })

  it('and the ballot is refused before the marks leave memory', () => {
    const review = src('features', 'elections', 'useReview.ts')
    expect(review.indexOf('if (refusal) return setProblem(refusal)')).toBeLessThan(review.indexOf('optionIdsOf(paper, ballotBox.take())'))
  })
})
