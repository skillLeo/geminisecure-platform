/**
 * The household forms — Add Household Member (board resident-app-32), and those no board draws: a vehicle, a pet and
 * an emergency contact (each added, or opened from Profile to change — M50 §5.2–§5.4), and my own contact details.
 *
 * EVERY ONE IS A QUEUEABLE WRITE (the contract's offline line is "Queue" for members.store, vehicles.store/update,
 * pets.store/update, contacts.store/update and me.update), so every one goes through `submit()`: captured on the
 * phone FIRST, with its Idempotency-Key, then sent — now, or when the signal returns. What happened
 * next is read from the outbox, and no screen says "added" until the server has said so:
 *
 *     Pending sync  →  Syncing  →  Confirmed        ·        Not accepted (the server's own sentence)
 *
 * A queued write's refusal arrives later and carries a sentence, not per-field errors — so each form's
 * own validation mirrors the server's rules exactly (household.ts) and is shown under the field.
 *
 * BOARD 32, LIVE. The relationship is one of the server's seven values. The "Access level" (Member ·
 * Dependent · Helper) is the server's own: it is always drawn, and a level below Member can be chosen once the
 * server has opened levels (`me.show.access.levels_open`); until then everybody is added as a Member, and the
 * screen says so when another is tapped. A new member is `pending` until the estate office verifies them, and
 * the screen says exactly that. The board's "Send an app invite" goes BY EMAIL (D-157; the server's
 * ResidentInvites has no other channel): with an address, the member is emailed an invite the moment the server
 * has them, and the answer says whether it went — but only from an add made ONLINE. An add saved with no
 * connection is queued with `invite: false` (D-158), and Send invite is offered once the member is on the server.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import * as Haptics from 'expo-haptics'
import { useForm, useFormState, useWatch, type Control, type FieldErrors, type FieldValues } from 'react-hook-form'
import { fidelityScreenId, isFidelity, useOfflineCache } from '@gs/core'
import { phoneWords, suggestEmail } from '@gs/core/lib'
import { isApiError, type EndpointName } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import type { OutboxState } from '@gs/core/offline'
import { addMemberFixture } from '@/fidelity/profile'
import { useAccess } from '@/features/access/useAccess'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { drain, drainNow, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import {
  CONTACT_FIELDS, CONTACT_RELATIONSHIPS, DETAILS_FIELDS, EMPTY_CONTACT, EMPTY_MEMBER, EMPTY_PET, EMPTY_VEHICLE, LEVELS, MEMBER_FIELDS, PET_FIELDS, PET_KINDS, RELATIONSHIPS, VEHICLE_FIELDS,
  canManageMembers, contactAlreadyListed, contactAlreadySentence, contactBody, contactRelationshipLabel, contactSchema, deniedSentence, detailsBody, detailsChange, detailsModeOf, detailsNameError, detailsSchema, isDenied, isLevel, isPetKind, isRelationship, levelFor, memberBody, memberSchema, normalisePlate, petAlreadyListed, petBody, petKindLabel, petLine, petSchema, plateAlreadyListed, sameDetails, sectionStatusOf, staleSentence, stalenessOf, vehicleBody, vehicleLine, vehicleSchema,
  type Contact, type ContactField, type ContactForm, type DetailsField, type DetailsForm, type DetailsMode, type HouseholdOp, type Level, type Member, type MemberField, type MemberForm, type Pet, type PetField, type PetForm, type PetKind, type ReadFailure, type Relationship, type Vehicle, type VehicleField, type VehicleForm,
} from './household'

/* ---- one queued write, and where it stands -------------------------------------------------------- */

export type Recorded = {
  /** Who or what was saved: "Marlon Fletcher", "PC 4471". */
  title: string
  state: OutboxState
  /** The server's sentence when it did not accept it. */
  refusal: string | null
  /** The server's answer once it has it (a member, with whether their invite went), else null. */
  result: unknown
}

export type QueuedWrite = {
  online: boolean
  busy: boolean
  /** It could not even be saved on the phone (storage) — said plainly, nothing is pretended. */
  saveError: string | null
  recorded: Recorded | null
  /** While it waits on this phone: try the queue again now (same Idempotency-Key — it cannot be sent twice). */
  retryNow: () => void
  /** After a refusal only: back to the form with everything still written. The refusal has been read; it leaves the queue. */
  changeAndResend: () => void
}

const RETRY_EVERY_MS = 30_000

type CaptureInput = { endpoint: EndpointName; params?: Record<string, string | number>; body: Record<string, unknown>; label: string; title: string }

function useQueuedWrite(): QueuedWrite & { capture: (input: CaptureInput) => Promise<void>; clear: () => void } {
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()
  const ops = useAppSelector((s) => s.outbox.ops)
  const [busy, setBusy] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [done, setDone] = useState<{ title: string; opId: string } | null>(null)
  // State lands a render late; two fast taps must not add the same person twice.
  const inFlight = useRef(false)

  /*
   * ONE GUARD FOR FOUR FORMS: a household member, a vehicle, an emergency contact and a
   * change of details all reach the server through here. Refused BEFORE `submit()`, because
   * these are the queueable writes — captured on the phone first — so without this a
   * demonstration session was told "Marlon Fletcher · saved" and then, a second later, "Not
   * accepted: this handset's token does not carry the ability this endpoint needs."
   */
  const capture = useCallback(async (input: CaptureInput) => {
    if (isFidelity || inFlight.current) return
    if (refusal) return setSaveError(refusal)
    inFlight.current = true
    setBusy(true)
    setSaveError(null)
    try {
      const op = await submit({ endpoint: input.endpoint, ...(input.params ? { params: input.params } : null), body: input.body, label: input.label, kind: 'household' })
      setDone({ title: input.title, opId: op.id })
      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined)
    } catch {
      setSaveError('That could not be saved on this phone, so nothing has been sent. Try again.')
    } finally {
      inFlight.current = false
      setBusy(false)
    }
  }, [refusal])

  const op = done ? ops.find((o) => o.id === done.opId) : undefined
  // An act that has left the queue was confirmed (a pending one can never be dismissed).
  const state: OutboxState = op?.state ?? 'confirmed'
  // The store's mirror carries no answers; the queue itself does. Read again whenever the mirror moves.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const result = useMemo(() => (done && state === 'confirmed' ? (outbox.all().find((o) => o.id === done.opId)?.result ?? null) : null), [done, state, ops])

  // The queue is sent when the phone reports signal again. A weak link fails without ever reporting
  // "offline", so while a saved change is on screen the queue is also tried every half minute.
  const waiting = !!done && state === 'pending'
  useEffect(() => {
    if (!waiting) return
    const timer = setInterval(() => void drain().catch(() => undefined), RETRY_EVERY_MS)
    return () => clearInterval(timer)
  }, [waiting])

  return {
    online,
    busy,
    saveError,
    recorded: done ? { title: done.title, state, refusal: state === 'refused' ? (op?.error?.message ?? 'The estate office did not accept this.') : null, result } : null,
    // A person pressed "Try sending now": go at once, whatever failed a moment ago (an automatic drain waits 4 s after a network failure).
    retryNow: () => void drainNow().catch(() => undefined),
    changeAndResend: () => {
      if (!done || state !== 'refused') return
      void outbox.dismiss(done.opId)
      setDone(null)
    },
    capture,
    clear: () => setDone(null),
  }
}

function flatten<F extends FieldValues, K extends keyof F & string>(errors: FieldErrors<F>, fields: readonly K[]): Partial<Record<K, string>> {
  const flat: Partial<Record<K, string>> = {}
  for (const field of fields) {
    const message = (errors as Record<string, { message?: unknown } | undefined>)[field]?.message
    if (typeof message === 'string' && message) flat[field] = message
  }
  return flat
}

const firstOf = <K extends string>(flat: Partial<Record<K, string>>, fields: readonly K[]): string | null => fields.map((field) => flat[field]).find((message) => !!message) ?? null

/* ---- add a household member (board 32) ------------------------------------------------------------ */

export type AddMemberModel = QueuedWrite & {
  /**
   * Who may add members: the PRIMARY resident (`me.household.can_manage_members`, else their own register entry).
   * `null` = not known yet. Always yes for the board's own picture.
   */
  allowed: boolean | null
  /** The board draws Full name focused — in fidelity mode, where the design's own state is drawn, and nowhere else. */
  nameLit: boolean
  control: Control<MemberForm>
  errors: Partial<Record<MemberField, string>>
  firstError: string | null
  relationships: typeof RELATIONSHIPS
  relationship: Relationship | null
  /** The chosen relationship in words (the board's own word in the fidelity build). */
  relationshipLabel: string | null
  chooseRelationship: (value: Relationship) => void
  /** An email has been typed — an invite has somewhere to go. */
  hasEmail: boolean
  /** "Did you mean chloe@gmail.com?" for a common misspelling of a provider (M18G §4.1) — a question, never a block. */
  emailSuggestion: string | null
  acceptEmailSuggestion: () => void
  /**
   * "Send an app invite" — the resident's own choice, ON until they switch it off (M47 §15k). A save with no connection
   * still sends no invite (D-158); the answer after Save says so, and Profile offers Send invite once they are added.
   */
  invite: boolean
  toggleInvite: () => void
  /** Whether the server lets anybody be given less than Member yet. */
  levelsOpen: boolean
  levels: typeof LEVELS
  /** The level they will be added at: the one chosen, else the one their relationship suggests — and Member until levels open. */
  level: Level
  /** Dependent or Helper was tapped while the server still gives Member only: said under the control, never in a dialog. */
  levelRefused: boolean
  chooseLevel: (value: Level) => void
  /** The add was saved with no connection, so no invite goes with it (D-158). */
  capturedOffline: boolean
  /** The member as the server answered — with whether their invite went — once it has them. */
  added: Member | null
  send: () => void
  /** A clean form for another person. */
  startAnother: () => void
}

/** A confirmed members.store answer is the member; anything else is not drawn as one. */
const memberOf = (result: unknown): Member | null =>
  typeof result === 'object' && result !== null && 'id' in result && 'full_name' in result ? (result as Member) : null

export function useAddMember(): AddMemberModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-32' ? addMemberFixture : null
  const write = useQueuedWrite()

  const form = useForm<MemberForm>({
    resolver: zodResolver(memberSchema),
    mode: 'onTouched',
    // The board's own content in the fidelity build — its switch is drawn on; a resident's form starts with it off (M52 §6).
    defaultValues: fixture ? { ...EMPTY_MEMBER, full_name: fixture.fullName, relationship: fixture.relationship, invite: true } : EMPTY_MEMBER,
  })
  const errors = flatten(useFormState({ control: form.control }).errors, MEMBER_FIELDS)
  const picked = useWatch({ control: form.control, name: 'relationship' }) ?? ''
  const relationship = isRelationship(picked) ? picked : null
  const typedEmail = useWatch({ control: form.control, name: 'email' }) ?? ''
  const invite = useWatch({ control: form.control, name: 'invite' }) !== false
  const [levelRefused, setLevelRefused] = useState(false)
  const chosen = useWatch({ control: form.control, name: 'access_level' })
  const level: Level = isLevel(chosen) ? chosen : levelFor(relationship)

  const { access } = useAccess()
  const levelsOpen = access?.levels_open === true
  const [capturedOffline, setCapturedOffline] = useState(false)

  // Adding members is the primary resident's right; whoever reaches the screen another way is told who can.
  const household = residentApi.useMeHouseholdQuery(undefined, { skip: !!fixture })
  const me = residentApi.useMeShowQuery(undefined, { skip: !!fixture })
  const allowed = fixture ? true : canManageMembers(household.data?.can_manage_members, me.data?.resident)

  const capture = write.capture
  const online = write.online
  const send = useCallback(
    async (values: MemberForm) => {
      // A level goes only when the server takes one; otherwise the server's default — Member — stands.
      const body = memberBody({ ...values, access_level: levelsOpen ? (isLevel(values.access_level) ? values.access_level : levelFor(values.relationship)) : undefined }, { online })
      setCapturedOffline(!online)
      await capture({ endpoint: 'members.store', body, label: `Household member · ${body.full_name}`, title: body.full_name })
    },
    [capture, levelsOpen, online],
  )

  return {
    ...write,
    allowed,
    nameLit: !!fixture,
    control: form.control,
    errors,
    firstError: firstOf(errors, MEMBER_FIELDS),
    relationships: RELATIONSHIPS,
    relationship,
    relationshipLabel: fixture ? fixture.board.relationshipLabel : (RELATIONSHIPS.find((r) => r.value === relationship)?.label ?? null),
    chooseRelationship: (value) => form.setValue('relationship', value, { shouldDirty: true, shouldValidate: true }),
    hasEmail: typedEmail.trim() !== '',
    emailSuggestion: suggestEmail(typedEmail),
    acceptEmailSuggestion: () => {
      const meant = suggestEmail(typedEmail)
      if (meant) form.setValue('email', meant, { shouldDirty: true, shouldValidate: true })
    },
    invite,
    toggleInvite: () => form.setValue('invite', !invite, { shouldDirty: true }),
    levelsOpen,
    levels: LEVELS,
    level: levelsOpen ? level : 'member',
    levelRefused: !levelsOpen && levelRefused,
    // Below Member only once the server takes it (a 409 adds nobody): until then the choice stays Member, and the
    // tap is answered under the control.
    chooseLevel: (value) => {
      if (levelsOpen || value === 'member') {
        form.setValue('access_level', value, { shouldDirty: true })
        setLevelRefused(false)
      } else setLevelRefused(true)
    },
    capturedOffline,
    added: memberOf(write.recorded?.result),
    send: () => void form.handleSubmit(send)(),
    startAnother: () => {
      form.reset(EMPTY_MEMBER)
      setCapturedOffline(false)
      setLevelRefused(false)
      write.clear()
    },
  }
}

/* ---- one vehicle, pet or contact: added, or opened to change (M50 §5.2–§5.4) ------------------------------ */

/**
 * WHICH ROW A FORM IS ABOUT. Opened from the "+ Add …" row, it adds; opened by tapping a pill or a contact, it is that
 * row — found in this phone's saved copy of the list, its fields filled in — and Save changes it in place. A row only
 * on this phone (not yet sent) or not on the list at all cannot be changed; the screen says so.
 */
export type Editing<T> = { id: string | null; item: T | null; missing: boolean }

function useEditing<T extends { id: number | string }>(id: string | undefined, items: readonly T[] | undefined): Editing<T> {
  const wanted = typeof id === 'string' && /^\d+$/.test(id) ? id : null
  const item = wanted ? ((items ?? []).find((row) => String(row.id) === wanted) ?? null) : null
  return { id: wanted, item, missing: wanted !== null && item === null }
}

/** Fills the form once the row is found — never over what the resident has started typing. */
function useFillOnce<F extends FieldValues>(form: { reset: (values: F) => void }, values: F | null) {
  const filled = useRef(false)
  useEffect(() => {
    if (!values || filled.current) return
    filled.current = true
    form.reset(values)
  }, [form, values])
}

/** What is still waiting to be ADDED on this phone — a plate, a pet or a number that is about to be on the list. */
const queuedBodies = (endpoint: EndpointName): Record<string, unknown>[] =>
  outbox
    .all()
    .filter((op) => op.endpoint === endpoint && (op.state === 'pending' || op.state === 'syncing'))
    .map((op) => op.body ?? {})

/* ---- a vehicle ------------------------------------------------------------------------------------------ */

export type VehicleModel = QueuedWrite & {
  editing: Editing<Vehicle>
  control: Control<VehicleForm>
  errors: Partial<Record<VehicleField, string>>
  firstError: string | null
  send: () => void
  startAnother: () => void
}

export function useVehicleForm(id?: string): VehicleModel {
  const write = useQueuedWrite()
  const active = useAppSelector((s) => s.session.status === 'active')
  const query = residentApi.useVehiclesIndexQuery(undefined, { skip: isFidelity || !active })
  const cached = useOfflineCache('vehicles.index', query.data)
  const editing = useEditing(id, cached?.items)

  const form = useForm<VehicleForm>({ resolver: zodResolver(vehicleSchema), mode: 'onTouched', defaultValues: EMPTY_VEHICLE })
  const errors = flatten(useFormState({ control: form.control }).errors, VEHICLE_FIELDS)
  const current = editing.item
  useFillOnce(form, useMemo(() => (current ? { plate: current.plate, make: current.make ?? '', model: current.model ?? '', colour: current.colour ?? '' } : null), [current]))

  const capture = write.capture
  const known = cached?.items
  const send = useCallback(
    async (values: VehicleForm) => {
      // A plate the household already has — on ANOTHER vehicle — is said here, and nothing is sent.
      const others = [...(known ?? []).filter((v) => String(v.id) !== editing.id), ...queuedBodies('vehicles.store').map((b) => ({ plate: typeof b.plate === 'string' ? b.plate : '' }))]
      if (plateAlreadyListed(values.plate, others)) {
        form.setError('plate', { type: 'duplicate', message: `${normalisePlate(values.plate)} is already on your household's list.` })
        return
      }
      const body = vehicleBody(values)
      const title = vehicleLine({ plate: body.plate, make: body.make ?? null, model: body.model ?? null, colour: body.colour ?? null })
      if (editing.id) await capture({ endpoint: 'vehicles.update', params: { vehicle: editing.id }, body, label: `Vehicle changed · ${body.plate}`, title })
      else await capture({ endpoint: 'vehicles.store', body, label: `Vehicle · ${body.plate}`, title })
    },
    [capture, known, form, editing.id],
  )

  return {
    ...write,
    editing,
    control: form.control,
    errors,
    firstError: firstOf(errors, VEHICLE_FIELDS),
    send: () => void form.handleSubmit(send)(),
    startAnother: () => {
      form.reset(EMPTY_VEHICLE)
      write.clear()
    },
  }
}

/* ---- a pet (M50 §5.3d) ------------------------------------------------------------------------------------- */

export type PetModel = QueuedWrite & {
  editing: Editing<Pet>
  control: Control<PetForm>
  errors: Partial<Record<PetField, string>>
  firstError: string | null
  kinds: typeof PET_KINDS
  kind: PetKind | null
  kindLabel: string | null
  chooseKind: (value: PetKind) => void
  /** The server answered that this pet was already on the list, and added nothing. */
  alreadyListed: boolean
  send: () => void
  startAnother: () => void
}

export function usePetForm(id?: string): PetModel {
  const write = useQueuedWrite()
  const active = useAppSelector((s) => s.session.status === 'active')
  const query = residentApi.usePetsIndexQuery(undefined, { skip: isFidelity || !active })
  const cached = useOfflineCache('pets.index', query.data)
  const editing = useEditing(id, cached?.items)

  const form = useForm<PetForm>({ resolver: zodResolver(petSchema), mode: 'onTouched', defaultValues: EMPTY_PET })
  const errors = flatten(useFormState({ control: form.control }).errors, PET_FIELDS)
  const picked = useWatch({ control: form.control, name: 'kind' }) ?? ''
  const kind = isPetKind(picked) ? picked : null
  const current = editing.item
  useFillOnce(form, useMemo(() => (current ? { name: current.name, kind: current.kind } : null), [current]))

  const capture = write.capture
  const known = cached?.items
  const send = useCallback(
    async (values: PetForm) => {
      const others = [...(known ?? []).filter((p) => String(p.id) !== editing.id), ...queuedBodies('pets.store').map((b) => ({ name: typeof b.name === 'string' ? b.name : '', kind: (isPetKind(b.kind) ? b.kind : 'other') as PetKind }))]
      if (!editing.id && petAlreadyListed(values, others)) {
        form.setError('name', { type: 'duplicate', message: `${values.name.trim()} is already on your household's list.` })
        return
      }
      const body = petBody(values)
      const title = petLine(body)
      if (editing.id) await capture({ endpoint: 'pets.update', params: { pet: editing.id }, body, label: `Pet changed · ${body.name}`, title })
      else await capture({ endpoint: 'pets.store', body, label: `Pet · ${body.name}`, title })
    },
    [capture, known, form, editing.id],
  )

  const result = write.recorded?.result
  return {
    ...write,
    editing,
    control: form.control,
    errors,
    firstError: firstOf(errors, PET_FIELDS),
    kinds: PET_KINDS,
    kind,
    kindLabel: kind ? petKindLabel(kind) : null,
    chooseKind: (value) => form.setValue('kind', value, { shouldDirty: true, shouldValidate: true }),
    alreadyListed: typeof result === 'object' && result !== null && (result as { already_listed?: unknown }).already_listed === true,
    send: () => void form.handleSubmit(send)(),
    startAnother: () => {
      form.reset(EMPTY_PET)
      write.clear()
    },
  }
}

/* ---- an emergency contact (M50 §5.2, §5.4) ---------------------------------------------------------------- */

export type ContactModel = QueuedWrite & {
  editing: Editing<Contact>
  control: Control<ContactForm>
  errors: Partial<Record<ContactField, string>>
  firstError: string | null
  /** Who they are to you, from a list of its own (household.ts · CONTACT_RELATIONSHIPS). */
  relationships: typeof CONTACT_RELATIONSHIPS
  relationship: string | null
  relationshipLabel: string | null
  chooseRelationship: (value: string) => void
  /** The server answered that this number was already on the list, and added nothing (`already_listed`). */
  alreadyListed: boolean
  /** "Did you mean marcia@gmail.com?" (M18G §4.1). */
  emailSuggestion: string | null
  acceptEmailSuggestion: () => void
  send: () => void
  startAnother: () => void
}

export function useContactForm(id?: string): ContactModel {
  const write = useQueuedWrite()
  const active = useAppSelector((s) => s.session.status === 'active')
  const query = residentApi.useContactsIndexQuery(undefined, { skip: isFidelity || !active })
  const cached = useOfflineCache('contacts.index', query.data)
  const editing = useEditing(id, cached?.items)
  const form = useForm<ContactForm>({ resolver: zodResolver(contactSchema), mode: 'onTouched', defaultValues: EMPTY_CONTACT })
  const errors = flatten(useFormState({ control: form.control }).errors, CONTACT_FIELDS)
  const typedEmail = useWatch({ control: form.control, name: 'email' }) ?? ''
  const relationship = (useWatch({ control: form.control, name: 'relationship' }) ?? '').trim() || null
  const current = editing.item
  useFillOnce(form, useMemo(() => (current ? { name: current.name, relationship: current.relationship ?? '', phone: String(current.phone ?? ''), email: current.email ?? '' } : null), [current]))

  const capture = write.capture
  const known = cached?.items
  const send = useCallback(
    async (values: ContactForm) => {
      // One number, one contact — by the panic screen's own rule, so a text never goes to one phone twice.
      const others = [...(known ?? []).filter((c) => String(c.id) !== editing.id), ...queuedBodies('contacts.store').map((b) => ({ phone: typeof b.phone === 'string' ? b.phone : '' }))]
      if (contactAlreadyListed(values.phone, others)) {
        form.setError('phone', { type: 'duplicate', message: contactAlreadySentence(values.phone) })
        return
      }
      const body = contactBody(values)
      if (editing.id) await capture({ endpoint: 'contacts.update', params: { contact: editing.id }, body, label: `Emergency contact changed · ${body.name}`, title: body.name })
      else await capture({ endpoint: 'contacts.store', body, label: `Emergency contact · ${body.name}`, title: body.name })
    },
    [capture, known, form, editing.id],
  )

  const result = write.recorded?.result
  const alreadyListed = !editing.id && typeof result === 'object' && result !== null && (result as { already_listed?: unknown }).already_listed === true

  return {
    ...write,
    editing,
    control: form.control,
    errors,
    firstError: firstOf(errors, CONTACT_FIELDS),
    relationships: CONTACT_RELATIONSHIPS,
    relationship,
    relationshipLabel: relationship ? contactRelationshipLabel(relationship) : null,
    chooseRelationship: (value) => form.setValue('relationship', value, { shouldDirty: true, shouldValidate: true }),
    alreadyListed,
    emailSuggestion: suggestEmail(typedEmail),
    acceptEmailSuggestion: () => {
      const meant = suggestEmail(typedEmail)
      if (meant) form.setValue('email', meant, { shouldDirty: true, shouldValidate: true })
    },
    send: () => void form.handleSubmit(send)(),
    startAnother: () => {
      form.reset(EMPTY_CONTACT)
      write.clear()
    },
  }
}

/* ---- my own details (me.update) ------------------------------------------------------------------- */

export type EditDetailsModel = QueuedWrite & {
  status: 'loading' | 'ready' | 'error' | 'denied'
  errorMessage: string | null
  /** Why these details cannot be changed from this account (403, or no home yet). */
  deniedMessage: string
  retry: () => void
  /** Which of me.update's fields this resident is offered (household.ts · detailsModeOf). */
  mode: DetailsMode
  /** The name on the estate's register — read-only: it is the estate's to change. */
  registerName: string | null
  /** Where sign-in codes go, masked by the server. Not something me.update can change. */
  signInHint: string | null
  /** Why what is on screen is this phone's saved copy — the TRUE reason ("No connection" only when that is it) — or null. */
  staleNote: string | null
  control: Control<DetailsForm>
  errors: Partial<Record<DetailsField, string>>
  firstError: string | null
  /** "Nothing has changed" — said instead of sending an empty change. */
  notice: string | null
  send: () => void
}

const householdOps = (): HouseholdOp[] => outbox.all().filter((op) => op.endpoint === 'me.update')

export function useEditDetails(): EditDetailsModel {
  const write = useQueuedWrite()
  const principal = useAppSelector((s) => s.session.principal)
  const view = useAppSelector((s) => s.outbox.ops)
  const active = useAppSelector((s) => s.session.status === 'active')
  const query = residentApi.useMeShowQuery(undefined, { skip: isFidelity || !active })
  const saved = useOfflineCache('me.show', query.data)
  const error = query.error
  const failure: ReadFailure = isApiError(error) ? { status: error.status, code: error.code } : null
  // A 403 is the server's "not for this account": nothing saved on this phone is shown — or edited — under it.
  const refusedRead = isDenied(failure)
  const me = !refusedRead && saved && (!principal || String(saved.account.id) === String(principal.id)) ? saved : undefined
  // `PATCH /me` needs a household (the server answers 403 without one), whatever `GET /me` says: no form that can only be refused.
  const denied = refusedRead || (!!me && !me.unit)

  // What the server holds, with a change still waiting on this phone laid over it: a second edit starts from what was typed.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const waitingChange = useMemo(() => detailsChange(householdOps()), [view])
  const current = useMemo<DetailsForm | null>(() => {
    if (!me) return null
    const over = waitingChange && waitingChange.state !== 'refused' ? waitingChange.body : {}
    return {
      full_name: over.full_name ?? me.account.full_name ?? '',
      phone: over.phone ?? me.resident?.phone ?? '',
      email: over.email ?? me.resident?.email ?? '',
    }
  }, [me, waitingChange])
  const mode: DetailsMode = me ? detailsModeOf(me) : 'register'

  const form = useForm<DetailsForm>({ resolver: zodResolver(detailsSchema), mode: 'onTouched', defaultValues: { full_name: '', phone: '', email: '' } })
  const formState = useFormState({ control: form.control })
  const errors = flatten(formState.errors, DETAILS_FIELDS)
  const dirty = formState.isDirty
  const [notice, setNotice] = useState<string | null>(null)

  // The form starts from the server's values — never over what the resident is typing. The first values to
  // arrive are usually this phone's SAVED copy; the server's fresh answer follows a moment later, and while
  // the resident has typed nothing the form takes it. (It used to be seeded once, from the saved copy: a
  // phone number the estate office had corrected since was then "changed" back by saving only an email.)
  // What is sent is what differs from this seed — what the resident was shown — never from a later answer.
  const seed = useRef<DetailsForm | null>(null)
  useEffect(() => {
    if (!current) return
    if (seed.current && (dirty || sameDetails(seed.current, current))) return
    seed.current = current
    form.reset(current)
  }, [current, dirty, form])

  const capture = write.capture
  const send = useCallback(
    async (values: DetailsForm) => {
      const shownAtStart = seed.current
      if (!shownAtStart) return
      setNotice(null)
      const nameError = detailsNameError(values, mode)
      if (nameError) return form.setError('full_name', { type: 'min', message: nameError })
      // Changed, never blanked (M18C): the dispatcher calls this number, and a panic emails this address.
      if (mode === 'register' && values.phone.trim() === '') return form.setError('phone', { type: 'required', message: `Enter your phone number: the control room calls it in an emergency. ${phoneWords().example}` })
      if (mode === 'register' && values.email.trim() === '') return form.setError('email', { type: 'required', message: 'Enter your email: the estate office writes to it, and your household is told through it.' })
      const body = detailsBody(values, shownAtStart, mode)
      if (!body) return setNotice('Nothing has changed, so there is nothing to save.')
      await capture({ endpoint: 'me.update', body, label: mode === 'account' ? 'My name' : 'My contact details', title: mode === 'account' ? 'Your name' : 'Your contact details' })
    },
    [capture, mode, form],
  )

  const online = write.online

  return {
    ...write,
    status: denied ? 'denied' : sectionStatusOf({ has: !!me, failed: !!error, failure }),
    errorMessage: !error ? null
      : isApiError(error) ? (error.status === 'network' || error.status === 'timeout' ? "You're offline, and your details are not saved on this phone yet. Check your connection and try again." : error.message)
      : 'Something unexpected happened. Please try again.',
    // `account_pending`, or no home on `GET /me`, is the one cause the app knows; any other 403 is not explained by guessing.
    deniedMessage: refusedRead && failure?.code !== 'account_pending'
      ? deniedSentence(failure?.code)
      : "Your account is not linked to a home on the estate's register yet. Once the estate approves your claim, you can keep your contact details up to date here.",
    retry: () => void query.refetch(),
    mode,
    registerName: me?.resident?.full_name ?? null,
    signInHint: me?.account.destination_hint ?? null,
    staleNote: denied ? null : staleSentence(stalenessOf({ has: !!me, online, failures: error ? [failure] : [] }), 'details'),
    control: form.control,
    errors,
    firstError: firstOf(errors, DETAILS_FIELDS),
    notice,
    send: () => void form.handleSubmit(send)(),
  }
}
