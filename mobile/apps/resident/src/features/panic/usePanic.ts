/**
 * The resident's panic alert — board resident-app-06, and the states around it the board does not draw.
 *
 *   arm        nothing is sent by a single tap. The button is HELD (or, for someone who cannot hold,
 *              tapped and then confirmed). The emergency number is on this screen before, during and after.
 *              Beside the button, never in front of it: an OPTIONAL one-tap choice of what kind of
 *              emergency it is (panicType.ts). Nothing is chosen to begin with, and with nothing chosen
 *              the alert is exactly the alert it always was.
 *   sending    the engine (packages/core/safety/raiseAlert): on disk first, sent directly, under three
 *              seconds, waiting for nothing that is not the alert — no position beyond 400 ms, never media.
 *   active     the board. The timeline is the server's own (`duress.show`, polled): sent · acknowledged ·
 *              dispatched · en route · on scene · closed, each ticked only by the time the server recorded
 *              for it, and "Not recorded" where a later step has one and it does not (alertProgress.ts).
 *              "Help is on the way" is said only once an officer was DISPATCHED; before that, "The control
 *              room has your alert". It survives closing the app: the alert is remembered on the phone
 *              and the screen returns to it. It also carries WHO IT ACTUALLY REACHED:
 *              the server counts the handsets it sent to, `alertReach` turns that into words, and when
 *              the answer is none the screen says so plainly instead of implying somebody is coming.
 *   queued     no signal. Said exactly: saved, NOT yet received, sent the moment any signal returns —
 *              with the two things that need no data beside it: call, and text the household's own
 *              emergency contacts (honestly labelled: a text from this phone, not to dispatch).
 *   reassure   M12 §2.4: the active screen leads with what calms — "Officers alerted", "All relevant people have
 *              been notified" — built only from facts the server recorded (`panicReassurance`), and never says an
 *              officer is coming when none was reached. 119 stays beside it: "if you can", or "now".
 *   emails     M12 §2.5: the household's contacts are EMAILED on a panic; "emailed" only from the mail server's
 *              accepted count (`householdEmails`).
 *   texts      NOTHING HERE SAYS A TEXT WENT OUT UNLESS THE SERVER SAYS A PROVIDER ACCEPTED ONE (M10 §1).
 *              The household's own contacts: `householdContactsLine` reads `sms.household` — "Texting your
 *              emergency contacts…" while it runs, "N of your emergency contacts were sent a text" only once
 *              it finished with deliveries, and otherwise (today: no SMS provider is connected) that they
 *              are not texted automatically. The community's emergency numbers: `alertReach`, and only on a
 *              delivered count above zero.
 *   cancel     only inside the server's ten-second window and before anyone acknowledges; a reason is
 *              required; online only ("the alert has not reached anyone to cancel" otherwise). The rebuilt
 *              screen (M42 §5 #5) draws no cancel — "Only a responder can close this alert" — so nothing calls it.
 *   closed     resolved or a false alarm, shown as it closes. An alert that is already over when the screen
 *              opens is not shown again, and leaving the screen puts a closed one away — and one refused or never
 *              kept — since the board has no "Done" and no "send a new alert" (M42 §5 #5): the next visit opens
 *              ready to send. (A failed one is itself shown on the ready-to-send screen, the reason in its gold line.)
 *
 * WHAT THE SCREEN DRAWS is `board` (panicBoard.ts): the board's slots, filled from the facts above — and, in the
 * fidelity build only, the board's own sample (`@/fidelity/panic`). The screen never asks which build it is in.
 *
 * INVARIANT 13: nothing on this screen can stop a call to the emergency services.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Linking, Platform } from 'react-native'
import * as Haptics from 'expo-haptics'
import * as Location from 'expo-location'
import { dateShort, dayTimeTag, isFidelity, now, siteDayIso, time12, time12s, timeTag, timeWords, useOfflineCache, withClockTag } from '@gs/core'
import type { Api, ApiError } from '@gs/core/api'
import { newStamp } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { alertReach, alertSteps, answerMoved, helpScreen, householdContactsLine, isAlertType, isDrill, keepOffering, offlineLine, panicReassurance, raiseAlert, responseHeadline, type AlertAnswer, type AlertReach, type AlertStep, type AlertType, type Headline, type HelpScreen, type Reassurance } from '@gs/core/safety'
import { newUuid } from '@gs/core/lib'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { panicFixture } from '@/fidelity/panic'
import { offer, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { MESSAGE_ENDPOINT, MESSAGE_MAX, threadOf, type ThreadItem } from './dispatchChannel'
import { microphone } from './LiveAudioHost'
import { CANNOT_RECORD, MICROPHONE_OFF, markSwitchedOff, setLiveAudioState, switchedOff, useLiveAudioState, wantLiveAudio, wantedAudio, type LiveAudioState } from './liveAudio'
import { drillModeLine, panicBoard, type DrillMode, type PanicBoard } from './panicBoard'
import { callLink, panicContactsOf, smsBody, smsLink, type SmsContact } from './panicSms'
import { EMERGENCY_NUMBER, belongsToSomeoneElse, ownerOf, panicBox, type HeldWhy, type Saved } from './panicRecord'
import { AMBULANCE_FIRE_NUMBER, alertLabel, toggleType } from './panicType'

export { EMERGENCY_NUMBER }
export const HOLD_MS = 1500
const POLL_MS = 5000

export const CANCEL_REASONS = ['Pressed by accident', 'I was testing it', 'The situation is over', 'Someone else is handling it'] as const

// The record itself — where it is kept, whose it is, what Log In says about it — is `panicRecord.ts` (B117, B118).
const box = panicBox

export const NOT_KEPT = `This phone could not keep this alert to send it again, and never heard whether it got through. If you still need help, send a new one, and call ${EMERGENCY_NUMBER} now.`
export const NOT_KNOWN = `This phone no longer knows whether this alert got through. If you still need help, send a new one, and call ${EMERGENCY_NUMBER} now.`
const REFUSED = `The server did not accept this alert. Send a new one, or call ${EMERGENCY_NUMBER} now.`

const isOpen = (a: AlertAnswer | null | undefined): boolean => !!a && !['resolved', 'false_alarm'].includes(a.status)

/**
 * WHY A HELD ALERT WILL NEVER BE SENT, or null while it still can be (B117). The server refused it — the direct send,
 * or the queue's copy later, since the same key is answered the same way for ever; it was never kept on the phone to
 * send again; or the queue no longer holds it (a confirmed act leaves a day after it was captured). Each of those
 * used to leave the screen on "saved — not yet received" for good, with no way to send another alert from the app.
 */
export function failureOf(saved: Saved | null, ops: readonly { id: string; state: string; error?: { message?: string } | null }[]): string | null {
  if (!saved || saved.alert) return null
  const op = saved.opId === null ? undefined : ops.find((o) => o.id === saved.opId)
  if (op?.state === 'refused') return op.error?.message || saved.message || REFUSED
  if (op?.state === 'confirmed') return null // delivered: it becomes the alert on the next look
  if (saved.why === 'refused') return saved.message || REFUSED
  if (saved.opId === null) return NOT_KEPT
  if (op === undefined) return NOT_KNOWN
  return null
}

/** Whether an alert is live — open at the control room, or held here and still to be sent. Only a live one stops a new press. */
export const isLive = (saved: Saved | null, ops: Parameters<typeof failureOf>[1]): boolean => !!saved && (saved.alert ? isOpen(saved.alert) : failureOf(saved, ops) === null)

/** A held alert, said by WHY it is held (B122): "no connection" only when that is the reason. */
export function heldLine(why: HeldWhy | null | undefined, online: boolean): string {
  const reason = why ?? 'no_signal'
  if (!online || reason === 'no_signal') return `Your phone has no connection right now, so nobody has this alert yet. It is saved on this phone and is sent by itself the moment any signal returns. Do not wait for it: call ${EMERGENCY_NUMBER} now.`
  if (reason === 'slow') return `Not confirmed yet, the control room may already have it. The server was slow to answer, and this phone keeps sending it until it does. Do not wait for it, call ${EMERGENCY_NUMBER} now.`
  return `Not confirmed yet, the server did not answer properly. This phone keeps sending it until it does. Do not wait for it, call ${EMERGENCY_NUMBER} now.`
}

export type PanicModel = {
  phase: 'arm' | 'sending' | 'active' | 'queued' | 'failed' | 'closed'
  /** Everything the rebuilt screen writes — its title, gold line, three steps, dispatch's message, the audio switch. */
  board: PanicBoard
  /** Why a held alert is held, in words that say so (B122). */
  heldLine: string
  /** Why an alert will never be sent — the server's own sentence where it gave one. Phase `failed` (B117). */
  failure: string | null
  sentLine: string
  /** Where the response stands, in the server's own push words (`responseHeadline`). Null once closed. */
  headline: Headline | null
  /** A DRILL raised to test the fan-out — never a resident's own press, but said first if the server ever says so. */
  drill: boolean
  steps: AlertStep[]
  /**
   * WHO THE ALERT ACTUALLY REACHED — the server's own count, put into words by
   * `@gs/core/safety`'s `alertReach`. `reach.reachedNobody` is the one flag the screen branches
   * on, and `reach.mayPromiseHelp` is the one that has to be true before anything on the screen
   * may suggest somebody is on their way.
   */
  reach: AlertReach
  /**
   * WHAT CALMS, FROM WHAT IS TRUE (M12 §2.4; `@gs/core/safety` reassurance.ts): the title, one line on what to do,
   * each fact the server recorded ticked, and 119 beside it — "if you can", or "now" when no officer was reached.
   * Null until there is an answer, and once the alert is closed.
   */
  reassurance: Reassurance | null
  /**
   * THE SCREEN AFTER THE PRESS, WORD FOR WORD (M18A; `@gs/core/safety` helpRequested.ts): "Help requested", what
   * happened ticked with its time, the 119 card, then what is and is not happening in plain type, and the contacts.
   * Null until the server has answered.
   */
  help: HelpScreen | null
  /** M18A §2.1 for an alert still on the phone: "Lot 47 · pressed 6:32 PM". */
  heldSubline: string
  /** M18A §2.7: an alert held for want of any signal, said calmly and truly. Null when that is not why it is held. */
  offline: string | null
  withPosition: boolean
  /** Where the press placed the resident, as it was sent (M11 §6); null when no position went, or none is kept. */
  position: { latitude: number; longitude: number } | null
  /** The resident's unit, for the map's pin and the words beside it. */
  unitLabel: string | null
  elapsedMs: number | null
  queuedWhy: string | null
  /**
   * A READ-ONLY demonstration session pressed the alert. Nothing was raised and nothing
   * was queued — which matters more here than anywhere else in the app, because an alert captured
   * on the phone and never delivered is the one failure that could get somebody hurt. The button
   * stays exactly where it is and 119 stays exactly where it is; only the press is refused.
   */
  demoRefusal: string | null
  canCancel: boolean
  cancelSecondsLeft: number
  cancelBusy: boolean
  cancelError: string | null
  closedAs: 'resolved' | 'false_alarm' | null
  /** The household's emergency contacts who can be texted — each person's phone once (M01). */
  contacts: SmsContact[]
  /** "Your emergency contacts are not texted automatically — call them" — said in every state, while it is true (`householdContactsLine`). */
  contactsLine: string
  /**
   * What kind of emergency: the one chosen for the NEXT press while arming, and the one that was sent
   * once there is an alert. Null is the default and is a complete answer — nothing waits for it.
   */
  type: AlertType | null
  chooseType: (type: AlertType) => void
  online: boolean
  fire: () => void
  cancel: (reason: string) => void
  callEmergency: () => void
  /** 110, for the medical and fire types: choosing one of those calls nobody by itself. */
  callAmbulanceFire: () => void
  /** Opens ONE text in the phone's own Messages app to these people — all of them unless the sheet chose (M01). */
  textContacts: (only?: readonly SmsContact[]) => void
  /** One person, on the phone's own dialler. */
  callContact: (contact: SmsContact) => void
  finish: () => void
  /** "Message dispatch…" — the live channel with the control room (M50 §4.2 #2; dispatchChannel.ts). */
  channel: DispatchChannel
  /** "Share live audio" (M50 §4.2 #1; liveAudio.ts). */
  audio: LiveAudio
}

export type LiveAudio = {
  /** The switch, as it truly stands: on only while the phone is recording or starting to. */
  on: boolean
  state: LiveAudioState
  toggle: (next: boolean) => void
  /** Why the switch could not be on — said in the screen's own sheet. */
  problem: { title: string; line: string; settings: boolean } | null
  clearProblem: () => void
}

export type DispatchChannel = {
  items: ThreadItem[]
  /** Whether a message can be written now: an alert held on the phone or open at the control room. */
  canWrite: boolean
  placeholder: string
  /** Queued, and sent at once or the moment there is a signal. True when it was taken (the box may clear). */
  send: (text: string) => Promise<boolean>
  /** A refused message tried again, as a new message with the same words. */
  retry: (opId: string) => void
  /** Why the last message could not even be queued (a demonstration session). */
  problem: string | null
}

/** The time of the press on the estate's clock, tagged (M19A §4.2) — and its day too when that is not today at the estate. */
const pressedWhen = (iso: string): string => (siteDayIso(iso) === siteDayIso(now()) ? timeTag(iso) : dayTimeTag(iso))

/** The board's own clock, untagged as the board writes it ("Sent 9:41 AM") — with the day in front when it is not today at the estate (B117). */
const boardTime = (iso: string): string => (siteDayIso(iso) === siteDayIso(now()) ? time12(iso) : `${dateShort(iso)}, ${time12(iso)}`)

/** An alert the control room has closed, or that was a false alarm. */
const isOver = (kept: Saved | null): boolean => !!kept?.alert && !isOpen(kept.alert)

const lastKnownPosition = async (): Promise<{ latitude: number; longitude: number } | null> => {
  if (Platform.OS === 'web') return null
  const permission = await Location.getForegroundPermissionsAsync()
  if (!permission.granted) return null // never ASKED for here: a permission dialog in the middle of a panic is a delay
  const fix = (await Location.getLastKnownPositionAsync({ maxAge: 120_000 })) ?? (await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced }))
  return fix ? { latitude: fix.coords.latitude, longitude: fix.coords.longitude } : null
}

export function usePanic(onDone: () => void): PanicModel {
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()
  const principal = useAppSelector((s) => s.session.principal)
  const queue = useAppSelector((s) => s.outbox.ops)
  const household = useOfflineCache<Api.MeHouseholdResponse>('me.household', undefined)
  // Every access level reads these (a Helper cannot read `me.household`); Home keeps the copy fresh (G2).
  const panicContacts = useOfflineCache<Api.PanicContactsResponse>('panic.contacts', undefined)
  // DRILL MODE (M53 §3A.1), asked afresh on every visit so the line before the press is as new as it can be. Nothing
  // waits on it: with no answer the line is simply not drawn, and the server decides what a press is when it arrives.
  const me = residentApi.useMeShowQuery(undefined, { skip: isFidelity, refetchOnMountOrArgChange: true })
  const savedMe = useOfflineCache<Api.MeShowResponse>('me.show', me.data)
  const drillMode = (savedMe as { drill_mode?: DrillMode | null } | undefined)?.drill_mode ?? null

  // Somebody else's, pressed before they were signed out on this phone, is not this resident's to see (B118).
  // One already over is not shown again: the screen opens ready to send, never on an old alert with no way to send
  // a new one (the board has no "Done" and no "send a new alert", M42 §5 #5). It is put away below.
  const [saved, setSaved] = useState<Saved | null>(() => {
    if (isFidelity) return null
    const kept = box().get()
    return belongsToSomeoneElse(kept, principal) || isOver(kept) ? null : kept
  })
  useEffect(() => {
    if (isFidelity) return
    const kept = box().get()
    if (isOver(kept) && !belongsToSomeoneElse(kept, principal)) box().remove()
    // Once, as the screen opens: what the record held at that moment.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  const [sending, setSending] = useState(false)
  const [elapsedMs, setElapsedMs] = useState<number | null>(null)
  const [queuedWhy, setQueuedWhy] = useState<string | null>(null)
  const [cancelBusy, setCancelBusy] = useState(false)
  const [cancelError, setCancelError] = useState<string | null>(null)
  // Nothing chosen until the resident chooses. Read by `fire` at the moment of the press, never waited for.
  const [type, setType] = useState<AlertType | null>(null)
  const firing = useRef(false)

  const remember = useCallback((next: Saved | null) => {
    setSaved(next)
    if (next) void box().setDurable(next)
    else box().remove()
  }, [])

  const [send] = residentApi.useDuressStoreMutation()
  const [cancelAlert] = residentApi.useDuressCancelMutation()

  /* ---- raise ---- */
  const fire = useCallback(async () => {
    // Only a LIVE alert stops a new press (B117): a refused, lost or closed one never stands between a resident and the next.
    if (isFidelity || firing.current || isLive(saved, outbox.all())) return
    // Before `raiseAlert`, whose offline path CAPTURES the alert on the phone and promises to
    // send it. A demonstration session must never hold an undeliverable alert.
    if (refusal) return setQueuedWhy(refusal)
    firing.current = true
    setSending(true)
    const stamp = newStamp() // the moment of the press — before anything else happens
    const pressedAt = now().toISOString()
    void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Warning).catch(() => undefined)
    // What the press sends, kept to show the resident (M11 §6) — only once the outcome says it went with the alert.
    let sentPosition: { latitude: number; longitude: number } | null = null
    try {
      const outcome = await raiseAlert(
        {
          stamp,
          online,
          clock: () => Date.now(),
          position: async () => (sentPosition = await lastKnownPosition()),
          capture: async (body, s) => outbox.enqueue({ endpoint: 'duress.store', body, stamp: s, label: 'Emergency alert', kind: 'alert', owner: principal ? `account:${principal.id}` : 'unknown' }),
          send: async (body, s) => {
            const answer = await send({ ...body, __stamp: s })
            return 'error' in answer && answer.error ? { error: answer.error as ApiError } : { data: answer.data as AlertAnswer }
          },
          settle: (opId, answer) => outbox.settle(opId, answer),
        },
        // The type is simply carried with the alert. `raiseAlert` checks it against the server's four
        // values and drops anything else, so a label can never be the reason an alert is refused.
        { mode: 'audible', type },
      )
      setElapsedMs(outcome.elapsedMs)
      // Whose it is goes on the record (B118): kept through a sign-out nobody chose, and shown to nobody else.
      const owner = ownerOf(principal)
      const position = outcome.withPosition ? sentPosition : null
      if (outcome.state === 'sent') remember({ alert: outcome.alert, opId: outcome.opId, pressedAt, withPosition: outcome.withPosition, type, why: null, message: null, owner, position })
      // WHY it is held goes on the record (B122), so the card says so on every mount and not only the first.
      else remember({ alert: null, opId: outcome.opId, pressedAt, withPosition: outcome.withPosition, type, why: outcome.why, message: outcome.message, owner, position })
    } finally {
      setSending(false)
      firing.current = false
    }
  }, [saved, online, principal, send, remember, refusal, type])

  /* ---- a queued alert that the outbox has since delivered becomes the active alert; one it was refused for says so ---- */
  useEffect(() => {
    if (!saved || saved.alert || !saved.opId) return
    const op = outbox.all().find((o) => o.id === saved.opId)
    if (op?.state === 'confirmed' && op.result && typeof op.result === 'object') remember({ ...saved, alert: op.result as AlertAnswer, why: null, message: null })
    // On the record, so the refusal is still said on the next mount (B117) — it used to live in this screen's state alone.
    else if (op?.state === 'refused' && saved.why !== 'refused') remember({ ...saved, why: 'refused', message: op.error?.message || REFUSED })
  }, [queue, saved, remember])

  /* ---- and while it is held, it is offered again every few seconds — a send can fail with the phone online ---- */
  useEffect(() => {
    // Not a refused one: the same key is answered the same way for ever.
    if (isFidelity || !saved || saved.alert || !saved.opId || saved.why === 'refused') return
    // No cleanup, deliberately: leaving this screen must not stop the alert being offered. It stops itself once answered.
    const held = saved.opId
    keepOffering(held, { state: (id) => outbox.all().find((o) => o.id === id)?.state, resend: () => offer(held) })
  }, [saved])

  /* ---- read it back while it is open ---- */
  const alertId = saved?.alert?.id
  const open = saved?.alert ? !['resolved', 'false_alarm'].includes(saved.alert.status) : false
  const live = residentApi.useDuressShowQuery({ alert: alertId ?? 0 }, { skip: isFidelity || alertId === undefined, pollingInterval: open ? POLL_MS : 0 })
  useEffect(() => {
    // `answerMoved` compares EVERY field the screen draws from, deliberately. The steps — dispatched,
    // en route, on scene, closed — each land on their own poll. `alerted_handsets` too: the fan-out
    // finishes a moment after the press, so the FIRST answer can honestly say "still reaching" and a
    // later poll is what turns that into a number — or into "no officer's phone was reached". Left
    // out, the screen would sit on "reaching" for ever, which is its own quiet untruth. The delivered-
    // text counts are there for the same reason: they are written after the texting finishes.
    //
    // ONLY THIS ALERT'S ANSWER (M23, found on the emulator; the Guard App's useDuress has held it since M11). While a new
    // alert's id is fetched, the query still hands back the LAST alert's answer, so a second press after a closed alert
    // was overwritten on the phone by the closed one — id, status and all — and the screen said "This alert has been
    // closed by the control room" over a live alert, for good. The alert itself was delivered; the resident was told
    // otherwise, and never saw it acknowledged.
    if (live.data && saved?.alert && String((live.data as AlertAnswer).id) === String(saved.alert.id) && answerMoved(saved.alert, live.data as AlertAnswer)) {
      remember({ ...saved, alert: { ...saved.alert, ...(live.data as AlertAnswer) } })
    }
  }, [live.data, saved, remember])

  /* ---- the cancel window: the SERVER's ten seconds ---- */
  const [tick, setTick] = useState(0)
  useEffect(() => {
    if (!open) return
    const timer = setInterval(() => setTick((n) => n + 1), 500)
    return () => clearInterval(timer)
  }, [open])
  const cancelSecondsLeft = useMemo(() => {
    const a = saved?.alert
    if (!a || a.acknowledged_at || a.status !== 'open') return 0
    return Math.max(0, Math.ceil((new Date(a.cancellable_until).getTime() - Date.now()) / 1000))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [saved, tick])

  const cancel = useCallback(
    async (reason: string) => {
      const a = saved?.alert
      if (!a || cancelBusy || reason.trim().length === 0) return
      setCancelBusy(true)
      setCancelError(null)
      try {
        const answer = await cancelAlert({ alert: a.id, reason: reason.trim().slice(0, 160) })
        if ('error' in answer && answer.error) {
          const e = answer.error as ApiError
          setCancelError(e.status === 'network' || e.status === 'timeout' ? `No connection: the alert stays active. Call ${EMERGENCY_NUMBER} or your gatehouse to stand it down.` : e.message)
        } else if (answer.data) remember({ ...saved, alert: { ...a, ...(answer.data as AlertAnswer) } })
      } finally {
        setCancelBusy(false)
      }
    },
    [saved, cancelBusy, cancelAlert, remember],
  )

  /*
   * Only contacts who can actually be texted are offered (panicSms.ts): a button that opens an empty message is a
   * dead one. And each PERSON once (M01): the same number entered four times is one phone, not four contacts.
   * From `panic.contacts` at every level, else the household's own list on an older server (G2).
   */
  const contacts = useMemo(() => panicContactsOf(panicContacts?.items, household?.emergency_contacts), [panicContacts, household])

  // The contact may be anywhere: the time says whose clock it is on, in full (M19A §4.2, §8.7).
  const body = useCallback(() => smsBody({ name: principal?.name, unit: principal?.reference, estate: principal?.siteName }, timeWords(saved?.pressedAt ?? now())), [principal, saved])

  /** The phone's own Messages app, addressed to these people (all of them unless the sheet chose), the words written. */
  const textContacts = useCallback(
    (only?: readonly SmsContact[]) => {
      const link = smsLink(only ?? contacts, body(), Platform.OS)
      if (link) void Linking.openURL(link).catch(() => undefined)
    },
    [contacts, body],
  )

  const callContact = useCallback((contact: SmsContact) => {
    const link = callLink(contact.phone)
    if (link) void Linking.openURL(link).catch(() => undefined)
  }, [])

  /*
   * "MESSAGE DISPATCH…" (M50 §4.2 #2). Through the queue, like the alert itself: sent at once with a connection, and
   * with none it waits on the phone and goes the moment there is one — never dropped. Written while the alert is
   * still held, it waits BEHIND the alert and takes the alert's id from its answer. The thread is the server's
   * (`messages` on this alert's read, as fresh as the last poll or live step) with what is still on its way.
   */
  const [messageProblem, setMessageProblem] = useState<string | null>(null)
  const freshAnswer = live.data && saved?.alert && String((live.data as AlertAnswer).id) === String(saved.alert.id) ? (live.data as AlertAnswer) : null
  const items = useMemo(
    () => (isFidelity ? [] : threadOf(freshAnswer?.messages ?? saved?.alert?.messages, outbox.all(), { alertId: saved?.alert ? String(saved.alert.id) : null, alertOp: saved?.opId ?? null }, online)),
    // The queue's own acts carry the words (the store's view of it does not): `queue` changing is what is on its way changing.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [queue, freshAnswer, saved, online],
  )
  const queueMessage = useCallback(async (words: string): Promise<boolean> => {
    const text = words.trim().slice(0, MESSAGE_MAX)
    if (isFidelity || text === '' || !saved) return false
    if (refusal) {
      setMessageProblem(refusal)
      return false
    }
    const params = saved.alert ? { alert: Number(saved.alert.id) } : saved.opId ? { alert: { $from: saved.opId, key: 'id' } } : null
    if (!params) return false
    setMessageProblem(null)
    try {
      const op = await submit({ endpoint: MESSAGE_ENDPOINT, params, body: { body: text, client_key: newUuid() }, label: 'Message to the control room', kind: 'message', after: saved.alert ? null : saved.opId })
      // Not behind the rest of the queue: a message to the control room goes on its own, as the alert does.
      if (saved.alert) void offer(op.id).catch(() => undefined)
      return true
    } catch (e) {
      setMessageProblem(e instanceof Error ? e.message : 'This message could not be kept on the phone. Call the control room or 119.')
      return false
    }
  }, [saved, refusal])
  const retryMessage = useCallback((opId: string) => {
    const op = outbox.all().find((o) => o.id === opId)
    const words = typeof op?.body?.body === 'string' ? op.body.body : ''
    if (!op || words === '') return
    void outbox.dismiss(opId).then(() => queueMessage(words))
  }, [queueMessage])

  /*
   * "SHARE LIVE AUDIO" (M50 §4.2 #1). The recorder is the app's (LiveAudioHost); this decides for which alert. On with
   * the alert, as the board draws it — but only with the microphone ALREADY allowed: nothing is asked for here, by
   * itself. Turned off by the resident, it stays off for that alert. It stops when the alert is closed.
   */
  const audioState = useLiveAudioState()
  const [audioProblem, setAudioProblem] = useState<LiveAudio['problem']>(null)
  const audioOp = saved?.opId ?? null
  const audioAlertId = saved?.alert ? Number(saved.alert.id) : null
  const audioLive = !isFidelity && !!saved && !isOver(saved) && !failureOf(saved, outbox.all())
  useEffect(() => {
    if (isFidelity || !audioOp) return
    if (!audioLive) {
      if (wantedAudio()?.alertOp === audioOp) wantLiveAudio(null)
      return
    }
    if (refusal || switchedOff(audioOp)) return
    let alive = true
    void microphone(false).then((allowed) => {
      if (alive && allowed === 'granted') wantLiveAudio({ alertOp: audioOp, alertId: audioAlertId })
    })
    return () => {
      alive = false
    }
  }, [audioOp, audioAlertId, audioLive, refusal])
  useEffect(() => {
    if (audioState === 'failed') setAudioProblem({ ...CANNOT_RECORD, settings: false })
  }, [audioState])
  const toggleAudio = useCallback((next: boolean) => {
    if (isFidelity || !audioOp || !audioLive) return
    if (!next) {
      markSwitchedOff(audioOp, true)
      wantLiveAudio(null)
      setLiveAudioState('off')
      return
    }
    void microphone(true).then((allowed) => {
      if (allowed === 'granted') {
        markSwitchedOff(audioOp, false)
        setAudioProblem(null)
        wantLiveAudio({ alertOp: audioOp, alertId: audioAlertId })
      } else if (allowed === 'denied') setAudioProblem({ ...MICROPHONE_OFF, settings: true })
      else setAudioProblem({ title: 'Live audio is not available here', line: 'Live audio is shared from the GeminiSecure app on a phone.', settings: false })
    })
  }, [audioOp, audioAlertId, audioLive])

  const a = saved?.alert ?? null
  const closedAs = a?.status === 'resolved' ? 'resolved' : a?.status === 'false_alarm' ? 'false_alarm' : null
  const secondsToAck = a?.acknowledged_at ? Math.max(0, Math.round((new Date(a.acknowledged_at).getTime() - new Date(a.server_time).getTime()) / 1000)) : null

  /*
   * The emergency number is passed in rather than baked into `alertReach`, so the sentence that
   * tells somebody to call and the button that calls can never name different numbers.
   */
  const reach = alertReach(a, EMERGENCY_NUMBER)
  // While arming, the choice for the next press; once there is an alert, the one it was sent with.
  const sentType = saved ? (isAlertType(saved.type) ? saved.type : null) : type

  const failure = failureOf(saved, outbox.all())
  const offline = saved && !a && (!online || (saved.why ?? 'no_signal') === 'no_signal') ? offlineLine(EMERGENCY_NUMBER) : null
  const held = saved && !a ? heldLine(saved.why, online) : ''

  // The fidelity build draws the board's own moment — an alert sent — from the board's own sample.
  const phase: PanicModel['phase'] = isFidelity ? 'active' : sending ? 'sending' : !saved ? 'arm' : closedAs ? 'closed' : a ? 'active' : failure ? 'failed' : 'queued'

  /*
   * LEAVING IS THE "DONE" THE BOARD DOES NOT DRAW (M42 §5 #5 took away "Done" and "Hold to send a new alert"). An alert
   * that is over for certain — closed, refused, or never kept — is put away when the screen goes, so the next visit
   * opens ready to send. Not one whose fate is "not known": that is also what a queue not yet read back from disk looks
   * like for a moment at launch, so it stays, and says so, until a new alert replaces it. A live one (held, or open at
   * the control room) is never touched: leaving does not cancel anything.
   */
  const over = phase === 'closed' || (phase === 'failed' && failure !== NOT_KNOWN)
  const putAway = useRef(over)
  useEffect(() => {
    putAway.current = over
  })
  useEffect(
    () => () => {
      if (!isFidelity && putAway.current) box().remove()
    },
    [],
  )

  const board: PanicBoard = isFidelity
    ? panicFixture
    : panicBoard({
        phase,
        label: alertLabel(sentType, a?.kind),
        alert: a,
        pressedAt: saved?.pressedAt ?? null,
        withPosition: saved?.withPosition ?? false,
        reach,
        held: offline ?? held,
        noSignal: !online || (saved?.why ?? 'no_signal') === 'no_signal',
        failure,
        demoRefusal: refusal,
        drillMode: drillModeLine(drillMode, now().getTime()),
        holdMs: HOLD_MS,
        emergencyNumber: EMERGENCY_NUMBER,
        at: boardTime,
        atSeconds: (iso) => time12s(iso),
      })

  return {
    phase,
    board,
    heldLine: held,
    failure,
    // An alert from another day says which day (B117): "Pressed 3:12 PM" on a days-old alert read as today's.
    sentLine: saved ? `${alertLabel(sentType, a?.kind)} · ${a ? 'Sent' : 'Pressed'} ${pressedWhen(saved.pressedAt)}` : '',
    headline: a ? responseHeadline(a) : null,
    drill: isDrill(a?.is_drill),
    steps: a && saved ? alertSteps(a, withClockTag(time12s(a.server_time)), secondsToAck, reach.reachedNobody, (iso) => withClockTag(time12s(iso))) : [],
    reach,
    reassurance: panicReassurance(a, reach, contacts.length, EMERGENCY_NUMBER),
    help: a && saved
      ? helpScreen(a, reach, {
          unit: household?.unit?.reference ?? principal?.reference ?? null,
          sentAt: pressedWhen(a.server_time),
          withPosition: saved.withPosition,
          when: pressedWhen,
          textable: contacts.length,
          emergencyNumber: EMERGENCY_NUMBER,
        })
      : null,
    heldSubline: saved ? [household?.unit?.reference ?? principal?.reference ?? null, `pressed ${pressedWhen(saved.pressedAt)}`].filter(Boolean).join(' · ') : '',
    offline,
    withPosition: saved?.withPosition ?? false,
    position: saved?.withPosition ? saved.position ?? null : null,
    unitLabel: household?.unit?.reference ?? null,
    elapsedMs,
    queuedWhy,
    demoRefusal: refusal,
    canCancel: cancelSecondsLeft > 0,
    cancelSecondsLeft,
    cancelBusy,
    cancelError,
    closedAs,
    contacts,
    // The ONE decision about the household's contacts (householdContacts.ts): today's line until the server reports texting them.
    contactsLine: householdContactsLine(a, contacts.length),
    type: sentType,
    // Only while arming: once pressed, the alert went with what it went with.
    chooseType: (tapped) => {
      if (!saved && !sending) setType((current) => toggleType(current, tapped))
    },
    online,
    fire: () => void fire(),
    cancel: (reason) => void cancel(reason),
    // INVARIANT 13: no state, no flag and no failure stands between this button and the dialler.
    callEmergency: () => void Linking.openURL(`tel:${EMERGENCY_NUMBER}`).catch(() => undefined),
    callAmbulanceFire: () => void Linking.openURL(`tel:${AMBULANCE_FIRE_NUMBER}`).catch(() => undefined),
    textContacts,
    callContact,
    finish: () => {
      remember(null)
      setType(null)
      onDone()
    },
    channel: {
      items,
      canWrite: phase === 'active' || phase === 'queued',
      placeholder: phase === 'closed' ? 'This alert is closed' : 'Message dispatch…', // framing-ok: the client's board copy
      send: queueMessage,
      retry: retryMessage,
      problem: messageProblem,
    },
    audio: {
      // The design build draws the board's own switch; a phone draws what is true.
      on: isFidelity ? board.audioOn : audioState === 'on' || audioState === 'starting',
      state: audioState,
      toggle: toggleAudio,
      problem: audioProblem,
      clearProblem: () => setAudioProblem(null),
    },
  }
}
