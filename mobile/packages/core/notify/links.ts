/**
 * Where a notification takes you — one table per app, and nothing outside it.
 *
 * A notification (local today; remote once the server sends them) carries a TYPE and a few ids, never
 * a URL. The app decides the route from this table, so a forged or malformed payload can only ever
 * open one of the app's own screens, with an id that is digits (or a short slug) and nothing else:
 * no path traversal, no query smuggling, no external link.
 *
 * The same table answers the app's own scheme links (gsguard:// · gsresident://): expo-router maps a
 * path to a screen, and `sanitisePath` is what the app runs an incoming path through first.
 */

export type AppKind = 'guard' | 'resident'

export type GuardType = 'site_alert' | 'site_ring' | 'ring_stop' | 'duress_update' | 'alertness_check' | 'message' | 'broadcast' | 'order_published' | 'shift_changed' | 'shift_open' | 'claim_decided' | 'request_decided' | 'payslip_ready' | 'resident_report' | 'tracking_gap'
export type ResidentType = 'approval_request' | 'visitor_admitted' | 'panic_update' | 'notice' | 'ticket_update' | 'payment_recorded' | 'dues_reminder' | 'meeting_reminder' | 'election_open' | 'election_results' | 'booking_decided' | 'claim_approved' | 'support_reply' | 'household_entry'
export type NotificationType = GuardType | ResidentType

export type Payload = { type?: unknown; id?: unknown; kind?: unknown }

const ID = /^[A-Za-z0-9_-]{1,40}$/
const id = (value: unknown): string | null => (typeof value === 'number' && Number.isFinite(value) ? String(Math.trunc(value)) : typeof value === 'string' && ID.test(value) ? value : null)

const GUARD: Record<GuardType, (p: Payload) => string | null> = {
  /*
   * A panic or another officer's duress at this site — and, with `kind: dispatched`, "you are the officer
   * sent" (M10 §1.3). Both open THAT alert (`alerts.show`): where it is, how far the response has got,
   * and the En route / On scene buttons when this handset may record them — nothing in a panic waits on
   * the dispatcher, so an officer who was not sent may take it too. It used to open Home, which has no
   * alert on it, so the officer sent had no way to say "on my way". A payload without a usable id still
   * opens Home: the post, the radio and the orders.
   */
  site_alert: (p) => { const value = id(p.id); return value ? `/alert/${value}` : '/(tabs)' },
  /*
   * The call-style ring (apps/guard/modules/guard-ring) and its stop. Both are handled NATIVELY when they arrive —
   * a data-only ring never reaches JS — so these rows matter only for a tap on the server's visible `ring_stop`, and
   * for any copy that does reach the app: both open that alert, like the push they follow.
   */
  site_ring: (p) => { const value = id(p.id); return value ? `/alert/${value}` : '/(tabs)' },
  ring_stop: (p) => { const value = id(p.id); return value ? `/alert/${value}` : '/(tabs)' },
  duress_update: () => '/duress',
  alertness_check: () => '/alertness',
  message: () => '/messages/dispatch',
  broadcast: () => '/messages/broadcast',
  order_published: () => '/orders',
  shift_changed: () => '/(tabs)',
  shift_open: () => '/leave?tab=open',
  claim_decided: () => '/leave?tab=open',
  request_decided: (p) => (p.kind === 'equipment' ? '/equipment' : '/leave'),
  payslip_ready: () => '/(tabs)/payslips',
  // A resident says something is happening NOW at this site: what they said and where, READ ONLY.
  // The report belongs to the estate office — this handset opens it, and changes nothing.
  resident_report: (p) => { const value = id(p.id); return value ? `/report/${value}` : null },
  /*
   * The officer's own positions stopped reaching the control room (M22 §1.1): the server's `tracking:watch`, once per
   * gap, or this phone finding itself signed out mid-shift. It opens Location sharing, which says what happened —
   * and bringing the app forward is also what starts sharing again.
   */
  tracking_gap: () => '/tracking-consent',
}

const needs = (path: (id: string) => string) => (p: Payload) => { const value = id(p.id); return value ? path(value) : null }

const RESIDENT: Record<ResidentType, (p: Payload) => string | null> = {
  approval_request: needs((v) => `/visitors/approval/${v}`),
  visitor_admitted: needs((v) => `/visitors/pass/${v}`),
  panic_update: () => '/panic',
  notice: needs((v) => `/notices/${v}`),
  ticket_update: needs((v) => `/maintenance/${v}`),
  payment_recorded: () => '/(tabs)/dues',
  dues_reminder: () => '/(tabs)/dues',
  meeting_reminder: needs((v) => `/meetings/${v}`),
  election_open: needs((v) => `/elections/${v}/ballot-a`),
  election_results: needs((v) => `/elections/${v}/results`),
  booking_decided: () => '/amenities/bookings',
  claim_approved: () => '/(tabs)',
  // Gemini Security answered on Live chat (M50 §4.3): the account's one conversation, so no id is needed to open it.
  support_reply: () => '/settings/chat',
  // A Helper or a Dependent came in or went out on their own e-Pass (8 Oct 2026): the household, on Profile.
  household_entry: () => '/(tabs)/profile',
}

/** The screen a notification opens — or null, and then the app simply opens where it was. */
export function linkFor(app: AppKind, payload: Payload | null | undefined): string | null {
  if (!payload || typeof payload.type !== 'string') return null
  const table = (app === 'guard' ? GUARD : RESIDENT) as Record<string, (p: Payload) => string | null>
  const make = Object.prototype.hasOwnProperty.call(table, payload.type) ? table[payload.type] : undefined
  return make ? make(payload) : null
}

/** Which of this phone's notification preferences governs a type (see each app's prefs). `null` = cannot be switched off. */
export const CATEGORY: Record<NotificationType, string | null> = {
  site_alert: null,
  site_ring: null,
  ring_stop: null,
  duress_update: null,
  alertness_check: null,
  message: 'messages',
  broadcast: 'messages',
  order_published: 'orders',
  shift_changed: 'shifts',
  shift_open: 'shifts',
  claim_decided: 'shifts',
  request_decided: 'requests',
  payslip_ready: 'payslips',
  // Site safety, not a preference: a guard on duty cannot switch off what a resident says is happening now.
  resident_report: null,
  // Nor what their own screen was saying untruly: "location shared" while nothing was recorded (M22 §1.1).
  tracking_gap: null,
  approval_request: null,
  visitor_admitted: 'visitors',
  panic_update: null,
  notice: 'notices',
  ticket_update: 'maintenance',
  payment_recorded: 'payments',
  dues_reminder: 'payments',
  meeting_reminder: 'meetings',
  election_open: 'voting',
  election_results: 'voting',
  booking_decided: 'bookings',
  claim_approved: null,
  // The answer to the person's own question: no switch holds it back.
  support_reply: null,
  // Who came through the gate on the household's word: the Visitors switch governs it, as a visitor's arrival.
  household_entry: 'visitors',
}

/**
 * The types that ring on the loud "Safety alerts" channel at high priority, whatever is muted.
 * The server keeps the same list (`App\Services\Push\PushTypes`); its test reads THIS file and fails if they differ.
 */
export const URGENT: readonly NotificationType[] = ['site_alert', 'site_ring', 'duress_update', 'alertness_check', 'approval_request', 'panic_update']

/**
 * The two Android channels every phone has, and then one per switchable category of its app.
 *
 * A CHANNEL ID IS FOREVER. Android will not let an app change a channel it already created, so these
 * ids are settled before the first release and only ever ADDED to. They are also what the server puts
 * on a push (`App\Services\Push\PushTypes`), which its own test holds to this file — an id changed on
 * one side alone sends the push to a channel the phone never made, and Android drops it to the app's
 * default channel without a sound. `runtime.ts` explains what "safety" can and cannot promise.
 */
export const SAFETY_CHANNEL = 'safety'
export const GENERAL_CHANNEL = 'general'

/**
 * WHICH APP ASKS ANDROID TO LET ITS SAFETY CHANNEL THROUGH DO NOT DISTURB — split by app. This is a
 * one-line table on purpose: it is the whole of the ruling, and it is the ONE part of a channel that
 * cannot be corrected in a later release.
 *
 *   guard     TRUE. An employer-issued handset. A duress alarm — the officer's own, another officer's,
 *             or a resident's panic at the site they are standing on — must not be silenced by a Focus
 *             or bed-time setting the phone turned on by itself. Android honours the flag only while
 *             the app holds ACCESS_NOTIFICATION_POLICY; the guard app declares that permission and asks
 *             for it in context, behind a flag (`flags.askForDndAccess`). Until it is granted Android
 *             drops the flag silently — which is why `runtime.ts` reads the channel BACK and the app
 *             never claims the bypass it has not got.
 *   resident  FALSE, and the permission is not declared or requested anywhere in that app. A resident
 *             silencing their own phone is their own choice, and there is no workforce-safety argument
 *             to make to a reviewer for taking control of a homeowner's Do Not Disturb.
 *
 * The id does NOT change with it. `safety` is what the SERVER puts on every urgent push
 * (`App\Services\Push\PushTypes::SAFETY`), and an id changed on one side alone sends every panic to a
 * channel the handset never created. When this was ruled, no build had been installed; that is no longer
 * true — release APKs from 23, 24 and 25 September 2026 are on client phones — so `safety` is now FIXED as
 * those phones created it, and nothing may assume it can be changed. That is why the call-style ring does
 * not touch it: it rings on channels of its own, created natively (apps/guard/modules/guard-ring,
 * `RingChannels`), which are never in `channelsFor` below.
 */
export const DND_BYPASS: Record<AppKind, boolean> = { guard: true, resident: false }

export type Channel = { id: string; name: string; urgent: boolean; bypassDnd: boolean }

const CHANNEL_NAMES: Record<string, string> = {
  messages: 'Messages',
  orders: 'Standing orders',
  shifts: 'Shifts & roster',
  requests: 'Leave & equipment',
  payslips: 'Payslips',
  visitors: 'Visitor arrivals',
  notices: 'Estate notices',
  maintenance: 'Maintenance updates',
  payments: 'Payments',
  meetings: 'Meetings',
  voting: 'Voting',
  bookings: 'Bookings',
}

const TYPES_OF: Record<AppKind, readonly string[]> = { guard: Object.keys(GUARD), resident: Object.keys(RESIDENT) }

/**
 * The Android channel a type is shown on — and the `channelId` the SERVER puts on a push for it, so a
 * remote push and the local notifier land on the same channel with the same sound and importance.
 * An unknown type (a newer server) falls to "Updates": shown, quietly, never dropped.
 */
export function channelFor(type: unknown): string {
  if (typeof type !== 'string' || !Object.prototype.hasOwnProperty.call(CATEGORY, type)) return GENERAL_CHANNEL
  if (URGENT.includes(type as NotificationType)) return SAFETY_CHANNEL
  return CATEGORY[type as NotificationType] ?? GENERAL_CHANNEL
}

/** Every channel this app's phone creates at start-up: safety, general, and one per category it can switch off. */
export function channelsFor(app: AppKind): Channel[] {
  const categories = [...new Set(TYPES_OF[app].map((type) => CATEGORY[type as NotificationType]).filter((c): c is string => c !== null))]
  return [
    { id: SAFETY_CHANNEL, name: 'Safety alerts', urgent: true, bypassDnd: DND_BYPASS[app] },
    { id: GENERAL_CHANNEL, name: 'Updates', urgent: false, bypassDnd: false },
    ...categories.map((id) => ({ id, name: CHANNEL_NAMES[id] ?? id, urgent: false, bypassDnd: false })),
  ]
}

/** The categories this app's phone may switch off — what the server is told as `muted` when one is off. */
export function mutableCategories(app: AppKind): string[] {
  return channelsFor(app).filter((c) => c.id !== SAFETY_CHANNEL && c.id !== GENERAL_CHANNEL).map((c) => c.id)
}

/** The short links each app publishes (its app.config.ts names them) → the screen they mean. */
const ALIASES: Record<AppKind, [RegExp, string][]> = {
  guard: [],
  resident: [
    [/^\/pass\/([A-Za-z0-9_-]{1,40})$/, '/visitors/pass/$1'],
    [/^\/ticket\/([A-Za-z0-9_-]{1,40})$/, '/maintenance/$1'],
    [/^\/election\/([A-Za-z0-9_-]{1,40})$/, '/elections/$1/ballot-a'],
  ],
}

/**
 * What the OS handed the app (gs://pass/41 · gsguard://leave?tab=open · in Expo Go exp://host:port/--/leave)
 * → the in-app path to open, or "/" when it is anything else. Never throws: a bad link opens Home, not a crash.
 */
export function resolveIncoming(app: AppKind, raw: string | null | undefined): string {
  try {
    if (!raw) return '/'
    const marker = raw.indexOf('/--/')
    const clean = sanitisePath(marker >= 0 ? raw.slice(marker + 3) : raw)
    if (!clean) return '/'
    const [path = '/', query] = clean.split('?')
    for (const [pattern, target] of ALIASES[app]) if (pattern.test(path)) return path.replace(pattern, target)
    return query ? `${path}?${query}` : path
  } catch {
    return '/'
  }
}

/**
 * An incoming scheme link (gsguard://leave?tab=open): keep it only if it is a plain in-app path —
 * segments of letters, digits, dashes, brackets-free — with a short, flat query. Anything else opens Home.
 */
export function sanitisePath(input: string | null | undefined): string | null {
  if (!input) return null
  const stripped = input.replace(/^[a-z][a-z0-9+.-]*:\/\/\/?/i, '/').replace(/^\/+/, '/')
  const [path = '', query = ''] = stripped.split('#')[0]!.split('?')
  if (path.includes('..') || path.includes('//') || path.length > 120) return null
  const segments = path.split('/').filter(Boolean)
  if (!segments.every((seg) => /^[A-Za-z0-9_-]{1,40}$/.test(seg) || /^\([a-z]+\)$/.test(seg))) return null
  const pairs = query ? query.split('&') : []
  if (pairs.length > 4 || !pairs.every((pair) => /^[a-z_]{1,20}=[A-Za-z0-9_-]{0,40}$/.test(pair))) return null
  return `/${segments.join('/')}${pairs.length ? `?${pairs.join('&')}` : ''}`
}
