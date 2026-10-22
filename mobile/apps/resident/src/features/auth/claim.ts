/**
 * The unit claim's pure logic — no React, no UI kit, so Jest can load it.
 *
 *   claimSchema    what the claim form accepts; the limits are the server's own
 *                  (full_name 2–160, lot ≤ 32, phase ≤ 64, phone ≤ 40, relationship ∈ the list).
 *   claimBody      the form → the request body: optional fields left OUT when empty, never sent as "".
 *   claimView      `GET /me` → which holding screen to draw. The account's status wins over the
 *                  claim's: an approved claim activates the account "on its next request", so
 *                  `account.status: active` is the only thing that opens the app.
 *
 * An account is NEVER linked by matching a contact on the register — the estate's approval of
 * this claim is the only link (D-099). Nothing here guesses at an outcome.
 */

import { z } from 'zod'
import type { Api } from '@gs/core/api'
import { dateLong, dateShort, normalisePhone, phoneProblem, phoneWords, timeWords } from '@gs/core/lib'

export const RELATIONSHIPS = ['owner', 'tenant', 'spouse', 'child', 'parent', 'relative', 'other'] as const
export type Relationship = (typeof RELATIONSHIPS)[number]

export const RELATIONSHIP_LABEL: Record<Relationship, string> = {
  owner: 'Owner',
  tenant: 'Tenant',
  spouse: 'Spouse',
  child: 'Child',
  parent: 'Parent',
  relative: 'Relative',
  other: 'Other',
}

/*
 * NOTHING IS OPTIONAL (M18C): the phone the office reaches you on, and your link to the home, are required. The phase
 * or block is ADDITIONAL (§3.3) — extra help finding the lot — and asked under its own heading on the form.
 */
export const claimSchema = z.object({
  full_name: z.string().trim().min(2, 'Enter your full name, as the estate knows you.').max(160, 'Keep your name to 160 characters or fewer.'),
  lot: z.string().trim().min(1, 'Enter your lot or unit number, e.g. 47.').max(32, 'Keep the lot to 32 characters or fewer.'),
  phase: z.string().trim().max(64, 'Keep the phase to 64 characters or fewer.'),
  phone: z
    .string()
    .trim()
    .max(40, 'Keep the phone number to 40 characters or fewer.')
    .superRefine((v, ctx) => {
      // The example is the estate's own country's, read when the form is checked (M32 F7).
      if (v === '') return void ctx.addIssue({ code: z.ZodIssueCode.custom, message: `Enter your phone number: the estate office calls it about this claim. ${phoneWords().example}` })
      const problem = phoneProblem(v)
      if (problem) ctx.addIssue({ code: z.ZodIssueCode.custom, message: problem })
    }),
  relationship: z.enum(RELATIONSHIPS).nullable(),
}).superRefine((values, ctx) => {
  if (values.relationship === null) ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['relationship'], message: 'Choose your link to this home.' })
})
export type ClaimForm = z.infer<typeof claimSchema>

export const CLAIM_FIELDS = ['full_name', 'lot', 'phase', 'phone', 'relationship'] as const
export type ClaimField = (typeof CLAIM_FIELDS)[number]

export function claimBody(form: ClaimForm): Api.AuthClaimUnitRequest {
  const phase = form.phase.trim()
  if (form.relationship === null) throw new Error('relationship not chosen')
  return {
    full_name: form.full_name.trim(),
    lot: form.lot.trim(),
    ...(phase ? { phase } : null),
    phone: normalisePhone(form.phone) ?? form.phone.trim(),
    relationship: form.relationship,
  }
}

/** A 422's `errors` → the form's fields (first sentence each); anything for a field the form does not have is returned as `rest`. */
export function claimFieldErrors(fieldErrors: Record<string, string[]>): { fields: Partial<Record<ClaimField, string>>; rest: string | null } {
  const fields: Partial<Record<ClaimField, string>> = {}
  const rest: string[] = []
  for (const [name, messages] of Object.entries(fieldErrors)) {
    const first = messages[0]
    if (!first) continue
    if ((CLAIM_FIELDS as readonly string[]).includes(name)) fields[name as ClaimField] = first
    else rest.push(first)
  }
  return { fields, rest: rest.length ? rest.join(' ') : null }
}

type Me = Api.MeShowResponse
export type Claim = NonNullable<Me['claim']>

export type ClaimView =
  /** The account is active: open the app. */
  | { kind: 'active' }
  /** Signed in, nothing claimed yet: the claim form is the only place to go. */
  | { kind: 'none' }
  | { kind: 'reviewing'; claim: Claim }
  /** Still pending, and the estate has asked for a document. There is no upload endpoint: it goes to the estate office. */
  | { kind: 'document'; claim: Claim; document: string }
  /** The claim says approved but the account has not been activated yet — the next answer will be `active`. */
  | { kind: 'approved'; claim: Claim }
  | { kind: 'rejected'; claim: Claim; reason: string | null }

export function claimView(me: Pick<Me, 'account' | 'claim'>): ClaimView {
  if (me.account.status === 'active') return { kind: 'active' }
  const claim = me.claim
  if (!claim) return { kind: 'none' }
  if (claim.status === 'rejected') return { kind: 'rejected', claim, reason: claim.decision_reason?.trim() || null }
  if (claim.status === 'approved') return { kind: 'approved', claim }
  const document = claim.document_requested?.trim()
  return document ? { kind: 'document', claim, document } : { kind: 'reviewing', claim }
}

/**
 * A CLAIM THAT CAME FROM AN INVITE (household invites, stage 2): the invited address was recognised at sign-in, so
 * there was no form to fill in, and the estate office confirms invited members with one tap.
 */
export const invitedPendingWords = (estate: string): { title: string; sub: string } => ({
  title: 'Your invite is with the estate office',
  sub: `${estate} confirms invited members with one tap. The app opens by itself when they do.`,
})

/** "Andrea Fletcher · 24 Sep 2026" — who invited this person, and when, at the site. Null for an ordinary claim. */
export function invitedByLine(claim: { invited_by?: string | null; invited_at?: string | null } | null | undefined): string | null {
  const by = (claim?.invited_by ?? '').trim()
  if (!by) return null
  const on = claim?.invited_at ? dateShort(claim.invited_at) : ''
  return on ? `${by} · ${on}` : by
}

/** "4 Sep 2026, 9:41 AM" — on the site’s clock, in the one form (M98), whatever zone the phone is in. */
export function submittedWhen(iso: string): string {
  return Number.isNaN(new Date(iso).getTime()) ? '' : `${dateLong(iso)}, ${timeWords(iso)}`
}
