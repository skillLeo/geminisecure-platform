/**
 * What this phone should notify the resident about — kept ON THE PHONE (the server keeps no per-phone notification
 * preferences of its own), and asked by the notifier before it shows anything. Email is the one switch that is the
 * ACCOUNT's, and the server's (useSettings, `me.notifications.email`).
 *
 * Three things cannot be switched off, by design: someone waiting at the gate, an update to a panic
 * alert, and the estate approving this account. Safety and access never depend on a preference.
 *
 * The switched-off categories are ALSO sent to the server with this phone's push registration,
 * because the server decides the pushes that reach the app while it is CLOSED — so a change here nudges
 * the registration. (`@gs/core/notify/registration` and not the notify index: this file is pure and is
 * read by tests, and the index reaches the notification runtime.)
 */

import { flags, getStorage, jsonStore, siteParts } from '@gs/core/lib'
import { nudgePushRegistration, type QuietReport } from '@gs/core/notify/registration'

export const CATEGORIES = [
  { key: 'gate', title: 'Someone at the gate', detail: 'Always on: a visitor is waiting for your answer', locked: true },
  { key: 'panic', title: 'Panic alert updates', detail: 'Always on for safety', locked: true },
  { key: 'visitors', title: 'Visitor arrivals', detail: 'When a guest you invited is let in', locked: false },
  { key: 'notices', title: 'Estate notices', detail: 'New notices from the estate office', locked: false },
  { key: 'maintenance', title: 'Maintenance updates', detail: 'When a request you made moves on', locked: false },
  { key: 'payments', title: 'Payments', detail: 'When the office records a payment', locked: false },
  { key: 'meetings', title: 'Meetings', detail: 'Reminders for meetings you said you would attend', locked: false },
  { key: 'voting', title: 'Voting', detail: 'When an election opens and when results are published', locked: false },
  { key: 'bookings', title: 'Bookings', detail: 'When the estate answers a booking request', locked: false },
] as const

export type Category = (typeof CATEGORIES)[number]['key']
export type Prefs = Record<Category, boolean>

/**
 * What the Notifications screen actually OFFERS, with bookings off for the pilot.
 *
 * A category belonging to a feature this build has switched off is a switch with nothing behind it:
 * the app has no way to reach bookings, so no booking can ever be answered, so the row is a dead
 * control — and a resident reading "When the estate answers a booking request" has been told the app
 * does something it does not. The stored preference keeps its key either way, so turning the flag on
 * brings the row back with whatever the resident had chosen.
 */
export const OFFERED_CATEGORIES = CATEGORIES.filter((cat) => cat.key !== 'bookings' || flags.amenityBookings)

const LOCKED: Category[] = CATEGORIES.filter((cat) => cat.locked).map((cat) => cat.key)
export const DEFAULTS: Prefs = { gate: true, panic: true, visitors: true, notices: true, maintenance: true, payments: true, meetings: true, voting: true, bookings: true }

/** Whatever was stored — by an older version, or by hand — a locked category reads as ON. */
export const normalise = (stored: Partial<Prefs> | null | undefined): Prefs => {
  const merged = { ...DEFAULTS, ...(stored ?? {}) }
  for (const key of LOCKED) merged[key] = true
  return merged
}

const box = () => jsonStore<Partial<Prefs>>(getStorage(), 'notifications.prefs', 'v1')

export const notificationPrefs = {
  get: (): Prefs => normalise(box().get()),
  set(key: Category, on: boolean): Prefs {
    const next = normalise({ ...notificationPrefs.get(), [key]: on })
    box().set(next)
    // Written through: the server decides a push this phone never sees arrive, so it is told what is off
    // here. The value above stays the truth on this phone, online or not; the server learns at the next chance.
    nudgePushRegistration()
    return next
  },
}

/*
 * QUIET HOURS (board 20; M50 §4.2 #5) — "10:00 PM – 7:00 AM", on as the board draws it. The hours are the board's and
 * are not edited in the app. On THIS phone, like the categories: kept here, and sent with the push registration, so
 * the server holds every push but the urgent ones inside them while the app is closed — and the notifier holds its own
 * banners the same way while it is open. Someone at the gate and a panic update are never held: "Urgent alerts still
 * come through".
 */
export const QUIET_FROM = '22:00'
export const QUIET_TO = '07:00'

const quietBox = () => jsonStore<{ on: boolean }>(getStorage(), 'notifications.quiet', 'v1')

export const quietHours = {
  get: (): boolean => quietBox().get()?.on !== false,
  set(on: boolean): boolean {
    quietBox().set({ on })
    nudgePushRegistration()
    return on
  },
}

/** What the push registration tells the server about quiet hours on this phone. */
export const quietReport = (): QuietReport => ({ on: quietHours.get(), from: QUIET_FROM, to: QUIET_TO })

/** Whether the estate's clock is inside quiet hours now — from is inside, until is not; the hours run over midnight. */
export const quietNow = (at: Date = new Date(), on: boolean = quietHours.get()): boolean => {
  if (!on) return false
  const parts = siteParts(at)
  if (!Number.isFinite(parts.hour) || !Number.isFinite(parts.minute)) return false
  const now = `${String(parts.hour).padStart(2, '0')}:${String(parts.minute).padStart(2, '0')}`
  return QUIET_FROM < QUIET_TO ? now >= QUIET_FROM && now < QUIET_TO : now >= QUIET_FROM || now < QUIET_TO
}

/**
 * For the notifier: `category` is packages/core/notify's name for it. Unknown names are allowed (never silently
 * dropped). Inside quiet hours only the locked categories — someone at the gate, a panic update — are shown.
 */
export const allows = (category: string): boolean => {
  const prefs = notificationPrefs.get() as Record<string, boolean>
  if (prefs[category] === false) return false
  return !quietNow() || LOCKED.includes(category as Category)
}
