/**
 * One meeting, for the Meeting Lobby (resident-app-13) and the Live Meeting screen (resident-app-23).
 *
 *   READ    `meetings.index`, cached (`useOfflineCache`): the contract says "Serve the cache", so a meeting
 *           opens with no connection. There is no `meetings.show`: a meeting is found BY ID in that list.
 *           The id `next` is Home's link — the next meeting still to come.
 *   WRITE   `meetings.rsvp` — the contract says "Queue", so a reply goes through `submit()`: captured on the
 *           phone first, then sent, and shown as Pending sync · Syncing · Confirmed · Not accepted. A reply
 *           is a plan, not attendance — quorum is counted from the register at the meeting — so no quorum
 *           figure is ever built from it. The server closes replies when the meeting starts; so does the app.
 *           The rebuilt lobby (board 13) draws no reply card — no board has one — so nothing calls it today.
 *   JOIN    The server has NO streaming, chat, hand-raise or live-vote endpoint — only `virtual_link`.
 *           The lobby's "Join meeting" opens the meeting's own screen, as it always has; that screen opens
 *           the link with the phone's browser or meeting app: only a plain https address, only from half an
 *           hour before the start, only while the meeting is still scheduled, and only with a connection. A
 *           meeting that will be RECORDED says so there, before the link is opened.
 *   MINUTES the live screen says when they are published (`minutesLine`); the rebuilt lobby draws no minutes.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { Linking } from 'react-native'
import { router } from 'expo-router'
import { fidelityScreenId, isFidelity, now, useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { mergeById, olderFor, type OlderPages } from '@gs/core/lib'
import { liveFixture, lobbyFixture } from '@/fidelity/meetings'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { unitAndPhase } from '@/features/visitors/passes'
import { drain, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import {
  RSVP_OPTIONS,
  SYNC_LABEL,
  agendaTime,
  attendingLine,
  findMeeting,
  heroWhen,
  joinStateOf,
  lockLine,
  minutesLine,
  recordingLine,
  roomNote,
  rsvpClosedLine,
  rsvpNote,
  rsvpOpen,
  rsvpStateOf,
  shouldQueueRsvp,
  staleLine,
  standing,
  statusShort,
  typeLabel,
  whenLine,
  type JoinState,
  type Meeting,
  type Rsvp,
  type RsvpOp,
  type RsvpState,
} from './meetings'

const DENIED = 'Your account cannot see the estate’s meetings. Ask your estate office to check your access.'
const OFFLINE_JOIN = 'You are offline. Joining the meeting needs a connection.'

/* ---- the list the phone holds ------------------------------------------------------------------- */

export type ListState = {
  status: 'loading' | 'ready' | 'error' | 'denied'
  errorMessage: string | null
  /** The first page AND whatever "All meetings" has fetched beyond it — so an old meeting opened from that list is found. */
  items: readonly Meeting[]
  /** The server's newest page alone, and where the page before it starts (null: nothing older, or a server that does not page). */
  first: readonly Meeting[]
  firstNext: string | null
  /** What has been fetched beyond the first page — only while it belongs to TODAY's first page (core: olderPages.ts). */
  older: OlderPages<Meeting> | null
  ops: readonly RsvpOp[]
  /** When the list on screen was ASKED of the server (this phone's clock), or null when it is the saved copy. */
  requestedAt: number | null
  online: boolean
  stale: boolean
  refreshing: boolean
  refresh: () => void
}

/** `fetchedOlder`: the chain "All meetings" has just extended — saved beside the first page, so it opens offline too. */
export function useMeetingList(skip: boolean, fetchedOlder?: OlderPages<Meeting>): ListState {
  const online = useAppSelector((s) => s.network.online)
  const view = useAppSelector((s) => s.outbox.ops)
  const query = residentApi.useMeetingsIndexQuery(undefined, { skip })
  // The SAME key Home saves the list under: a meeting tapped on Home with no connection opens from that copy.
  const cached = useOfflineCache('meetings.index', query.data)
  const savedOlder = useOfflineCache<OlderPages<Meeting>>('meetings.older', fetchedOlder)
  const [refreshing, setRefreshing] = useState(false)

  // When the request that produced the list on screen was SENT. Held while a refetch is in flight (its
  // start time is already the new request's, its data still the old one's). After an error the start is
  // the failed request's, so the earlier of the two stamps is taken.
  const [requested, setRequested] = useState<{ fulfilled: number; at: number } | null>(null)
  const { fulfilledTimeStamp: fulfilled, startedTimeStamp: started, isFetching } = query
  const hasData = query.data !== undefined
  useEffect(() => {
    if (!hasData || !fulfilled || isFetching) return
    setRequested((held) => (held?.fulfilled === fulfilled ? held : { fulfilled, at: Math.min(started ?? fulfilled, fulfilled) }))
  }, [hasData, fulfilled, started, isFetching])

  // The store mirrors the queue without bodies or answers; the queue itself has both.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const ops = useMemo<readonly RsvpOp[]>(() => (skip ? [] : outbox.all().filter((op) => op.kind === 'rsvp')), [view, skip])

  const refetch = query.refetch
  const refresh = useCallback(() => {
    if (skip) return
    setRefreshing(true)
    void drain()
    void refetch().finally(() => setRefreshing(false))
  }, [refetch, skip])

  // A 403 is the estate's answer about THIS account, and it outranks the copy on the phone: an account that
  // may no longer see the meetings is not shown a saved one — nor handed its link to open.
  const denied = isApiError(query.error) && query.error.status === 403
  // `?? null`: a first page saved by an older build has no `next_before` at all.
  const firstNext = denied ? null : (cached?.next_before ?? null)
  const first = useMemo<readonly Meeting[]>(() => (denied ? [] : (cached?.items ?? [])), [denied, cached])
  const older = useMemo(() => (denied ? null : olderFor(savedOlder, firstNext)), [denied, savedOlder, firstNext])
  const items = useMemo<readonly Meeting[]>(() => (older ? mergeById(first, older.items) : first), [first, older])
  return {
    status: denied ? 'denied' : cached ? 'ready' : query.error ? 'error' : 'loading',
    errorMessage: denied ? DENIED : isApiError(query.error) ? query.error.message : null,
    items,
    first,
    firstNext,
    older,
    ops,
    requestedAt: hasData && requested ? requested.at : null,
    online,
    stale: !denied && !!cached && (!online || !!query.error),
    refreshing,
    refresh,
  }
}

/** The screen's clock: a meeting's standing ("starts in 25 minutes", the link opening) moves with it. */
function useClock(): Date {
  const [at, setAt] = useState(now)
  useEffect(() => {
    if (isFidelity) return
    const timer = setInterval(() => setAt(now()), 20_000)
    return () => clearInterval(timer)
  }, [])
  return at
}

/* ---- one meeting: what both screens share -------------------------------------------------------- */

type Base = {
  status: 'loading' | 'ready' | 'missing' | 'error' | 'denied'
  errorMessage: string | null
  /** Asked for as `next` — so "missing" means "nothing is scheduled", not "no such meeting". */
  askedForNext: boolean
  meeting: Meeting | null
  at: Date
  list: ListState
}

function useMeeting(id: string | undefined, fixture: { meeting: Meeting } | null): Base {
  const list = useMeetingList(isFidelity)
  const at = useClock()
  const meeting = fixture ? fixture.meeting : findMeeting(id, list.items, at)
  return {
    status: meeting ? 'ready' : list.status === 'ready' ? 'missing' : list.status,
    errorMessage: list.errorMessage,
    askedForNext: id === 'next',
    meeting,
    at,
    list,
  }
}

const withOnline = (join: JoinState, online: boolean): JoinState => (join.canJoin && !online ? { canJoin: false, url: null, host: null, reason: OFFLINE_JOIN } : join)

/* ---- the lobby ------------------------------------------------------------------------------------ */

export type AgendaRow = { key: string; time: string | null; text: string }

export type RsvpModel = {
  open: boolean
  closedLine: string
  options: typeof RSVP_OPTIONS
  state: RsvpState
  /** Pending sync · Syncing · Confirmed · Not accepted — or null when the server's word stands alone. */
  syncLabel: string | null
  note: string | null
  attending: string | null
  problem: string | null
  busy: boolean
  choose: (value: Rsvp) => void
}

/** The quorum bar's figures: "142" in white, " of 450 households · need 25%" after it, the gold fill's width. */
export type Quorum = { count: string; rest: string; fill: `${number}%` }

export type LobbyModel = {
  status: Base['status']
  errorMessage: string | null
  askedForNext: boolean
  meeting: Meeting | null
  /** "ANNUAL GENERAL MEETING" */
  tag: string
  title: string
  /** "Saturday 27 September · 10:00 AM · Hosted in-app" — the day, the time, and where the server says it is held. */
  when: string
  /** "Verified as Lot 47 · Andrea Fletcher" — the lot and the name this phone is signed in with. */
  verified: string | null
  /**
   * DATA GAP: the server sends no quorum figure — quorum is counted from the register taken at the meeting, and an RSVP
   * is not attendance — so live this is null and the bar is drawn empty. The board's own figures in fidelity mode.
   */
  quorum: Quorum | null
  agenda: AgendaRow[]
  /** "Join meeting": open from half an hour before the start, while scheduled, with a connection — else the reason. */
  join: JoinState
  /** What "Join meeting" does, exactly as before the rebuild: the meeting's screen (/meetings/[id]/live). */
  openRoom: () => void
  /** The board's line under the button, with the estate's own name in it. */
  lockNote: string
  /**
   * The household's reply (`meetings.rsvp`, queued). NOT DRAWN: no board has a reply card, so the rebuilt lobby has
   * none (the user, 7 October: "no more, no less"). The wiring is kept here, whole, for the day one is drawn.
   */
  rsvp: RsvpModel | null
  online: boolean
  /** The meeting on screen is the phone's saved copy — so "not found" means "not saved here". */
  stale: boolean
  refreshing: boolean
  refresh: () => void
}

const NO_JOIN: JoinState = { canJoin: false, url: null, host: null, reason: 'This meeting could not be loaded.' }

export function useMeetingLobby(id: string | undefined): LobbyModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-13' ? lobbyFixture : null
  const base = useMeeting(id, fixture)
  const principal = useAppSelector((s) => s.session.principal)
  const refusal = useDemoRefusal()
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  const { meeting, at, list } = base
  const state = useMemo(() => (meeting ? rsvpStateOf(meeting, list.ops, list.requestedAt) : null), [meeting, list.ops, list.requestedAt])

  const choose = useCallback(
    (value: Rsvp) => {
      if (fixture || !meeting || !state || busy || !rsvpOpen(meeting, now()) || !shouldQueueRsvp(value, state)) return
      // Before `submit()`: a queued reply would have been saved on the phone and shown as sent.
      if (refusal) return setProblem(refusal)
      setBusy(true)
      setProblem(null)
      const label = RSVP_OPTIONS.find((o) => o.value === value)?.label ?? value
      // Captured on the phone FIRST (with its key and the time it happened), then sent — online or not.
      submit({ endpoint: 'meetings.rsvp', params: { meeting: meeting.id }, body: { response: value }, label: `Meeting reply · ${label}`, kind: 'rsvp' })
        .catch(() => setProblem('Your reply could not be saved on this phone. Nothing was sent: please try again.'))
        .finally(() => setBusy(false))
    },
    [fixture, meeting, state, busy, refusal],
  )

  const agenda = useMemo<AgendaRow[]>(() => (meeting ? meeting.agenda.map((item, i) => ({ key: `${i}`, time: agendaTime(item.start_time), text: item.text })) : []), [meeting])
  // "Lot 47 · Andrea Fletcher": the lot alone — the session's reference is "Phase 2 · Lot 47" — and the name.
  const who = [unitAndPhase(principal?.reference).unit, principal?.name].map((part) => part?.trim()).filter(Boolean).join(' · ')

  // "Join meeting" — exactly what it did before the rebuild: the meeting's own screen. The board's button is a picture.
  const meetingId = meeting ? String(meeting.id) : null
  const openRoom = useCallback(() => {
    if (fixture || meetingId === null) return
    router.push(`/meetings/${meetingId}/live` as never)
  }, [fixture, meetingId])

  return {
    status: base.status,
    errorMessage: base.errorMessage,
    askedForNext: base.askedForNext,
    meeting,
    tag: meeting ? typeLabel(meeting.type) : '',
    title: meeting?.title ?? '',
    // The board's own words in fidelity: its 27 September 2026 is a Sunday, which the real date code would say.
    when: fixture ? fixture.board.when : meeting ? heroWhen(meeting, at) : '',
    verified: fixture ? fixture.board.verified : who ? `Verified as ${who}` : null,
    quorum: fixture ? fixture.board.quorum : null,
    agenda,
    // The board draws the room open; its meeting is three weeks away. Fidelity only.
    join: fixture ? { canJoin: true, url: '', host: '', reason: null } : meeting ? withOnline(joinStateOf(meeting, at), list.online) : NO_JOIN,
    openRoom,
    lockNote: fixture ? fixture.board.lockNote : lockLine(principal?.siteName),
    rsvp:
      fixture || !meeting || !state
        ? null
        : {
            open: rsvpOpen(meeting, at),
            closedLine: rsvpClosedLine(meeting),
            options: RSVP_OPTIONS,
            state,
            syncLabel: state.sync ? SYNC_LABEL[state.sync] : null,
            note: rsvpNote(state),
            attending: state.sync === 'confirmed' ? attendingLine(state.attending) : null,
            problem,
            busy,
            choose,
          },
    online: list.online,
    stale: !fixture && list.stale,
    refreshing: list.refreshing,
    refresh: list.refresh,
  }
}

/* ---- the live screen ------------------------------------------------------------------------------ */

export type LiveModel = {
  status: Base['status']
  errorMessage: string | null
  askedForNext: boolean
  meeting: Meeting | null
  /** The lobby this screen closes back to. */
  lobbyRoute: string
  title: string
  sub: string
  stateLabel: string
  stateLine: string
  /** Held or cancelled: there is no Join button to draw at all. */
  ended: boolean
  where: string
  /** What "joining" really is for this meeting — the panel where the board draws video tiles. */
  room: { title: string; body: string } | null
  join: JoinState
  recording: string | null
  minutes: string | null
  /** The phone could not open the link. */
  problem: string | null
  opening: boolean
  /** Opens the estate's link in the browser or meeting app. Nothing else on this screen leaves the app. */
  openLink: () => void
  online: boolean
  stale: boolean
  /** Why this is the saved copy (no connection · the estate could not be reached) — null when it is fresh. */
  staleNote: string | null
  refreshing: boolean
  refresh: () => void
  /** Board-only parts, in fidelity mode only. */
  board: typeof liveFixture.board | null
}

export function useLiveMeeting(id: string | undefined): LiveModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-23' ? liveFixture : null
  const base = useMeeting(id, fixture)
  const [problem, setProblem] = useState<string | null>(null)
  const [opening, setOpening] = useState(false)

  const { meeting, at, list } = base
  const join = meeting ? withOnline(joinStateOf(meeting, at), list.online) : NO_JOIN
  const phase = meeting ? standing(meeting, at, join.canJoin) : null

  const url = join.canJoin ? join.url : null
  const openLink = useCallback(() => {
    if (fixture || !url || opening) return
    setOpening(true)
    setProblem(null)
    Linking.openURL(url)
      .catch(() => setProblem('Your phone could not open the meeting link. Check that you have a web browser or the meeting app installed, then try again.'))
      .finally(() => setOpening(false))
  }, [fixture, url, opening])

  return {
    status: base.status,
    errorMessage: base.errorMessage,
    askedForNext: base.askedForNext,
    meeting,
    lobbyRoute: `/meetings/${meeting ? String(meeting.id) : (id ?? 'next')}`,
    title: fixture ? fixture.board.title : (meeting?.title ?? 'Meeting'),
    sub: fixture ? fixture.board.sub : meeting ? statusShort(meeting, at) : '',
    stateLabel: phase?.label ?? '',
    stateLine: phase?.line ?? '',
    ended: phase?.phase === 'held' || phase?.phase === 'cancelled',
    where: meeting ? whenLine(meeting, at) : '',
    room: meeting && !fixture ? roomNote(meeting) : null,
    join,
    recording: meeting ? recordingLine(meeting) : null,
    minutes: meeting ? minutesLine(meeting) : null,
    problem,
    opening,
    openLink,
    online: list.online,
    stale: !fixture && list.stale,
    staleNote: !fixture && list.stale ? staleLine(list.online, 'The time or the link') : null,
    refreshing: list.refreshing,
    refresh: list.refresh,
    board: fixture ? fixture.board : null,
  }
}
