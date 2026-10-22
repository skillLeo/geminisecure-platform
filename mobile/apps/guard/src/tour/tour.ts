/**
 * THE GUARD APP'S FIRST-RUN TOUR (M32 F11): its five cards, the record of who has seen it on this handset, and when it
 * opens.
 *
 *   ONCE, on Home, the first time a guard comes to it with the phone enrolled AND setup finished — not before the
 *   account works, and never in the middle of setup.
 *   NEVER AGAIN after Done or Skip (Skip on the first card included), unless the guard asks: More → "Show me around
 *   again" opens it in place and records nothing.
 *   NEVER DURING AN EMERGENCY, A SCAN OR A SHIFT START: not while a duress alert is live, only while Home is the screen
 *   in front (the scanner, clocking in and the duress screen are screens of their own), and `Tour` itself hides over
 *   every one of them.
 *
 * The cards' words are §14.1's.
 */

import { useCallback, useState } from 'react'
import { useFocusEffect } from 'expo-router'
import type { TourCard } from '@gs/core'
import { getStorage, jsonStore } from '@gs/core/lib'
import { onboarding } from '@/features/enrolment/onboardingState'
import { useAppSelector } from '@/store'

export const TOUR_CARDS: readonly TourCard[] = [
  { key: 'shift', icon: 'bio-btn', title: 'Your shift', body: 'Clock in at your post with your fingerprint. The app knows which post and when.' },
  { key: 'scan', icon: 'qr-box', title: 'Scan a visitor', body: "Point the camera at a visitor's pass. It tells you to let them in or not, and it works with no signal." },
  { key: 'checkpoints', icon: 'tab-patrol', title: 'Checkpoints', body: 'Scan the tags on your patrol so the office can see you passed them.' },
  { key: 'report', icon: 'mic-btn', title: 'Report something', body: 'Speak it, add a photo, send. It saves even with no signal and goes when you have one.' },
  { key: 'alerts', icon: 'shield-alert', title: 'Alerts', body: 'When an emergency comes in, your phone rings like a call, even if it is locked.' },
]

type Seen = { seen: Record<string, string> }

const box = () => jsonStore<Seen>(getStorage(), 'tour', 'v1')

/** Who this handset has shown the tour to: the guard's own id. */
export const tourKey = (principal: { id: number } | null): string | null => (principal === null ? null : String(principal.id))

export const tourStore = {
  seen: (key: string): boolean => (box().get()?.seen ?? {})[key] !== undefined,
  markSeen(key: string, at: Date = new Date()): void {
    const now = box().get()?.seen ?? {}
    box().set({ seen: { ...now, [key]: at.toISOString() } })
  },
}

/** Home's tour. `busy` is anything that must never have it on top — a live duress alert. */
export function useFirstRunTour(busy: boolean): { open: boolean; close: () => void } {
  const status = useAppSelector((s) => s.session.status)
  const principal = useAppSelector((s) => s.session.principal)
  const key = status === 'active' && onboarding.isFinished() ? tourKey(principal) : null
  const [focused, setFocused] = useState(true)
  const [closed, setClosed] = useState(false)

  useFocusEffect(
    useCallback(() => {
      setFocused(true)
      return () => setFocused(false)
    }, []),
  )

  const open = key !== null && focused && !busy && !closed && !tourStore.seen(key)
  const close = useCallback(() => {
    if (key !== null) tourStore.markSeen(key)
    setClosed(true)
  }, [key])

  return { open, close }
}
