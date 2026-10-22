/**
 * Everything the new maintenance request decides — board resident-app-11 (M42 §5 #10). The screen only draws it, and
 * reads no mode of its own: in fidelity mode this hook hands it the board's own moment (fidelity/maintenance.ts).
 *
 *   KIND         the board's four chips — Plumbing · Electrical · Lighting · Gate — each filed under one of the
 *                server's categories (tickets.ts: REQUEST_CHIPS). Required (M18C).
 *   WHERE        "Inside my unit" sends no location — the server files it against the unit. "Common area" sends
 *                "Common area": left empty, the server would file a street light against the unit.
 *   TITLE        the server REQUIRES one (4–140 characters) and the design has no field for it, so it is written
 *                here from what is wrong, then where (M47 §11.5): "Gate lighting — Phase 2 visitor parking" (titleFrom).
 *   DETAILS      the transcript card: typed, or spoken where the phone can (dictation.ts). What is being heard shows
 *                in the card as it is heard and joins the text once the phone settles on it; tapping in to edit stops
 *                the listening first.
 *   WAVEFORM     the microphone level the recogniser reports, ten times a second — the dictation Report incident uses
 *                (features/incidents/dictation.ts asks for `volumechange`; @gs/core/speech, which the Guard App
 *                shares, does not). Never a loop: with no level the bars lie still. The board's bars in fidelity only.
 *   URGENCY      low · medium · high — the server's three values; medium unless the resident says otherwise.
 *   ENTRY        "Allow entry when I'm not home" is `access_permitted`, asked only for work inside the unit. Off
 *                unless the resident turns it on, and "off" is sent as the answer it is. "Best time to come" is
 *                gone (M42 §5 #10): no `preferred_time` is sent.
 *   PHOTOS       photoPicker.ts — local uris only, up to PHOTO_MAX, made small and re-encoded on the phone. PHOTOS
 *                ONLY, though the design says "Photos & video": a video cannot be re-encoded on the phone, so its
 *                hidden location tag would go with it (D-M076; the store declarations say photos). The "+" asks
 *                camera or library in a sheet; a thumbnail asks before it is taken back.
 *
 *   SUBMIT IS OFFLINE-CAPABLE AND ORDERED ("Queue; attach photos after it syncs"). The request is
 *   captured on the phone FIRST (submit()); each photo is captured behind it — `after: request.id`,
 *   its path parameter filled from the request's confirmed answer (`{ $from: request.id, key: 'id' }`) —
 *   so a photo can never reach the server before the ticket it belongs to has an id. What happened next
 *   is read from the outbox, and the screen never says "submitted" until the server has said so.
 *
 * A queued write's refusal arrives later and carries the server's sentence only (no per-field errors),
 * so the form's own validation mirrors the server's limits exactly and the sentence is shown as given.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import * as Haptics from 'expo-haptics'
import { useController, useForm, useFormState, useWatch } from 'react-hook-form'
import { fidelityScreenId, isFidelity } from '@gs/core'
import type { OutboxState } from '@gs/core/offline'
import { STILL_DICTATION } from '@gs/core/speech'
import { requestFixture } from '@/fidelity/maintenance'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { drain, drainNow, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { stillLevels, useReportDictation, type Levels } from '@/features/incidents/dictation'
import { REQUEST_WORDS, type Dictation } from './dictation'
import { PHOTOS_UNAVAILABLE, photosAvailable, pickPhotos } from './photoPicker'
import { requestPrefill } from './prefill'
import { clearRefusal } from './useTickets'
import { EMPTY_REQUEST, PHOTO_MAX, REQUEST_CHIPS, REQUEST_FIELDS, appendTranscript, isPriority, isTicket, mediaBody, prefillFrom, requestSchema, ticketBody, type Area, type ChipId, type Photo, type Priority, type RequestField, type RequestForm } from './tickets'

export type Recorded = {
  title: string
  state: OutboxState
  /** The server's sentence when it did not accept the request. */
  refusal: string | null
  /** Once confirmed: the ticket the server made. */
  ticketId: string | null
  number: string | null
  photos: { total: number; waiting: number; refused: number; notSaved: number }
}

export type RequestChip = { id: ChipId; label: string; on: boolean }

/** A photo beside the "+": the picture on this phone (none for the board's two plain tiles), and how it is taken back. */
export type Thumb = { key: string; uri: string | null; remove: (() => void) | null }

export type MaintenanceRequestModel = {
  chips: readonly RequestChip[]
  chooseChip: (id: ChipId) => void
  area: Area
  chooseArea: (value: Area) => void
  mic: {
    available: boolean
    listening: boolean
    /** One level per bar, 0 to 1, read as it changes — all at rest when nothing reports a level. */
    levels: Levels
    /** While it is not listening: why the microphone is off, what went wrong, or what the phone said about its speech model. */
    notice: string | null
    /** The phone's offline speech model, when it is missing (Android): offered, never assumed. */
    model: { downloading: boolean; download: () => void } | null
    toggle: () => void
  }
  transcript: {
    /** What the card shows: what is written, then what is being heard right now. */
    value: string
    /** Something to put right here after Submit: the card takes the gold edge. */
    attention: boolean
    change: (text: string) => void
    /** Tapping in to edit stops the listening first, so heard words and typed ones never cross. */
    focus: () => void
    blur: () => void
  }
  photos: {
    thumbs: readonly Thumb[]
    /** The "+" — gone once the request holds PHOTO_MAX. */
    canAdd: boolean
    /** "Add a photo": the camera or the phone's photos, asked in a sheet that comes back with what went wrong. */
    adding: { open: boolean; blocked: string | null; problem: string | null; ask: () => void; cancel: () => void; take: () => void; choose: () => void }
    /** "Remove this photo?" — before it is sent a photo is only a choice, and can be taken back. */
    removing: { open: boolean; confirm: () => void; cancel: () => void }
  }
  priority: Priority
  choosePriority: (value: Priority) => void
  /** "Allow entry when I'm not home" — asked only for work inside the unit. */
  entry: { shown: boolean; on: boolean; set: (on: boolean) => void }
  busy: boolean
  /** After Submit: the first thing still to put right, or why nothing could be saved — said above the button. */
  problem: string | null
  send: () => void
  recorded: Recorded | null
  /** While it waits on this phone: try the queue again now (same Idempotency-Key — it cannot be sent twice). */
  retryNow: () => void
  /** After a refusal only: back to the form with everything still written. */
  changeAndResend: () => void
  /** A clean form for another problem. */
  startAnother: () => void
}

const RETRY_EVERY_MS = 30_000
/** Long enough for the sheet's own close to finish before the phone's picker is presented (as the profile photo's). */
const SHEET_CLOSE_MS = 350

const shortened = (text: string, max: number) => (text.length > max ? `${text.slice(0, max - 1).trimEnd()}…` : text)

export function useMaintenanceRequest(): MaintenanceRequestModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-11' ? requestFixture : null
  const refusal = useDemoRefusal()
  const ops = useAppSelector((s) => s.outbox.ops)
  // The unit an "inside my unit" request is filed against — the session's own label, e.g. "Phase 2 · Lot 47".
  const unit = useAppSelector((s) => s.session.principal?.reference ?? null)

  // "Report it again" hands over a title in memory. Read without consuming, cleared once mounted.
  const [prefill] = useState(() => (fixture ? null : requestPrefill.peek()))
  useEffect(() => requestPrefill.clear(), [])

  const form = useForm<RequestForm>({
    resolver: zodResolver(requestSchema),
    // Nothing is said before the first Submit; after it, every change checks again, so a line goes as it is put right.
    mode: 'onSubmit',
    reValidateMode: 'onChange',
    defaultValues: fixture
      ? { kind: fixture.kind, area: fixture.area, description: fixture.transcript, priority: fixture.priority, allowEntry: fixture.allowEntry }
      : { ...EMPTY_REQUEST, ...prefillFrom(prefill?.title ?? '') },
  })
  const formState = useFormState({ control: form.control })
  const errors = formState.errors
  // Read while rendering, so the form tells this hook when it changes (a handler reading it later would see a stale copy).
  const submitted = useRef(false)
  submitted.current = formState.isSubmitted
  const kind = useWatch({ control: form.control, name: 'kind' }) ?? ''
  const area = useWatch({ control: form.control, name: 'area' }) ?? 'unit'
  const priorityValue = useWatch({ control: form.control, name: 'priority' }) ?? 'medium'
  const allowEntry = useWatch({ control: form.control, name: 'allowEntry' }) ?? false
  const { field: description } = useController({ control: form.control, name: 'description' })

  // ── dictation: what is heard is appended to what is written
  const onHeard = useCallback(
    (words: string) => form.setValue('description', appendTranscript(form.getValues('description'), words), { shouldDirty: true, shouldValidate: submitted.current }),
    [form],
  )
  const live = useReportDictation(onHeard, REQUEST_WORDS)
  const dictation: Dictation = fixture ? { ...STILL_DICTATION, listening: fixture.listening } : live
  const boardLevels = useMemo(() => (fixture ? stillLevels(fixture.levels) : null), [fixture])

  // ── photos: local uris only
  const [photos, setPhotos] = useState<Photo[]>([])
  const [adding, setAdding] = useState(false)
  const [trouble, setTrouble] = useState<string | null>(null)
  const [removing, setRemoving] = useState<string | null>(null)
  const picking = useRef(false)
  const canPick = !fixture && photosAvailable()
  const pick = useCallback(
    async (source: 'camera' | 'library') => {
      if (!canPick || picking.current) return
      picking.current = true
      setAdding(false)
      setTrouble(null)
      try {
        // The sheet closes BEFORE the picker opens: a picker presented over a modal does not open on every phone.
        await new Promise((resolve) => setTimeout(resolve, SHEET_CLOSE_MS))
        const outcome = await pickPhotos(source, PHOTO_MAX - photos.length)
        if (outcome.photos.length) setPhotos((held) => [...held, ...outcome.photos.filter((p) => !held.some((h) => h.uri === p.uri))].slice(0, PHOTO_MAX))
        // What went wrong is said in the sheet, which comes back with it.
        if (outcome.trouble) {
          setTrouble(outcome.trouble)
          setAdding(true)
        }
      } finally {
        picking.current = false
      }
    },
    [canPick, photos.length],
  )

  // ── submit: the request first, its photos behind it
  const [busy, setBusy] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [done, setDone] = useState<{ title: string; requestOp: string; mediaOps: string[]; notSaved: number } | null>(null)
  // State lands a render late; two fast taps must not file two requests.
  const inFlight = useRef(false)
  // What the saved request was last seen to be in the queue (see `recorded` below).
  const lastSeen = useRef<{ op: string; state: OutboxState; refusal: string | null } | null>(null)

  const send = useCallback(
    async (values: RequestForm) => {
      if (fixture || inFlight.current) return
      // Before `submit()`: the report and its photos are captured on the phone first, so without
      // this a demonstration session was told the request had been filed.
      if (refusal) return setSaveError(refusal)
      inFlight.current = true
      setBusy(true)
      setSaveError(null)
      try {
        const body = ticketBody(values, unit)
        const label = shortened(body.title, 48)
        const request = await submit({ endpoint: 'tickets.store', body, label: `Maintenance request · ${label}`, kind: 'ticket' })
        const mediaOps: string[] = []
        let notSaved = 0
        for (const [index, photo] of photos.entries()) {
          try {
            const media = await submit({
              endpoint: 'tickets.media',
              params: { ticket: { $from: request.id, key: 'id' } },
              body: mediaBody(photo, index),
              label: `Maintenance photo · ${label} (${index + 1} of ${photos.length})`,
              kind: 'ticket',
              after: request.id,
            })
            mediaOps.push(media.id)
          } catch {
            notSaved += 1
          }
        }
        setDone({ title: body.title, requestOp: request.id, mediaOps, notSaved })
        void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined)
      } catch {
        setSaveError('That could not be saved on this phone, so nothing has been sent. Try again.')
      } finally {
        inFlight.current = false
        setBusy(false)
      }
    },
    [fixture, photos, refusal, unit],
  )

  // The queue is sent when the phone reports signal again. A weak link fails without ever reporting
  // "offline", so while a saved request is on screen the queue is also tried every half minute.
  const waiting = !!done && [done.requestOp, ...done.mediaOps].some((id) => ops.find((o) => o.id === id)?.state === 'pending')
  useEffect(() => {
    if (!waiting) return
    const timer = setInterval(() => void drain(), RETRY_EVERY_MS)
    return () => clearInterval(timer)
  }, [waiting])

  const flat: Partial<Record<RequestField, string>> = {}
  for (const field of REQUEST_FIELDS) {
    const message = errors[field]?.message
    if (message) flat[field] = message
  }

  let recorded: Recorded | null = null
  if (done) {
    const requestOp = ops.find((o) => o.id === done.requestOp)
    const media = done.mediaOps.map((id) => ops.find((o) => o.id === id))
    // An act leaves the queue a day after it was CONFIRMED — or when a refusal that was read is cleared
    // (perhaps on another copy of this screen). So a request that is no longer in the queue is whatever it
    // was last SEEN to be: it is never promoted to "submitted" by vanishing.
    if (requestOp) lastSeen.current = { op: done.requestOp, state: requestOp.state, refusal: requestOp.error?.message ?? null }
    const seen = lastSeen.current?.op === done.requestOp ? lastSeen.current : null
    const state: OutboxState = requestOp?.state ?? seen?.state ?? 'pending'
    // The store mirrors the queue without answers; the ticket the server made is in the queue itself.
    const answer = state === 'confirmed' ? outbox.all().find((o) => o.id === done.requestOp)?.result : null
    const ticket = isTicket(answer) ? answer : null
    recorded = {
      title: done.title,
      state,
      refusal: state === 'refused' ? (requestOp?.error?.message ?? seen?.refusal ?? 'The estate office did not accept this request.') : null,
      ticketId: ticket ? String(ticket.id) : null,
      number: ticket ? String(ticket.number).replace(/^#/, '') : null,
      photos: {
        total: done.mediaOps.length + done.notSaved,
        waiting: media.filter((o) => o?.state === 'pending' || o?.state === 'syncing').length,
        refused: media.filter((o) => o?.state === 'refused').length,
        notSaved: done.notSaved,
      },
    }
  }

  const removable = removing !== null && photos.some((photo) => photo.uri === removing)

  return {
    chips: REQUEST_CHIPS.map((chip) => ({ id: chip.id, label: chip.label, on: chip.id === kind })),
    chooseChip: (id) => form.setValue('kind', id, { shouldDirty: true, shouldValidate: submitted.current }),
    area,
    chooseArea: (value) => form.setValue('area', value, { shouldDirty: true }),
    mic: {
      available: dictation.available,
      listening: dictation.listening,
      levels: boardLevels ?? live.levels,
      notice: dictation.listening ? null : (dictation.trouble ?? dictation.note ?? dictation.reason),
      model: dictation.model ? { downloading: dictation.model.downloading, download: dictation.model.download } : null,
      toggle: dictation.toggle,
    },
    transcript: {
      value: dictation.hearing ? appendTranscript(description.value, dictation.hearing) : description.value,
      attention: !!flat.description,
      change: (text) => description.onChange(text),
      focus: () => {
        if (dictation.listening) dictation.toggle()
      },
      blur: description.onBlur,
    },
    photos: {
      thumbs: fixture
        ? Array.from({ length: fixture.photoThumbs }, (_, i) => ({ key: `board-${i}`, uri: null, remove: null }))
        : photos.map((photo) => ({ key: photo.uri, uri: photo.uri, remove: () => setRemoving(photo.uri) })),
      canAdd: fixture ? true : photos.length < PHOTO_MAX,
      adding: {
        open: adding,
        blocked: canPick ? null : PHOTOS_UNAVAILABLE,
        problem: trouble,
        ask: () => {
          setTrouble(null)
          setAdding(true)
        },
        cancel: () => {
          setAdding(false)
          setTrouble(null)
        },
        take: () => void pick('camera'),
        choose: () => void pick('library'),
      },
      removing: {
        open: removable,
        confirm: () => {
          setPhotos((held) => held.filter((photo) => photo.uri !== removing))
          setRemoving(null)
        },
        cancel: () => setRemoving(null),
      },
    },
    priority: isPriority(priorityValue) ? priorityValue : 'medium',
    choosePriority: (value) => form.setValue('priority', value, { shouldDirty: true, shouldValidate: submitted.current }),
    entry: { shown: area === 'unit', on: allowEntry, set: (on) => form.setValue('allowEntry', on, { shouldDirty: true }) },
    busy,
    problem: saveError ?? REQUEST_FIELDS.map((field) => flat[field]).find((message) => !!message) ?? null,
    send: () => void form.handleSubmit(send)(),
    recorded,
    retryNow: () => void drainNow(),
    changeAndResend: () => {
      if (recorded?.state !== 'refused' || !done) return
      // The refusal has been read and is being acted on: it leaves the queue, so the request that replaces
      // it is not listed under a "Not accepted" copy of itself for good. (Its photos are still held here.)
      void clearRefusal(done.requestOp)
      setDone(null)
    },
    startAnother: () => {
      form.reset(EMPTY_REQUEST)
      setPhotos([])
      setTrouble(null)
      setAdding(false)
      setRemoving(null)
      setSaveError(null)
      setDone(null)
    },
  }
}
