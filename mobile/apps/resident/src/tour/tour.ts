/**
 * THE RESIDENT APP'S FIRST-RUN TOUR (M32 F11): its five cards, the record of who has seen it, and when it opens.
 *
 *   ONCE PER ACCOUNT (M47 §3), on the first opening after the account WORKS — signed in and active, past the permissions
 *   explained once — on Home. A resident waiting for the estate to approve them is not toured round features they
 *   cannot reach. The record is the ACCOUNT'S, on the server (`once.ts`), so it never comes back: not after a sign-out,
 *   a reinstall, or on another phone. This phone's own record stays as the fallback for an older server.
 *   NEVER AGAIN after Done or Skip (Skip on the first card included).
 *   NEVER OVER AN EMERGENCY: only while Home is the screen in front, and `Tour` itself hides over the panic screen.
 *
 * The cards' words are §14.1's, with two corrections the app requires: the panic is HELD for a second and a half
 * (`HOLD_MS`), not three seconds; and the dues are paid to the estate, not from the app, so "pay it" is not said.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { useFocusEffect } from 'expo-router'
import { isFidelity, type TourCard } from '@gs/core'
import { residentApi } from '@gs/core/api/resident'
import { getStorage, jsonStore } from '@gs/core/lib'
import { accountShown, onceVerdict, tellAccount } from '@/once/once'
import { useAppDispatch, useAppSelector } from '@/store'
import { TOUR_STORAGE_NAME, TOUR_STORAGE_VERSION } from './tourKey'

export const TOUR_CARDS: readonly TourCard[] = [
  { key: 'visitors', icon: 'qa-tile-4', title: 'Visitors', body: 'Make a pass for a guest and send it to them. The guard scans it at the gate and you get a message when they arrive.' },
  { key: 'panic', icon: 'shield-alert', title: 'Panic', body: 'In an emergency, hold the panic button for a second and a half. The control room and the officers on duty get it at once.' },
  { key: 'dues', icon: 'tab-card', title: 'Dues', body: 'See what your home owes and what has been paid, with a receipt for every payment the estate records.' },
  { key: 'maintenance', icon: 'rp-icon', title: 'Maintenance', body: 'Report something broken, add a photo, and follow it until it is fixed.' },
  { key: 'community', icon: 'tab-notices', title: 'Community', body: "Notices, meetings and voting from your estate's office." },
]

type Seen = { seen: Record<string, string> }

// THIS PHONE'S record: the fallback for a server that keeps none, and what tells the account later when it could not
// be told at once. Kept through a session that ends by itself (store/index.ts).
const box = () => jsonStore<Seen>(getStorage(), TOUR_STORAGE_NAME, TOUR_STORAGE_VERSION)

/** Who this phone has shown the tour to: the estate and the account, so a person on two estates is toured on each. */
export const tourKey = (principal: { id: number; siteName: string | null } | null): string | null =>
  principal === null ? null : `${principal.siteName ?? ''}|${principal.id}`

export const tourStore = {
  seen: (key: string): boolean => (box().get()?.seen ?? {})[key] !== undefined,
  markSeen(key: string, at: Date = new Date()): void {
    const now = box().get()?.seen ?? {}
    box().set({ seen: { ...now, [key]: at.toISOString() } })
  },
}

/**
 * Home's tour: open the first time an active account comes to Home, closed for good once it is done or skipped — for
 * the account, on every phone. `open` is false whenever Home is not the screen in front, and until the account's
 * record has answered.
 */
export function useFirstRunTour(): { open: boolean; close: () => void } {
  const dispatch = useAppDispatch()
  const status = useAppSelector((s) => s.session.status)
  const principal = useAppSelector((s) => s.session.principal)
  const demo = useAppSelector((s) => s.session.demo === true)
  const key = status === 'active' ? tourKey(principal) : null
  const [focused, setFocused] = useState(true)
  const [closed, setClosed] = useState(false)

  // The account's record (M47 §3) — the same `me.show` Home already reads, so no second request.
  const me = residentApi.useMeShowQuery(undefined, { skip: isFidelity || key === null })
  const record = accountShown(me.data)
  const seenHere = key !== null && tourStore.seen(key)
  const verdict = onceVerdict({ key: 'tour', record, answered: me.isSuccess || me.isError, seenHere })

  // Seen here and not yet on the account (no signal when it closed, or a server that kept none then): told once now.
  const told = useRef(false)
  const tell = useCallback(() => {
    // A demonstration session writes nothing (its token cannot), and the reviewer's tour is the phone's alone.
    if (told.current || demo) return
    told.current = true
    void dispatch(residentApi.endpoints.meShown.initiate({ key: 'tour' }, { track: false }))
  }, [dispatch, demo])
  useEffect(() => {
    if (tellAccount({ key: 'tour', record, seenHere })) tell()
  }, [record, seenHere, tell])

  useFocusEffect(
    useCallback(() => {
      setFocused(true)
      return () => setFocused(false)
    }, []),
  )

  const open = key !== null && focused && !closed && verdict === 'show'
  const close = useCallback(() => {
    if (key !== null) tourStore.markSeen(key)
    setClosed(true)
    tell()
  }, [key, tell])

  return { open, close }
}
