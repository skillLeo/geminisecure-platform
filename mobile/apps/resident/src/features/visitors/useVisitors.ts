/**
 * The resident's visitor passes — boards resident-app-08 (the list) and -07 (invite), plus the
 * pass itself (its QR, sharing, cancelling) which no board draws.
 *
 *   LIST      `visitor_passes.index`, cached: the QR codes render with no connection.
 *   INVITE    `visitor_passes.store` is ONLINE ONLY — a pass is signed by the server. With no
 *             connection the invitation is kept as a DRAFT, shown as a draft, and says plainly that
 *             the guest cannot enter on it; it is sent by itself once there is signal (draftStore.ts),
 *             and "Send now" sends it at once.
 *   SHARE     online: the server counts the share and writes the message. Offline: the phone shares
 *             the pass it already holds and the count is sent later (the contract's own rule). What goes is
 *             the pass AS A TICKET-LIKE PICTURE (M12 §2.8 — estate, lot, visitor, day, window, the QR and the
 *             code; PassTicket.tsx) with the message as its caption, or the message alone when the resident
 *             asks for that (an SMS) or the phone cannot make or share a picture.
 *   CANCEL    asked first in a ConfirmSheet on every platform; works offline (queued) — and says the gate only
 *             learns of it when both are online. `cancel()` says what became of it: done, saved or refused.
 *
 * A restricted household's invitation is refused by the server (409). The app shows the gate's own
 * sentence and NOTHING more — a resident learns about arrears on the Dues screen, never from a
 * visitor form guessing at reasons.
 */

import { useCallback, useEffect, useMemo, useState, useSyncExternalStore } from 'react'
import { Platform, Share } from 'react-native'
import * as Clipboard from 'expo-clipboard'
import * as Haptics from 'expo-haptics'
import { isFidelity, newUuid, now, useOfflineCache } from '@gs/core'
import { isApiError, keepForSharing, safeFileName, type ApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { deviceTimeIso, useMinute } from '@gs/core/lib'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { inviteFixture } from '@/fidelity/visitors'
import { submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { draftOwner, draftsOf, readDrafts, sendDraft as sendOneDraft, subscribeDrafts, writeDrafts, type IssuePass } from './draftStore'
import { ARRIVALS, CATEGORIES, LAST_MINUTE, atSite, dayLabel, defaultWindow, epassNow, epassOff, footerLine, fromChips, groupPasses, onDay, passInvitation, siteDay, spansDays, staleLeadOf, toRequest, unitAndPhase, untilChips, validateInvite, windowLabel, type Category, type Draft, type Groups, type InviteErrors, type InviteInput, type Pass, type TimeChip } from './passes'
import { ticketOf, type Ticket } from './passTicket'
import { qrPng, toBase64 } from './qrPicture'
import { VISIT_PURPOSES } from './visitPurposes'

/* ---- drafts: invitations written with no connection (draftStore.ts) --------------------------- */

/** The signed-in person's own drafts, whoever else's the phone still holds after a sign-out nobody chose (draftStore.ts). */
const useOwner = (): string | null => draftOwner(useAppSelector((s) => s.session.principal?.id))

/**
 * Read on every render, so a screen still mounted when the store is wiped draws nothing of the last resident's —
 * and only this person's, so one kept for somebody else is never drawn.
 */
function useDrafts(owner: string | null): Draft[] {
  const all = useSyncExternalStore(subscribeDrafts, readDrafts, readDrafts)
  return useMemo(() => draftsOf(owner, all), [owner, all])
}

/* ---- the list ------------------------------------------------------------------------------------ */

export type VisitorListModel = {
  status: 'loading' | 'ready' | 'error' | 'denied'
  errorMessage: string | null
  /** Refused because of the person's access level (a Dependent or a Helper) — they have a home, and their own e-Pass. */
  levelRefused: boolean
  groups: Groups
  drafts: Draft[]
  online: boolean
  stale: boolean
  /** "No connection" or "This could not be refreshed just now" — why a saved copy is on screen (B123). */
  staleLead: string
  refreshing: boolean
  sendingDraft: string | null
  draftError: { id: string; message: string } | null
  refresh: () => void
  sendDraft: (id: string) => void
  discardDraft: (id: string) => void
}

export function useVisitorList(): VisitorListModel {
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()
  const query = residentApi.useVisitorPassesIndexQuery({}, { skip: isFidelity })
  const cached = useOfflineCache('passes.all', query.data)
  const owner = useOwner()
  const drafts = useDrafts(owner)
  const [issue] = residentApi.useVisitorPassesStoreMutation()
  const [refreshing, setRefreshing] = useState(false)
  const [sendingDraft, setSendingDraft] = useState<string | null>(null)
  const [draftError, setDraftError] = useState<{ id: string; message: string } | null>(null)

  const [minute, setMinute] = useState(0)
  useEffect(() => {
    const timer = setInterval(() => setMinute((n) => n + 1), 60_000)
    return () => clearInterval(timer)
  }, [])
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const groups = useMemo(() => groupPasses(cached?.items ?? [], now()), [cached, minute])

  const refetch = query.refetch
  const refresh = useCallback(() => {
    setRefreshing(true)
    void refetch().finally(() => setRefreshing(false))
  }, [refetch])

  // "Send now": the same send the runtime makes by itself (draftStore.ts) — the same key, so never a second pass.
  const sendDraft = useCallback(
    async (id: string) => {
      if (!draftsOf(owner).some((d) => d.id === id) || sendingDraft) return
      if (refusal) return setDraftError({ id, message: refusal })
      setSendingDraft(id)
      setDraftError(null)
      try {
        const outcome = await sendOneDraft(id, issue as unknown as IssuePass)
        if (outcome.kind === 'sent') void refetch()
        // A refusal is kept on the draft itself (its `problem`); anything else is said under it until the next try.
        else if (outcome.kind === 'waiting') setDraftError({ id, message: outcome.message })
      } finally {
        setSendingDraft(null)
      }
    },
    [issue, refetch, sendingDraft, refusal, owner],
  )

  const denied = isApiError(query.error) && query.error.status === 403
  return {
    status: cached ? 'ready' : denied ? 'denied' : query.error ? 'error' : 'loading',
    errorMessage: isApiError(query.error) ? query.error.message : null,
    levelRefused: denied && isApiError(query.error) && query.error.code === 'not_for_your_access_level',
    groups,
    drafts,
    online,
    stale: !!cached && (!online || !!query.error),
    staleLead: staleLeadOf(online),
    refreshing,
    sendingDraft,
    draftError,
    refresh,
    sendDraft: (id) => void sendDraft(id),
    discardDraft: (id) => writeDrafts(readDrafts().filter((d) => d.id !== id)),
  }
}

/* ---- invite -------------------------------------------------------------------------------------- */

export type InviteModel = {
  input: InviteInput
  set: <K extends keyof InviteInput>(key: K, value: InviteInput[K]) => void
  setCategory: (category: Category) => void
  /** A start time chosen: a visit's end moves with it when it would no longer be after it. */
  chooseStart: (minutes: number) => void
  /** The switch's four kinds of pass; the purpose list (visitPurposes.ts, data); the three ways of arriving. */
  categories: typeof CATEGORIES
  purposes: readonly string[]
  arrivals: typeof ARRIVALS
  /** A recurring or contractor pass: its day box chooses a first and a last day. */
  spansDays: boolean
  days: { iso: string; label: string }[]
  /** What the Arrival window's two boxes read, as the board writes them: "Today" · "2:00–4:00 PM". */
  dayText: string
  timeText: string
  /** The time box's two steps: "From" (Now first, on today) and "Until". */
  startTimes: TimeChip[]
  endTimes: TimeChip[]
  /** The board draws Visitor name focused — in fidelity mode, where the design's own state is drawn, and nowhere else. */
  nameLit: boolean
  /** The design's footer, with the visitor's first name (footerLine). */
  footer: string
  /** "Generate QR pass" — or, with no connection, what will really happen: "Save as a draft". */
  submitLabel: string
  errors: InviteErrors
  problem: string | null
  busy: boolean
  online: boolean
  submit: () => void
}

/** The board's own form (fidelity/visitors.ts): Marcia James, a family visit, 2:00 to 4:00 PM on the board's day. */
const designInput = (): Partial<InviteInput> => ({ category: inviteFixture.category, name: inviteFixture.name, phone: inviteFixture.phone, purpose: inviteFixture.purpose, arrival: inviteFixture.arrival, fromMinutes: inviteFixture.fromMinutes, toMinutes: inviteFixture.toMinutes })

export function useInvite(done: (result: { kind: 'issued'; id: string } | { kind: 'draft' }) => void, initial?: Partial<InviteInput>): InviteModel {
  // The board is drawn online: in fidelity mode the button reads as the design's.
  const online = useAppSelector((s) => s.network.online) || isFidelity
  const refusal = useDemoRefusal()
  const owner = useOwner()
  const [issue] = residentApi.useVisitorPassesStoreMutation()
  const today = siteDay(now()).iso
  // M12 §3.1: the form opens on NOW, for two hours — not on 2:00 PM whatever the time (passes.ts, defaultWindow).
  const [input, setInput] = useState<InviteInput>(() => ({ category: 'single', name: '', phone: '', purpose: '', arrival: null, plate: '', fromDay: today, toDay: today, ...defaultWindow(now()), ...(isFidelity ? designInput() : null), ...initial }))
  const [errors, setErrors] = useState<InviteErrors>({})
  const [problem, setProblem] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  // "Now" and the slots still to come follow the clock while the form is open.
  useMinute()

  const days = useMemo(() => Array.from({ length: 32 }, (_, i) => siteDay(now(), i)), [])

  const set = useCallback(<K extends keyof InviteInput>(key: K, value: InviteInput[K]) => {
    setErrors({})
    setProblem(null)
    setInput((current) => {
      const next = { ...current, [key]: value }
      // A short pass starts and ends on one day; moving its day moves both ends.
      if (key === 'fromDay' && !spansDays(next.category)) next.toDay = next.fromDay
      if (key === 'fromDay' && next.toDay < next.fromDay) next.toDay = next.fromDay
      // "Now" is not a slot on another day, and a slot already gone is not a start on today.
      return key === 'fromDay' ? onDay(next, now()) : next
    })
  }, [])

  const setCategory = useCallback((category: Category) => {
    setErrors({})
    setInput((current) => (!spansDays(category) ? onDay({ ...current, category, toDay: current.fromDay }, now()) : { ...current, category, fromMinutes: 6 * 60, toMinutes: 18 * 60, toDay: current.toDay === current.fromDay ? siteDay(new Date(atSite(current.fromDay, 720)), 7).iso : current.toDay }))
  }, [])

  const chooseStart = useCallback((minutes: number) => {
    setErrors({})
    setProblem(null)
    // Two hours on, to the half hour, never past the end of the day (passes.ts, defaultWindow) — a visit only.
    setInput((current) => ({ ...current, fromMinutes: minutes, toMinutes: !spansDays(current.category) && current.toMinutes <= minutes ? Math.min(Math.ceil((minutes + 120) / 30) * 30, LAST_MINUTE) : current.toMinutes }))
  }, [])

  const send = useCallback(async () => {
    if (isFidelity || busy) return
    const found = validateInvite(input, now())
    setErrors(found)
    setProblem(null)
    if (Object.keys(found).length > 0) return
    /*
     * The form was filled in and validated exactly as a resident's is — a reviewer has now seen
     * the whole feature — and only the issuing is refused, BEFORE the draft path below could
     * save it on the phone and promise to send it later.
     */
    if (refusal) return setProblem(refusal)
    const body = toRequest(input)

    const keepAsDraft = () => {
      writeDrafts([...readDrafts(), { id: newUuid(), category: input.category, visitor_name: body.visitor_name, visitor_phone: body.visitor_phone ?? '', purpose: body.purpose ?? '', vehicle_plate: body.vehicle_plate ?? '', no_vehicle: body.no_vehicle === true, ...(input.arrival ? { arrival: input.arrival } : null), valid_from: body.valid_from, valid_to: body.valid_to, createdAt: now().toISOString(), deviceTime: deviceTimeIso(), ...(owner ? { owner } : null) }])
      done({ kind: 'draft' })
    }

    setBusy(true)
    try {
      const answer = await issue(body)
      if ('error' in answer && answer.error) {
        const e = answer.error as ApiError
        if (e.status === 'network' || e.status === 'timeout') return keepAsDraft()
        if (e.status === 422 && Object.keys(e.fieldErrors).length > 0) {
          return setErrors({ name: e.fieldErrors.visitor_name?.[0], phone: e.fieldErrors.visitor_phone?.[0], purpose: e.fieldErrors.purpose?.[0], plate: e.fieldErrors.vehicle_plate?.[0], window: e.fieldErrors.valid_to?.[0] ?? e.fieldErrors.valid_from?.[0] })
        }
        // 409 access_restricted: the gate's own sentence, and nothing added to it.
        return setProblem(e.message)
      }
      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined)
      if (answer.data) done({ kind: 'issued', id: String(answer.data.id) })
    } finally {
      setBusy(false)
    }
  }, [busy, input, issue, done, refusal, owner])

  const at = now()
  const long = spansDays(input.category)
  return {
    input,
    set,
    setCategory,
    chooseStart,
    categories: CATEGORIES,
    purposes: VISIT_PURPOSES,
    arrivals: ARRIVALS,
    spansDays: long,
    days,
    dayText: dayLabel(input, at),
    timeText: windowLabel(input, at),
    startTimes: fromChips(input.fromDay, at),
    endTimes: untilChips(input.fromMinutes, long),
    nameLit: isFidelity,
    // The made-true line everywhere (M47 §7.5) — the board's own "by SMS and WhatsApp automatically" is never drawn.
    footer: footerLine(isFidelity ? inviteFixture.name : input.name),
    submitLabel: online ? 'Generate QR pass' : 'Save as a draft',
    errors,
    problem,
    busy,
    online,
    submit: () => void send(),
  }
}

/* ---- one pass: its QR, sharing it, cancelling it -------------------------------------------------- */

export type PassModel = {
  status: 'loading' | 'ready' | 'missing' | 'error'
  /** The e-Pass: the phone holds one, and the day of half hours it collected is over (epassNow). */
  ranOut: boolean
  /** A Helper's e-Pass the server turned off — paused, ended, or closed for the next day — in its words (8 Oct 2026). */
  off: string | null
  /** A Helper's days and hours, to show under their e-Pass; null for anybody else. */
  hours: string | null
  pass: Pass | null
  /** What the ticket picture says (M12 §2.8): estate, lot, visitor, day, window, code. */
  ticket: Ticket | null
  isEpass: boolean
  online: boolean
  stale: boolean
  /** "No connection" or "This could not be refreshed just now" — why a saved copy is on screen (B123). */
  staleLead: string
  busy: 'share' | 'cancel' | null
  note: string | null
  /**
   * `picture` (the default): the pass as a ticket-like PNG (M12 §2.8) with the invitation as its caption. `text`: the
   * invitation alone — for SMS. `ticket` draws the picture (PassTicket's `toPng`); without it, or when it cannot, the
   * QR code alone is sent as the picture.
   */
  share: (how?: ShareHow, ticket?: () => Promise<string | null>) => void
  /** Asked first in a ConfirmSheet (PassScreen); says what became of it, so the sheet closes only when it went. */
  cancel: () => Promise<PassCancel>
  refresh: () => void
}

/**
 * What became of a cancellation: `done` — the server cancelled it; `saved` — no connection, so it is on this phone
 * and the gate learns of it when both are online (the note on the pass says so); `refused` — in the server's words.
 */
export type PassCancel = { outcome: 'done' } | { outcome: 'saved' } | { outcome: 'refused'; message: string }

export type ShareHow = 'picture' | 'text'

type Sharing = { isAvailableAsync: () => Promise<boolean>; shareAsync: (url: string, options?: { mimeType?: string; dialogTitle?: string; UTI?: string }) => Promise<void> }

/** Lazily, and never on web — as the notice attachments do: a module missing from a build means "words only", not a crash. */
function sharing(): Sharing | null {
  if (Platform.OS === 'web') return null
  try {
    const mod = require('expo-sharing') as Partial<Sharing> | undefined
    return mod && typeof mod.shareAsync === 'function' && typeof mod.isAvailableAsync === 'function' ? (mod as Sharing) : null
  } catch {
    return null
  }
}

/**
 * The pass written to the phone as a PNG, for the share sheet — the TICKET (M12 §2.8) when the screen can draw it,
 * else the QR code alone — or null (web, no file module, a phone that could not write it). One picture at a time,
 * under the folder sign-out clears (core: keepForSharing).
 */
async function passPicture(pass: Pick<Pass, 'token' | 'code'>, ticket?: () => Promise<string | null>): Promise<string | null> {
  if (Platform.OS === 'web') return null
  // Let the button's spinner draw before the picture is worked out (a few hundred milliseconds on a phone).
  await new Promise((resolve) => setTimeout(resolve, 0))
  try {
    const drawn = ticket ? await ticket().catch(() => null) : null
    return await keepForSharing({ folder: 'pass-share', name: `visitor-pass-${safeFileName(pass.code)}.png`, base64: drawn ?? toBase64(qrPng(pass.token)) })
  } catch {
    return null
  }
}

type CaptionShare = { open: (options: { url: string; type?: string; message?: string; title?: string; filename?: string; failOnCancel?: boolean }) => Promise<unknown> }

/**
 * A share sheet that takes a picture AND its caption on Android (react-native-share: the file as the stream, the words
 * as its text — WhatsApp shows them as the caption). Lazily, and never on web: a build without it means the old way
 * (the words copied to paste beside the picture), not a crash.
 */
function captionShare(): CaptionShare | null {
  if (Platform.OS !== 'android') return null
  try {
    const mod = require('react-native-share') as { default?: Partial<CaptionShare> } | undefined
    const share = mod?.default
    return share && typeof share.open === 'function' ? (share as CaptionShare) : null
  } catch {
    return null
  }
}

export function usePass(id: string | undefined): PassModel {
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()
  const estate = useAppSelector((s) => s.session.principal?.siteName ?? 'your estate')
  const lot = useAppSelector((s) => s.session.principal?.reference ?? null)
  // "Sent by Hassam Ahmed" — so the visitor knows who invited them (M18F §4).
  const host = useAppSelector((s) => s.session.principal?.name ?? '')
  const isEpass = id === 'e-pass'
  const list = residentApi.useVisitorPassesIndexQuery({}, { skip: isFidelity || isEpass })
  const cachedList = useOfflineCache('passes.all', list.data)
  const epass = residentApi.useEpassShowQuery(undefined, { skip: isFidelity || !isEpass })
  const cachedEpass = useOfflineCache('passes.epass', epass.data)
  const [shareCount] = residentApi.useVisitorPassesShareMutation()
  const [cancelPass] = residentApi.useVisitorPassesCancelMutation()
  const [busy, setBusy] = useState<PassModel['busy']>(null)
  const [note, setNote] = useState<string | null>(null)
  const [override, setOverride] = useState<Pass | null>(null)
  // The e-pass's half hour moves on while the screen is open: the minute's tick picks the next one (M47 §6).
  useMinute()

  // A Helper's e-Pass the server has turned off (8 Oct 2026): its words, and never the copy kept for a gate with no signal.
  const off = isEpass ? epassOff(epass.error) : null
  // The e-pass is the half hour this moment is in, from the day of them the phone holds; a spent one is never shown.
  const current = isEpass && off === null ? epassNow(cachedEpass, now()) : null
  const pass: Pass | null = override ?? (current ? current.pass : (cachedList?.items.find((p) => String(p.id) === String(id)) ?? null))
  const source = isEpass ? epass : list

  const share = useCallback(async (how: ShareHow, ticket?: () => Promise<string | null>) => {
    if (!pass || busy) return
    setBusy('share')
    setNote(null)
    try {
      // M18F §4: the invitation — the caption, and the words-only version for a text message. The server's words when
      // it can be asked; the same words from the pass this phone holds when it cannot.
      const local = passInvitation(pass, { estate, ...unitAndPhase(lot), host })
      let message = local.message
      let sms = local.sms
      /*
       * SHARING IS NOT REFUSED IN A DEMONSTRATION, and this is the one place that is right.
       * What is written server-side is a COUNT of how often a pass was shared; the act the
       * resident asked for is the phone's own share sheet, from the pass this phone already
       * holds. Refusing it would hide a working feature from a reviewer and would say something
       * untrue — the pass really is shared. So the count is simply not sent, and nothing else
       * changes.
       */
      if (online && !refusal) {
        const answer = await shareCount({ pass: pass.id, channel: how === 'text' ? 'sms' : 'copy' })
        if ('data' in answer && answer.data?.message) {
          message = answer.data.message
          // An older server sends no `sms`: the phone's own words-only version stands.
          if (typeof (answer.data as { sms?: unknown }).sms === 'string') sms = (answer.data as { sms: string }).sms
        } else if ('error' in answer && answer.error && (answer.error as ApiError).code === 'pass_not_active') return setNote('This pass is no longer active, so it cannot be shared.')
      } else if (!refusal) {
        // The contract: share from what the phone holds, and send the count when there is signal.
        await submit({ endpoint: 'visitor_passes.share', params: { pass: pass.id }, body: { channel: 'copy' }, label: `Pass shared · ${pass.visitor_name}`, kind: 'pass' })
      }
      if (how === 'text') {
        await Share.share({ message: sms })
        return
      }

      // THE PASS ITSELF, as a ticket-like picture (R6; M12 §2.8): the visitor gets something a gate scanner reads,
      // with the estate, the lot, the day and the window on it — not only words about it.
      const picture = await passPicture(pass, ticket)
      if (!picture) {
        await Share.share({ message })
        return setNote('The pass picture could not be made on this phone, so the invitation went as words. The code in it still opens the gate: the guard types it.')
      }
      if (Platform.OS === 'ios') {
        // iOS hands the picture and the words to the app together.
        await Share.share({ message, url: picture })
        return
      }
      // Android: the picture with the words as its caption, in one share (WhatsApp shows them under the picture).
      const withCaption = captionShare()
      if (withCaption) {
        await withCaption.open({ url: picture, type: 'image/png', message, title: `Send ${pass.visitor_name}'s pass`, filename: `visitor-pass-${safeFileName(pass.code)}`, failOnCancel: false })
        return
      }
      // A build without it: Android's own share sheet takes a file OR words, not both — so the words go to the
      // clipboard first, to paste as the picture's caption, and the screen says so.
      const sheet = sharing()
      if (!sheet || !(await sheet.isAvailableAsync().catch(() => false))) {
        await Share.share({ message })
        return setNote('This phone could not share a picture, so the invitation went as words. The code in it still opens the gate: the guard types it.')
      }
      const copied = await Clipboard.setStringAsync(message).then(() => true, () => false)
      await sheet.shareAsync(picture, { mimeType: 'image/png', dialogTitle: `Send ${pass.visitor_name}'s pass` })
      if (copied) setNote('The invitation, the times and the gate code, is copied as well. If the picture went on its own, paste it beside it.')
    } catch {
      setNote('The share sheet could not be opened. You can read the code out instead.')
    } finally {
      setBusy(null)
    }
  }, [pass, busy, online, estate, lot, host, shareCount, refusal])

  const cancel = useCallback(async (): Promise<PassCancel> => {
    if (!pass) return { outcome: 'refused', message: 'This pass is not on this phone. Pull down to check again.' }
    if (busy) return { outcome: 'refused', message: 'Wait a moment: this pass is being shared.' }
    // Refused before either branch: the offline one would have saved it and said so.
    if (refusal) {
      setNote(refusal)
      return { outcome: 'refused', message: refusal }
    }
    setBusy('cancel')
    setNote(null)
    try {
      if (online) {
        const answer = await cancelPass({ pass: pass.id })
        if ('error' in answer && answer.error) {
          const message = (answer.error as ApiError).message
          setNote(message)
          return { outcome: 'refused', message }
        }
        if (answer.data) setOverride(answer.data as Pass)
        return { outcome: 'done' }
      }
      await submit({ endpoint: 'visitor_passes.cancel', params: { pass: pass.id }, label: `Pass cancelled · ${pass.visitor_name}`, kind: 'pass' })
      setOverride({ ...pass, status: 'cancelled', cancelled_at: now().toISOString() })
      setNote('Saved on this phone. The gate learns of the cancellation when you are both online, until then a guard with no signal may still admit on it.')
      return { outcome: 'saved' }
    } finally {
      setBusy(null)
    }
  }, [pass, busy, online, cancelPass, refusal])

  const ranOut = !pass && !!current?.ranOut
  // LOADING IS NOT "NOT FOUND" (M50 §6.4). A pass just issued is not in the list this phone saved; the list is being
  // asked for again as the screen opens (`isFetching`, with the old answer still held). Until that answer is in, the
  // screen draws the skeleton — "not on this phone" only once the server has answered without it.
  const asking = source.isLoading || source.isUninitialized || (!pass && source.isFetching)
  return {
    status: pass ? 'ready' : ranOut || off !== null ? 'missing' : asking ? 'loading' : source.error ? 'error' : 'missing',
    ranOut,
    off,
    // A Helper's days and hours, under their e-Pass ("Your e-Pass works Mon–Sat, 7:00 AM – 7:00 PM.").
    hours: isEpass && off === null ? (cachedEpass?.hours ?? null) : null,
    pass,
    ticket: pass && !isEpass ? ticketOf(pass, estate, lot) : null,
    isEpass,
    online,
    stale: !!pass && (!online || !!source.error),
    staleLead: staleLeadOf(online),
    busy,
    note,
    share: (how = 'picture', ticket) => void share(how, ticket),
    cancel,
    refresh: () => void source.refetch(),
  }
}
