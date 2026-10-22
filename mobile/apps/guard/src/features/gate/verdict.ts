/**
 * One verdict shape for the gate, whether the server gave it or the handset did.
 *
 * INVARIANT 3 — A GUARD NEVER SEES MONEY. A household in arrears reaches this file as one
 * boolean, `access_restricted`, and leaves it as one fixed sentence. The server's own
 * wording for a restricted pass is deliberately NOT shown: whatever it may one day say,
 * the guard reads "Access restricted — contact management" and nothing more.
 *
 * INVARIANT 2 — OFFLINE IS NEVER "VALID". The handset can prove a pass is genuine, in its
 * window and not on the revoked list it last pulled. It cannot know a cancellation made a
 * minute ago, so the offline headline always says it was checked on this phone.
 *
 * WRONG SITE NEVER SAYS WHICH SITE.
 *
 * `revoked_resident` IS ONLINE ONLY, AND HONESTLY SO (Q-M44). Whether the estate office
 * has ended somebody's resident access is a fact only the server holds. Revoking cancels
 * the person's e-pass, so a handset with no signal refuses it on the revoked list it last
 * pulled and reads PASS CANCELLED — which is true of the pass, refuses the admission, and
 * is the most this phone actually knows. It is never dressed up as more.
 */

// From the lib entry, not the package root: this file is pure, and is tested without the UI kit loaded.
import { dateShort, time12 } from '@gs/core/lib'
import type { Api } from '@gs/core/api'
import type { OfflineVerdict } from '@gs/core/crypto'

export const RESTRICTED_SENTENCE = 'Access restricted: contact management'

export type VerdictCode =
  | 'valid' | 'valid_offline' | 'restricted' | 'revoked_resident' | 'cancelled' | 'already_used' | 'expired' | 'not_yet_valid'
  | 'wrong_site' | 'unknown_key' | 'bad_signature' | 'malformed' | 'unknown_pass' | 'needs_signal'

export type GatePass = {
  id: string
  visitorName: string | null
  /** `resident` is the resident's OWN e-pass — the credential a guard admits a resident on. */
  category: 'single' | 'recurring' | 'contractor' | 'delivery' | 'resident' | null
  purpose: string | null
  plate: string | null
  validFrom: string
  validTo: string
  singleUse: boolean
}

export type GateVerdict = {
  source: 'online' | 'offline'
  code: VerdictCode
  tone: 'green' | 'amber' | 'red'
  headline: string
  /** One sentence for the guard, or null when the headline and the pass say it all. */
  detail: string | null
  admitAllowed: boolean
  /** Admit against the advice, with a written reason (gate.override). Needs a pass on record, or one the handset proved genuine. */
  overrideAllowed: boolean
  pass: GatePass | null
  unit: string | null
  household: string | null
}

const KIND: Record<NonNullable<GatePass['category']>, string> = { single: 'SINGLE VISIT', recurring: 'RECURRING', contractor: 'CONTRACTOR', delivery: 'DELIVERY', resident: 'RESIDENT' }

/** The category the gate log takes, from the pass's own. */
export const entryCategory = (pass: GatePass | null): string =>
  pass?.category === 'contractor' ? 'Contractor' : pass?.category === 'delivery' ? 'Delivery' : pass?.category === 'resident' ? 'Resident' : 'Visitor'

/** Who the gate log names. Offline the handset knows no name — a QR code carries none, by design. */
export const entrySubject = (v: GateVerdict): string => v.pass?.visitorName ?? (v.pass ? `Pass ${v.pass.id.slice(0, 8)}` : 'Unverified pass holder')

export const windowLabel = (pass: GatePass): string => {
  // A pass that spans days (recurring, contractor) is read by its dates; a visit by its hours.
  if (dateShort(pass.validFrom) !== dateShort(pass.validTo)) return `${dateShort(pass.validFrom)} – ${dateShort(pass.validTo)}`
  const from = time12(pass.validFrom)
  const to = time12(pass.validTo)
  // "2:00–4:00 PM" when both ends share a meridiem, as the board draws it.
  return from.slice(-2) === to.slice(-2) ? `${from.slice(0, -3)}–${to}` : `${from}–${to}`
}

const RED: Record<string, { headline: string; detail: (p: GatePass | null) => string; override: boolean }> = {
  /*
   * THE ESTATE OFFICE ENDED THIS PERSON'S RESIDENT ACCESS (Q-M44). Not an override:
   * a resident e-pass says "admit this person as a resident" and that is the one thing
   * this person is not. The sheet's other button is Walk-up entry, which is exactly
   * where they go — the Visitor Entrance, booked in as a visitor like anybody else.
   */
  revoked_resident: {
    headline: 'NO LONGER A RESIDENT',
    detail: () =>
      'The estate office has ended this person’s resident access. They are not a resident of this unit any more. Do not admit them as a resident: direct them to the Visitor Entrance and book them in there as a visitor.',
    override: false,
  },
  cancelled: { headline: 'PASS CANCELLED', detail: () => 'The resident cancelled this pass. Admitting now requires a note, recorded on the gate log for your supervisor.', override: true },
  already_used: { headline: 'PASS ALREADY USED', detail: () => 'This single-visit pass has already been used. Admitting now requires a note, recorded on the gate log for your supervisor.', override: true },
  expired: { headline: 'EXPIRED PASS', detail: (p) => `This pass expired${p ? ` at ${time12(p.validTo)}` : ''}. Admitting now requires a note, recorded on the gate log for your supervisor.`, override: true },
  not_yet_valid: { headline: 'PASS NOT YET VALID', detail: (p) => `This pass${p ? ` starts at ${time12(p.validFrom)}` : ' is not valid yet'}. Admitting now requires a note, recorded on the gate log for your supervisor.`, override: true },
  wrong_site: { headline: 'NOT A PASS FOR THIS SITE', detail: () => 'This pass was not issued for this estate. Do not admit on it. Use walk-up entry if the visitor is expected here.', override: false },
  unknown_key: { headline: 'PASS CANNOT BE CHECKED', detail: () => 'This phone does not yet hold the key this pass was signed with. Connect to refresh, or use walk-up entry.', override: false },
  bad_signature: { headline: 'NOT A GENUINE PASS', detail: () => 'The signature on this code does not match. Do not admit on it. Use walk-up entry if the visitor is expected.', override: false },
  malformed: { headline: 'NOT A GEMINISECURE PASS', detail: () => 'This code is not a visitor pass. Ask for the QR pass the resident sent, or use walk-up entry.', override: false },
  unknown_pass: { headline: 'PASS NOT FOUND', detail: () => 'No pass with this code is on record for this estate. Check the code, or use walk-up entry.', override: false },
}

function red(source: GateVerdict['source'], code: VerdictCode, pass: GatePass | null, unit: string | null, household: string | null): GateVerdict {
  const r = RED[code] ?? RED.malformed!
  return { source, code, tone: 'red', headline: r.headline, detail: r.detail(pass), admitAllowed: false, overrideAllowed: r.override && pass !== null, pass, unit, household }
}

export function fromServer(answer: Api.GateVerifyResponse): GateVerdict {
  const p = answer.pass
  const pass: GatePass | null = p
    ? { id: String(p.pass_id), visitorName: p.visitor_name ?? null, category: p.category ?? null, purpose: p.purpose ?? null, plate: p.vehicle_plate ?? null, validFrom: p.valid_from, validTo: p.valid_to, singleUse: p.single_use === true }
    : null
  const unit = answer.unit ?? null
  const household = answer.household ?? null

  // The boolean is checked FIRST and on its own: restricted is restricted even if a verdict string ever disagrees.
  if (answer.access_restricted === true || answer.verdict === 'restricted') {
    return { source: 'online', code: 'restricted', tone: 'amber', headline: 'ACCESS RESTRICTED', detail: RESTRICTED_SENTENCE, admitAllowed: false, overrideAllowed: pass !== null, pass, unit, household }
  }
  if (answer.verdict === 'valid' && answer.admit_allowed === true && pass) {
    return { source: 'online', code: 'valid', tone: 'green', headline: `VALID PASS · ${pass.category ? KIND[pass.category] : pass.singleUse ? 'SINGLE VISIT' : 'VISITOR'}`, detail: null, admitAllowed: true, overrideAllowed: false, pass, unit, household }
  }
  return red('online', answer.verdict === 'valid' ? 'unknown_pass' : answer.verdict, pass, unit, household)
}

export function fromHandset(answer: OfflineVerdict): GateVerdict {
  const pass: GatePass | null =
    'pass' in answer ? { id: answer.pass.pass_id, visitorName: null, category: null, purpose: null, plate: null, validFrom: answer.pass.valid_from, validTo: answer.pass.valid_to, singleUse: answer.pass.single_use } : null

  if (answer.verdict === 'valid_offline' && pass) {
    return {
      source: 'offline',
      code: 'valid_offline',
      tone: 'green',
      headline: `GENUINE PASS · CHECKED ON THIS PHONE`,
      detail: 'No signal: the signature and time window are good, and it is not on the cancelled list this phone last received. A cancellation made since cannot be known here: the server checks again when this entry syncs.',
      admitAllowed: true,
      overrideAllowed: false,
      pass,
      unit: null,
      household: null,
    }
  }
  if (answer.verdict === 'revoked') return red('offline', answer.reason === 'used' ? 'already_used' : 'cancelled', pass, null, null)
  return red('offline', answer.verdict === 'valid_offline' ? 'malformed' : answer.verdict, pass, null, null)
}

/** A short code typed with no signal: the handset holds signatures' keys, not the estate's pass list. */
export const needsSignal = (): GateVerdict => ({
  source: 'offline',
  code: 'needs_signal',
  tone: 'amber',
  headline: 'NO SIGNAL · CODE CANNOT BE CHECKED',
  detail: 'A typed code can only be checked online. Scan the QR code instead, that works with no signal, or use walk-up entry.',
  admitAllowed: false,
  overrideAllowed: false,
  pass: null,
  unit: null,
  household: null,
})
