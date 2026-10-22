/**
 * Everything the security report decides — board resident-app-14, as the client's M42 §5 #15 rebuilt it.
 * ONE SCREEN, NO SCROLL: a type, the microphone, the transcript, photos, where, who sees the name, Submit.
 *
 *   WHAT IT IS   the Resident API has no incident endpoint. This is a TICKET, category `security`
 *                (tickets.store): it goes to the ESTATE OFFICE's queue and nowhere else. It does NOT tell the
 *                guards on duty — `happening_now` is never sent (the user, 7 October: "happening now" is Panic).
 *   TYPE         the board's four chips; the first is on when the screen opens, as the board draws it. The
 *                server has no field for it: written INTO the description (incidents.ts).
 *   TITLE        the server REQUIRES one; the design draws none. Written by the app from what the resident said,
 *                then where — the incident, not its type (M47 §12.4; reportTitle in incidents.ts).
 *   TRANSCRIPT   what the microphone heard (dictation.ts, on the phone only), edited like typing. The one thing
 *                the resident must give.
 *   WHERE        the spot the phone is on, pinned when the screen opens (location.ts: the phone's own permission
 *                decides, and its dialog appears only after the Permissions screen's disclosure), and NAMED by the
 *                phone's own geocoder — "12 Palm Drive, Portmore", or "Your current location" when it cannot say;
 *                never degrees (M47 §13.3). What the row shows is what is sent as the server's `location`. "Change"
 *                lets the resident say the place in their own words instead; with no pin, the server files it at
 *                their unit.
 *   NAME         "Keep my name private from other residents" — on, as the board draws it. NOTHING IS SENT: the
 *                server takes no such field, and none is invented. What is true (checked against the server,
 *                7 October): no resident-facing answer carries a report's reporter — tickets.index is the
 *                household's own tickets with no reporter field, the "reported" step has no note, ticket pushes
 *                and live events go to the unit's own household (and the console), and nothing shares a ticket
 *                with other residents. Only the estate console prints "Submitted by …". So the switch's promise
 *                is already kept by the server whichever way it is set; it is held here and nowhere else.
 *   PHOTOS       photoPicker.ts — local uris only, up to PHOTO_MAX, shrunk on the phone, EXIF off. Photos only:
 *                the board's video tile is drawn for a video, and the picker cannot give one (tickets.media would
 *                take MP4/MOV; the picker's photos-only rule is an earlier decision this rebuild does not reverse).
 *
 *   SUBMIT IS OFFLINE-CAPABLE AND ORDERED ("Queue; attach photos after it syncs"). The report is
 *   captured on the phone FIRST (submit()); each photo is captured behind it — `after: report.id`, its
 *   path parameter filled from the report's confirmed answer (`{ $from: report.id, key: 'id' }`) — so a
 *   photo can never reach the server before the ticket it belongs to has an id. What happened next is
 *   read from the outbox, and the screen never says "sent" until the server has said so.
 *
 *   APPEND-ONLY. Once the server has a report it is a record: nothing here edits or deletes one. A report
 *   the server REFUSED was never filed; the resident may send a NEW report with the same words, and the
 *   refusal — once read and acted on — leaves this phone's queue (photos first). No record is removed.
 *
 *   FIDELITY (the board's own content): the fixture swaps in the board's data here, and nothing else changes —
 *   the screen draws the same model either way.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import * as Haptics from 'expo-haptics'
import { fidelityScreenId, isFidelity, now } from '@gs/core'
import type { OutboxState } from '@gs/core/offline'
import { reportFixture } from '@/fidelity/incidents'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { permissionsAsked } from '@/features/auth/usePermissions'
import { drain, drainNow, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { STILL_DICTATION } from '@gs/core/speech'
import { stillLevels, useReportDictation, type ReportDictation } from './dictation'
import { EMPTY_REPORT, INCIDENT_KIND, INCIDENT_TYPES, LOCATION_MAX, NOT_ACCEPTED, PHOTO_MAX, appendTranscript, isIncidentType, isTicket, mediaBody, numberOf, reportBody, reportSchema, type IncidentTypeId, type Photo, type ReportForm } from './incidents'
import { HERE, namePin, pinLine, pinLocation, type LocationState } from './location'
import { PHOTOS_UNAVAILABLE, photosAvailable, pickPhotos } from './photoPicker'
import { clearRefusal } from './useIncidents'

export type Recorded = {
  title: string
  state: OutboxState
  /** The server's sentence when it did not accept the report. */
  refusal: string | null
  /** Once confirmed: the ticket the server made. */
  incidentId: string | null
  number: string | null
  photos: { total: number; waiting: number; refused: number; notSaved: number }
}

/** A tile on the photo row: a photo the resident added — or, on the board, its photo and video. */
export type MediaItem = { key: string; kind: 'photo' | 'video'; uri: string | null }

export type IncidentReportModel = {
  /** The board's four chips, and the one that is on. */
  types: typeof INCIDENT_TYPES
  type: IncidentTypeId
  chooseType: (value: IncidentTypeId) => void
  dictation: ReportDictation
  /** What was seen or heard, as settled so far — edited like typing. */
  transcript: string
  setTranscript: (text: string) => void
  /** Why the report cannot go yet: shown only after Submit was pressed with nothing said. */
  transcriptError: string | null
  media: {
    items: MediaItem[]
    /** The add tile is drawn: there is room on the row. */
    canAdd: boolean
    /** "Add a photo": the camera or the phone's photos, asked in a sheet that comes back with what went wrong — never a
     *  system dialog (M47 U7). `blocked`: why photos cannot be added on this phone, said in the same sheet. */
    adding: { open: boolean; blocked: string | null; problem: string | null; ask: () => void; cancel: () => void; take: () => void; choose: () => void }
    /** A photo the resident added asks, in a sheet, before it is taken back off. */
    removing: { open: boolean; ask: (key: string) => void; confirm: () => void; cancel: () => void }
  }
  where: {
    /** The location row's first line: the place in the resident's words; else the pin's name (or HERE); else the unit. */
    title: string
    /** Its second line: "Pinned from current location", or why there is no pin. */
    line: string
    /** The place in words, as typed after "Change" — empty until then. */
    place: string
    setPlace: (text: string) => void
    /** What the first line falls back to when the typed place is empty. */
    fallback: string
    maxLength: number
  }
  keepNamePrivate: boolean
  setKeepNamePrivate: (on: boolean) => void
  busy: boolean
  /** Submit. What was saved is `recorded`; a transcript still empty is `transcriptError`. */
  send: () => void
  /**
   * Why nothing was sent when nothing was — this session cannot write (a demonstration), or the phone could not even
   * save it — said above Submit, as New maintenance request says its own; never a system dialog (M47 U7).
   */
  problem: string | null
  recorded: Recorded | null
  /** While it waits on this phone: try the queue again now (same Idempotency-Key — it cannot be sent twice). */
  retryNow: () => void
  /** After a refusal only: back to the form with everything still written — what is sent next is a NEW report. */
  changeAndResend: () => void
}

const RETRY_EVERY_MS = 30_000
/** Long enough for the sheet's own close to finish before the phone's picker is presented (as New maintenance request's). */
const SHEET_CLOSE_MS = 350

const shortened = (text: string, max: number) => (text.length > max ? `${text.slice(0, max - 1).trimEnd()}…` : text)

const FIXTURE_DICTATION: ReportDictation = { ...STILL_DICTATION, listening: reportFixture.listening, levels: stillLevels(reportFixture.levels) }

export function useIncidentReport(): IncidentReportModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-14' ? reportFixture : null
  const refusal = useDemoRefusal()
  const ops = useAppSelector((s) => s.outbox.ops)
  const unitLabel = useAppSelector((s) => s.session.principal?.reference ?? null)

  // ── the form: what the board asks, and nothing else
  const [type, setType] = useState<IncidentTypeId>(fixture && isIncidentType(fixture.type) ? fixture.type : (EMPTY_REPORT.type as IncidentTypeId))
  const [transcript, setTranscript] = useState(fixture ? fixture.transcript : EMPTY_REPORT.description)
  const [place, setPlace] = useState(fixture ? fixture.place : EMPTY_REPORT.place)
  const [keepNamePrivate, setKeepNamePrivate] = useState<boolean>(fixture ? fixture.keepNamePrivate : true)
  // Messages appear once Submit has been pressed, and follow the words from then on.
  const [tried, setTried] = useState(false)

  // ── where the phone is: read once when the screen opens. Held here only until the report is captured.
  const [pin, setPin] = useState<LocationState>(fixture ? { state: 'attached', fix: fixture.fix } : { state: 'finding' })
  useEffect(() => {
    if (fixture) return
    let alive = true
    // The phone's dialog only after the Permissions screen's disclosure; before it, the permission it holds decides.
    void pinLocation({ mayAsk: permissionsAsked.get() }).then((next) => {
      if (alive) setPin(next)
    })
    return () => {
      alive = false
    }
  }, [fixture])

  // ── and its name: the phone's own geocoder, asked once for that pin (location.ts) — never degrees (M47 §13.3).
  const [pinName, setPinName] = useState<string | null>(null)
  useEffect(() => {
    if (fixture || pin.state !== 'attached') return
    let alive = true
    void namePin(pin.fix).then((name) => {
      if (alive) setPinName(name)
    })
    return () => {
      alive = false
    }
  }, [fixture, pin])
  const named = pin.state === 'attached' ? pinName : null

  // What the row shows is what is sent: the place in the resident's words, else the pin's name — never the unit by
  // default when the phone has said where it is.
  const values: ReportForm = { type, description: transcript, place: place.trim() ? place : (named ?? '') }
  const parsed = reportSchema.safeParse(values)
  const issues = parsed.success ? [] : parsed.error.issues
  const transcriptError = tried ? (issues.find((issue) => issue.path[0] === 'description')?.message ?? null) : null

  // ── dictation: what is heard is appended to what is written
  const onHeard = useCallback((words: string) => setTranscript((written) => appendTranscript(written, words)), [])
  const live = useReportDictation(onHeard)
  const dictation: ReportDictation = fixture ? FIXTURE_DICTATION : live

  // ── photos: local uris only, asked for in a sheet (never a system dialog, M47 U7)
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

  // ── submit: the report first, its photos behind it
  const [busy, setBusy] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [done, setDone] = useState<{ title: string; reportOp: string; mediaOps: string[]; notSaved: number } | null>(null)
  // State lands a render late; two fast taps must not file two reports.
  const inFlight = useRef(false)
  // What the saved report was last seen to be in the queue (see `recorded` below).
  const lastSeen = useRef<{ op: string; state: OutboxState; refusal: string | null } | null>(null)

  const send = useCallback(
    async (form: ReportForm): Promise<void> => {
      if (fixture || inFlight.current) return
      setSaveError(null)
      if (!reportSchema.safeParse(form).success) {
        setTried(true)
        return
      }
      // Before `submit()`: the report and its photos are captured on the phone first, so without
      // this a demonstration session was told a security report had been filed.
      if (refusal) {
        setSaveError(refusal)
        return
      }
      inFlight.current = true
      setBusy(true)
      try {
        // The pin goes with THIS act and nowhere else: it is not stored, and it is gone with the form.
        const body = reportBody(form, now(), pin.state === 'attached' ? pin.fix : null)
        const label = shortened(body.title, 48)
        const report = await submit({ endpoint: 'tickets.store', body, label: `Security report · ${label}`, kind: INCIDENT_KIND })
        const mediaOps: string[] = []
        let notSaved = 0
        for (const [index, photo] of photos.entries()) {
          try {
            const media = await submit({
              endpoint: 'tickets.media',
              params: { ticket: { $from: report.id, key: 'id' } },
              body: mediaBody(photo, index),
              label: `Report photo · ${label} (${index + 1} of ${photos.length})`,
              kind: INCIDENT_KIND,
              after: report.id,
            })
            mediaOps.push(media.id)
          } catch {
            notSaved += 1
          }
        }
        setDone({ title: body.title, reportOp: report.id, mediaOps, notSaved })
        void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined)
      } catch {
        setSaveError('That could not be saved on this phone, so nothing has been sent. Try again, and if it is urgent, call the estate office or use Panic.')
      } finally {
        inFlight.current = false
        setBusy(false)
      }
    },
    [fixture, photos, pin, refusal],
  )

  // The queue is sent when the phone reports signal again. A weak link fails without ever reporting
  // "offline", so while a saved report is on screen the queue is also tried every half minute.
  const waiting = !!done && [done.reportOp, ...done.mediaOps].some((id) => ops.find((o) => o.id === id)?.state === 'pending')
  useEffect(() => {
    if (!waiting) return
    const timer = setInterval(() => void drain(), RETRY_EVERY_MS)
    return () => clearInterval(timer)
  }, [waiting])

  let recorded: Recorded | null = null
  if (done) {
    const reportOp = ops.find((o) => o.id === done.reportOp)
    const media = done.mediaOps.map((id) => ops.find((o) => o.id === id))
    // An act leaves the queue a day after it was CONFIRMED — or when a refusal that was read is cleared.
    // So a report that is no longer in the queue is whatever it was last SEEN to be: it is never promoted
    // to "sent" by vanishing.
    if (reportOp) lastSeen.current = { op: done.reportOp, state: reportOp.state, refusal: reportOp.error?.message ?? null }
    const seen = lastSeen.current?.op === done.reportOp ? lastSeen.current : null
    const state: OutboxState = reportOp?.state ?? seen?.state ?? 'pending'
    // The store mirrors the queue without answers; the ticket the server made is in the queue itself.
    const answer = state === 'confirmed' ? outbox.all().find((o) => o.id === done.reportOp)?.result : null
    const ticket = isTicket(answer) ? answer : null
    recorded = {
      title: done.title,
      state,
      refusal: state === 'refused' ? (reportOp?.error?.message ?? seen?.refusal ?? NOT_ACCEPTED) : null,
      incidentId: ticket ? String(ticket.id) : null,
      number: ticket ? numberOf(ticket) : null,
      photos: {
        total: done.mediaOps.length + done.notSaved,
        waiting: media.filter((o) => o?.state === 'pending' || o?.state === 'syncing').length,
        refused: media.filter((o) => o?.state === 'refused').length,
        notSaved: done.notSaved,
      },
    }
  }

  const said = place.trim().replace(/\s+/g, ' ')
  const fallback = pin.state === 'attached' ? (named ?? HERE) : unitLabel ?? 'Your unit'
  const removable = removing !== null && photos.some((photo) => photo.uri === removing)

  return {
    types: INCIDENT_TYPES,
    type,
    // A radio row: one is always on, as the board draws it.
    chooseType: (value) => setType(value),
    dictation,
    transcript,
    setTranscript,
    transcriptError,
    media: {
      items: fixture
        ? fixture.media.map((item) => ({ ...item }))
        : photos.map((photo) => ({ key: photo.uri, kind: 'photo' as const, uri: photo.uri })),
      canAdd: fixture ? true : photos.length < PHOTO_MAX,
      adding: {
        open: adding,
        blocked: fixture || canPick ? null : PHOTOS_UNAVAILABLE,
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
      // Before it is sent a photo is only a choice: it can be taken back. Once captured, it is a record.
      removing: {
        open: removable,
        ask: (key) => setRemoving(key),
        confirm: () => {
          setPhotos((held) => held.filter((photo) => photo.uri !== removing))
          setRemoving(null)
        },
        cancel: () => setRemoving(null),
      },
    },
    where: {
      title: said || fallback,
      line: pinLine(pin),
      place,
      setPlace,
      fallback,
      maxLength: LOCATION_MAX,
    },
    keepNamePrivate,
    setKeepNamePrivate,
    busy,
    send: () => void send(values),
    problem: saveError,
    recorded,
    retryNow: () => void drainNow(), // a person asked: go at once
    changeAndResend: () => {
      if (recorded?.state !== 'refused' || !done) return
      // The refusal has been read and is being acted on: it leaves the queue, so the report that replaces it
      // is not listed under a "Not accepted" copy of itself for good. Nothing was filed, so no record is
      // removed. (The words and the photos are still held here, in the form.)
      void clearRefusal(done.reportOp)
      setDone(null)
    },
  }
}
