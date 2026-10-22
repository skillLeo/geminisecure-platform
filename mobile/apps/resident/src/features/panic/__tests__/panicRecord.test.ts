/**
 * THE ONE PANIC ON THIS PHONE, WHATEVER SCREEN IS OPEN — AND THROUGH A SIGN-OUT NOBODY CHOSE (B117, B118).
 *
 *   B117  a panic the queue delivered while the panic screen was closed stayed "saved — not yet received",
 *         because only that screen ever turned the queue's answer into the alert; a day later the queue's
 *         confirmed copy left and nothing could.
 *   B118  the thirty-minute idle window closing wiped the panic's record, and the resident was moved to Log In
 *         with no 119 and no word that the alert had not gone; after signing in, it went unseen.
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { getStorage } from '@gs/core/lib'
import { belongsToSomeoneElse, EMERGENCY_NUMBER, ownerOf, PANIC_RECORD_STORAGE_KEY, panicBox, panicNotice, settleHeldPanic, type Saved } from '../panicRecord'

const OPEN = { id: 7, status: 'open', cancellable_until: '2026-09-20T12:00:10Z', acknowledged_at: null, cancelled_at: null, server_time: '2026-09-20T12:00:00Z' }
const held = (extra: Partial<Saved> = {}): Saved => ({ alert: null, opId: 'op-1', pressedAt: '2026-09-20T12:00:00Z', withPosition: false, owner: 'account:5', ...extra })
const src = (rel: string) => readFileSync(path.join(__dirname, '..', '..', '..', rel), 'utf8')

beforeEach(async () => {
  await getStorage().clearAll()
})

describe('B117: the queue’s answer reaches the record with no panic screen open', () => {
  it('delivered: the record becomes the alert, so its confirmed copy can leave the queue without taking it along', async () => {
    await panicBox().setDurable(held())
    settleHeldPanic([{ id: 'op-1', state: 'confirmed', result: OPEN }])
    expect(panicBox().get()?.alert).toMatchObject({ id: 7, status: 'open' })
    expect(panicBox().get()?.why).toBeNull()
  })

  it('refused: the record says so, in the server’s words', async () => {
    await panicBox().setDurable(held({ why: 'no_signal' }))
    settleHeldPanic([{ id: 'op-1', state: 'refused', error: { message: 'Your account is suspended.' } }])
    expect(panicBox().get()).toMatchObject({ alert: null, why: 'refused', message: 'Your account is suspended.' })
  })

  it('anything else, or somebody else’s act, leaves it exactly as it was', async () => {
    await panicBox().setDurable(held({ why: 'slow' }))
    settleHeldPanic([{ id: 'op-1', state: 'pending' }, { id: 'op-2', state: 'confirmed', result: OPEN }])
    expect(panicBox().get()).toMatchObject({ alert: null, why: 'slow' })
  })

  it('is run by the offline runtime on every change to the queue', () => {
    expect(src('offline/runtime.ts')).toMatch(/onChange: \(ops\) => \{\s*store\.dispatch\(outboxChanged\(ops\)\)[\s\S]*?settleHeldPanic\(ops\)/)
  })
})

describe('B118: through a sign-out nobody chose, and to nobody else', () => {
  it('the record is kept beside the queue when the session ends by itself', () => {
    expect(PANIC_RECORD_STORAGE_KEY).toBe('v1:panic.active')
    // The keep list names the record (and, since D-M160, the pass drafts beside it).
    expect(src('store/index.ts')).toMatch(/clearEverything\('resident', store\.dispatch, reason, \[[^\]]*\bPANIC_RECORD_STORAGE_KEY\b[^\]]*\]\)/)
  })

  it('Log In says an alert that has not gone has not gone, with 119, and says nothing when there is none', () => {
    const notSent = panicNotice(held())
    expect(notSent?.title).toBe('YOUR EMERGENCY ALERT HAS NOT BEEN SENT')
    expect(notSent?.body).toContain(`call ${EMERGENCY_NUMBER} now`)
    expect(panicNotice(held({ why: 'refused', message: 'Not accepted.' }))?.title).toBe('YOUR EMERGENCY ALERT WAS NOT ACCEPTED')
    expect(panicNotice({ ...held(), alert: OPEN })?.title).toBe('YOUR EMERGENCY ALERT IS OPEN')
    expect(panicNotice({ ...held(), alert: { ...OPEN, status: 'resolved' } })).toBeNull()
    expect(panicNotice(null)).toBeNull()
  })

  // M47 §1.2 1j: the red "YOUR EMERGENCY ALERT IS OPEN" panel is deleted from Log In, as the board draws none. A held
  // alert still goes straight to the panic screen the moment the resident is back in (below), and Panic stays top
  // right on Home.
  it('Log In draws no alert panel', () => {
    const logIn = src('features/auth/screens/LogInScreen.tsx')
    expect(logIn).not.toMatch(/panicNotice\(|tel:/)
  })

  it('signing back in goes straight to the panic screen, where it is seen going', () => {
    // enterApp: straight to it, with the sign-in screens cleared from behind it.
    expect(src('features/auth/afterSignIn.ts')).toMatch(/panicNotice\(held\) && !belongsToSomeoneElse\(held, store\.getState\(\)\.session\.principal\)\) return enterApp\('\/panic'/)
    // Both ways back in go through it: the emailed code, and the fingerprint (M50 §3.2).
    expect(src('features/auth/screens/CodeScreen.tsx')).toMatch(/goAfterSignIn\(outcome\.next\)/)
    expect(src('features/auth/useAuthForms.ts')).toMatch(/goAfterSignIn\(/)
  })

  it('whoever signs in next on the phone is not shown somebody else’s', () => {
    expect(ownerOf({ id: 5 })).toBe('account:5')
    expect(belongsToSomeoneElse(held(), { id: 9 })).toBe(true)
    expect(belongsToSomeoneElse(held(), { id: 5 })).toBe(false)
    // A record from an older build has no owner: it is taken as the person signed in.
    expect(belongsToSomeoneElse(held({ owner: undefined }), { id: 9 })).toBe(false)
  })
})
