/**
 * The profile feature's pure logic — no React, no UI kit — so it is unit-tested without a phone.
 *
 * WHAT THE SERVER KEEPS about a household: its members on the estate's register (name, relationship,
 * primary or not, verified or pending, phone, email, their ACCESS LEVEL in the app — Member, Dependent or
 * Helper — and the invite to the Resident App a pending member was emailed, and whether it went), its
 * vehicles (plate, make, model, colour) and its emergency contacts (name, relationship, phone). It keeps
 * NO pets and NO photo. It edits a member's level (`members.level`) and nothing else about a member, a
 * vehicle or a contact: those are added and removed, never edited.
 *
 * THE LEVEL IS THE SERVER'S RULE. What a level may do is `me.show.access.may`, from the matrix the server
 * enforces; anything this phone hides, the server refuses anyway (`403 not_for_your_access_level`). Until
 * the server opens levels (`access.levels_open`), everybody is a Member and the choice is not offered.
 *
 * THE FORMS mirror the server's own validation rules exactly (MeController): every one of these
 * writes is queueable ("Offline: Queue"), and a queued write's refusal arrives later as a sentence
 * with no per-field errors — so what the server would refuse is caught on the phone, in words.
 *
 *   members.store    full_name 2–160 · relationship one of seven · phone ≤ 40 · email valid ≤ 190
 *   vehicles.store   plate ≤ 16, letters digits spaces hyphens · make ≤ 40 · model ≤ 40 · colour ≤ 24
 *   contacts.store   name 2–160 · relationship ≤ 40 · phone 7–20 of digits, spaces, ( ) - and a leading +
 *   me.update        full_name 2–160 · phone ≤ 40 · email valid ≤ 190 — only what CHANGED is sent
 */

import type { Api } from '@gs/core/api'
import { dateShort, normalisePhone, phoneProblem, phoneWords, sinceWords } from '@gs/core/lib'
import { z } from 'zod'
import { numberKey } from '../panic/panicSms'

// ───────────────────────────────────────────────────────────── the server's shapes, narrowed

type ApiMember = Api.MembersIndexResponse['items'][number]
/** A pending member's invite to the Resident App, as the server says it stands (`ResidentInvites::outcome`). */
export type Invite = ApiMember['invite']
/**
 * The generated `relationship` is inferred from the contract's examples ("owner, tenant, spouse, child, …")
 * and `is_primary` from its description; the server sends more relationships, and a real boolean.
 * `invite` may be missing: a list this phone saved before the server sent one, or a row still only on this phone.
 */
export type Member = Omit<ApiMember, 'relationship' | 'is_primary' | 'invite'> & { relationship: string; is_primary: boolean | string | number; invite?: Invite | null }
export type Vehicle = Api.VehiclesIndexResponse['items'][number]
export type Contact = Api.ContactsIndexResponse['items'][number]
export type Pet = Api.PetsIndexResponse['items'][number]
export type PetKind = Pet['kind']
export type Relationship = Api.MembersStoreRequest['relationship']

export const isPrimary = (m: Pick<Member, 'is_primary'>): boolean => m.is_primary === true || m.is_primary === 1 || m.is_primary === '1' || m.is_primary === 'true'
export const isVerified = (m: Pick<Member, 'status'>): boolean => m.status === 'verified'

// ───────────────────────────────────────────────────────────── words

const tidy = (text: string | null | undefined): string => (text ?? '').trim().replace(/\s+/g, ' ')

/** "Andrea Fletcher" → "AF" · "Milo" → "M" · "" → "?" */
export function initialsOf(name: string | null | undefined): string {
  const words = tidy(name).split(' ').filter(Boolean)
  if (words.length === 0) return '?'
  const first = words[0]?.charAt(0) ?? ''
  const last = words.length > 1 ? (words[words.length - 1]?.charAt(0) ?? '') : ''
  return `${first}${last}`.toUpperCase()
}

/** The seven relationships `members.store` takes, in words a homeowner uses. */
export const RELATIONSHIPS: readonly { value: Relationship; label: string; hint: string }[] = [
  { value: 'spouse', label: 'Spouse or partner', hint: 'Husband, wife, partner' },
  { value: 'child', label: 'Child', hint: 'Son, daughter, a dependant' },
  { value: 'parent', label: 'Parent', hint: 'Mother, father, in-law' },
  { value: 'relative', label: 'Other relative', hint: 'Brother, sister, aunt, cousin…' },
  { value: 'tenant', label: 'Tenant', hint: 'Someone renting from you' },
  { value: 'domestic_staff', label: 'Household helper', hint: 'Helper, nanny, gardener, driver' },
  { value: 'other', label: 'Someone else', hint: 'Anyone else who lives at this address' },
]

export const isRelationship = (value: unknown): value is Relationship => RELATIONSHIPS.some((r) => r.value === value)

const sentenceCase = (text: string): string => {
  const words = text.replace(/[_-]+/g, ' ').trim()
  return words ? words.charAt(0).toUpperCase() + words.slice(1) : ''
}

/** A relationship in words. One this app does not know (the console may record its own) is shown as written. */
export function relationshipLabel(value: string | null | undefined): string {
  if (!value) return 'Member'
  if (value === 'owner') return 'Owner'
  if (value === 'spouse') return 'Spouse'
  if (value === 'domestic_staff') return 'Helper'
  if (value === 'relative') return 'Relative'
  if (value === 'other') return 'Member'
  return sentenceCase(value)
}

// ───────────────────────────────────────────────────────────── access levels

export type Level = 'member' | 'dependent' | 'helper'

/** The three levels, in the words the estate office reads too (`AccessLevels::describe`). */
export const LEVELS: readonly { value: Level; label: string; sentence: string }[] = [
  { value: 'member', label: 'Member', sentence: 'An adult of the household: passes, dues, voting and everything else. Only the primary resident adds or removes people.' },
  { value: 'dependent', label: 'Dependent', sentence: 'A child or an elderly relative: the panic button, their own e-pass, notices and maintenance. No payments, passes or voting.' },
  { value: 'helper', label: 'Helper', sentence: "Domestic staff: the panic button, with the household's emergency contacts to text or call from it, and their own e-pass to come and go. Nothing else about the household, its money or its votes." },
]

export const isLevel = (value: unknown): value is Level => value === 'member' || value === 'dependent' || value === 'helper'

export const levelLabel = (level: string | null | undefined): string => LEVELS.find((l) => l.value === level)?.label ?? 'Member'

/** The level a relationship suggests, pre-selected on the add form — the primary may choose another. */
export const levelFor = (relationship: string | null | undefined): Level => (relationship === 'child' ? 'dependent' : relationship === 'domestic_staff' ? 'helper' : 'member')

const RANK: Record<Level, number> = { member: 2, dependent: 1, helper: 0 }

/** Whether moving from one level to another takes something away. */
export const narrowing = (from: string | null | undefined, to: Level): boolean => RANK[to] < RANK[isLevel(from) ? from : 'member']

/** What a person loses, at once, at a level — the sheet's consequence line. */
export function lostWith(level: Level): string {
  if (level === 'dependent') return 'They lose visitor passes, dues, payments, meetings, voting and bookings at once. Panic and their own e-pass stay.'
  if (level === 'helper') return "They lose the household's details, visitor passes, dues, payments, notices, maintenance, meetings, voting and bookings at once. Panic, with the household's emergency contacts, and their own e-pass stay."
  return 'They can do everything a resident can except manage members.'
}

/** The pill beside a member: "Primary", else their LEVEL when the server sends one, else who they are to the household (an older server). */
/**
 * The row's chip (M47 §14.1, M48 §2.1): "Primary" for the household's one primary resident; everybody else their ACCESS
 * LEVEL — Member, Dependent or Helper — never the relationship, which lives in the member's sheet. A row the server sent
 * without a level is a Member: that is the server's own default for every household member.
 */
export const memberTag = (m: Pick<Member, 'is_primary'> & { access_level?: string | null }): string =>
  isPrimary(m) ? 'Primary' : levelLabel(m.access_level)

/** The hero's pill. The role is the estate's to set (read-only to the resident). */
export const roleTag = (m: Pick<Member, 'is_primary' | 'relationship'> | null): string | null => (m ? (isPrimary(m) ? 'Primary resident' : relationshipLabel(m.relationship)) : null)

// ───────────────────────────────────────────────────────────── who may add and remove

/**
 * Adding and removing members is the PRIMARY resident's right; a household member cannot invite.
 * The server says it (`me.household.can_manage_members`); before that answer has ever arrived, the
 * resident's own register entry says the same thing. `null` = not known yet — draw neither the
 * controls nor the "only the primary can" note.
 */
export function canManageMembers(flag: boolean | null | undefined, self: Pick<Member, 'is_primary'> | null | undefined): boolean | null {
  if (typeof flag === 'boolean') return flag
  return self ? isPrimary(self) : null
}

/** Who a remove control may be drawn beside: never the primary, never yourself, never a row that is still only on this phone. */
/**
 * The row is the person holding the phone. They are signed in, so an invite line under it ("No invite has been sent to
 * you yet. Send invite") would be nonsense, whatever the register says (found on the emulator, M19 §10.3).
 */
export const isSelf = (member: Pick<Member, 'id'>, selfId: number | string | null | undefined): boolean =>
  selfId !== null && selfId !== undefined && String(selfId) !== '' && String(member.id) === String(selfId)

export const removable = (member: Pick<Member, 'id' | 'is_primary'>, selfId: number | string | null | undefined, queued: boolean): boolean =>
  !queued && !isPrimary(member) && String(member.id) !== String(selfId ?? '') && /^\d+$/.test(String(member.id))

export const NOT_PRIMARY_NOTE = "Only your household's primary resident can add or remove members. Ask them to do it in their app, or contact the estate office."
export const REMOVE_OFFLINE = 'Removing someone needs a connection. It takes their access away at once, so it is never saved to do later.'

/** What removing means, said before it is done. */
export const removeSentence = (name: string): string =>
  `${tidy(name) || 'This person'} leaves your household's list at once and can no longer use the Resident App for this home. An invite you sent them is cancelled. The estate's record of passes they issued stays. To add them again later, add them as a new member.`

// ───────────────────────────────────────────────────────────── the invite to the Resident App

/*
 * A pending member with an email is emailed an invite the moment the server has them — and the answer says
 * whether the mail server ACCEPTED it. This phone never says "sent" on its own account: every line below is
 * the server's `invite.status`, and a failure is shown in the server's own words.
 *
 * RESEND waits 1 minute after the first send, then 2, then 5 after every one after (the server's timer —
 * `resend_after`). The countdown is this phone measuring the wait; the server still decides, and a press
 * it refuses comes back with its own sentence and the list is read again.
 */

export const INVITE_OFFLINE = 'Sending an invite needs a connection: whether it went is the answer, so it is never saved to send later.'

/** What cancelling does, said before it is done — and nothing it does not do. */
export const cancelInviteSentence = (name: string): string =>
  `The invite shows as cancelled, to you and to the estate office, and ${tidy(name) || 'they'} stay${tidy(name) ? 's' : ''} on your household as Pending. An email that has already arrived cannot be taken back. To take them off your household, remove them instead.`

export type InviteTone = 'ok' | 'warn' | 'bad' | 'quiet'

/**
 * " 2 minutes ago", " yesterday at 4:15 PM JA", " on Thu, Sep 24" — WHEN AN INVITE WENT, in words that belong to no clock
 * (M19A §2.3, §4.1). "Invite emailed … at 9:38 AM" read from Pakistan at 7:39 PM looked broken; "2 minutes ago" cannot.
 */
function whenSaid(iso: string | null, nowMs: number): string {
  if (!iso) return ''
  const since = sinceWords(iso, nowMs)
  if (!since) return ''
  return nowMs - new Date(iso).getTime() < 48 * 3_600_000 ? ` ${since}` : ` on ${dateShort(iso)}`
}

/**
 * The line under a member, from the server's word alone. `null` = no line: a verified member, or a server that
 * has not said (an older saved list, a row still on this phone).
 */
export function inviteLine(member: Pick<Member, 'status' | 'email' | 'invite'> & { phone?: string | null }, nowMs: number): { text: string; tone: InviteTone } | null {
  if (isVerified(member)) return null
  const invite = member.invite
  if (!invite) return null
  const email = tidy(invite.email ?? member.email)
  switch (invite.status) {
    case 'sent':
      return { text: `Invite emailed to ${email}${whenSaid(invite.sent_at, nowMs)}. They sign in with that address; the estate office confirms them, usually with one tap (or at once, where the estate trusts its residents' invites).`, tone: 'ok' }
    case 'not_sent':
      return { text: `Invite not sent. ${tidy(invite.reason) || 'The mail server did not accept it.'}`, tone: 'bad' }
    case 'expired':
      return { text: `The invite to ${email} has lapsed. Send it again to give them another fortnight.`, tone: 'warn' }
    case 'cancelled':
      return { text: 'Invite cancelled.', tone: 'quiet' }
    case 'claimed':
      return { text: 'They have signed in with that address. The estate office confirms them next, usually with one tap.', tone: 'ok' }
    default:
      if (email) return { text: `No invite has been sent to ${email} yet.`, tone: 'warn' }
      // Invites go by email only until the app signs in by text (D-157) — true on every estate, whatever its SMS set-up.
      return tidy(member.phone)
        ? { text: 'Invites go by email for now: add an email to invite them.', tone: 'quiet' }
        : { text: 'No email address, so no invite can be sent. To invite them, remove them and add them again with one, or ask the estate office to add it.', tone: 'quiet' }
  }
}

/** Whole seconds until Resend may be pressed, by the server's `resend_after`; 0 = now. */
export function resendWait(invite: Pick<Invite, 'resend_after'> | null | undefined, nowMs: number): number {
  const at = invite?.resend_after ? Date.parse(invite.resend_after) : NaN
  return Number.isFinite(at) ? Math.max(0, Math.ceil((at - nowMs) / 1000)) : 0
}

/** 105 → "1:45" */
export const mmss = (seconds: number): string => {
  const s = Math.max(0, Math.floor(seconds))
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
}

/**
 * What the primary resident may do about a member's invite. Only a PENDING member, with an address, who has not
 * already claimed the home, can be sent one; only one that is standing (sent, not sent, lapsed) can be cancelled.
 */
export function inviteActions(member: Pick<Member, 'status' | 'email' | 'invite'>, nowMs: number): { send: { label: string; wait: number } | null; cancel: boolean } {
  const invite = member.invite
  if (isVerified(member) || !invite || invite.status === 'claimed') return { send: null, cancel: false }
  const email = tidy(invite.email ?? member.email)
  const standing = invite.status === 'sent' || invite.status === 'not_sent' || invite.status === 'expired'
  if (!email) return { send: null, cancel: standing }
  const fresh = invite.status === 'none' || invite.status === 'cancelled'
  const wait = fresh ? 0 : resendWait(invite, nowMs)
  const label = wait > 0 ? `Resend in ${mmss(wait)}` : fresh ? 'Send invite' : 'Resend invite'
  return { send: { label, wait }, cancel: standing }
}

/** "Phoenix Park Village 1 · Phase 2 · Lot 47" — whichever parts exist. */
export const unitLine = (estate: string | null | undefined, unit: { phase?: string | null; reference?: string | null } | null | undefined): string =>
  [tidy(estate), tidy(unit?.phase), tidy(unit?.reference)].filter(Boolean).join(' · ')

/** "Grey Honda CR-V · PC 4471" — a vehicle with only a plate is just its plate. */
export function vehicleLine(v: Pick<Vehicle, 'plate' | 'make' | 'model' | 'colour'>): string {
  const what = [tidy(v.colour), tidy(v.make), tidy(v.model)].filter(Boolean).join(' ')
  return what ? `${what} · ${tidy(v.plate)}` : tidy(v.plate)
}

/**
 * ONE PHONE FORMAT, EVERYWHERE (M50 §6.11): "+1 876 555 0148" — however it was typed or stored ("876-555-0148",
 * "8765550148"). A number this app cannot read as one (another country's, written without its +) is shown as written.
 */
export const phoneShown = (phone: string | null | undefined): string => normalisePhone(phone) ?? tidy(phone)

/** "Marlon Fletcher · Brother · +1 876 555 0192" */
export const contactLine = (c: Pick<Contact, 'name' | 'relationship' | 'phone'>): string => [tidy(c.name), tidy(c.relationship), phoneShown(c.phone)].filter(Boolean).join(' · ')

/** The server's own rule for a plate: trimmed, single-spaced, upper-cased. */
export const normalisePlate = (plate: string): string => tidy(plate).toUpperCase()

// ───────────────────────────────────────────────────────────── the forms

export const NAME_MIN = 2
export const NAME_MAX = 160
export const PHONE_MAX = 40
export const EMAIL_MAX = 190
export const PLATE_MAX = 16
export const MAKE_MAX = 40
export const COLOUR_MAX = 24
export const CONTACT_RELATIONSHIP_MAX = 40

const PLATE = /^[A-Za-z0-9 -]+$/
/** contacts.store: `^\+?[\d\s\-()]{7,20}$` */
const CONTACT_PHONE = /^\+?[\d\s\-()]{7,20}$/
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

const optionalEmail = z
  .string()
  .trim()
  .max(EMAIL_MAX, `Keep the email to ${EMAIL_MAX} characters or fewer.`)
  .refine((value) => value === '' || EMAIL.test(value), 'That does not look like an email address. Example: chloe.fletcher@gmail.com.')

/*
 * NOTHING IS OPTIONAL (M18C). A panic emails the household and puts its numbers in front of the dispatcher, so a blank
 * is somebody nobody can reach. The number is read the server's way (`phoneProblem`): spaces, dashes, a leading 1 or +1,
 * or another country's with its +; the sentence beside the field says what a right one looks like.
 */
// `missing` ends with the estate's own example, read when the form is checked (M32 F7): the estate's country, not the phone's.
const requiredPhone = (missing: string) =>
  z
    .string()
    .trim()
    .max(PHONE_MAX, `Keep the phone number to ${PHONE_MAX} characters or fewer.`)
    .superRefine((value, ctx) => {
      if (value === '') return void ctx.addIssue({ code: z.ZodIssueCode.custom, message: `${missing} ${phoneWords().example}` })
      const problem = phoneProblem(value)
      if (problem) ctx.addIssue({ code: z.ZodIssueCode.custom, message: problem })
    })

const requiredEmail = (missing: string) => optionalEmail.refine((value) => value !== '', missing)

const optionalPhone = z.string().trim().max(PHONE_MAX, `Keep the phone number to ${PHONE_MAX} characters or fewer.`)

const personName = (tooShort: string) => z.string().trim().min(NAME_MIN, tooShort).max(NAME_MAX, `Keep the name to ${NAME_MAX} characters or fewer.`)

/* ---- a household member ---- */

export const memberSchema = z
  .object({
    full_name: personName('Enter their full name, as the estate office should know them.'),
    relationship: z.string().refine(isRelationship, 'Choose how they are related to your household.'),
    phone: requiredPhone('Enter their phone number: the control room calls it in an emergency.'),
    /**
     * REQUIRED (M47 §15m): their invite goes to it, and so do the household's alerts. The old "This person has no
     * email — alerts go to me instead" tick is gone with the design (§15e): no address, no member.
     */
    email: requiredEmail('Enter their email address: their invite to the app goes to it.'),
    /** Email them an invite to the Resident App the moment the server has them. Left out = yes. */
    invite: z.boolean().optional(),
    /** What they may do in the app. Left out = Member (and always Member until the server opens levels). */
    access_level: z.string().optional(),
  })
export type MemberForm = z.input<typeof memberSchema>
export type MemberField = keyof MemberForm
export const MEMBER_FIELDS: readonly MemberField[] = ['full_name', 'relationship', 'phone', 'email']
/**
 * "Send an app invite" starts OFF (the user, 8 Oct 2026, M52 §6: "inviting a person is something I choose rather than
 * something that happens because I filled in a form"). An invitation is a real email to whoever owns the address typed;
 * the board draws the switch on, and that difference is accepted in the release notes.
 */
export const EMPTY_MEMBER: MemberForm = { full_name: '', relationship: '', phone: '', email: '', invite: false }

export type MemberBody = Omit<Api.MembersStoreRequest, '__stamp'>

/**
 * Exactly the fields members.store takes. Empty optionals are left out, never sent as "". The email is always there
 * (required, M47 §15m), and `invite` always says which: the server's "left out = yes" is for an app that predates the
 * field, not a choice this one leaves to it.
 *
 * AN INVITE IS AN ONLINE-ONLY WRITE (D-158). An add captured with no connection is queued with `invite: false`,
 * so it can never send an invite hours or days later that the primary may no longer mean; once the member is on
 * the server, Send invite is offered. `access_level` goes only when one was chosen.
 */
export function memberBody(values: MemberForm, options: { online?: boolean } = {}): MemberBody {
  if (!isRelationship(values.relationship)) throw new Error('relationship not chosen')
  const phone = normalisePhone(values.phone) ?? tidy(values.phone)
  const email = values.email.trim().toLowerCase()
  const online = options.online !== false
  const level = isLevel(values.access_level) ? values.access_level : null
  return {
    full_name: tidy(values.full_name),
    relationship: values.relationship,
    phone,
    email,
    invite: online && values.invite !== false,
    ...(level ? { access_level: level } : null),
  }
}

/* ---- a vehicle ---- */

export const vehicleSchema = z.object({
  plate: z
    .string()
    .trim()
    .min(1, "Enter the vehicle's licence plate.")
    .max(PLATE_MAX, `A plate is ${PLATE_MAX} characters at most.`)
    .refine((value) => value === '' || PLATE.test(value), 'A plate has only letters, numbers, spaces and hyphens.'),
  // Required since 23 September 2026, on the client's instruction: a guard at the gate matches the car in
  // front of them against the register, and "PC 4471" alone does not tell them whether this is that car.
  make: z
    .string()
    .trim()
    .min(1, "Enter the vehicle's make: a guard checks it against the car at the gate.")
    .max(MAKE_MAX, `Keep the make to ${MAKE_MAX} characters or fewer.`),
  model: z
    .string()
    .trim()
    .min(1, "Enter the vehicle's model.")
    .max(MAKE_MAX, `Keep the model to ${MAKE_MAX} characters or fewer.`),
  colour: z
    .string()
    .trim()
    .min(1, "Enter the vehicle's colour.")
    .max(COLOUR_MAX, `Keep the colour to ${COLOUR_MAX} characters or fewer.`),
})
export type VehicleForm = z.input<typeof vehicleSchema>
export type VehicleField = keyof VehicleForm
export const VEHICLE_FIELDS: readonly VehicleField[] = ['plate', 'make', 'model', 'colour']
export const EMPTY_VEHICLE: VehicleForm = { plate: '', make: '', model: '', colour: '' }

export type VehicleBody = Omit<Api.VehiclesStoreRequest, '__stamp'>

export function vehicleBody(values: VehicleForm): VehicleBody {
  // All four are required now, so all four are always sent — no key is dropped for being empty.
  return { plate: normalisePlate(values.plate), make: tidy(values.make), model: tidy(values.model), colour: tidy(values.colour) }
}

/** The server answers a plate the household already has with the vehicle as it is — so the phone says so first and sends nothing. */
export const plateAlreadyListed = (plate: string, known: readonly Pick<Vehicle, 'plate'>[]): boolean => {
  const wanted = normalisePlate(plate)
  return wanted !== '' && known.some((v) => normalisePlate(v.plate) === wanted)
}

/**
 * A number already among the household's emergency contacts, by the panic screen's own rule (`numberKey`):
 * "876-555-0148" and "+1 876 555 0148" are one person to text, so they are one contact too.
 */
export const contactAlreadyListed = (phone: string, known: readonly Pick<Contact, 'phone'>[]): boolean => {
  const wanted = numberKey(phone)
  return wanted !== '' && known.some((c) => numberKey(String(c.phone ?? '')) === wanted)
}

export const contactAlreadySentence = (phone: string): string => `${tidy(phone)} is already one of your emergency contacts.`

/* ---- an emergency contact ---- */

export const contactSchema = z.object({
  name: personName("Enter the person's name."),
  // Nothing is optional (M18C): who they are to you, in your own words.
  relationship: z
    .string()
    .trim()
    .min(2, 'Say who they are to you: for example Sister, Neighbour, Doctor.')
    .max(CONTACT_RELATIONSHIP_MAX, `Keep this to ${CONTACT_RELATIONSHIP_MAX} characters or fewer.`),
  phone: requiredPhone('Enter the number to call in an emergency.'),
  // M12 §2.5, M18C: a panic emails them at once; the number is there for the control room to call. Both required.
  email: requiredEmail('Enter their email: a panic emails them at once. Example: marcia.brown@gmail.com.'),
})
export type ContactForm = z.input<typeof contactSchema>
export type ContactField = keyof ContactForm
export const CONTACT_FIELDS: readonly ContactField[] = ['name', 'relationship', 'phone', 'email']
export const EMPTY_CONTACT: ContactForm = { name: '', relationship: '', phone: '', email: '' }

export type ContactBody = Omit<Api.ContactsStoreRequest, '__stamp'>

export function contactBody(values: ContactForm): ContactBody {
  return { name: tidy(values.name), relationship: tidy(values.relationship), phone: normalisePhone(values.phone) ?? values.phone.trim(), email: values.email.trim() }
}

/**
 * WHO AN EMERGENCY CONTACT IS TO YOU (M50 §5.2) — chosen from a list, as Add household member chooses, but NOT the
 * household's list: a contact may be a doctor, a neighbour or a friend, who never lives at the address, and is never a
 * tenant or a helper. The server keeps the words (`relationship`, up to 40 characters), so a contact saved earlier in
 * the resident's own words ("cousin") is shown as it was written until another is chosen.
 */
export const CONTACT_RELATIONSHIPS: readonly { value: string; label: string }[] = [
  { value: 'Spouse', label: 'Spouse or partner' },
  { value: 'Parent', label: 'Parent' },
  { value: 'Child', label: 'Son or daughter' },
  { value: 'Sibling', label: 'Brother or sister' },
  { value: 'Relative', label: 'Other relative' },
  { value: 'Friend', label: 'Friend' },
  { value: 'Neighbour', label: 'Neighbour' },
  { value: 'Doctor', label: 'Doctor' },
  { value: 'Other', label: 'Someone else' },
]

/** The words a contact's relationship is shown in: the list's own label, else what was written. */
export const contactRelationshipLabel = (value: string | null | undefined): string =>
  CONTACT_RELATIONSHIPS.find((r) => r.value.toLowerCase() === tidy(value).toLowerCase())?.label ?? sentenceCase(tidy(value))

/* ---- a pet (M50 §5.3d) ---- */

/** What `pets.store` takes, in the order the chooser offers them. */
export const PET_KINDS: readonly { value: PetKind; label: string }[] = [
  { value: 'dog', label: 'Dog' },
  { value: 'cat', label: 'Cat' },
  { value: 'bird', label: 'Bird' },
  { value: 'fish', label: 'Fish' },
  { value: 'rabbit', label: 'Rabbit' },
  { value: 'other', label: 'Other' },
]

export const isPetKind = (value: unknown): value is PetKind => PET_KINDS.some((k) => k.value === value)
export const petKindLabel = (value: string | null | undefined): string => PET_KINDS.find((k) => k.value === value)?.label ?? sentenceCase(tidy(value))

export const PET_NAME_MAX = 40

export const petSchema = z.object({
  name: z.string().trim().min(1, "Enter your pet's name.").max(PET_NAME_MAX, `Keep the name to ${PET_NAME_MAX} characters or fewer.`),
  kind: z.string().refine(isPetKind, 'Choose what kind of pet it is.'),
})
export type PetForm = z.input<typeof petSchema>
export type PetField = keyof PetForm
export const PET_FIELDS: readonly PetField[] = ['name', 'kind']
export const EMPTY_PET: PetForm = { name: '', kind: '' }

export type PetBody = Omit<Api.PetsStoreRequest, '__stamp'>

export function petBody(values: PetForm): PetBody {
  if (!isPetKind(values.kind)) throw new Error('kind not chosen')
  return { name: tidy(values.name), kind: values.kind }
}

/** "Milo · dog" — the pill beside the vehicles, in the board's own words (board 17: the kind in small letters). */
export const petLine = (p: Pick<Pet, 'name' | 'kind'> & { kind_label?: string | null }): string => [tidy(p.name), (tidy(p.kind_label) || petKindLabel(p.kind)).toLowerCase()].filter(Boolean).join(' · ')

/** The same pet, by the server's own rule: its name in any capitals, and its kind. */
export const petAlreadyListed = (values: { name: string; kind: string }, known: readonly Pick<Pet, 'name' | 'kind'>[]): boolean => {
  const name = tidy(values.name).toLowerCase()
  return name !== '' && known.some((p) => tidy(p.name).toLowerCase() === name && p.kind === values.kind)
}

/* ---- the resident's own details ---- */

/**
 * WHICH of me.update's three fields this resident is offered. The server writes `full_name` to the ACCOUNT
 * and `phone` / `email` to the REGISTER entry — and once an account is linked to a register entry, every
 * screen and every server record uses the REGISTER's name, which "is the estate's". So:
 *   linked (the normal case)   phone and email; the name is shown read-only with where to change it
 *   not linked                 the account's name only (the server would silently drop phone and email)
 */
export type DetailsMode = 'register' | 'account'
export const detailsModeOf = (me: { resident: unknown | null }): DetailsMode => (me.resident ? 'register' : 'account')

export const detailsSchema = z.object({
  full_name: z.string().trim().max(NAME_MAX, `Keep the name to ${NAME_MAX} characters or fewer.`),
  // A number and an email can be changed, never blanked (M18C): somebody nobody can reach in an emergency.
  phone: optionalPhone.superRefine((value, ctx) => {
    const problem = value === '' ? null : phoneProblem(value)
    if (problem) ctx.addIssue({ code: z.ZodIssueCode.custom, message: problem })
  }),
  email: optionalEmail,
})
export type DetailsForm = z.input<typeof detailsSchema>
export type DetailsField = keyof DetailsForm
export const DETAILS_FIELDS: readonly DetailsField[] = ['full_name', 'phone', 'email']

export type DetailsBody = { full_name?: string; phone?: string; email?: string }

/**
 * Only what changed, and only what this mode is offered. `null` = nothing to send.
 * An emptied phone or email is sent as "" — the server reads that as "none" (nullable).
 *
 * `current` is what the form was SEEDED with — what the resident was looking at when they started
 * typing — not the server's latest answer. A field they never touched equals its seed and is not sent,
 * so a number the estate office corrected in the meantime is never quietly put back by this phone.
 */
export function detailsBody(values: DetailsForm, current: DetailsForm, mode: DetailsMode): DetailsBody | null {
  const body: DetailsBody = {}
  if (mode === 'account') {
    const name = tidy(values.full_name)
    if (name !== tidy(current.full_name)) body.full_name = name
  } else {
    const phone = tidy(values.phone)
    const email = values.email.trim().toLowerCase()
    if (phone !== tidy(current.phone)) body.phone = phone
    if (email !== current.email.trim().toLowerCase()) body.email = email
  }
  return Object.keys(body).length > 0 ? body : null
}

/** The same three values — so a form is only re-seeded when the server's answer really differs. */
export const sameDetails = (a: DetailsForm | null, b: DetailsForm | null): boolean =>
  a === b || (!!a && !!b && a.full_name === b.full_name && a.phone === b.phone && a.email === b.email)

/** The account's own name has the server's minimum; the register's is not the resident's to change. */
export const detailsNameError = (values: DetailsForm, mode: DetailsMode): string | null =>
  mode === 'account' && tidy(values.full_name).length < NAME_MIN ? 'Enter your full name.' : null

// ───────────────────────────────────────────────────────────── what a read can honestly say

/** What is known about a read that failed. `null` = it failed, and nothing more is known. */
export type ReadFailure = { status: number | 'network' | 'timeout'; code: string } | null

export type SectionStatus = 'loading' | 'ready' | 'error' | 'denied'

export const isDenied = (failure: ReadFailure): boolean => failure?.status === 403

/**
 * A 403 is the SERVER saying "not for this account" — it outranks whatever this phone saved earlier
 * (a household the account has since been unlinked from is not shown as if the signal were weak).
 * Any other failure keeps the saved copy.
 */
export function sectionStatusOf(input: { has: boolean; failed: boolean; failure: ReadFailure }): SectionStatus {
  if (isDenied(input.failure)) return 'denied'
  if (input.has) return 'ready'
  return input.failed ? 'error' : 'loading'
}

const DENIED_PENDING = "Your account is not linked to a home on the estate's register yet. Once the estate approves your claim, your household appears here."
const DENIED_OTHER = 'Your account cannot see this household. Ask your estate office to check your access.'

/**
 * `account_pending` is one 403 whose cause the app knows; a level refusal (`not_for_your_access_level`) is the
 * other, and its sentence is the server's — what it is for, the person's access, and who can change it — shown as
 * sent. Any other is not explained by guessing.
 */
export const deniedSentence = (code: string | null | undefined, message?: string | null): string => {
  if (code === 'account_pending') return DENIED_PENDING
  if (code === 'not_for_your_access_level' && tidy(message)) return tidy(message)
  return DENIED_OTHER
}

/**
 * Why a saved copy is on screen instead of a fresh one:
 *   offline      no connection (the phone says so, or a request never reached the server)
 *   unrefreshed  the server was reached and failed — "No connection" would be untrue
 * A 403 is neither: nothing saved is shown under it.
 */
export type Staleness = 'offline' | 'unrefreshed' | null

export function stalenessOf(input: { has: boolean; online: boolean; failures: readonly ReadFailure[] }): Staleness {
  if (!input.has) return null
  if (!input.online) return 'offline'
  const failed = input.failures.filter((failure) => !isDenied(failure))
  if (failed.length === 0) return null
  return failed.some((failure) => failure?.status === 'network' || failure?.status === 'timeout') ? 'offline' : 'unrefreshed'
}

export function staleSentence(staleness: Staleness, what: 'household' | 'details'): string | null {
  if (staleness === null) return null
  if (what === 'household') {
    return staleness === 'offline'
      ? 'No connection: this is your household as saved on this phone. It may be out of date.'
      : 'Your household could not be refreshed just now: this is how it was saved on this phone. Pull down to try again.'
  }
  return staleness === 'offline'
    ? 'No connection: these are your details as saved on this phone.'
    : 'Your details could not be refreshed just now: these are as saved on this phone.'
}

// ───────────────────────────────────────────────────────────── signing out: what would be lost

/**
 * What is still waiting on this phone, in the words each act was saved under ("Vehicle · 1234 AB") —
 * the first few, then how many more. Signing out deletes them; the resident is told WHICH, not only how many.
 */
export function unsentSummary(labels: readonly string[], show = 3): { shown: string[]; more: number } {
  const clean = labels.map((label) => tidy(label)).filter(Boolean)
  return { shown: clean.slice(0, show), more: Math.max(0, clean.length - show) }
}

// ───────────────────────────────────────────────────────────── what is still on this phone

export type QueueState = 'pending' | 'syncing' | 'confirmed' | 'refused'

/** A queued household write, as the outbox holds it. Declared here so this file stays free of the UI kit. */
export type HouseholdOp = {
  id: string
  endpoint: string
  /** The path's ids — which vehicle, pet or contact a change is to. */
  params?: Record<string, unknown> | null
  body: Record<string, unknown> | null
  state: QueueState
  error: { message: string } | null
  result: unknown
}

export const HOUSEHOLD_ENDPOINTS = ['members.store', 'vehicles.store', 'vehicles.update', 'pets.store', 'pets.update', 'contacts.store', 'contacts.update', 'me.update'] as const

/** A row of a list: the server's, or one that is still on this phone (with where it stands). */
export type Row<T> = { key: string; item: T; queue: Exclude<QueueState, 'confirmed'> | null; opId: string | null; refusal: string | null }

const text = (value: unknown): string => (typeof value === 'string' ? value : '')
const textOrNull = (value: unknown): string | null => (typeof value === 'string' && value.trim() !== '' ? value : null)
const hasId = (value: unknown): value is { id: number | string } => typeof value === 'object' && value !== null && 'id' in value && (typeof (value as { id: unknown }).id === 'number' || typeof (value as { id: unknown }).id === 'string')

/**
 * The server's list, then what this phone still holds for it:
 *   pending · syncing   drawn from what the resident typed, marked as not sent yet
 *   refused             drawn with the server's sentence, until the resident has read it
 *   confirmed           the server's own answer — shown only until the list has caught up (never twice)
 */
function mergeQueued<T extends { id: number | string }>(items: readonly T[], ops: readonly HouseholdOp[], endpoint: string, fromBody: (body: Record<string, unknown>, key: string) => T, fromResult: (result: unknown) => T | null): Row<T>[] {
  const rows: Row<T>[] = items.map((item) => ({ key: `s:${item.id}`, item, queue: null, opId: null, refusal: null }))
  const seen = new Set(items.map((item) => String(item.id)))
  for (const op of ops) {
    if (op.endpoint !== endpoint) continue
    if (op.state === 'confirmed') {
      const made = fromResult(op.result)
      if (made && !seen.has(String(made.id))) {
        seen.add(String(made.id))
        rows.push({ key: `s:${made.id}`, item: made, queue: null, opId: null, refusal: null })
      }
      continue
    }
    rows.push({ key: `q:${op.id}`, item: fromBody(op.body ?? {}, op.id), queue: op.state, opId: op.id, refusal: op.state === 'refused' ? (op.error?.message ?? 'The estate office did not accept this.') : null })
  }
  return rows
}

export const memberRows = (items: readonly Member[], ops: readonly HouseholdOp[]): Row<Member>[] =>
  mergeQueued<Member>(
    items,
    ops,
    'members.store',
    (body, key) => ({ id: key, full_name: text(body.full_name), relationship: text(body.relationship), is_primary: false, status: 'pending', phone: textOrNull(body.phone), email: textOrNull(body.email), alerts_to_primary: body.no_email === true, ...(isLevel(body.access_level) ? { access_level: body.access_level } : null) }),
    (result) => (hasId(result) && 'full_name' in result ? (result as Member) : null),
  )

/**
 * The queue's own record of having ADDED this member. A confirmed add is drawn "until the list has caught up" —
 * so once the member is removed and the list no longer holds them, that record would draw them back. It goes with them.
 */
export const addRecordsOf = (ops: readonly HouseholdOp[], memberId: number | string, endpoint: 'members.store' | 'contacts.store' | 'vehicles.store' | 'pets.store' = 'members.store'): string[] =>
  ops.filter((op) => op.endpoint === endpoint && op.state === 'confirmed' && hasId(op.result) && String(op.result.id) === String(memberId)).map((op) => op.id)

/**
 * A CHANGE STILL ON THIS PHONE (M50 §5.3–§5.4), laid over the row it changes: drawn as it was typed, and marked as not
 * sent yet. A change the server refused leaves the row as the server holds it — the edit screen said why at the time,
 * and the row is still there (only an ADD that was refused never existed).
 */
function withChanges<T extends { id: number | string }>(rows: Row<T>[], ops: readonly HouseholdOp[], endpoint: string, param: string, apply: (item: T, body: Record<string, unknown>) => T): Row<T>[] {
  return rows.map((row) => {
    let shown = row
    for (const op of ops) {
      if (op.endpoint !== endpoint || (op.state !== 'pending' && op.state !== 'syncing')) continue
      if (String(op.params?.[param] ?? '') !== String(row.item.id)) continue
      shown = { ...shown, item: apply(shown.item, op.body ?? {}), queue: op.state, opId: op.id, refusal: null }
    }
    return shown
  })
}

export const vehicleRows = (items: readonly Vehicle[], ops: readonly HouseholdOp[]): Row<Vehicle>[] =>
  withChanges(
    mergeQueued<Vehicle>(
      items,
      ops,
      'vehicles.store',
      (body, key) => ({ id: key, plate: normalisePlate(text(body.plate)), make: textOrNull(body.make), model: textOrNull(body.model), colour: textOrNull(body.colour) }),
      (result) => (hasId(result) && 'plate' in result ? (result as Vehicle) : null),
    ),
    ops,
    'vehicles.update',
    'vehicle',
    (v, body) => ({ ...v, plate: normalisePlate(text(body.plate)) || v.plate, make: textOrNull(body.make) ?? v.make, model: textOrNull(body.model) ?? v.model, colour: textOrNull(body.colour) ?? v.colour }),
  )

export const contactRows = (items: readonly Contact[], ops: readonly HouseholdOp[]): Row<Contact>[] =>
  withChanges(
    mergeQueued<Contact>(
      items,
      ops,
      'contacts.store',
      (body, key) => ({ id: key, name: text(body.name), relationship: textOrNull(body.relationship), phone: text(body.phone), email: textOrNull(body.email) }),
      (result) => (hasId(result) && 'name' in result && 'phone' in result ? (result as Contact) : null),
    ),
    ops,
    'contacts.update',
    'contact',
    (c, body) => ({ ...c, name: textOrNull(body.name) ?? c.name, relationship: textOrNull(body.relationship) ?? c.relationship, phone: textOrNull(body.phone) ?? c.phone, email: textOrNull(body.email) ?? c.email }),
  )

export const petRows = (items: readonly Pet[], ops: readonly HouseholdOp[]): Row<Pet>[] =>
  withChanges(
    mergeQueued<Pet>(
      items,
      ops,
      'pets.store',
      (body, key) => ({ id: key, name: text(body.name), kind: isPetKind(body.kind) ? body.kind : 'other', kind_label: petKindLabel(text(body.kind)) }),
      (result) => (hasId(result) && 'name' in result && 'kind' in result ? (result as Pet) : null),
    ),
    ops,
    'pets.update',
    'pet',
    (p, body) => (isPetKind(body.kind) ? { ...p, name: textOrNull(body.name) ?? p.name, kind: body.kind, kind_label: petKindLabel(body.kind) } : { ...p, name: textOrNull(body.name) ?? p.name }),
  )

/** The newest change to the resident's own details that the server has not answered "yes" to. */
export function detailsChange(ops: readonly HouseholdOp[]): { opId: string; state: Exclude<QueueState, 'confirmed'>; body: DetailsBody; refusal: string | null } | null {
  for (let i = ops.length - 1; i >= 0; i--) {
    const op = ops[i]
    if (!op || op.endpoint !== 'me.update' || op.state === 'confirmed') continue
    const body: DetailsBody = {}
    if (typeof op.body?.full_name === 'string') body.full_name = op.body.full_name
    if (typeof op.body?.phone === 'string') body.phone = op.body.phone
    if (typeof op.body?.email === 'string') body.email = op.body.email
    return { opId: op.id, state: op.state, body, refusal: op.state === 'refused' ? (op.error?.message ?? 'The estate office did not accept this change.') : null }
  }
  return null
}

/** How many household writes are still on this phone. */
export const waitingCount = (ops: readonly HouseholdOp[]): number => ops.filter((op) => (HOUSEHOLD_ENDPOINTS as readonly string[]).includes(op.endpoint) && (op.state === 'pending' || op.state === 'syncing')).length
