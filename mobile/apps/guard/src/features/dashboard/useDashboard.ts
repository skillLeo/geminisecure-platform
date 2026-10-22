/**
 * Everything the Home tab decides, in one place — boards guard-app-03 (on post, synced)
 * and guard-app-33 (the same dashboard with no signal and actions queued). ONE screen:
 * the offline state is `state.network.online` + `state.outbox.waiting`, nothing else.
 *
 *   shifts.me.current   the shift the guard is on, or the next one rostered (cached for offline, with its age)
 *   open checks         pending alertness checks → NEXT CHECK (the earliest `respond_by` still ahead), read
 *                       from the alertness watcher's pull — the app's one "what changed?" call, every 20 s.
 *                       The server sends every open check on EVERY pull, whatever `since` says, so that list
 *                       is complete. This screen used to send a pull of its own as well: a second request
 *                       on every mount, and again every minute, for seven days of shifts, orders, messages
 *                       and revoked passes it never read (M11 A4).
 *
 * What the API does not give, and what is done instead:
 *   "Static Post" / "Patrol"   derived from the post's name (contains "patrol" → Patrol, else Static Post).
 *   a clock-in made offline    the server has not heard of it yet, so the cached shift still says `rostered`.
 *                              The outbox has — a waiting `shifts.clock_in` puts the card on post from the
 *                              time it was captured, marked "pending sync", so nobody clocks in twice.
 *
 * SAFETY NEVER DEPENDS ON CONNECTIVITY: the quick actions and the duress button are
 * drawn in every state of this screen — loading, error, offline, no shift.
 */

import { useCallback, useEffect, useState, useSyncExternalStore } from 'react'
import { duration, fidelityScreenId, isFidelity, now, shiftName, time12, timeCompact, useOfflineCache } from '@gs/core'
import { isApiError, type Api } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { pullChecks } from '@/features/alertness/AlertnessWatcher'
import { openChecks } from '@/features/alertness/state'
import { dashboardFixtures } from '@/fidelity/dashboard'
import { gateKnowledge } from '@/features/gate/knowledge'
import { scheduleWarm } from '@/offline/warmCaches'
import { useAppSelector } from '@/store'

export type DashboardShift = NonNullable<Api.ShiftsMeCurrentResponse['shift']>
type PendingCheck = Api.SyncPullResponse['alertness_checks'][number]

export type SyncBadge = {
  tone: 'navy-500' | 'amber-500' | 'red-500'
  icon: 'check' | 'sync-badge' | 'qa-tile-3' | null
  label: string
  /** Read out with the label: what pressing the badge opens. */
  hint: string
}

export type ShiftCard = {
  title: string
  site: string
  badge: { label: string; tone: 'solid' | 'soft' | 'muted' }
  cells: [Cell, Cell, Cell]
  /** An honest line under the times: a clock-in still waiting to sync, or how old the cached shift is. */
  note: string | null
  /** on duty → the card opens clock-out; rostered → it carries a Clock in button; otherwise neither. */
  action: 'clock-out' | 'clock-in' | null
}
type Cell = { value: string; label: string }

export type Colleague = { key: string; name: string; post: string; initials: string }

export type DashboardModel = {
  status: 'loading' | 'error' | 'denied' | 'ready'
  errorMessage: string | null
  name: string
  initials: string
  subline: string
  sync: SyncBadge
  online: boolean
  offlineTitle: string
  card: ShiftCard | null
  colleagues: Colleague[]
  /** Board 33 draws no roster card while offline; an empty one from a cache would only be a guess. */
  showColleagues: boolean
  orders: { title: string; body: string } | null
  quickActionsTitle: string
  refreshing: boolean
  refresh: () => void
}

export function initialsOf(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean)
  const first = words[0]?.[0] ?? ''
  const last = words.length > 1 ? (words[words.length - 1]?.[0] ?? '') : ''
  return `${first}${last}`.toUpperCase()
}

/** Not in the API (recorded as a decision): the boards say "Main Gate — Static Post". */
const postKind = (postName: string) => (/patrol/i.test(postName) ? 'Patrol' : 'Static Post')

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`

export function useDashboard(): DashboardModel {
  const fixture = isFidelity ? (dashboardFixtures[fidelityScreenId() ?? ''] ?? null) : null
  const principal = useAppSelector((s) => s.session.principal)
  const storeOnline = useAppSelector((s) => s.network.online)
  const outbox = useAppSelector((s) => s.outbox)
  const online = fixture ? fixture.online : storeOnline
  const waiting = fixture ? fixture.waiting : outbox.waiting
  const refused = fixture ? 0 : outbox.refused
  const syncing = !fixture && outbox.ops.some((o) => o.state === 'syncing')

  const current = guardApi.useShiftsMeCurrentQuery(undefined, { skip: !!fixture, pollingInterval: 60_000 })
  const cached = useOfflineCache('shift.current', current.data)
  const cachedAt = useOfflineCache('shift.current.at', current.data === undefined ? undefined : current.fulfilledTimeStamp)
  // Nothing is known until the watcher has pulled once this run: until then, the copy on disk.
  const open = useSyncExternalStore(openChecks.subscribe, openChecks.get, openChecks.get)
  const checks = useOfflineCache<PendingCheck[]>('dashboard.alertness_checks', fixture || open.pulledAt === 0 ? undefined : open.checks)

  // REMAINING moves once a minute. `now()` is frozen at the board's moment in fidelity mode, so nothing ticks there.
  const [, setTick] = useState(0)
  useEffect(() => {
    if (isFidelity) return
    const timer = setInterval(() => setTick((n) => n + 1), 60_000)
    return () => clearInterval(timer)
  }, [])
  const nowMs = now().getTime()

  const shift: DashboardShift | null = fixture ? fixture.shift : (cached?.shift ?? null)

  // A shift at a new site: its tour and standing orders are saved now, and the site's pass keys and cancelled list,
  // before the officer walks into a dead spot (M11 §1.1; offline/warmCaches.ts, gate/knowledge.ts).
  const siteId = shift?.site.id
  useEffect(() => {
    if (fixture || siteId === undefined) return
    scheduleWarm()
    void gateKnowledge.refresh(siteId)
  }, [fixture, siteId])

  // A clock-in captured on this phone that the server has not confirmed yet.
  const pendingClockIn = fixture ? undefined : outbox.ops.find((o) => o.endpoint === 'shifts.clock_in' && (o.state === 'pending' || o.state === 'syncing'))
  // …and a clock-out likewise. The saved shift still says `on_duty` until it syncs; the card said "On post" over a
  // shift the officer had just ended with no signal (the M11 §1.7 airplane test, 26 Sep).
  const pendingClockOut = fixture ? undefined : outbox.ops.find((o) => o.endpoint === 'shifts.clock_out' && (o.state === 'pending' || o.state === 'syncing'))

  const nextCheckAt = fixture
    ? fixture.nextCheckAt
    : ((checks ?? [])
        .map((check) => check.respond_by)
        .filter((at) => new Date(at).getTime() > nowMs)
        .sort((x, y) => new Date(x).getTime() - new Date(y).getTime())[0] ?? null)

  const stale = !fixture && !!shift && (!online || !!current.error) && typeof cachedAt === 'number'
  const staleNote = stale ? `Shift details last updated ${time12(new Date(cachedAt))}` : null

  let card: ShiftCard | null = null
  if (shift) {
    const base = { title: `${shift.post.name}: ${postKind(shift.post.name)}`, site: shift.site.name }
    // The server marks a shift `missed` once its start has passed with nobody on it — but it still accepts
    // the clock-in (first clock-in wins, whenever it comes). A guard who is LATE is not a guard with no shift:
    // until the rostered end, a missed shift is still theirs to start. Found on the client's phone, 2026-09-19.
    const late = shift.status === 'missed' && new Date(shift.rostered_end).getTime() > nowMs
    const upcoming = shift.status === 'rostered' || shift.status === 'open' || late
    if (pendingClockOut && (shift.status === 'on_duty' || pendingClockIn)) {
      // Ended on this phone, not yet on the server: said as exactly that, and nothing to press — it sends by itself.
      const startedAt = shift.actual_start ?? pendingClockIn?.capturedAt ?? null
      card = {
        ...base,
        badge: { label: 'Clocked out', tone: 'muted' },
        cells: [
          { value: startedAt ? timeCompact(startedAt) : '—', label: 'CLOCKED IN' },
          { value: timeCompact(pendingClockOut.capturedAt), label: 'CLOCKED OUT' },
          { value: startedAt ? duration(new Date(pendingClockOut.capturedAt).getTime() - new Date(startedAt).getTime()) : '—', label: 'ON POST' },
        ],
        note: 'Clock-out saved on this phone: pending sync',
        action: null,
      }
    } else if (shift.status === 'on_duty' || (upcoming && pendingClockIn)) {
      const startedAt = shift.actual_start ?? pendingClockIn?.capturedAt ?? null
      card = {
        ...base,
        badge: shift.on_break ? { label: 'On break', tone: 'soft' } : { label: 'On post', tone: 'solid' },
        cells: [
          { value: startedAt ? timeCompact(startedAt) : '—', label: 'CLOCKED IN' },
          { value: duration(new Date(shift.rostered_end).getTime() - nowMs), label: 'REMAINING' },
          { value: nextCheckAt ? timeCompact(nextCheckAt) : '—', label: 'NEXT CHECK' },
        ],
        note: shift.status !== 'on_duty' ? 'Clock-in saved on this phone: pending sync' : staleNote,
        /*
         * On duty, or started on this phone and not yet synced: either way the card opens clock-out (B129). It used
         * to wait for the SERVER's on-duty, so a guard who clocked in with no signal could not reach clock-out at
         * all, although Help says clock-out works offline — the shift was left open, auto-closed and flagged.
         * useClockOut queues the clock-out after the waiting clock-in, so the two reach the server in order.
         */
        action: 'clock-out',
      }
    } else if (upcoming) {
      card = {
        ...base,
        badge: { label: late ? 'Late, not clocked in' : 'Not clocked in', tone: 'muted' },
        cells: [
          // WITH THE MERIDIEM (M18 §5): a twelve-hour watch read "12:00 STARTS · 12:00 ENDS", which says nothing.
          { value: time12(shift.rostered_start), label: 'STARTS' },
          { value: time12(shift.rostered_end), label: 'ENDS' },
          { value: duration(new Date(shift.rostered_end).getTime() - new Date(shift.rostered_start).getTime()), label: 'LENGTH' },
        ],
        note: staleNote,
        action: 'clock-in',
      }
    }
    // `completed` / `missed`: nothing is running and nothing is ahead — the empty state says so.
  }

  const sync: SyncBadge = !online
    ? { tone: 'amber-500', icon: 'sync-badge', label: 'Offline', hint: 'Opens the sync queue' }
    : waiting > 0
      ? { tone: 'amber-500', icon: null, label: syncing ? 'Syncing' : `${waiting} queued`, hint: 'Opens the sync queue' }
      : refused > 0
        ? { tone: 'red-500', icon: 'qa-tile-3', label: `${refused} not accepted`, hint: 'Opens the sync queue' }
        : { tone: 'navy-500', icon: 'check', label: 'Synced', hint: 'Opens the sync queue' }

  const [refreshing, setRefreshing] = useState(false)
  const refetchCurrent = current.refetch
  const refresh = useCallback(() => {
    if (fixture) return
    setRefreshing(true)
    void Promise.allSettled([refetchCurrent(), pullChecks()]).finally(() => setRefreshing(false))
  }, [fixture, refetchCurrent])

  const hasAnswer = !!fixture || cached !== undefined
  const denied = isApiError(current.error) && current.error.status === 403
  const status: DashboardModel['status'] = hasAnswer ? 'ready' : denied ? 'denied' : current.error ? 'error' : 'loading'

  const name = fixture ? fixture.name : (principal?.name ?? '')
  const colleagues = (shift?.colleagues ?? []).map((who, i) => ({ key: `${i}:${who.name}:${who.post}`, name: who.name, post: who.post, initials: initialsOf(who.name) }))
  const toAcknowledge = shift?.orders_to_acknowledge ?? 0

  return {
    status,
    errorMessage: isApiError(current.error) ? current.error.message : null,
    name,
    initials: initialsOf(name),
    subline: shift ? `${shift.post.name} · ${shiftName(shift.rostered_start)}` : (principal?.siteName ?? ''),
    sync,
    online,
    offlineTitle: waiting > 0 ? `No connection · ${plural(waiting, 'action', 'actions')} queued` : 'No connection · nothing queued',
    card,
    colleagues,
    showColleagues: !!card && (colleagues.length > 0 || online),
    orders:
      shift && toAcknowledge > 0
        ? { title: `New standing orders: ${shift.post.name}`, body: card?.action === 'clock-in' ? 'Acknowledge before you go on post' : 'Acknowledge before you can stay on post' }
        : null,
    quickActionsTitle: online ? 'Quick actions' : 'Quick actions: all work offline',
    refreshing,
    refresh,
  }
}
