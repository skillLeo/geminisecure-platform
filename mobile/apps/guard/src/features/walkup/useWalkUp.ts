/**
 * Everything the walk-up form decides — board guard-app-31, the no-pass path.
 *
 *   THE FORM     unit (from Search, or typed and matched against the live register) · visitor name ·
 *                ID type + number · purpose · vehicle plate. Limits are the server's own.
 *   ASKING       gate.approvals.store, called DIRECTLY. It is ONLINE ONLY by the server's contract —
 *                the household cannot be asked without signal — so it is NEVER queued. A retry after
 *                a lost connection carries the SAME Idempotency-Key: one visitor, one request.
 *   NO SIGNAL    said plainly, with the alternative the contract names: apply the estate's policy.
 *                The guard records the entry on their own decision, or a denial — both through
 *                submit(), so they are safe on the phone and sync by themselves.
 *   ERRORS       422 → onto the fields · 404 → "No such unit" on the unit · 403 → a plain explanation.
 *
 * NO PHOTO, NO CAMERA (invariant 14): the server stores no image of a walk-up visitor — there is no
 * ruling on keeping pictures of the public — so the board's photo tile and plate-camera button are
 * drawn but inert, each with its reason. Nothing on this screen opens a camera.
 *
 * A household's standing arrives from the register as ONE boolean and is shown as one fixed sentence.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm, useFormState, useWatch, type Control } from 'react-hook-form'
import { z } from 'zod'
import { fidelityScreenId, isFidelity, now } from '@gs/core'
import { isApiError, isRetryable, newStamp, type WriteStamp } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { walkUpFormFixture } from '@/fidelity/walkup'
import { useAppSelector } from '@/store'
import { useGateRecord, type Recorded } from './useGateRecord'
import { clockSkewMs, subjectOf } from './walkup'

/*
 * NOTHING IS OPTIONAL (M18C): the ID, what the visit is for, and the plate — or the explicit "Arriving on foot or by
 * taxi — no vehicle". One or the other, never neither.
 */
const schema = z
  .object({
    unit: z.string().trim().min(1, 'Enter the unit the visitor is going to.').max(32, 'A unit reference is 32 characters or fewer.'),
    visitor_name: z.string().trim().min(1, "Enter the visitor's name.").max(160, 'Keep the name to 160 characters or fewer.'),
    id_type: z.string().trim().min(1, 'Choose the kind of ID they showed.').max(40, 'Keep the ID type to 40 characters or fewer.'),
    id_number: z.string().trim().min(1, 'Enter the number on their ID, as it is written.').max(40, 'Keep the ID number to 40 characters or fewer.'),
    purpose: z.string().trim().min(2, 'Say what the visit is for: e.g. Family visit, delivery.').max(160, 'Keep the purpose to 160 characters or fewer.'),
    vehicle_plate: z.string().trim().max(16, 'A plate is 16 characters or fewer.'),
    no_vehicle: z.boolean(),
  })
  .superRefine((values, ctx) => {
    if (!values.no_vehicle && values.vehicle_plate.trim().length < 2) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['vehicle_plate'], message: 'Enter the plate, or tick “Arriving on foot or by taxi: no vehicle”.' })
    }
  })
export type WalkUpForm = z.infer<typeof schema>
export type WalkUpField = keyof WalkUpForm

const FIELDS: readonly WalkUpField[] = ['unit', 'visitor_name', 'id_type', 'id_number', 'purpose', 'vehicle_plate', 'no_vehicle']
const isField = (name: string): name is WalkUpField => (FIELDS as readonly string[]).includes(name)

/** What a visitor is likely to hold. The server takes any text up to 40 characters. */
export const ID_TYPES = ["Driver's licence", "Voter's ID", 'Passport', 'Work ID', 'Other'] as const

const DEBOUNCE_MS = 350

export type UnitChoice = { unit: string; household: string; restricted: boolean }

/**
 * `cannotAnswer` is "1" when the server said nobody in the household can answer in the app (`household_can_answer: false`).
 * `restricted` is "1" when the household's visitors are held at the gate (`access_restricted`, M21 §1.7): an approval
 * does not lift it, and admitting is an override, with a reason.
 */
export type WaitingParams = { id: string; visitor: string; unit: string; household: string; purpose: string; plate: string; requestedAt: string; respondBy: string; skew: string; cannotAnswer: string; restricted: string }

export type WalkUpModel = {
  control: Control<WalkUpForm>
  errors: Partial<Record<WalkUpField, string>>
  unit: {
    value: string
    /** Drawn as the board's lookup row (a settled unit) rather than as a field being typed. */
    settled: boolean
    fromSearch: boolean
    household: string | null
    restricted: boolean
    lookup: 'idle' | 'searching' | 'matched' | 'choices' | 'none' | 'offline'
    choices: UnitChoice[]
    edit: () => void
    choose: (choice: UnitChoice) => void
  }
  idType: { value: string; open: boolean; toggle: () => void; choose: (value: string) => void }
  /** "Arriving on foot or by taxi — no vehicle" (M18C §3.1): a plate, or this. */
  noVehicle: { on: boolean; toggle: () => void }
  fixture: typeof walkUpFormFixture | null
  online: boolean
  /** No signal, or the request just failed to reach the server: the estate's policy is offered. */
  policyOffered: boolean
  busy: boolean
  formError: string | null
  send: () => void
  policy: { busy: boolean; saveError: string | null; admit: () => void; deny: () => void }
  recorded: Recorded | null
}

const norm = (text: string) => text.trim().replace(/\s+/g, ' ').toLowerCase()

export function useWalkUp(params: { unit?: string; household?: string }, onSent: (next: WaitingParams) => void): WalkUpModel {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-31' ? walkUpFormFixture : null
  const online = useAppSelector((s) => s.network.online)
  const given = (fixture ? fixture.unit : (params.unit ?? '')).trim().slice(0, 32)

  const form = useForm<WalkUpForm>({
    resolver: zodResolver(schema),
    mode: 'onTouched',
    defaultValues: fixture ? { ...fixture.values, no_vehicle: false } : { unit: given, visitor_name: '', id_type: '', id_number: '', purpose: '', vehicle_plate: '', no_vehicle: false },
  })
  const noVehicle = useWatch({ control: form.control, name: 'no_vehicle' }) === true
  const errors = useFormState({ control: form.control }).errors
  const unitValue = useWatch({ control: form.control, name: 'unit' }) ?? ''
  const idTypeValue = useWatch({ control: form.control, name: 'id_type' }) ?? ''

  const [editingUnit, setEditingUnit] = useState(given.length === 0)
  const [idTypeOpen, setIdTypeOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [unreachable, setUnreachable] = useState(false)
  const inFlight = useRef(false)
  /** The stamp of a request that FAILED to reach the server, kept so its retry is the same act. */
  const unanswered = useRef<{ body: string; stamp: WriteStamp } | null>(null)

  // ── the unit, matched against the live register (gate.search is online only: never cached)
  const [term, setTerm] = useState(given)
  useEffect(() => {
    if (fixture) return
    const timer = setTimeout(() => setTerm(unitValue.trim().slice(0, 32)), DEBOUNCE_MS)
    return () => clearTimeout(timer)
  }, [fixture, unitValue])

  const ask = !fixture && online && term.length > 0
  const search = guardApi.useGateSearchQuery({ unit: term }, { skip: !ask })
  const items = ask && !search.isFetching && search.data ? search.data.items : null
  const exact = items?.find((item) => norm(item.unit) === norm(unitValue)) ?? null
  // An exact reference is offered on its own — tapping it settles the unit; typing on keeps looking.
  const choices: UnitChoice[] = (exact ? [exact] : (items ?? []).slice(0, 4)).map((item) => ({ unit: item.unit, household: item.household, restricted: item.access_restricted === true }))

  const lookup: WalkUpModel['unit']['lookup'] = fixture ? 'matched'
    : unitValue.trim().length === 0 ? 'idle'
    : !online ? 'offline'
    : exact ? 'matched'
    : !items ? (search.error ? 'idle' : 'searching')
    : choices.length > 0 ? 'choices' : 'none'

  const record = useGateRecord()

  const [store] = guardApi.useGateApprovalsStoreMutation()
  const send = useCallback(
    async (values: WalkUpForm) => {
      if (fixture || inFlight.current) return
      // Online only, by the server's contract. Never queued, never faked.
      if (!online) return setUnreachable(true)
      inFlight.current = true
      setBusy(true)
      setFormError(null)
      try {
        const body = {
          unit: values.unit,
          visitor_name: values.visitor_name,
          id_type: values.id_type,
          id_number: values.id_number,
          purpose: values.purpose,
          ...(values.no_vehicle ? { no_vehicle: true } : { vehicle_plate: values.vehicle_plate.toUpperCase() }),
        }
        const key = JSON.stringify(body)
        const stamp = unanswered.current?.body === key ? unanswered.current.stamp : newStamp()
        const sentAt = now().getTime()
        const answer = await store({ ...body, __stamp: stamp })

        if ('data' in answer && answer.data) {
          unanswered.current = null
          setUnreachable(false)
          const a = answer.data
          // The handset's clock against the server's, halfway through the round trip.
          const skew = clockSkewMs(a.server_time, (sentAt + now().getTime()) / 2)
          // Absent on an older server: as before, the household is waited on.
          const cannotAnswer = a.household_can_answer === false ? '1' : ''
          const restricted = a.access_restricted === true ? '1' : ''
          return onSent({ id: String(a.approval_id), visitor: a.visitor_name, unit: a.unit, household: a.household ?? '', purpose: values.purpose, plate: 'vehicle_plate' in body ? body.vehicle_plate : 'On foot or by taxi', requestedAt: a.requested_at, respondBy: a.respond_by, skew: String(Math.round(skew)), cannotAnswer, restricted })
        }

        const e = 'error' in answer ? answer.error : null
        if (!isApiError(e)) return setFormError('Something unexpected happened. Please try again.')
        unanswered.current = isRetryable(e) ? { body: key, stamp } : null

        if (e.status === 'network' || e.status === 'timeout') return setUnreachable(true)
        setUnreachable(false)
        if (e.status === 404) {
          form.setError('unit', { type: 'server', message: 'No such unit. Check the reference, or find the household under Search.' })
          return setEditingUnit(true)
        }
        if (e.status === 422) {
          const unplaced: string[] = []
          for (const [field, messages] of Object.entries(e.fieldErrors)) {
            const message = messages[0]
            if (!message) continue
            if (isField(field)) form.setError(field, { type: 'server', message })
            else unplaced.push(message)
          }
          if (e.fieldErrors.unit?.length) setEditingUnit(true)
          return setFormError(unplaced.length > 0 || Object.keys(e.fieldErrors).length === 0 ? [e.message, ...unplaced].join(' ') : null)
        }
        if (e.status === 403) return setFormError('This handset is not allowed to ask a household for approval. Contact your supervisor: scanning a pass still works.')
        setFormError(e.message)
      } finally {
        inFlight.current = false
        setBusy(false)
      }
    },
    [fixture, online, store, onSent, form],
  )

  // The estate's policy needs to know WHO was at the gate; where they were going is recorded if it was given.
  const decide = useCallback(
    async (act: 'admit' | 'deny') => {
      if (fixture || !(await form.trigger('visitor_name'))) return
      const name = form.getValues('visitor_name').trim()
      const subject = subjectOf(name, form.getValues('unit'))
      if (act === 'admit') record.admitOnOwnDecision(name, subject)
      else record.deny(name, subject)
    },
    [fixture, form, record],
  )

  const flat: Partial<Record<WalkUpField, string>> = {}
  for (const field of FIELDS) {
    const message = errors[field]?.message
    if (message) flat[field] = message
  }

  return {
    control: form.control,
    errors: flat,
    unit: {
      value: unitValue,
      settled: !editingUnit && unitValue.trim().length > 0 && !flat.unit,
      fromSearch: fixture ? true : given.length > 0 && norm(given) === norm(unitValue),
      household: fixture ? fixture.household : (exact?.household ?? (params.household && norm(given) === norm(unitValue) ? params.household : null)),
      restricted: exact?.access_restricted === true,
      lookup,
      choices,
      edit: () => setEditingUnit(true),
      choose: (choice) => {
        form.setValue('unit', choice.unit, { shouldValidate: true, shouldDirty: true })
        form.clearErrors('unit')
        setEditingUnit(false)
      },
    },
    idType: {
      value: idTypeValue,
      open: idTypeOpen,
      toggle: () => setIdTypeOpen((v) => !v),
      choose: (value) => {
        form.setValue('id_type', value, { shouldDirty: true, shouldValidate: true })
        setIdTypeOpen(false)
      },
    },
    noVehicle: {
      on: noVehicle,
      // Ticking "no vehicle" clears the plate; unticking asks for one again (M18C §3.1).
      toggle: () => {
        form.setValue('no_vehicle', !noVehicle, { shouldDirty: true })
        if (!noVehicle) form.setValue('vehicle_plate', '', { shouldDirty: true })
        void form.trigger('vehicle_plate')
      },
    },
    fixture,
    online,
    policyOffered: !fixture && (!online || unreachable),
    busy,
    formError,
    send: () => void form.handleSubmit(send, (invalid) => (invalid.unit ? setEditingUnit(true) : undefined))(),
    policy: { busy: record.busy, saveError: record.saveError, admit: () => void decide('admit'), deny: () => void decide('deny') },
    recorded: record.recorded,
  }
}
