/**
 * Notifications and links for the Guard App — mounted once, in the root layout.
 *
 *   - sets up the phone's notification channels: "Safety alerts" (loud — a panic or duress at the site, an
 *     alertness check), "Updates", and one per category the guard can switch off. The server's pushes name
 *     the same channels;
 *   - keeps the server told where to reach this handset when the app is CLOSED (usePushRegistration) —
 *     never blocking the app, silent in Expo Go, where the poller below remains the only notifier;
 *   - a TAP on a notification — local, or a push opened from the lock screen — opens the screen its type
 *     names (packages/core/notify/links.ts), but only for a signed-in guard: a notification left over from
 *     the last session opens nothing;
 *   - tells the poller what the guard is looking at, so a banner never announces what is already on screen.
 *
 * It never ASKS for permission: that belongs to a screen, at a moment that makes sense — setup step 4
 * ("Before you start work", which every guard passes once), and afterwards More → Notifications.
 * Scheme links (gsguard://…) are routed by expo-router itself; the SessionGate still guards every route.
 */

import { useEffect } from 'react'
import { router, usePathname } from 'expo-router'
import { isFidelity } from '@gs/core'
import { configureNotifications, onOpen, usePushRegistration } from '@gs/core/notify'
import { store, useAppSelector } from '@/store'
import { setLooking } from './poller'
import { mutedCategories, pushRegistration } from './pushRegistration'

export function GuardNotifier() {
  const pathname = usePathname()
  const guardId = useAppSelector((s) => (s.session.status === 'active' ? s.session.principal?.id ?? null : null))
  const online = useAppSelector((s) => s.network.online)
  /*
   * REVOKED ONLY, AND NOT `expired` (M6, Q-M42). `registration.revoked()` hands the device
   * token back to the OS, after which nothing at all is delivered to this install. That is
   * right when the office has taken the handset away — and wrong when the idle window merely
   * closed: the API token row is still there, so the server can still reach this phone, and
   * a guard whose session timed out in a pocket must go on hearing the site's panic and
   * duress alerts. Silencing the handset would be the worst possible reading of "logged out".
   */
  const revoked = useAppSelector((s) => s.session.status === 'signedOut' && s.session.endedReason === 'revoked')

  useEffect(() => { setLooking(pathname) }, [pathname])

  usePushRegistration({ registration: pushRegistration, owner: guardId === null ? null : `guard:${guardId}`, online, muted: mutedCategories, revoked, disabled: isFidelity })

  useEffect(() => {
    if (isFidelity) return
    void configureNotifications('guard')
    return onOpen('guard', (path) => {
      // A tap that LAUNCHED the app arrives while the session is still booting: wait for the answer, then decide.
      const decide = (): boolean => {
        const status = store.getState().session.status
        if (status === 'booting') return false
        if (status === 'active') router.push(path as never)
        return true
      }
      if (decide()) return
      const stop = store.subscribe(() => { if (decide()) stop() })
    })
  }, [])

  return null
}
