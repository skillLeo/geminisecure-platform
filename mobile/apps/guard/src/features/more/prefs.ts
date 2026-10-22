/**
 * What this phone should notify the guard about — kept ON THE PHONE, which is the truth for it, online or
 * not, and read by the notification layer before it shows anything. The switched-off categories are also
 * sent to the server with this phone's push registration, because the server decides the pushes
 * that reach the app while it is CLOSED.
 *
 * Three categories cannot be turned off, by design and not by omission: a panic or duress alert, an
 * alertness check-in, and a resident saying something is happening NOW at this site. Safety
 * never depends on a preference — the server ignores a mute for them too, so the screen says so rather
 * than showing a switch that would do nothing. A locked row has no category on the server side either
 * (`mutableCategories('guard')`), so it is never sent as muted.
 */

import { getStorage, jsonStore } from '@gs/core/lib'
import { nudgePushRegistration } from '@gs/core/notify/registration'

export const CATEGORIES = [
  { key: 'duress', title: 'Panic & duress alerts', detail: 'Always on for safety', locked: true },
  { key: 'alertness', title: 'Alertness check-ins', detail: 'Always on: you have two minutes to answer', locked: true },
  { key: 'reports', title: 'Resident reports at this site', detail: 'Always on while you are on duty: a resident says something is happening now', locked: true },
  { key: 'shifts', title: 'Shift & roster changes', detail: 'Roster changes and open-shift decisions', locked: false },
  { key: 'orders', title: 'Standing order updates', detail: 'New or revised orders for your posts', locked: false },
  { key: 'messages', title: 'Messages', detail: 'From the control room, and site broadcasts', locked: false },
  { key: 'requests', title: 'Leave & equipment decisions', detail: 'When a supervisor answers a request', locked: false },
  { key: 'payslips', title: 'Payslip ready', detail: 'When a new payslip is posted', locked: false },
] as const

export type Category = (typeof CATEGORIES)[number]['key']
export type Prefs = Record<Category, boolean>

const LOCKED: Category[] = CATEGORIES.filter((cat) => cat.locked).map((cat) => cat.key)
export const DEFAULTS: Prefs = { duress: true, alertness: true, reports: true, shifts: true, orders: true, messages: true, requests: true, payslips: true }

/** Whatever was stored — by an older version, or by hand — a locked category reads as ON. */
export const normalise = (stored: Partial<Prefs> | null | undefined): Prefs => {
  const merged = { ...DEFAULTS, ...(stored ?? {}) }
  for (const key of LOCKED) merged[key] = true
  return merged
}

export const withChange = (prefs: Prefs, key: Category, on: boolean): Prefs => normalise({ ...prefs, [key]: on })

const box = () => jsonStore<Partial<Prefs>>(getStorage(), 'notifications.prefs', 'v1')

export const notificationPrefs = {
  get: (): Prefs => normalise(box().get()),
  set(key: Category, on: boolean): Prefs {
    const next = withChange(notificationPrefs.get(), key, on)
    box().set(next)
    // Written through: the server decides a push this phone never sees arrive, so it is told what is off here.
    // The value above stays the truth on this phone, online or not; the server learns at the next chance.
    nudgePushRegistration()
    return next
  },
  /** For the notification layer: may this category be shown on this phone? */
  allows: (key: Category): boolean => notificationPrefs.get()[key],
}
