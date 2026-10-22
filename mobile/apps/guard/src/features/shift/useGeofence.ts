/**
 * Where the phone is, against the geofence of the post the guard is ROSTERED to (M12 §1) — the check the clock-in
 * screen runs (boards guard-app-02, -37, -38) and the supervisor-override screen shows again.
 *
 *   PERMISSION FIRST   location refused, or the phone's location switched off, is said as exactly that — which
 *                      one, and a button to the right settings screen. Never "you are not at the estate".
 *   NEVER ONE FIX      readings are taken until the phone is sure to within the server's `good_accuracy_m` (50 m),
 *                      or a short wait runs out; "Finding your position… ±N m" is shown while it settles, and the
 *                      best reading is the one judged.
 *   ONE RULE           online, the SERVER judges (`shifts.preflight`: the post's fence, else the estate's, else
 *                      none; the fix a circle). With no signal the phone judges the same way against the fence it
 *                      cached with the shift at its last sync (`@gs/core` judgeFence) and the server judges again
 *                      when the clock-in arrives.
 *
 * The position is held in memory for the one request that needs it. It is never stored.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { Linking, Platform } from 'react-native'
import * as Location from 'expo-location'
import { judgeFence, type Fence, type FenceVerdict } from '@gs/core'
import { guardApi } from '@gs/core/api/guard'
import { deviceUid } from '@gs/core/crypto'
// Setup's location disclosure. Nothing here may put the phone's location dialog up before it was read.
import { onboarding } from '@/features/enrolment/onboardingState'

type Where = { source?: 'post' | 'estate' | 'none'; fenceName?: string | null }

export type Geo =
  /** Readings are being taken; `accuracyM` is the best so far. */
  | { state: 'checking'; accuracyM?: number | null }
  /**
   * permission    this app may not read the location · services_off  the phone's location is switched off
   * no_fix        no reading came in time · offline  no signal and no fence saved on the phone, so it is judged on sync
   */
  | { state: 'unavailable'; why: 'permission' | 'services_off' | 'no_fix' | 'offline' }
  | ({ state: 'inside'; boundaryM: number; accuracyM: number | null; radiusM: number; margin?: boolean; offline?: boolean } & Where)
  | ({ state: 'outside'; boundaryM: number; accuracyM: number | null; radiusM: number; distanceM?: number; direction?: string | null; offline?: boolean } & Where)
  /** A reading, but not good enough to judge by: the position is not established. */
  | ({ state: 'poor_fix'; accuracyM: number } & Where)
  /** Neither the post nor the estate is placed: nothing to judge against, and the office is told. */
  | ({ state: 'no_geofence' } & Where)

/** What the last fix said — in memory only, for the request that starts the shift. */
export type LastFix = { distance: number | null; mocked: boolean; latitude: number | null; longitude: number | null; accuracyM: number | null }

const NO_FIX: LastFix = { distance: null, mocked: false, latitude: null, longitude: null, accuracyM: null }
const boundary = (distance: number, radius: number) => Math.abs(radius - distance)

/** The server's thresholds, used until a fence says otherwise. */
const DEFAULT_GOOD_M = 50
/** How long readings are taken before the best one is judged. */
const SETTLE_MS = 15_000
const MAX_READINGS = 6

const ANDROID_LOCATION_SETTINGS = 'android.settings.LOCATION_SOURCE_SETTINGS'

/** A screen the guard can put it right on: this app's settings for the permission, the phone's for location itself. */
export function openLocationSettings(why: 'permission' | 'services_off'): void {
  if (why === 'services_off' && Platform.OS === 'android') {
    void Linking.sendIntent(ANDROID_LOCATION_SETTINGS).catch(() => Linking.openSettings().catch(() => undefined))
    return
  }
  void Linking.openSettings().catch(() => undefined)
}

const accuracyOf = (fix: Location.LocationObject): number => (fix.coords.accuracy === null || fix.coords.accuracy === undefined ? Number.POSITIVE_INFINITY : fix.coords.accuracy)

/** Readings until one is good enough, or the wait runs out — never a single fix. The best one is kept. */
async function settle(goodM: number, onProgress: (accuracyM: number | null) => void): Promise<Location.LocationObject | null> {
  const deadline = Date.now() + SETTLE_MS
  let best: Location.LocationObject | null = null
  for (let reading = 0; reading < MAX_READINGS; reading++) {
    const left = deadline - Date.now()
    if (left <= 0) break
    const fix = await Promise.race([
      Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Highest }).catch(() => null),
      new Promise<null>((resolve) => setTimeout(() => resolve(null), left)),
    ])
    if (!fix) break
    if (!best || accuracyOf(fix) < accuracyOf(best)) best = fix
    const accuracy = accuracyOf(best)
    onProgress(Number.isFinite(accuracy) ? Math.round(accuracy) : null)
    if (accuracy <= goodM) break
  }
  return best
}

/** The judgement — the server's or the phone's — as the screen draws it. */
export function geoOf(verdict: Pick<FenceVerdict, 'outcome' | 'distanceM' | 'radiusM' | 'accuracyM' | 'direction' | 'source' | 'fenceName'>, offline = false): Geo {
  const where: Where = { source: verdict.source, fenceName: verdict.fenceName }
  const distance = verdict.distanceM ?? 0
  const radius = verdict.radiusM ?? 0
  switch (verdict.outcome) {
    case 'inside':
    case 'inside_margin':
      return { state: 'inside', boundaryM: boundary(distance, radius), accuracyM: verdict.accuracyM, radiusM: radius, margin: verdict.outcome === 'inside_margin', offline, ...where }
    case 'outside':
      return { state: 'outside', boundaryM: Math.max(0, distance - radius), accuracyM: verdict.accuracyM, radiusM: radius, distanceM: distance, direction: verdict.direction, offline, ...where }
    case 'poor_fix':
      return { state: 'poor_fix', accuracyM: verdict.accuracyM ?? 0, ...where }
    case 'no_geofence':
      return { state: 'no_geofence', ...where }
    default:
      return { state: 'unavailable', why: 'no_fix' }
  }
}

type Preflight = {
  outcome?: FenceVerdict['outcome'] | null
  distance_m?: number | string | null
  radius_m?: number | string | null
  accuracy_m?: number | null
  direction?: string | null
  fence?: { source?: Fence['source']; name?: string } | null
  within_geofence?: boolean | null
  supervisor_phone?: string | null
  reviewer?: boolean
  passcode_identity?: boolean
  flagged_for_review?: boolean
}

/** The server's answer as a verdict. A server older than M12 sent no `outcome`: read it from `within_geofence`. */
function verdictOf(p: Preflight, accuracyM: number | null): Parameters<typeof geoOf>[0] {
  const distance = p.distance_m === null || p.distance_m === undefined ? null : Number(p.distance_m)
  const radius = p.radius_m === null || p.radius_m === undefined ? null : Number(p.radius_m)
  const outcome = p.outcome ?? (p.within_geofence === true ? 'inside' : p.within_geofence === false ? 'outside' : 'no_geofence')
  return { outcome, distanceM: distance, radiusM: radius, accuracyM: p.accuracy_m ?? accuracyM, direction: p.direction ?? null, source: p.fence?.source ?? 'post', fenceName: p.fence?.name ?? null }
}

export function useGeofence(input: { shiftId: number | string | undefined; online: boolean; fixture: { geo: Geo; mocked: boolean } | null; fence?: Fence | null }) {
  const { shiftId, online, fixture } = input
  const fence = input.fence ?? null
  const [preflight] = guardApi.useShiftsPreflightMutation()
  const [geo, setGeo] = useState<Geo>(fixture ? fixture.geo : { state: 'checking' })
  const [mocked, setMocked] = useState(fixture ? fixture.mocked : false)
  const [supervisorPhone, setSupervisorPhone] = useState<string | null>(null)
  /**
   * The SERVER's answer for an app-store reviewer's demonstration account, and nothing else.
   * `passcodeIdentity` lets the phone's passcode stand in for a fingerprint or face at
   * clock-in; `reviewer` says the geofence was judged from the POST's own surveyed coordinates
   * rather than this handset's. Both are false for every real guard.
   */
  const [passcodeIdentity, setPasscodeIdentity] = useState(false)
  const [reviewer, setReviewer] = useState(false)
  const lastFix = useRef<LastFix>({ ...NO_FIX })
  const fenceRef = useRef<Fence | null>(fence)
  fenceRef.current = fence

  const check = useCallback(async () => {
    if (fixture || shiftId === undefined) return
    setGeo({ state: 'checking' })
    lastFix.current = { ...NO_FIX }

    /**
     * Ask the server what it can say with no position at all.
     *
     * WHY IT IS ASKED AT ALL. A reviewer will refuse the location prompt — they are not at a gate in Jamaica —
     * and the whole reviewer path lives in this answer. For a REVIEWER the answer is the truth: the server stood
     * the check at the post's own coordinates. For anybody else the phone's own reason stands (permission, the
     * location switched off, no fix), because that is what the guard must put right.
     */
    const askWithoutAFix = async (why: 'permission' | 'services_off' | 'no_fix'): Promise<void> => {
      if (!online) return setGeo({ state: 'unavailable', why })
      try {
        const answer = await preflight({ shift: shiftId, mock_location: false, device_uid: await deviceUid() })
        if ('error' in answer || !answer.data) return setGeo({ state: 'unavailable', why })
        const p = answer.data as Preflight
        setPasscodeIdentity(p.passcode_identity === true)
        setReviewer(p.reviewer === true)
        setSupervisorPhone(p.supervisor_phone ?? null)
        if (p.reviewer === true && (p.outcome === 'inside' || p.within_geofence === true)) {
          const verdict = verdictOf(p, null)
          lastFix.current.distance = verdict.distanceM
          return setGeo(geoOf(verdict))
        }
        // No fence configured anywhere: there is nothing to be outside of, whatever the phone can say.
        if (p.outcome === 'no_geofence') return setGeo(geoOf(verdictOf(p, null)))
        setGeo({ state: 'unavailable', why })
      } catch {
        setGeo({ state: 'unavailable', why })
      }
    }

    try {
      /*
       * THE PROMPT ONLY AFTER THE DISCLOSURE (Google's prominent-disclosure rule). Until setup step 4 has said what a
       * position is read for, this asks the phone what it already holds and never opens a dialog.
       */
      const perm = onboarding.locationDisclosed() ? await Location.requestForegroundPermissionsAsync() : await Location.getForegroundPermissionsAsync()
      if (!perm.granted) return await askWithoutAFix('permission')
      // The permission is there, but the phone's location may be switched off altogether — said as that.
      if (!(await Location.hasServicesEnabledAsync().catch(() => true))) return await askWithoutAFix('services_off')

      const good = fenceRef.current?.good_accuracy_m ?? DEFAULT_GOOD_M
      const fix = await settle(good, (accuracyM) => setGeo({ state: 'checking', accuracyM }))
      if (!fix) return await askWithoutAFix('no_fix')

      const isMocked = fix.mocked === true
      const accuracyM = fix.coords.accuracy === null || fix.coords.accuracy === undefined ? null : Math.round(fix.coords.accuracy)
      setMocked(isMocked)
      lastFix.current = { distance: null, mocked: isMocked, latitude: fix.coords.latitude, longitude: fix.coords.longitude, accuracyM }

      if (!online) {
        // No signal: the same rule, against the fence saved with the shift. None saved — the server judges on sync.
        const cachedFence = fenceRef.current
        if (!cachedFence) return setGeo({ state: 'unavailable', why: 'offline' })
        const verdict = judgeFence(cachedFence, { latitude: fix.coords.latitude, longitude: fix.coords.longitude, accuracyM })
        lastFix.current.distance = verdict.distanceM
        return setGeo(geoOf(verdict, true))
      }

      const answer = await preflight({
        shift: shiftId,
        latitude: fix.coords.latitude,
        longitude: fix.coords.longitude,
        accuracy_m: accuracyM ?? undefined,
        mock_location: isMocked,
        device_uid: await deviceUid(),
      })
      if ('error' in answer || !answer.data) {
        // The server could not be asked: judge against the saved fence rather than leave the guard waiting.
        const cachedFence = fenceRef.current
        if (!cachedFence) return setGeo({ state: 'unavailable', why: 'offline' })
        const verdict = judgeFence(cachedFence, { latitude: fix.coords.latitude, longitude: fix.coords.longitude, accuracyM })
        lastFix.current.distance = verdict.distanceM
        return setGeo(geoOf(verdict, true))
      }
      const p = answer.data as Preflight
      setPasscodeIdentity(p.passcode_identity === true)
      setReviewer(p.reviewer === true)
      setSupervisorPhone(p.supervisor_phone ?? null)
      if (p.flagged_for_review) setMocked(true)
      const verdict = verdictOf(p, accuracyM)
      lastFix.current.distance = verdict.distanceM
      setGeo(geoOf(verdict))
    } catch {
      await askWithoutAFix('no_fix')
    }
  }, [fixture, shiftId, online, preflight])

  useEffect(() => {
    void check()
  }, [check])

  return { geo, mocked, passcodeIdentity, reviewer, lastFix, supervisorPhone, recheck: () => void check() }
}
