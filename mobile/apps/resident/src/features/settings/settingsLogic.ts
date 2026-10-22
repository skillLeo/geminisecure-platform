/**
 * The settings screens' words and rules — pure, so they can be held to account by a test.
 *
 *   HELP        what THIS app really does, in a homeowner's words. It is on the phone, so it is there with
 *               no signal. Every answer is checked against the code: if the behaviour changes, so does it.
 *   EMERGENCY   119 police · 110 fire and ambulance. They are data with no condition attached — nothing in
 *               this file (or the screen) can produce a disabled emergency number.
 *   CLEARING    "Clear data saved on this phone" removes COPIES of what the server already has. It never
 *               touches what only this phone has: things waiting to be sent, an invitation still in draft,
 *               an emergency alert in progress, who is signed in, or the choices made on these screens.
 */

import { flags } from '@gs/core/lib'

export type HelpIcon = 'help-icon-2' | 'tab-card' | 'tab-profile' | 'help-icon' | 'offline-note'
export type Article = { q: string; a: string }
export type Topic = { key: string; icon: HelpIcon; title: string; articles: Article[] }

export const TOPICS: Topic[] = [
  {
    key: 'visitors',
    icon: 'help-icon-2',
    title: 'Visitors & gate access',
    articles: [
      { q: 'How does my visitor get in?', a: 'Invite them from the Visitors tab and share the pass. The guard scans its QR code at the gate. Your passes are saved on this phone, so you can show or share one again even with no connection.' },
      { q: 'Can I invite someone with no connection?', a: 'You can write the invitation, but it stays a DRAFT: a pass has to be signed by the estate\'s server before a guard will accept it, so your guest cannot enter on a draft. The app sends it by itself as soon as the phone has signal, even with the app closed, and "Send now" on the draft sends it at once.' },
      { q: 'A guard is asking me to approve someone', a: 'That is a visitor with no pass. You have about two minutes to answer, and it needs a connection: a late answer counts as no answer, so it is never saved for later. If time runs out the estate\'s own policy applies. With no connection, phone the gatehouse.' },
      { q: 'I cancelled a pass with no signal', a: 'The cancellation is saved on this phone and sent when you reconnect. The gate only learns of it then: if it matters right now, phone the gatehouse as well.' },
    ],
  },
  {
    key: 'dues',
    icon: 'tab-card',
    title: 'Dues & payments',
    articles: [
      { q: 'How do I pay my dues?', a: 'Dues are paid by hand on this estate, not through the app. Open Dues, choose the charge and tap "How to pay": the app shows the amount still owed, the reference to quote and the estate\'s instructions. The app itself never takes a payment.' },
      { q: 'Why can\'t I add a card or turn on AutoPay?', a: 'The estate has no card gateway, so the app will not ask for card details it could do nothing with. AutoPay asks the estate\'s server and shows you its answer in its own words.' },
      { q: 'I paid. Why does my balance look the same?', a: 'The balance changes when the estate office records your payment. It then appears in your payment history with a receipt. If it has been a few working days, contact the office with your reference.' },
      { q: 'Is the balance right when I am offline?', a: 'With no connection the app shows the last statement saved on this phone, with the time it was saved. It may have changed since: reconnect and pull down to refresh before relying on it.' },
    ],
  },
  {
    key: 'elections',
    icon: 'tab-profile',
    title: 'Elections & voting',
    articles: [
      { q: 'Can I vote with no connection?', a: 'No. A ballot is sent once, live, and is never saved to be sent later: a vote nobody can confirm was counted once is worse than no vote. Find a connection, then cast it.' },
      { q: 'Is my vote secret?', a: 'Yes. Your household\'s paper is stored with nothing that links it to you. That is also why the receipt only says your paper is in: there is no code that could look your choices up, by anyone.' },
      { q: 'The app says it does not know if my vote arrived', a: 'The connection dropped while it was being sent. The app will not send it twice. It asks the server whether your household\'s paper is in: if it is, you see the receipt; if not, you mark a fresh paper.' },
    ],
  },
  {
    key: 'panic',
    icon: 'help-icon',
    title: 'Using the panic button',
    articles: [
      { q: 'How do I send an emergency alert?', a: 'Tap the panic button on Home, then HOLD the alert button until it fills: a single tap sends nothing. Your gatehouse and the control room are alerted at once, and the screen shows when someone acknowledges and responds.' },
      { q: 'Do I have to say what kind of emergency it is?', a: 'No. If you can, tap Medical, Fire, Security or Other before you hold the button, and the responders see it. If you do not, the alert goes to exactly the same people. Choosing Medical or Fire does not call an ambulance or the fire service: for those, call 110.' },
      { q: 'Are my emergency contacts told?', a: 'Not automatically: your emergency contacts are not texted for you, so call them. If they have a phone number on your household, the alert screen can open a text to them from your own phone, already written: you press send, and it goes like any other text.' },
      { q: 'What if I have no signal?', a: 'The alert is saved and sent the moment any signal returns, and the screen tells you plainly that it has NOT been received yet. Call 119 (police) or 110 (fire and ambulance): a phone call needs no data.' },
      { q: 'I pressed it by mistake', a: 'You can cancel within ten seconds, before anyone acknowledges, and you must give a reason. After that, tell the responder: the alert stays on record.' },
      { q: 'Can I turn emergency notifications off?', a: 'Not in this app. Updates to an emergency alert, and a guard asking you to approve a visitor, have no switch here: safety never depends on a setting you could turn off by accident. They do still need notifications allowed for GeminiSecure in your phone\'s own settings.' },
    ],
  },
  {
    key: 'offline',
    icon: 'offline-note',
    title: 'Using the app with no connection',
    articles: [
      { q: 'What works offline?', a: 'Your passes and their QR codes, notices, your statement, and your household: as last saved on this phone. Maintenance requests, security reports, changes to your household and cancelling a pass are saved on this phone and sent when you reconnect.' },
      // Bookings ship switched off for the pilot (flags.amenityBookings): an answer naming an
      // act this build cannot perform is help about somebody else's app.
      { q: 'What needs a connection?', a: `Signing in, inviting a visitor, answering the gate, ${flags.amenityBookings ? 'booking an amenity, ' : ''}voting, and asking how to pay a charge. The app says so on the screen rather than pretending.` },
      { q: 'How do I know something was sent?', a: 'It shows "Pending sync" while it waits on this phone, and "Not accepted" with the estate\'s reason if it was refused. When the label is gone, the estate has it.' },
    ],
  },
]

const fold = (text: string) => text.toLowerCase().normalize('NFKD').replace(/[^a-z0-9 ]+/g, ' ')

/** Topics narrowed to the articles that mention every word typed. An empty search is everything. */
export function searchHelp(query: string, topics: Topic[] = TOPICS): Topic[] {
  const words = fold(query).split(' ').filter(Boolean)
  if (words.length === 0) return topics
  return topics
    .map((t) => ({ ...t, articles: t.articles.filter((art) => words.every((w) => fold(`${t.title} ${art.q} ${art.a}`).includes(w))) }))
    .filter((t) => t.articles.length > 0)
}

/** Jamaica's emergency numbers. No field here says "enabled": there is no state in which they are not. */
export const EMERGENCY = [
  { key: 'police', label: 'Police', number: '119' },
  { key: 'fire', label: 'Fire & ambulance', number: '110' },
] as const

export const telLink = (number: string): string => `tel:${number.replace(/[^\d+]/g, '')}`

/* ---- clearing what is saved on this phone ------------------------------------------------------- */

/** A copy of a server answer: `v1:cache:<endpoint>`. Nothing else is a copy. */
const COPY = /^v\d+:cache:/

/** Of every key in the phone's store, the ones "Clear data" removes. */
export const copiesIn = (keys: readonly string[]): string[] => keys.filter((key) => COPY.test(key))

/** What each saved copy is, in the resident's words — the first that matches (M12 §2.11, "Saved copies" opens). */
const COPY_NAMES: readonly [RegExp, string][] = [
  [/^passes\.epass/, 'Your e-Pass'],
  [/^passes\./, 'Your visitor passes'],
  [/^notices\./, 'Estate notices'],
  [/^(dues|invoices|payments|households)\./, 'Your dues and statement'],
  [/^me\.household$/, 'Your household and balance'],
  [/^(members|vehicles|contacts)\./, 'Household members, vehicles and emergency contacts'],
  [/^panic\.contacts$/, 'Who your alert can text'],
  [/^tickets\./, 'Your requests and reports'],
  [/^(meetings|elections)\./, 'Meetings and votes'],
  [/^(amenities|bookings)\./, 'Amenities and your bookings'],
  [/^me\.sessions$/, 'Your signed-in phones'],
  [/^me\.show$/, 'Your account'],
  [/^estates\./, 'The list of estates'],
]

export type SavedCopy = { label: string; bytes: number; copies: number }

/**
 * The copies on this phone, by what they are — the same keys "Clear data" removes, named, with their size. No
 * "saved at": the copies do not keep one, and the app does not guess.
 */
export function savedCopies(entries: readonly { key: string; bytes: number }[]): SavedCopy[] {
  const byLabel = new Map<string, SavedCopy>()
  for (const { key, bytes } of entries) {
    if (!COPY.test(key)) continue
    const name = key.replace(COPY, '')
    const label = COPY_NAMES.find(([pattern]) => pattern.test(name))?.[1] ?? 'Other screens'
    const row = byLabel.get(label) ?? { label, bytes: 0, copies: 0 }
    byLabel.set(label, { label, bytes: row.bytes + Math.max(0, bytes), copies: row.copies + 1 })
  }
  const order = [...COPY_NAMES.map(([, label]) => label), 'Other screens']
  return [...byLabel.values()].sort((a, b) => order.indexOf(a.label) - order.indexOf(b.label))
}

/** "3 KB", "under 1 KB", "1.2 MB". */
export function sizeWords(bytes: number): string {
  if (bytes < 1024) return 'under 1 KB'
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export type WaitingOp = { state: string }

/** Things the resident did with no connection that the estate does not have yet. */
export const waitingIn = (ops: readonly WaitingOp[]): number => ops.filter((op) => op.state === 'pending' || op.state === 'syncing').length

const things = (n: number) => (n === 1 ? '1 thing you saved with no connection is' : `${n} things you saved with no connection are`)

/** What the resident is told BEFORE anything is cleared. */
export function clearWarning(input: { waiting: number; online: boolean }): { title: string; lines: string[] } {
  const lines = ['This removes the copies of your passes, notices, statement and household that the app keeps so it can open with no connection. Nothing is removed from the estate\'s records, and you stay signed in.']
  if (input.waiting > 0) lines.push(`${things(input.waiting)} still waiting to be sent. They are NOT cleared: they stay on this phone until the estate has them.`)
  if (!input.online) lines.push('You have no connection right now. If you clear now, your passes\' QR codes and your lists will be EMPTY until you reconnect. It is safer to do this when you are online.')
  return { title: input.online ? 'Clear the data saved on this phone?' : 'Clear it with no connection?', lines }
}

export function clearedSentence(input: { removed: number; waiting: number; online: boolean }): string {
  const kept = input.waiting > 0 ? ` ${things(input.waiting)} still waiting to be sent, untouched.` : ''
  const next = input.online ? 'The app fetches fresh copies as you open each screen.' : 'Reconnect to fetch fresh copies.'
  return `${input.removed === 0 ? 'There was nothing saved to clear.' : 'Cleared.'} ${next}${kept}`
}

/* ---- signing out of this phone ------------------------------------------------------------------ */

/** Invitations written with no connection live under this key (features/visitors) — copied here, not imported. */
export const DRAFTS_KEY = 'v1:passes.drafts'

/** How many invitation drafts the stored text holds. Anything unreadable counts as none — it never blocks a sign-out. */
export function draftsIn(raw: string | null | undefined): number {
  if (!raw) return 0
  try {
    const parsed: unknown = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed.length : 0
  } catch {
    return 0
  }
}

/**
 * What the resident is told BEFORE signing out: everything on this phone goes, and what ONLY this phone has —
 * things still waiting to be sent, invitations still in draft — is counted out loud, because it cannot come back.
 */
export function signOutWarning(input: { unsent: number; drafts: number; online: boolean; blocked: boolean }): { lines: string[]; confirmLabel: string } {
  const lines = ['Everything the app saved on this phone is removed. You will need a new sign-in code to come back.']
  if (input.unsent > 0) lines.push(`${input.unsent === 1 ? '1 thing you saved with no connection has' : `${input.unsent} things you saved with no connection have`} NOT been sent yet${input.online ? '' : ': you are offline'}. Signing out deletes ${input.unsent === 1 ? 'it' : 'them'}.`)
  if (input.drafts > 0) lines.push(`${input.drafts === 1 ? '1 invitation is still a draft' : `${input.drafts} invitations are still drafts`} on this phone: no pass was made for ${input.drafts === 1 ? 'it' : 'them'}. Signing out deletes ${input.drafts === 1 ? 'it' : 'them'} too.`)
  const losing = input.unsent + input.drafts
  return { lines, confirmLabel: losing > 0 && !input.blocked ? `Sign out and delete ${losing === 1 ? 'it' : 'them'}` : 'Sign out' }
}

/* ---- who this is, and which version ------------------------------------------------------------- */

/** "Signed in with a one-time code sent to a•••@example.com" — the server's own mask, never the address. */
export function signInLine(account: { channel?: string | null; destination_hint?: string | null } | null | undefined): string {
  if (!account?.destination_hint) return 'You sign in with a one-time code sent to your email address.'
  return `You sign in with a one-time code sent to ${account.destination_hint}${account.channel === 'sms' ? ' by text message' : ''}.`
}

export const versionLine = (version: string | null | undefined, build?: string | null): string => (version ? `Version ${version}${build ? ` (${build})` : ''}` : 'Version not reported by this build')

/* ---- the phones signed in to this account (me.sessions, 26 Sep) ------------------------------------- */

export type SignedInPhone = { id: number | string; device: string | null; platform: string | null; signed_in_at: string | null; last_used_at: string | null; current: boolean; timed_out: boolean }
export type PhoneRow = { id: number | string; name: string; detail: string; current: boolean; canSignOut: boolean }

/** "5 min ago", "3 hours ago", or the date — how long since a phone was last used. */
export function sinceShort(iso: string | null, nowMs: number, day: (iso: string) => string): string | null {
  if (!iso) return null
  const minutes = Math.max(0, Math.round((nowMs - new Date(iso).getTime()) / 60_000))
  if (minutes < 1) return 'just now'
  if (minutes < 60) return `${minutes} min ago`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`
  return day(iso)
}

/** "Last active 2 days ago" — board 19's words for when another signed-in phone was last used. */
export function lastActiveLine(iso: string | null, nowMs: number): string {
  const at = iso ? new Date(iso).getTime() : Number.NaN
  if (Number.isNaN(at)) return 'Last active a while ago'
  const minutes = Math.max(0, Math.floor((nowMs - at) / 60_000))
  if (minutes < 1) return 'Last active just now'
  if (minutes < 60) return `Last active ${minutes} min ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `Last active ${hours} ${hours === 1 ? 'hour' : 'hours'} ago`
  const days = Math.floor(hours / 24)
  return `Last active ${days} ${days === 1 ? 'day' : 'days'} ago`
}

/**
 * A phone's model code as a person reads it (the user, M51-A §3: "Samsung Galaxy S25 Ultra", not `SM-S938B`). Android
 * reports its model code, and Samsung's carry a region letter after the model, so the code's stem is matched. A code not
 * listed is kept as the phone gave it — a guessed name would be worse than the code. An emulator says it is one.
 */
const SAMSUNG: ReadonlyArray<readonly [RegExp, string]> = [
  [/^SM-S938/i, 'Samsung Galaxy S25 Ultra'], [/^SM-S936/i, 'Samsung Galaxy S25+'], [/^SM-S931/i, 'Samsung Galaxy S25'],
  [/^SM-S928/i, 'Samsung Galaxy S24 Ultra'], [/^SM-S926/i, 'Samsung Galaxy S24+'], [/^SM-S921/i, 'Samsung Galaxy S24'],
  [/^SM-S918/i, 'Samsung Galaxy S23 Ultra'], [/^SM-S916/i, 'Samsung Galaxy S23+'], [/^SM-S911/i, 'Samsung Galaxy S23'],
  [/^SM-S908/i, 'Samsung Galaxy S22 Ultra'], [/^SM-S906/i, 'Samsung Galaxy S22+'], [/^SM-S901/i, 'Samsung Galaxy S22'],
  [/^SM-G998/i, 'Samsung Galaxy S21 Ultra'], [/^SM-G996/i, 'Samsung Galaxy S21+'], [/^SM-G991/i, 'Samsung Galaxy S21'],
  [/^SM-G990/i, 'Samsung Galaxy S21 FE'], [/^SM-A566/i, 'Samsung Galaxy A56'], [/^SM-A556/i, 'Samsung Galaxy A55'],
  [/^SM-A546/i, 'Samsung Galaxy A54'], [/^SM-A356/i, 'Samsung Galaxy A35'], [/^SM-A256/i, 'Samsung Galaxy A25'],
  [/^SM-A156/i, 'Samsung Galaxy A15 5G'], [/^SM-A155/i, 'Samsung Galaxy A15'], [/^SM-F956/i, 'Samsung Galaxy Z Fold6'],
  [/^SM-F741/i, 'Samsung Galaxy Z Flip6'],
]

export function phoneLabel(device: string): string {
  const code = device.trim()
  if (/^(sdk_gphone|sdk_phone|google_sdk|Android SDK built for)/i.test(code) || /emulator/i.test(code)) return 'An Android emulator'
  return SAMSUNG.find(([stem]) => stem.test(code))?.[1] ?? code
}

/**
 * One phone as the Security screen lists it. The name is what the phone called itself at sign-in, read as a person
 * reads it (`phoneLabel`); an older sign-in that carried none is "An iPhone", "An Android phone", or "A phone". This
 * phone first, and never with a Sign out: that is "Sign out of this phone", which also clears what the app keeps here.
 */
export function phoneRow(phone: SignedInPhone, nowMs: number, day: (iso: string) => string, thisModel?: string | null): PhoneRow {
  const given = phone.device?.trim() || (phone.current && thisModel ? thisModel : '')
  const named = given ? phoneLabel(given) : ''
  const name = named || (phone.platform === 'ios' ? 'An iPhone' : phone.platform === 'android' ? 'An Android phone' : 'A phone')
  const signedIn = phone.signed_in_at ? `Signed in ${day(phone.signed_in_at)}` : 'Signed in'
  // M12 §2.10: this phone says when it signed in too — and that it is the one in use.
  if (phone.current) return { id: phone.id, name, detail: `This phone · ${phone.signed_in_at ? `signed in ${day(phone.signed_in_at)}` : 'signed in'} · in use now`, current: true, canSignOut: false }
  const since = sinceShort(phone.last_used_at, nowMs, day)
  const detail = phone.timed_out
    ? `${signedIn} · last used ${since ?? 'a while ago'} · signed out after a quiet spell, but still gets your notifications until you sign it out here`
    : `${signedIn} · last used ${since ?? 'recently'}`
  return { id: phone.id, name, detail, current: false, canSignOut: true }
}

/* ---- the app lock: WHEN the cover comes down ---------------------------------------------------- */

export type LockStatus = 'booting' | 'signedOut' | 'pending' | 'active'

/** A minute or more in the background locks the app again. */
export const AWAY_MS = 60_000

/**
 * What a change of session status does to the lock. `true` lock · `false` unlock · `null` leave it alone.
 *
 *   booting → anything   THE COLD START. The phone's storage is read behind the splash, so the choice can only
 *                        be trusted once booting is over — asking any earlier reads "off" and the app never
 *                        locks. Someone already signed in has to show the phone is theirs.
 *   signedOut → …        a sign-in DURING this launch: a fresh one-time code is stronger proof than the phone's
 *                        lock, so the resident is not asked twice.
 */
export function lockOnStatus(was: LockStatus, status: LockStatus, chosen: boolean): boolean | null {
  if (was === status) return null
  if (was === 'booting') return status !== 'signedOut' && chosen
  if (was === 'signedOut') return false
  return null
}

/** Coming back to the app: lock again only if it was really left (`leftAt`), for long enough, with the lock chosen. */
export const lockOnReturn = (leftAt: number | null, nowMs: number, chosen: boolean): boolean => chosen && leftAt !== null && nowMs - leftAt >= AWAY_MS

/* ---- the phone's own permissions ---------------------------------------------------------------- */

/**
 *   granted      allowed — a tap opens the phone's Settings, the only place it can be taken back
 *   notAsked     the phone has never been asked — a tap ASKS
 *   refused      refused, but the phone lets the app ask again — a tap ASKS
 *   blocked      only the phone's Settings can change it — a tap opens them
 *   unsupported  nothing to ask here (a browser) — not a control at all
 */
export type PhonePermission = 'granted' | 'notAsked' | 'refused' | 'blocked' | 'unsupported'
export type PermissionAnswer = { granted: boolean; canAskAgain: boolean; status?: string }

/** "Not asked" is only said when the PHONE says it has never been asked — a refusal is not called that. */
export function permissionOf(answer: PermissionAnswer | null | undefined): PhonePermission {
  if (!answer) return 'unsupported'
  if (answer.granted) return 'granted'
  if (!answer.canAskAgain) return 'blocked'
  return answer.status === 'undetermined' ? 'notAsked' : 'refused'
}

export const PERMISSION_WORD: Record<PhonePermission, string> = { granted: 'Allowed', notAsked: 'Not asked', refused: 'Not allowed', blocked: 'Blocked', unsupported: 'Phone only' }

export const canAskPhone = (p: PhonePermission): boolean => p === 'notAsked' || p === 'refused'

/** What a tap on the row does — said to a screen reader, and true. Null: the row is not a control. */
export const permissionTapHint = (p: PhonePermission): string | null => (p === 'unsupported' ? null : canAskPhone(p) ? 'Asks the phone to allow it' : "Opens the phone's settings for this app")

/** What the phone calls its own lock, in words a homeowner uses. `types` are expo-local-authentication's numbers. */
export function lockName(types: readonly number[], os: string): string {
  const face = types.includes(2)
  const finger = types.includes(1)
  if (os === 'ios') return face ? 'Face ID' : finger ? 'Touch ID' : 'your phone\'s passcode'
  if (face && finger) return 'fingerprint or face unlock'
  return face ? 'face unlock' : finger ? 'fingerprint' : 'your phone\'s screen lock'
}
