/**
 * The gate's verdicts, held to three invariants:
 *
 *   3  A GUARD NEVER SEES MONEY — a restricted household is one boolean in and one fixed
 *      sentence out. Whatever the server's text says, none of it reaches the screen.
 *   2  OFFLINE IS NEVER "VALID" — the handset's best verdict says it was checked on the phone.
 *      WRONG SITE NEVER SAYS WHICH SITE.
 *  11  NO DEAD ENDS — every refusal offers either an override (with a reason) or walk-up entry.
 */
import type { Api } from '@gs/core/api'
import type { OfflineVerdict } from '@gs/core/crypto'
import { entryCategory, entrySubject, fromHandset, fromServer, needsSignal, RESTRICTED_SENTENCE, windowLabel, type GateVerdict } from '../verdict'

const pass: NonNullable<Api.GateVerifyResponse['pass']> = {
  pass_id: '7d0c1b52-3f0e-4c58-9a51-5b8f2f6a4471',
  category: 'single',
  visitor_name: 'Marcia James',
  vehicle_plate: null,
  purpose: 'Family visit',
  valid_from: '2026-09-04T14:00:00-05:00',
  valid_to: '2026-09-04T16:00:00-05:00',
  single_use: true,
  status: 'active',
}

const server = (over: Partial<Api.GateVerifyResponse>): Api.GateVerifyResponse => ({
  verdict: 'valid', tone: 'green', headline: 'Valid pass', detail: 'Admit.', admit_allowed: true, pass, unit: 'Lot 47', household: 'Andrea Fletcher', access_restricted: false, server_time: '2026-09-04T14:41:00Z', ...over,
})

const everyString = (v: GateVerdict): string => JSON.stringify(v)

describe('invariant 3: restriction is a boolean in and a fixed sentence out', () => {
  const leaky = server({
    verdict: 'restricted', tone: 'amber', admit_allowed: false, access_restricted: true,
    headline: 'Household 94 days in arrears', detail: 'Balance J$38,450.00 overdue since June: payment plan declined.',
  })

  it('shows the fixed sentence and NOTHING of the server’s wording', () => {
    const v = fromServer(leaky)
    expect(v.code).toBe('restricted')
    expect(v.tone).toBe('amber')
    expect(v.detail).toBe(RESTRICTED_SENTENCE)
    expect(v.headline).toBe('ACCESS RESTRICTED')
    expect(v.admitAllowed).toBe(false)
    expect(everyString(v)).not.toMatch(/arrears|balance|J\$|38,?450|overdue|payment|plan|94/i)
  })

  it('the boolean wins even when the verdict string says valid', () => {
    const v = fromServer(server({ access_restricted: true }))
    expect(v.code).toBe('restricted')
    expect(v.admitAllowed).toBe(false)
    expect(v.detail).toBe(RESTRICTED_SENTENCE)
  })

  it('carries exactly these keys: an allowlist, so a new money field cannot ride along', () => {
    const v = fromServer(leaky)
    expect(Object.keys(v).sort()).toEqual(['admitAllowed', 'code', 'detail', 'headline', 'household', 'overrideAllowed', 'pass', 'source', 'tone', 'unit'])
    expect(Object.keys(v.pass!).sort()).toEqual(['category', 'id', 'plate', 'purpose', 'singleUse', 'validFrom', 'validTo', 'visitorName'])
  })
})

describe('invariant 2: what the handset may claim', () => {
  const held = { pass_id: pass.pass_id, valid_from: pass.valid_from, valid_to: pass.valid_to, single_use: true }

  it('never says VALID offline: it says it was checked on this phone, and why that is weaker', () => {
    const v = fromHandset({ verdict: 'valid_offline', pass: held })
    expect(v.source).toBe('offline')
    expect(v.code).toBe('valid_offline')
    expect(v.headline).not.toMatch(/^VALID/)
    expect(v.headline).toMatch(/CHECKED ON THIS PHONE/)
    expect(v.detail).toMatch(/cancellation made since cannot be known/i)
    expect(v.admitAllowed).toBe(true)
  })

  it('knows no name, unit or household offline: a QR code carries none', () => {
    const v = fromHandset({ verdict: 'valid_offline', pass: held })
    expect(v.pass?.visitorName).toBeNull()
    expect(v.unit).toBeNull()
    expect(v.household).toBeNull()
    expect(entrySubject(v)).toBe('Pass 7d0c1b52')
  })

  it('wrong site never says which site', () => {
    const v = fromHandset({ verdict: 'wrong_site' })
    expect(v.tone).toBe('red')
    expect(everyString(v)).not.toMatch(/phoenix|ocean|village|tenant|site_id/i)
    expect(v.pass).toBeNull()
    expect(v.admitAllowed).toBe(false)
    expect(v.overrideAllowed).toBe(false)
  })

  it('a pass on the cancelled list this phone last pulled is refused offline', () => {
    const cancelled = fromHandset({ verdict: 'revoked', reason: 'cancelled', pass: held })
    const used = fromHandset({ verdict: 'revoked', reason: 'used', pass: held })
    expect([cancelled.code, used.code]).toEqual(['cancelled', 'already_used'])
    expect(cancelled.admitAllowed || used.admitAllowed).toBe(false)
  })

  it('a typed short code with no signal is not guessed at', () => {
    const v = needsSignal()
    expect(v.admitAllowed).toBe(false)
    expect(v.overrideAllowed).toBe(false)
    expect(v.detail).toMatch(/Scan the QR code instead/)
  })
})

describe('invariant 11: no refusal is a dead end', () => {
  const refusals: OfflineVerdict['verdict'][] = ['malformed', 'wrong_site', 'unknown_key', 'bad_signature']
  it.each(refusals)('%s points the guard to walk-up entry', (verdict) => {
    const v = fromHandset({ verdict } as OfflineVerdict)
    expect(v.overrideAllowed).toBe(false)
    expect(v.detail).toMatch(/walk-up entry/i)
  })

  it.each(['cancelled', 'already_used', 'expired', 'not_yet_valid'] as const)('%s on a pass on record can be overridden with a reason', (verdict) => {
    const v = fromServer(server({ verdict, tone: 'red', admit_allowed: false }))
    expect(v.admitAllowed).toBe(false)
    expect(v.overrideAllowed).toBe(true)
    expect(v.detail).toMatch(/requires a note/i)
  })

  it('"valid" without admit_allowed or without a pass is never admitted on', () => {
    expect(fromServer(server({ admit_allowed: false })).admitAllowed).toBe(false)
    expect(fromServer(server({ pass: null })).admitAllowed).toBe(false)
  })
})

/**
 * Q-M44 — the estate office ended somebody's resident access, and the client's own
 * addition: at the gate they are a VISITOR and come in through the Visitor Entrance.
 */
describe('a person the estate office is no longer calling a resident', () => {
  const epass: NonNullable<Api.GateVerifyResponse['pass']> = { ...pass, category: 'resident', visitor_name: 'Delroy Former', purpose: 'Resident', single_use: false, status: 'cancelled' }

  const revoked = server({
    verdict: 'revoked_resident',
    tone: 'red',
    admit_allowed: false,
    pass: epass,
    headline: 'No longer a resident',
    detail: 'The estate office has ended this person’s resident access. They are no longer a resident of Lot 47. Do not admit them as a resident: direct them to the Visitor Entrance.',
  })

  it('says it plainly and sends them to the Visitor Entrance', () => {
    const v = fromServer(revoked)
    expect(v.code).toBe('revoked_resident')
    expect(v.tone).toBe('red')
    expect(v.headline).toBe('NO LONGER A RESIDENT')
    expect(v.detail).toMatch(/not a resident of this unit any more/i)
    expect(v.detail).toMatch(/Visitor Entrance/)
    expect(v.admitAllowed).toBe(false)
  })

  /*
   * NOT AN OVERRIDE. Every other red verdict on a pass ON RECORD offers one, because the
   * guard may have a reason to admit anyway. A resident e-pass says "admit this person AS A
   * RESIDENT", and that is the one thing this person is not — so the sheet's other button is
   * Walk-up entry, which is the Visitor Entrance, and there is still no dead end.
   */
  it('offers no override: the way in is the visitor entrance, not a note on the gate log', () => {
    const v = fromServer(revoked)
    expect(v.overrideAllowed).toBe(false)
    expect(v.detail).not.toMatch(/requires a note/i)
  })

  it('still carries the unit and household, so the sheet can say who they came to see', () => {
    const v = fromServer(revoked)
    expect(v.unit).toBe('Lot 47')
    expect(v.household).toBe('Andrea Fletcher')
    expect(v.pass?.visitorName).toBe('Delroy Former')
  })

  // Invariant 3 still holds: nothing about the household's money rides in on the new verdict.
  it('says nothing about money', () => {
    // `\bowed\b`, because "admitAllowed" contains those four letters and is not money.
    expect(everyString(fromServer(revoked))).not.toMatch(/arrears|balance|J\$|overdue|\bowed\b|payment/i)
  })

  // A restricted household is answered first, whatever else is true of the pass.
  it('is outranked by a restriction, which is checked first and on its own', () => {
    expect(fromServer({ ...revoked, access_restricted: true }).code).toBe('restricted')
  })

  it('a resident’s own e-pass, when it IS good, is labelled as one', () => {
    const v = fromServer(server({ pass: { ...epass, status: 'active' } }))
    expect(v.admitAllowed).toBe(true)
    expect(v.headline).toBe('VALID PASS · RESIDENT')
  })
})

describe('what the gate log is told', () => {
  it('maps the pass category to the log’s category', () => {
    expect(entryCategory(fromServer(server({})).pass)).toBe('Visitor')
    expect(entryCategory(fromServer(server({ pass: { ...pass, category: 'contractor' } })).pass)).toBe('Contractor')
    expect(entryCategory(fromServer(server({ pass: { ...pass, category: 'delivery' } })).pass)).toBe('Delivery')
    expect(entryCategory(null)).toBe('Visitor')
  })

  it('draws the window as the board does, in the site’s zone', () => {
    expect(windowLabel(fromServer(server({})).pass!)).toBe('2:00–4:00 PM')
    expect(windowLabel({ ...fromServer(server({})).pass!, validFrom: '2026-09-04T11:00:00-05:00', validTo: '2026-09-04T13:00:00-05:00' })).toBe('11:00 AM–1:00 PM')
    expect(windowLabel({ ...fromServer(server({})).pass!, validTo: '2026-09-11T16:00:00-05:00' })).toBe('4 Sep 2026 – 11 Sep 2026')
  })
})
