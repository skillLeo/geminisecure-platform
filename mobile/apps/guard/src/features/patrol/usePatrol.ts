/**
 * Everything the Patrol tab decides, in one place — board guard-app-07.
 *
 *   shifts.me.current   the site whose checkpoints these are (cached as 'shift.current' — never typed)
 *   patrol.checkpoints  the tour, in order, with this guard's scans today (cached: the tour is walked with no signal)
 *   patrol.scan         `{ code, captured_offline }` against the checkpoint the guard is at — through submit(),
 *                       so it is on the phone first, with the time it happened, and sent when it can be
 *
 * WHAT THE BOARD DRAWS THAT THE SERVER DOES NOT HAVE (each is the honest version here; patrolLogic.ts has the detail):
 *   "Randomised" badge, "Elapsed 11:32"    no such fields → no badge; "Last scan 11:32" (the server's own time)
 *   "Checkpoint 5 · 6m away", "within 15m" the tab never asks where the guard is → no distance. Once the office has
 *                                          placed checkpoints (M11 §6) the radar becomes the tour's map — the pins and
 *                                          this guard's post's geofence; until then it stays the board's illustration.
 *                                          Either way the label names the next checkpoint only
 *   missed / overdue checkpoints           the response has no such key → nothing is derived, no banner (the control
 *                                          room's patrol monitor raises a missed checkpoint, not the phone)
 *   a scan without a code                  `code` is required → there is no manual fallback for a damaged tag
 *   latitude / longitude on a scan         sent when on-duty sharing already holds a recent fix (dutyTracker.sharedFix,
 *                                          M21 §1.1) — no new reading, no wait, and location is never asked for here.
 *                                          The server judges it against the checkpoint's circle; with none, the scan
 *                                          is recorded "no position". recordMoment still takes the moment's own fix
 *
 * The phone cannot tell whose tag it read (the list carries no codes): the scan is sent against the
 * checkpoint the guard chose — the next on the tour, or the row they pressed — and the SERVER answers.
 * Its refusal (`code_mismatch`, `not_found`) arrives on the act as "Not accepted", in its own sentence.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import * as Haptics from 'expo-haptics'
import { fidelityScreenId, isFidelity, now, time12, timeCompact, useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { patrolFixtures } from '@/fidelity/patrol'
import { recordMoment, sharedFix } from '@/features/tracking/dutyTracker'
import { drain, drainNow, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { NEXT_DETAIL, PATIENCE_MS, SCAN_FIX_WAIT_MS, buildTour, dayKey, isSending, judgeTag, panelFor, scanBody, toCheckpoints, tourMap, unplacedNote, type Checkpoint, type CheckpointId, type Panel, type PatrolRow, type ScanOp, type ScanOutcome, type TourMap } from './patrolLogic'

export type PatrolFixture = {
  checkpoints: Checkpoint[]
  /** The board's own words where the API has none (see the header). */
  copy: { title: string; badge: string | null; metaRight: string; radarLabel: string; nextDetail: string }
}

export type ScannerModel = {
  targetLabel: string
  targetPosition: number
  total: number
  /** null while the camera is looking for a tag. */
  panel: Panel | null
  /** After a scan that stands: the checkpoint the tour goes to next. */
  nextLabel: string | null
}

export type PatrolModel = {
  status: 'loading' | 'error' | 'denied' | 'no_shift' | 'empty' | 'ready'
  errorMessage: string | null
  online: boolean
  title: string
  badge: string | null
  countLabel: string
  metaRight: string
  percent: number
  radarLabel: string
  /** The tour's map once any checkpoint is placed; null keeps the board's illustration. */
  map: TourMap | null
  /** Said under the map while some checkpoints are not on it. */
  mapNote: string | null
  rows: PatrolRow[]
  /** How old the cached list is — shown only when it could not be refreshed. */
  staleNote: string | null
  refusedCount: number
  refreshing: boolean
  refresh: () => void
  scanner: ScannerModel | null
  openScanner: (id?: CheckpointId) => void
  closeScanner: () => void
  onTag: (raw: string) => void
  scanAgain: () => void
  scanNext: () => void
}

const sameId = (x: CheckpointId, y: CheckpointId) => String(x) === String(y)

export function usePatrol(): PatrolModel {
  const fixture = isFidelity ? (patrolFixtures[fidelityScreenId() ?? ''] ?? null) : null
  const storeOnline = useAppSelector((s) => s.network.online)
  const views = useAppSelector((s) => s.outbox.ops)
  const online = fixture ? true : storeOnline

  const current = guardApi.useShiftsMeCurrentQuery(undefined, { skip: !!fixture })
  const cachedShift = useOfflineCache('shift.current', current.data)
  const shift = cachedShift?.shift ?? null
  const siteId = shift?.site.id ?? null

  const list = guardApi.usePatrolCheckpointsQuery({ site: siteId ?? '' }, { skip: !!fixture || siteId === null })
  const cachedList = useOfflineCache('patrol.checkpoints', list.data)
  const cachedAt = useOfflineCache('patrol.checkpoints.at', list.data === undefined ? undefined : list.fulfilledTimeStamp)
  // A list cached at another estate is not this shift's tour.
  const mine = cachedList && siteId !== null && String(cachedList.site_id) === String(siteId) ? cachedList : undefined

  const checkpoints = useMemo(() => (fixture ? fixture.checkpoints : toCheckpoints(mine)), [fixture, mine])

  // The store mirrors the outbox without params or body; the acts themselves are read whenever the mirror changes.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const ops = useMemo<ScanOp[]>(() => (fixture ? [] : outbox.all().filter((op) => op.endpoint === 'patrol.scan')), [fixture, views])

  // The server's tour is a DAY's tour and a night patrol walks through midnight: the day is watched, so the
  // count starts again when the server's does — not at whatever later moment something else changes.
  const [day, setDay] = useState(() => dayKey(now()))
  useEffect(() => {
    if (fixture) return
    const timer = setInterval(() => setDay(dayKey(now())), 30_000) // the same string is not a state change: one render a day
    return () => clearInterval(timer)
  }, [fixture])

  // `day` is a dependency on purpose: now() is read inside, and the day is the only thing about it that matters here.
  const tour = useMemo(() => buildTour(checkpoints, ops, now(), fixture?.copy.nextDetail ?? NEXT_DETAIL), [checkpoints, ops, fixture, day])
  const nextRow = tour.rows.find((r) => tour.nextId !== null && sameId(r.id, tour.nextId)) ?? null
  const postId = shift?.post.id ?? null
  const map = useMemo(() => (fixture ? null : tourMap(tour.rows, checkpoints, mine?.posts, postId)), [fixture, tour.rows, checkpoints, mine, postId])

  /* ---- the scanner ---------------------------------------------------------- */

  const [scan, setScan] = useState<{ targetId: CheckpointId; outcome: ScanOutcome | null } | null>(null)
  // Held from the moment a read is taken until the scanner is POINTED again (open / scan again / scan next) — not
  // merely until the result is set: the camera decodes several frames a second, and one that arrives after the act is
  // saved but before the result is drawn would otherwise be recorded as a second scan of the same checkpoint.
  const taken = useRef(false)
  const captured = useRef<ScanOp | null>(null)

  /*
   * THE MOMENT'S FIX IS TAKEN WHEN THE CAMERA OPENS, NOT AFTER THE TAG IS READ (M21 §1.1, found on the emulator).
   * On-duty sharing samples on MOVEMENT: a guard standing at a checkpoint produces no new fix, so the newest one
   * could be fifteen minutes old and the scan went up with no position. Asked for as the scanner opens, the fix is
   * usually in by the time the tag is lined up; a read waits for it at most SCAN_FIX_WAIT_MS, and never longer.
   */
  const momentFix = useRef<Promise<void> | null>(null)
  const takeMomentFix = useCallback(() => {
    momentFix.current = recordMoment('checkpoint').catch(() => undefined)
  }, [])

  const openScanner = useCallback(
    (id?: CheckpointId) => {
      const targetId = id ?? tour.nextId
      if (fixture || targetId === null) return
      taken.current = false
      takeMomentFix()
      setScan({ targetId, outcome: null })
    },
    [fixture, tour.nextId, takeMomentFix],
  )
  const closeScanner = useCallback(() => setScan(null), [])
  const scanAgain = useCallback(() => {
    taken.current = false
    takeMomentFix()
    setScan((s) => (s ? { targetId: s.targetId, outcome: null } : s))
  }, [takeMomentFix])
  const scanNext = useCallback(() => {
    taken.current = false
    takeMomentFix()
    setScan((s) => (s && tour.nextId !== null ? { targetId: tour.nextId, outcome: null } : s))
  }, [tour.nextId, takeMomentFix])

  const onTag = useCallback(
    async (raw: string) => {
      if (fixture || !scan || scan.outcome || taken.current) return
      taken.current = true
      const verdict = judgeTag(raw, scan.targetId, checkpoints, ops)
      if (verdict.kind === 'not_a_tag') return setScan({ targetId: scan.targetId, outcome: verdict })
      const at = checkpoints.find((cp) => sameId(cp.id, verdict.checkpointId))
      if (!at) {
        taken.current = false // nothing was decided: keep looking
        return
      }
      const expected = verdict.redirectedFrom === null ? null : (checkpoints.find((cp) => sameId(cp.id, scan.targetId))?.label ?? null)
      try {
        // The fix asked for when the camera opened, if it is not in yet — bounded, so the scan is never held up.
        if (momentFix.current) await Promise.race([momentFix.current, new Promise((resolve) => setTimeout(resolve, SCAN_FIX_WAIT_MS))])
        // On the phone FIRST, with the time it happened. INVARIANT 14: what is sent is the tag's text — never a frame.
        const op = await submit({ endpoint: 'patrol.scan', params: { checkpoint: at.id }, body: scanBody(verdict.code, !online, sharedFix()), label: `Checkpoint scan · ${at.label}`, kind: 'patrol' })
        captured.current = op
        // `s &&`: a scanner closed while the act was being written stays closed — the row shows the scan.
        setScan((s) => s && { targetId: at.id, outcome: { kind: 'recorded', opId: op.id, checkpointId: at.id, label: at.label, expectedLabel: expected } })
      } catch {
        setScan({ targetId: scan.targetId, outcome: { kind: 'not_saved' } }) // shown even over a closed scanner: nothing else would say so
      }
    },
    [fixture, scan, checkpoints, ops, online],
  )

  const outcome = scan?.outcome ?? null
  const activeOp = outcome?.kind === 'recorded' ? (ops.find((op) => op.id === outcome.opId) ?? (captured.current?.id === outcome.opId ? captured.current : null)) : null

  // "Sending it now" is a promise with a limit: the queue sends in order and stops at the first act that fails, so a
  // scan behind a stuck act is not even tried. After PATIENCE_MS the guard is told it is saved and to walk on.
  const [waitedFor, setWaitedFor] = useState<string | null>(null)
  const sendingId = outcome?.kind === 'recorded' && isSending(activeOp, online) ? outcome.opId : null
  useEffect(() => {
    if (sendingId === null) return
    const timer = setTimeout(() => setWaitedFor(sendingId), PATIENCE_MS)
    return () => clearTimeout(timer)
  }, [sendingId])

  const panel = outcome ? panelFor(outcome, activeOp, online, outcome.kind === 'recorded' && waitedFor === outcome.opId) : null

  // A gloved hand in the dark gets an answer it can feel — once per answer, when the answer is final.
  const announced = panel?.settled ? `${panel.tone}:${panel.headline}` : null
  useEffect(() => {
    if (!announced) return
    const kind = announced.startsWith('green') ? Haptics.NotificationFeedbackType.Success : announced.startsWith('red') ? Haptics.NotificationFeedbackType.Error : Haptics.NotificationFeedbackType.Warning
    void Haptics.notificationAsync(kind).catch(() => undefined)
  }, [announced])

  const target = scan ? (tour.rows.find((r) => sameId(r.id, scan.targetId)) ?? null) : null
  const stands = outcome?.kind === 'recorded' && activeOp?.state !== 'refused'
  const scanner: ScannerModel | null =
    scan && target
      ? { targetLabel: target.label, targetPosition: target.position, total: tour.total, panel, nextLabel: stands && nextRow && !sameId(nextRow.id, scan.targetId) ? nextRow.label : null }
      : null

  /* ---- refresh, status -------------------------------------------------------- */

  const [refreshing, setRefreshing] = useState(false)
  const refetchShift = current.refetch
  const refetchList = list.refetch
  const refresh = useCallback(() => {
    if (fixture) return
    setRefreshing(true)
    // Pulling down is also a reason to try the queue: a scan waiting for signal goes up with it.
    void Promise.allSettled([refetchShift(), siteId === null ? Promise.resolve() : refetchList(), drainNow()]).finally(() => setRefreshing(false))
  }, [fixture, refetchShift, refetchList, siteId])

  // A new day: the server's list starts from nothing scanned — ask for it (the phone's own view has already reset).
  const listDay = useRef(day)
  useEffect(() => {
    if (listDay.current === day) return
    listDay.current = day
    if (!fixture && siteId !== null) void refetchList()
  }, [day, fixture, siteId, refetchList])

  // The server's "no" outranks the cache: a list this handset may no longer read is not shown as if it still could.
  const denied = (e: unknown) => isApiError(e) && e.status === 403
  const status: PatrolModel['status'] = fixture
    ? 'ready'
    : denied(current.error) || denied(list.error)
      ? 'denied'
      : cachedShift === undefined
        ? current.error ? 'error' : 'loading'
        : siteId === null
          ? 'no_shift'
          : mine
            ? checkpoints.length > 0 ? 'ready' : 'empty'
            : list.error ? 'error' : 'loading'

  const failure = current.error ?? list.error
  const errorMessage = !online
    ? 'No signal, and this phone has not downloaded the checkpoint list yet. It is needed once: connect, then pull down to refresh.'
    : isApiError(failure) ? failure.message : null

  const stale = !fixture && !!mine && (!online || !!list.error) && typeof cachedAt === 'number'

  return {
    status,
    errorMessage,
    online,
    title: fixture ? fixture.copy.title : (shift?.site.name ?? ''),
    badge: fixture ? fixture.copy.badge : null,
    countLabel: `${tour.scanned} of ${tour.total} checkpoints`,
    metaRight: fixture ? fixture.copy.metaRight : tour.lastScanAt ? `Last scan ${timeCompact(tour.lastScanAt)}` : 'No scans yet today',
    percent: tour.percent,
    radarLabel: fixture ? fixture.copy.radarLabel : tour.allScanned ? `All ${tour.total} scanned today` : nextRow ? `Checkpoint ${nextRow.position} · next on the tour` : '',
    map,
    mapNote: map ? unplacedNote(map.unplaced) : null,
    rows: tour.rows,
    staleNote: stale ? `Checkpoint list last updated ${time12(new Date(cachedAt))}` : null,
    refusedCount: tour.rows.filter((r) => r.state === 'refused').length,
    refreshing,
    refresh,
    scanner,
    openScanner,
    closeScanner,
    onTag: (raw) => void onTag(raw),
    scanAgain,
    scanNext,
  }
}
