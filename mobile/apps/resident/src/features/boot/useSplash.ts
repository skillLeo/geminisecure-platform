/**
 * resident-app-01 · Splash — what the screen does, so the rebuilt screen only draws (one path, M42 Stage 0 §4.1): the
 * loader's progress, and where the splash hands over, and when. Fidelity mode draws the board's 62% and stays put.
 *
 * "STRAIGHT" MEANS STRAIGHT (M11 A4). The ~1.5 s is for a phone that has never been used — the one resident who is
 * seeing the brand for the first time. Every other launch used to wait the same 1.65 s before a single real screen,
 * which the board says it should not: a phone with a saved session, or one that has signed in here before, leaves the
 * moment the boot has read the disk.
 */

import { useEffect, useRef, useState } from 'react'
import { router } from 'expo-router'
import { Easing, useAnimatedStyle, useSharedValue, withTiming } from 'react-native-reanimated'
import { isFidelity } from '@gs/core'
import type { SessionStatus } from '@gs/core/auth'
import { size } from '@/design/tokens'
import { lastEstate } from '@/features/auth/session'
import { permissionsAsked } from '@/features/auth/usePermissions'
import { bootFixture } from '@/fidelity/fixtures'
import { useAppSelector } from '@/store'

/** `.loader` — the board's 132-point track. */
const LOADER = size[132]
const BOOT_MS = 1500

/**
 * Where the splash hands over — decided from disk alone, so it is the same with no signal.
 * A saved session goes to its own place (the claim's holding screen while pending, Home once
 * active). Everybody else goes to LOG IN (M32 F10): someone who already has an account is the
 * common case, every day, and creating one happens once — so a brand-new phone opens on Log in
 * too, with "Create an account" a full-width button below it.
 */
export function nextRoute(status: SessionStatus): string {
  // The permissions are explained once per PHONE, before Home: until the phone has answered each (M47 §3).
  if (status === 'active') return permissionsAsked.get() ? '/(tabs)' : '/permissions'
  if (status === 'pending') return '/(auth)/pending'
  return '/(auth)/log-in'
}

/**
 * Does this launch wait out the board's ~1.5 s? Only a phone that has never been used: no session
 * saved and no estate remembered from an earlier sign-in. Read once the boot has answered.
 */
export function waitsForBrand(status: SessionStatus): boolean {
  return status === 'signedOut' && lastEstate.get() === null
}

export function useSplash() {
  const progress = useSharedValue(isFidelity ? bootFixture.loaderProgress : 0)
  const status = useAppSelector((s) => s.session.status)
  const [minimumShown, setMinimumShown] = useState(false)
  // What the phone has already answered (M47 §3): asked of the phone, silently, while the boot reads the disk.
  const [phoneKnown, setPhoneKnown] = useState(permissionsAsked.known)

  useEffect(() => {
    if (phoneKnown) return
    let alive = true
    void permissionsAsked.hydrate().finally(() => { if (alive) setPhoneKnown(true) })
    return () => { alive = false }
  }, [phoneKnown])

  useEffect(() => {
    if (isFidelity) return
    progress.value = withTiming(1, { duration: BOOT_MS, easing: Easing.out(Easing.cubic) })
    const timer = setTimeout(() => setMinimumShown(true), BOOT_MS + 150)
    return () => clearTimeout(timer)
  }, [progress])

  // The boot (SessionGate) always; the ~1.5 s the board asks for only on a phone's very first launch.
  // Once: the timer that runs out after the splash has already handed over must not send it anywhere again.
  const left = useRef(false)
  useEffect(() => {
    if (isFidelity || left.current || status === 'booting') return
    if (!minimumShown && waitsForBrand(status)) return
    // An active session goes to Home or, the first time on this phone, to the permissions: which, the phone says.
    if (status === 'active' && !phoneKnown) return
    left.current = true
    router.replace(nextRoute(status) as never)
  }, [minimumShown, status, phoneKnown])

  /** The loader's gold, as far along as the boot is. */
  const fill = useAnimatedStyle(() => ({ width: LOADER * progress.value }))
  return { fill }
}
