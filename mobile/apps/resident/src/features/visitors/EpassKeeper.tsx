/**
 * THE RESIDENT'S OWN E-PASS, KEPT CURRENT WITH NO SCREEN OPEN (B120) — mounted once, in the root layout.
 *
 * The e-Pass screen told the resident "each pass lasts 24 hours and the app collects a fresh one about an hour
 * before it runs out", and that once loaded it works with no connection. Nothing collected anything: the only
 * request for the pass was made by that screen, while it was open. So a resident who opened it at 8 AM on Monday
 * stood at the gate at 8:30 AM on Tuesday, with no signal, and was shown "Your e-Pass is not on this phone yet".
 *
 * HALF AN HOUR AT A TIME SINCE M47 §6: each collection brings a day of half hours (`slots`), and the server's
 * `refresh_after` is the end of the current one, so while the app is open with a connection the day ahead is topped
 * up every half hour. While the resident is signed in and the app is open, the pass is fetched:
 *
 *   at launch                      the one small request, so a phone opened today always carries today's
 *   at the server's `refresh_after` a timer, while the app stays open
 *   on coming back to the front    and on the connection returning, whenever the copy on the phone is due
 *
 * NOT FOR A PHONE NOBODY OPENS (the user, 7 October): no collecting ahead once the app has not been brought to the
 * front for a day. What it holds then runs out within the next day, and a lost phone stops being a pass with it.
 *
 * The copy it saves is the one the e-Pass screen reads (`passes.epass`) — in the app's encrypted store, wiped at
 * sign-out — so the gate screen opens with no signal. What it cannot do is fetch while the phone is off or the app
 * has not been opened: the screen says so.
 */

import { useEffect, useRef } from 'react'
import { AppState } from 'react-native'
import { isFidelity, now, saveOfflineCache, useOfflineCache } from '@gs/core'
import { residentApi } from '@gs/core/api/resident'
import { store, useAppSelector } from '@/store'
import { epassOff } from './passes'

type Kept = { valid_to: string; refresh_after?: string | null } | null | undefined

/** When a fresh pass should be collected: the server's `refresh_after`, or an hour before `valid_to` when a copy has none. */
export function refreshAtOf(pass: Kept): number | null {
  if (!pass) return null
  const after = pass.refresh_after ? Date.parse(pass.refresh_after) : Number.NaN
  if (Number.isFinite(after)) return after
  const until = Date.parse(pass.valid_to)
  return Number.isFinite(until) ? until - 3_600_000 : null
}

/** Whether the pass on this phone should be replaced now: there is none, or its refresh time has come. */
export const epassDue = (pass: Kept, at: number): boolean => {
  const refreshAt = refreshAtOf(pass)
  return refreshAt === null || refreshAt <= at
}

/** No collecting ahead for an app that has not been brought to the front for this long. */
export const OPENED_WITHIN_MS = 24 * 3_600_000

/** A timer longer than this is not honoured by every JS engine; the check simply runs again. */
const LONGEST_TIMER_MS = 6 * 3_600_000
/** And a phone that slept through the timer is caught by this, while the app is open. */
const RECHECK_MS = 10 * 60_000

export function EpassKeeper(): null {
  const active = useAppSelector((s) => s.session.status === 'active')
  const online = useAppSelector((s) => s.network.online)
  const off = isFidelity || !active
  const query = residentApi.useEpassShowQuery(undefined, { skip: off })
  const kept = useOfflineCache<Kept>('passes.epass', query.data as Kept)

  // A Helper's e-Pass turned off — paused, ended, or closed for the next day (8 Oct 2026): the day of half hours kept
  // for a gate with no signal goes too, so this phone cannot show what the household has just stopped.
  const turnedOff = epassOff(query.error)
  useEffect(() => {
    if (turnedOff !== null) saveOfflineCache('passes.epass', null)
  }, [turnedOff])

  const latest = useRef({ kept, refetch: query.refetch, off })
  latest.current = { kept, refetch: query.refetch, off }
  // When the app was last brought to the front: launching it counts.
  const openedAt = useRef(now().getTime())

  // Collect a fresh one when it is due — only with a connection: a request with none would only fail.
  const collectIfDue = useRef(() => {
    const { kept: pass, refetch, off: skipped } = latest.current
    if (skipped || !store.getState().network.online) return
    const at = now().getTime()
    if (at - openedAt.current > OPENED_WITHIN_MS) return
    if (epassDue(pass, at)) void Promise.resolve(refetch()).catch(() => undefined)
  }).current

  // At the refresh time, while the app stays open.
  useEffect(() => {
    if (off) return
    const refreshAt = refreshAtOf(kept)
    const wait = refreshAt === null ? 0 : Math.min(LONGEST_TIMER_MS, Math.max(0, refreshAt - now().getTime())) // phone-clock: a delay until the server's refresh instant
    const timer = setTimeout(collectIfDue, wait)
    const recheck = setInterval(collectIfDue, RECHECK_MS)
    return () => {
      clearTimeout(timer)
      clearInterval(recheck)
    }
  }, [off, kept, collectIfDue])

  // Back to the front, or the connection returning.
  useEffect(() => {
    if (off) return
    const sub = AppState.addEventListener('change', (state) => {
      if (state !== 'active') return
      openedAt.current = now().getTime()
      collectIfDue()
    })
    return () => sub.remove()
  }, [off, collectIfDue])

  useEffect(() => {
    if (!off && online) collectIfDue()
  }, [off, online, collectIfDue])

  return null
}
