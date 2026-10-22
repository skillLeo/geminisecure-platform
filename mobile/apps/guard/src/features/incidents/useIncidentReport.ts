/**
 * Everything the incident report decides — board guard-app-08. ONE scrolling screen, no wizard:
 * a guard files a report in under three minutes, with one hand, often with no signal.
 *
 *   KIND         the board's chips. The server's `kind` is free text, so the chip's words are what is filed.
 *   SEVERITY     set BY THE GUARD — low · medium · high, the server's three values. There is no AI on this
 *                server and nothing is ever suggested. (The board draws no severity control; the server
 *                requires one, so the live screen has it and the fidelity build leaves it out.)
 *   THE ACCOUNT  typed, or dictated where the phone can (dictation.ts). At least 20 characters after
 *                trimming — the server's rule — with the count shown live.
 *   PHOTOS       photoPicker.ts — local uris only, up to PHOTO_MAX.
 *   LOCATION     the current shift's post name (editable: an incident is rarely AT the post) plus an
 *                optional GPS fix. The fix never blocks: no permission, no fix, no shift — each is said
 *                plainly and the report still goes. GPS is read only while the guard is clocked in (SA-12).
 *   THE SHIFT    the server links a report to the running shift BY ITSELF. The board's "Link to current
 *                patrol" switch has nothing to switch, so it is drawn as a statement of that, not a control.
 *
 *   SUBMIT IS OFFLINE-CAPABLE AND ORDERED. The report is captured on the phone FIRST (submit()), with the
 *   time it happened; each photo is captured behind it — `after: report.id`, its path parameter filled
 *   from the report's confirmed answer (`{ $from: report.id, key: 'id' }`) — so media can never reach the
 *   server before the report it belongs to has an id. What happened next is read from the outbox:
 *   Pending sync · Syncing · Confirmed · Not accepted (with the server's sentence).
 *
 * A queued write's refusal arrives later and carries the server's sentence only (no per-field errors),
 * so the form's own validation mirrors the server's limits exactly and the sentence is shown as given.
 * Records are append-only: nothing here edits or withdraws a report.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import * as Haptics from 'expo-haptics'
import * as Location from 'expo-location'
import { useForm, useFormState, useWatch, type Control } from 'react-hook-form'
import { deviceTimeIso, fidelityScreenId, isFidelity, useOfflineCache } from '@gs/core'
import { guardApi } from '@gs/core/api/guard'
import { incidentReportFixture } from '@/fidelity/incidents'
// Setup's location disclosure. Nothing here may put the phone's location dialog up before it was read.
import { onboarding } from '@/features/enrolment/onboardingState'
import { submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { STILL_DICTATION } from '@gs/core/speech'
import { useDictation, type Dictation } from './dictation'
import { CATEGORIES, INCIDENT_FIELDS, PHOTO_MAX, appendTranscript, categoryById, detailCount, incidentSchema, isSeverity, mediaBody, reportBody, type Category, type Fix, type IncidentField, type IncidentForm, type Photo, type Severity } from './incidents'
import { PHOTOS_UNAVAILABLE, photosAvailable, pickPhotos } from './photoPicker'

export type Gps =
  | { state: 'off_duty' }
  | { state: 'finding' }
  | { state: 'fixed'; fix: Fix }
  | { state: 'unavailable'; why: 'permission' | 'no_fix' }

export type Recorded = {
  category: string
  state: 'pending' | 'syncing' | 'confirmed' | 'refused'
  /** The server's sentence when it did not accept the report. */
  refusal: string | null
  photos: { total: number; waiting: number; refused: number; notSaved: number }
}

export type IncidentReportModel = {
  fixture: typeof incidentReportFixture | null
  control: Control<IncidentForm>
  errors: Partial<Record<IncidentField, string>>
  /** The first thing still wrong, for the line above the sticky Submit (the field itself may be scrolled away). */
  firstError: string | null
  categories: readonly Category[]
  category: string
  chooseCategory: (id: string) => void
  severity: Severity | null
  chooseSeverity: (value: Severity) => void
  count: { count: number; ok: boolean; label: string }
  dictation: Dictation
  photos: { items: Photo[]; available: boolean; reason: string | null; canAdd: boolean; trouble: string | null; add: (source: 'camera' | 'library') => void; remove: (uri: string) => void }
  location: { value: string; gps: Gps; gpsLabel: string }
  shiftLink: { label: string; on: boolean; detail: string | null }
  online: boolean
  busy: boolean
  /** The report could not even be saved on the phone (storage) — said plainly, nothing is pretended. */
  saveError: string | null
  send: () => void
  recorded: Recorded | null
  /** After a refusal only: back to the form with everything still written. The refused attempt stays in the sync queue. */
  fileAgain: () => void
}

const GPS_TIMEOUT_MS = 12_000

const timeout = <T,>(work: Promise<T>, ms: number): Promise<T | null> => Promise.race([work, new Promise<null>((resolve) => setTimeout(() => resolve(null), ms))])

export function useIncidentReport(): IncidentReportModel {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-08' ? incidentReportFixture : null
  const online = useAppSelector((s) => s.network.online)
  const ops = useAppSelector((s) => s.outbox.ops)

  // ── the shift: where the guard is posted, and whether they are on duty
  const shiftQuery = guardApi.useShiftsMeCurrentQuery(undefined, { skip: !!fixture })
  const shift = useOfflineCache('shift.current', shiftQuery.data)?.shift ?? null
  const postName = shift?.post.name ?? ''
  // A clock-in captured with no signal is not in the cached shift yet — it is in the outbox.
  const clockInWaiting = ops.some((o) => o.endpoint === 'shifts.clock_in' && (o.state === 'pending' || o.state === 'syncing'))
  const onDuty = (!!shift && !!shift.actual_start && !shift.actual_end) || clockInWaiting

  const form = useForm<IncidentForm>({
    resolver: zodResolver(incidentSchema),
    mode: 'onTouched',
    defaultValues: fixture ? { category: fixture.category, severity: fixture.severity, detail: fixture.transcript, location: fixture.location.title } : { category: '', severity: '', detail: '', location: '' },
  })
  const errors = useFormState({ control: form.control }).errors
  const category = useWatch({ control: form.control, name: 'category' }) ?? ''
  const severityValue = useWatch({ control: form.control, name: 'severity' }) ?? ''
  const detail = useWatch({ control: form.control, name: 'detail' }) ?? ''
  const locationValue = useWatch({ control: form.control, name: 'location' }) ?? ''

  // The post's name is where a report starts from; whatever the guard has typed is never overwritten.
  useEffect(() => {
    if (fixture || !postName || form.getFieldState('location').isDirty || form.getValues('location')) return
    form.setValue('location', postName)
  }, [fixture, postName, form])

  // ── GPS: optional, never blocking
  const [gps, setGps] = useState<Gps>(fixture ? { state: 'unavailable', why: 'no_fix' } : { state: 'off_duty' })
  useEffect(() => {
    if (fixture) return
    if (!onDuty) return setGps({ state: 'off_duty' })
    let alive = true
    setGps({ state: 'finding' })
    void (async () => {
      try {
        // The dialog only after setup's disclosure said what a position is read for (useGeofence.ts
        // carries the full reason). Before that this only LOOKS at the permission and no dialog appears.
        const permission = onboarding.locationDisclosed() ? await Location.requestForegroundPermissionsAsync() : await Location.getForegroundPermissionsAsync()
        if (!alive) return
        if (!permission.granted) return setGps({ state: 'unavailable', why: 'permission' })
        const recent = await Location.getLastKnownPositionAsync({ maxAge: 120_000, requiredAccuracy: 100 }).catch(() => null)
        const position = recent ?? (await timeout(Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced }), GPS_TIMEOUT_MS))
        if (!alive) return
        if (!position) return setGps({ state: 'unavailable', why: 'no_fix' })
        setGps({ state: 'fixed', fix: { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracyM: position.coords.accuracy === null ? null : Math.round(position.coords.accuracy) } })
      } catch {
        if (alive) setGps({ state: 'unavailable', why: 'no_fix' })
      }
    })()
    return () => {
      alive = false
    }
  }, [fixture, onDuty])

  const gpsLabel =
    gps.state === 'fixed' ? `GPS-pinned${gps.fix.accuracyM === null ? '' : ` · accurate to ${gps.fix.accuracyM}m`}`
    : gps.state === 'finding' ? 'Finding your position…'
    : gps.state === 'off_duty' ? 'Location not available: GPS is only read while you are clocked in'
    : gps.why === 'permission' ? 'Location not available: location is switched off for this app'
    : 'Location not available: no GPS fix. The report still goes.'

  // ── dictation: what is heard is appended to what is written
  const onHeard = useCallback(
    (words: string) => form.setValue('detail', appendTranscript(form.getValues('detail'), words), { shouldDirty: true, shouldValidate: form.formState.isSubmitted }),
    [form],
  )
  const live = useDictation(onHeard)
  const dictation: Dictation = fixture ? { ...STILL_DICTATION, listening: fixture.listening } : live

  // ── photos: local uris only
  const [photos, setPhotos] = useState<Photo[]>([])
  const [photoTrouble, setPhotoTrouble] = useState<string | null>(null)
  const picking = useRef(false)
  const canPick = !fixture && photosAvailable()
  const addPhoto = useCallback(
    async (source: 'camera' | 'library') => {
      if (!canPick || picking.current) return
      picking.current = true
      try {
        const outcome = await pickPhotos(source, PHOTO_MAX - photos.length)
        setPhotoTrouble(outcome.trouble)
        if (outcome.photos.length) setPhotos((held) => [...held, ...outcome.photos.filter((p) => !held.some((h) => h.uri === p.uri))].slice(0, PHOTO_MAX))
      } finally {
        picking.current = false
      }
    },
    [canPick, photos.length],
  )

  // ── submit: the report first, its photos behind it
  const [busy, setBusy] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [done, setDone] = useState<{ category: string; reportOp: string; mediaOps: string[]; notSaved: number } | null>(null)
  // State lands a render late; two fast taps must not file two reports.
  const inFlight = useRef(false)

  const send = useCallback(
    async (values: IncidentForm) => {
      if (fixture || inFlight.current) return
      inFlight.current = true
      setBusy(true)
      setSaveError(null)
      try {
        const label = categoryById(values.category)?.label ?? 'Other'
        const report = await submit({
          endpoint: 'incidents.store',
          body: reportBody({ values, fix: gps.state === 'fixed' ? gps.fix : null, occurredAt: deviceTimeIso() }),
          label: `Incident report · ${label}`,
          kind: 'incident',
        })
        const mediaOps: string[] = []
        let notSaved = 0
        for (const [index, photo] of photos.entries()) {
          try {
            const media = await submit({
              endpoint: 'incidents.media',
              params: { incident: { $from: report.id, key: 'id' } },
              body: mediaBody(photo, index),
              label: `Incident photo · ${label} (${index + 1} of ${photos.length})`,
              kind: 'incident',
              after: report.id,
            })
            mediaOps.push(media.id)
          } catch {
            notSaved += 1
          }
        }
        setDone({ category: label, reportOp: report.id, mediaOps, notSaved })
        void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined)
      } catch {
        setSaveError('That could not be saved on this phone, so nothing has been filed. Try again.')
      } finally {
        inFlight.current = false
        setBusy(false)
      }
    },
    [fixture, gps, photos],
  )

  const flat: Partial<Record<IncidentField, string>> = {}
  for (const field of INCIDENT_FIELDS) {
    const message = errors[field]?.message
    if (message) flat[field] = message
  }

  let recorded: Recorded | null = null
  if (done) {
    const reportOp = ops.find((o) => o.id === done.reportOp)
    const media = done.mediaOps.map((id) => ops.find((o) => o.id === id))
    recorded = {
      category: done.category,
      // An act that has left the queue was confirmed (a pending one can never be dismissed).
      state: reportOp?.state ?? 'confirmed',
      refusal: reportOp?.state === 'refused' ? (reportOp.error?.message ?? 'The server did not accept this report.') : null,
      photos: {
        total: done.mediaOps.length + done.notSaved,
        waiting: media.filter((o) => o?.state === 'pending' || o?.state === 'syncing').length,
        refused: media.filter((o) => o?.state === 'refused').length,
        notSaved: done.notSaved,
      },
    }
  }

  return {
    fixture,
    control: form.control,
    errors: flat,
    firstError: INCIDENT_FIELDS.map((field) => flat[field]).find((message) => !!message) ?? null,
    categories: CATEGORIES,
    category,
    chooseCategory: (id) => form.setValue('category', id, { shouldDirty: true, shouldValidate: true }),
    severity: isSeverity(severityValue) ? severityValue : null,
    chooseSeverity: (value) => form.setValue('severity', value, { shouldDirty: true, shouldValidate: true }),
    count: detailCount(detail),
    dictation,
    photos: {
      items: photos,
      available: canPick,
      reason: canPick ? null : PHOTOS_UNAVAILABLE,
      canAdd: canPick && photos.length < PHOTO_MAX,
      trouble: photoTrouble,
      add: (source) => void addPhoto(source),
      // Before it is filed a photo is only a choice, not a record: it can be taken back.
      remove: (uri) => setPhotos((held) => held.filter((p) => p.uri !== uri)),
    },
    location: { value: locationValue, gps, gpsLabel: fixture ? fixture.location.detail : gpsLabel },
    shiftLink: fixture
      ? { label: fixture.link.label, on: fixture.link.on, detail: null }
      : onDuty
        ? { label: 'Linked to your shift', on: true, detail: `The office links every report to the shift you are clocked in to${postName ? `: ${postName}` : ''}. There is nothing to switch.` }
        : { label: 'Not linked to a shift', on: false, detail: 'You are not clocked in, so this report is filed without a shift. It is still filed under your name.' },
    online,
    busy,
    saveError,
    send: () => void form.handleSubmit(send)(),
    recorded,
    fileAgain: () => {
      if (recorded?.state === 'refused') setDone(null)
    },
  }
}
