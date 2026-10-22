/**
 * Settings — boards resident-app-18 · -19 · -20 · -33 · -38 · -39.
 *
 * THE SERVER HAS LITTLE OF THIS. There is no endpoint for notification preferences, a PIN or password, a data
 * download, consents, quiet hours, email or text-message alerts, live chat or a language. So every control on
 * these screens is one of two things, and never a third:
 *
 *   REAL             a choice this phone keeps and the app reads (prefs.ts, the notifier's categories), the
 *                    PHONE's own answer (permissions, its screen lock), the server's own (the signed-in phones),
 *                    or an act that really happens (sign out, clear the saved copies, write to the company).
 *   NOT YET          drawn as the client's board draws it (M42 round 2: "no more, no less"), off, and a tap
 *                    says plainly that it is not available yet — it never claims something happened.
 *
 * READS  `me.sessions` (the phones signed in to this account), cached, so Security opens with no connection.
 * WRITES `me.sessions.end` — signing one of the other phones out, asked first and online only. Changing one's
 *        own details is the Profile tab's job (`me.update` through the queue) and is not repeated here.
 *
 * HARD RULES HELD HERE
 *   - "Call Gemini Security" exists only while the console has a number for it (the user, 8 Oct 2026): no placeholder,
 *     no button that rings nothing. The emergency numbers are the panic screen's, never behind anything.
 *   - Panic updates and "someone at the gate" cannot be switched off (features/notify/prefs locks them;
 *     Notifications draws them as the board's locked "Urgent alerts").
 *   - The app lock is the OS prompt: a yes or a no from the phone. Nothing biometric is read, kept or sent.
 *   - "Clear data" never touches what only this phone has (the queue, drafts, an alert in progress).
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { AppState, Linking, Platform, useWindowDimensions } from 'react-native'
import { router } from 'expo-router'
import * as Application from 'expo-application'
import Constants from 'expo-constants'
import * as Device from 'expo-device'
import { Image } from 'expo-image'
import * as LocalAuthentication from 'expo-local-authentication'
import * as Location from 'expo-location'
import { SUPPORT_CONTACT, clockNameOf, estateClockExplained, fidelityScreenId, getStorage, isExpoGo, isFidelity, legalAddress, now, openLegal, openSupportEmail, readingAnotherClock, sinceWords, siteParts, useConfirmFlow, useOfflineCache, useSiteZone } from '@gs/core'
import { notificationPermission } from '@gs/core/notify'
import { baseApi, downloadFile } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { canUseFingerprint, forgetKey, handsetId, keepKey, phoneName } from '@/features/auth/fingerprint'
import { closeSession, lastEstate } from '@/features/auth/session'
import { CATEGORIES, OFFERED_CATEGORIES, notificationPrefs, quietHours, type Category } from '@/features/notify/prefs'
import { useChatRowLine } from '@/features/support/useLiveChat'
import { useSupportLine } from '@/features/support/useSupportLine'
import { drain } from '@/offline/runtime'
import { helpFixture, languageFixture, notificationsFixture, privacyFixture, securityFixture, settingsFixture } from '@/fidelity/settings'
import { useAppDispatch, useAppSelector } from '@/store'
import { settingsPrefs, stepTextSize, useSettingsPrefs, useSystemReduceMotion, wired, TEXT_SIZES, type TextSize } from './prefs'
import { DRAFTS_KEY, TOPICS, canAskPhone, draftsIn, signOutWarning, clearWarning, clearedSentence, copiesIn, lastActiveLine, lockName, permissionOf, permissionTapHint, phoneLabel, phoneRow, searchHelp, telLink, versionLine, waitingIn, type PermissionAnswer, type PhonePermission, type SignedInPhone, type Topic } from './settingsLogic'

export type { PhonePermission } from './settingsLogic'

export const onBoard = (id: string): boolean => isFidelity && fidelityScreenId() === id

const appVersion = (): string => versionLine(Constants.expoConfig?.version ?? Application.nativeApplicationVersion, Application.nativeBuildVersion)

/** Whether the screen is still there when the phone finally answers. */
function useAlive() {
  const alive = useRef(true)
  useEffect(() => {
    alive.current = true
    return () => { alive.current = false }
  }, [])
  return alive
}

/**
 * The phone's answers change OUTSIDE the app — a permission allowed in the phone's Settings, a screen lock
 * set up there — so whatever shows one asks again when the resident comes back. Never in fidelity mode.
 */
function useOnReturn(look: () => void): void {
  useEffect(() => {
    if (isFidelity || Platform.OS === 'web') return
    const sub = AppState.addEventListener('change', (state) => { if (state === 'active') look() })
    return () => sub.remove()
  }, [look])
}

/* ---- signing out of this phone ------------------------------------------------------------------ */

export type SignOutModel = {
  asking: boolean
  working: boolean
  /** Why signing out is refused right now, or null. */
  blocked: string | null
  unsent: number
  /** What is said before anything goes — and what the button is called. */
  warning: { lines: string[]; confirmLabel: string }
  online: boolean
  /** Signing out did not finish — said in the sheet, never swallowed. */
  problem: string | null
  ask: () => void
  dismiss: () => void
  confirm: () => void
}

const ALERT_UNSENT = 'An emergency alert from this phone has not reached the estate yet. Signing out would delete it: stay signed in until it has been sent.'
const SIGN_OUT_UNFINISHED = 'Signing out did not finish, so you are still signed in on this phone. Try again.'

/**
 * Real: the key that keeps this phone signed in, the saved copies and the queue all go (`closeSession`).
 * So whatever is still waiting is given one last chance to be sent, then COUNTED for the resident, who
 * decides — in a ConfirmSheet. An emergency alert that has not reached the estate yet blocks it outright.
 * It runs on confirmFlow: signed out once however fast it is pressed, and a failure stays in the sheet.
 */
export function useSignOut(): SignOutModel {
  const dispatch = useAppDispatch()
  const online = useAppSelector((s) => s.network.online)
  const ops = useAppSelector((s) => s.outbox.ops)

  const waiting = ops.filter((op) => op.state === 'pending' || op.state === 'syncing')
  const alertUnsent = waiting.some((op) => op.endpoint === 'duress.store')

  const flow = useConfirmFlow<true>({
    send: async () => {
      if (alertUnsent) return { ok: false, message: ALERT_UNSENT }
      if (isFidelity) return { ok: false, message: SIGN_OUT_UNFINISHED }
      try {
        await drain().catch(() => undefined)
        await closeSession(dispatch)
        settingsPrefs.reset()
        router.replace('/(auth)/log-in' as never)
        return { ok: true }
      } catch {
        return { ok: false, message: SIGN_OUT_UNFINISHED }
      }
    },
    failed: SIGN_OUT_UNFINISHED,
  })

  const blocked = alertUnsent ? ALERT_UNSENT : null
  // Read when the question is on the screen, so the count is the one that is about to be deleted.
  const drafts = flow.open ? draftsIn(getStorage().getString(DRAFTS_KEY)) : 0

  return {
    asking: flow.open,
    working: flow.working,
    blocked,
    unsent: waiting.length,
    warning: signOutWarning({ unsent: waiting.length, drafts, online, blocked: blocked !== null }),
    online,
    problem: flow.problem,
    ask: () => {
      if (isFidelity) return
      flow.ask(true)
      void drain().catch(() => undefined)
    },
    dismiss: flow.cancel,
    confirm: flow.confirm,
  }
}

/* ---- 18 · Settings ------------------------------------------------------------------------------ */

/** The board's own "Version 2.4.1", for its fidelity measurement only. */
const BOARD_VERSION = settingsFixture.sections.flatMap((section) => [...section.rows]).find((row) => row.title === 'About GeminiSecure')?.detail ?? ''

export type SettingsHomeModel = {
  /** "Version 2.4.1" — this phone's own version, in the board's words (About GeminiSecure's line). */
  version: string
  /** What About GeminiSecure says when tapped: the app and its full version, build included. */
  about: string
  signOut: SignOutModel
}

export function useSettingsHome(): SettingsHomeModel {
  const board = onBoard('resident-app-18')
  return {
    version: board ? BOARD_VERSION : versionLine(Constants.expoConfig?.version ?? Application.nativeApplicationVersion),
    about: `GeminiSecure Resident · ${appVersion()}`,
    signOut: useSignOut(),
  }
}

/* ---- 19 · Security ------------------------------------------------------------------------------ */

type LockSupport =
  | { state: 'checking' }
  | { state: 'unavailable'; why: string }
  | { state: 'ready'; name: string; note: string | null }

/** One signed-in phone as board 19 draws it: its name, then "This device" or "Last active 2 days ago". */
export type SessionLine = { id: number | string; name: string; line: string; current: boolean; canSignOut: boolean }

export type SecurityModel = {
  /*
   * NO "CHANGE PIN" (M50 §4.4, the lead's decision): there is no resident PIN anywhere — no column, no endpoint, nothing
   * in the master reference, nothing in the app that uses one; the board drew it only because it draws a password, and
   * there is no password. The row is gone, and its card with it.
   */
  /**
   * Face ID / fingerprint: the app lock, the phone's own prompt (`toggle`). `unavailable` is why it cannot be switched
   * ON here (no screen lock on the phone, a browser) — said when the switch is tapped; OFF is always allowed.
   */
  lock: { on: boolean; busy: boolean; problem: string | null; unavailable: string | null; toggle: (next: boolean) => void }
  /**
   * Require biometrics for payments (board 19, drawn ON; M50 §3.3): the phone confirms its owner before any card payment
   * above $5,000 (paymentsLock.ts). On until the resident turns it off — and turning it off is confirmed too.
   */
  payments: { on: boolean; busy: boolean; toggle: (next: boolean) => void }
  /**
   * Every phone signed in to this account (me.sessions, 26 Sep), this one first — this phone alone while the list has
   * never been read. Signing one of the OTHERS out is online only, and asked first, in a sheet.
   */
  sessions: SessionLine[]
  ending: { asking: boolean; working: boolean; problem: string | null; name: string | null; online: boolean; ask: (row: SessionLine) => void; confirm: () => void; dismiss: () => void }
  /** "Sign out all other phones" (the client, 27 Sep) — asked in a sheet, online only. Board 19 draws no such button. */
  endAll: { offered: boolean; count: number; asking: boolean; working: boolean; problem: string | null; ask: () => void; confirm: () => void; dismiss: () => void }
}

const NOT_A_FAILURE: readonly string[] = ['user_cancel', 'system_cancel', 'app_cancel']

/** Board 19's "This device · Spanish Town, JM", up to its dot: the place after it is data the server does not have. */
const THIS_DEVICE = 'This device'

async function readLockSupport(): Promise<LockSupport> {
  if (Platform.OS === 'web') return { state: 'unavailable', why: 'A browser has no screen lock to ask. This works in the phone app.' }
  if (!wired.appLock) return { state: 'unavailable', why: 'The lock screen is not part of this build of the app yet, so there is nothing to switch on.' }
  try {
    const level = await LocalAuthentication.getEnrolledLevelAsync()
    if (level === LocalAuthentication.SecurityLevel.NONE) return { state: 'unavailable', why: "This phone has no screen lock. Set up a fingerprint, face unlock or passcode in the phone's Settings first." }
    const types = await LocalAuthentication.supportedAuthenticationTypesAsync()
    const biometric = (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync())
    const name = lockName(biometric ? types : [], Platform.OS)
    // Expo Go carries no Face ID permission of its own: the phone asks for its passcode there instead.
    const faceInPreview = isExpoGo && Platform.OS === 'ios' && biometric && types.includes(LocalAuthentication.AuthenticationType.FACIAL_RECOGNITION)
    return { state: 'ready', name: faceInPreview ? "your phone's passcode" : name, note: faceInPreview ? 'Face ID cannot be used inside this preview app (Expo Go): the phone asks for its passcode instead. The installed app uses Face ID.' : null }
  } catch {
    return { state: 'unavailable', why: "The phone did not say whether it has a screen lock. Try again, or check the phone's Settings." }
  }
}

export function useSecurity(): SecurityModel {
  const board = onBoard('resident-app-19')
  const { appLock, paymentsLock } = useSettingsPrefs()
  const [support, setSupport] = useState<LockSupport>({ state: 'checking' })
  const [busy, setBusy] = useState(false)
  const [paymentsBusy, setPaymentsBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const online = useAppSelector((s) => s.network.online)
  const principal = useAppSelector((s) => s.session.principal)
  const [storeKey] = residentApi.useMeDeviceKeyStoreMutation()
  const [destroyKey] = residentApi.useMeDeviceKeyDestroyMutation()

  const alive = useAlive()
  const look = useCallback(() => {
    if (isFidelity) return
    void readLockSupport().then((s) => { if (alive.current) setSupport(s) })
  }, [alive])
  useEffect(look, [look])
  // A screen lock set up (or removed) in the phone's Settings is seen on the way back, not on the next visit.
  useOnReturn(look)

  /*
   * "FACE ID / FINGERPRINT — Use biometrics to log in and confirm payments" (board 19; M50 §3). ON is two things at once:
   * the app lock while signed in, and a KEY for this phone that signs it back in after a sign-out or a timed-out session
   * with no emailed code (auth/fingerprint.ts). ON needs a connection (the server issues the key) and the phone's own
   * confirmation; OFF revokes the key on the server and deletes it here. Either way the answer is said under the card.
   */
  const fingerprintOn = useCallback(async (): Promise<void> => {
    if (!online) return setProblem('Turning on fingerprint sign-in needs a connection. Nothing was changed.')
    if (!canUseFingerprint()) return setProblem("This phone has no fingerprint or face unlock set up. Add one in the phone's Settings first.")
    const estate = lastEstate.get()
    if (!estate || !principal) return setProblem('Fingerprint sign-in could not be set up just now. Sign out and in once, then try again.')
    // iOS keeps the key without asking, so the phone confirms first; Android's store asks as it keeps it.
    if (Platform.OS === 'ios') {
      const asked = await LocalAuthentication.authenticateAsync({ promptMessage: 'Confirm it is you to turn on fingerprint sign-in', cancelLabel: 'Cancel', disableDeviceFallback: false })
      if (!asked.success) return void (NOT_A_FAILURE.includes(asked.error) ? undefined : setProblem('The phone did not confirm it was you, so fingerprint sign-in stays off.'))
    }
    const name = phoneName()
    const hardware = await handsetId()
    const issued = await storeKey({ platform: Platform.OS === 'ios' ? 'ios' : 'android', ...(name ? { device_name: name } : {}), ...(hardware ? { hardware_id: hardware } : {}) })
    if ('error' in issued || !issued.data?.key) {
      const e = 'error' in issued ? (issued.error as { code?: string; message?: string }) : null
      // A 404 is a server without fingerprint sign-in yet (M57 §5.3): said as that, with what to do.
      return setProblem(
        e?.code === 'not_found'
          ? "Your estate's server cannot turn on fingerprint sign-in yet. Nothing was changed; try again after the next update."
          : (e?.message ?? 'Fingerprint sign-in could not be turned on just now. Try again.'),
      )
    }
    const kept = await keepKey(issued.data.key, { estate: estate.id, estateName: estate.name ?? principal.siteName ?? null, accountId: principal.id, accountName: principal.name || null, email: null })
    if (!kept) {
      // Issued but not kept (the prompt was cancelled): the key stops working on the server too.
      void destroyKey()
      return setProblem('The phone did not confirm it was you, so fingerprint sign-in stays off.')
    }
    settingsPrefs.set({ appLock: true })
  }, [online, principal, storeKey, destroyKey])

  const fingerprintOff = useCallback(async (): Promise<void> => {
    const asked = await LocalAuthentication.authenticateAsync({ promptMessage: 'Confirm it is you to turn off fingerprint sign-in', cancelLabel: 'Cancel', disableDeviceFallback: false })
    if (!asked.success) return void (NOT_A_FAILURE.includes(asked.error) ? undefined : setProblem('The phone did not confirm it was you, so fingerprint sign-in stays on.'))
    // Best effort on the server (no connection: the key is forgotten here, and nothing on this phone can use it).
    if (online) void destroyKey()
    await forgetKey()
    settingsPrefs.set({ appLock: false })
  }, [online, destroyKey])

  const toggle = useCallback(
    async (next: boolean) => {
      // Until the phone has answered, nothing is switched — least of all OFF without being confirmed.
      if (isFidelity || busy || support.state === 'checking') return
      setProblem(null)
      // The phone's lock was removed after the switch was turned on: nothing can confirm, so OFF is simply allowed.
      if (support.state !== 'ready') {
        if (!next) {
          await forgetKey()
          settingsPrefs.set({ appLock: false })
        } else setProblem(support.why)
        return
      }
      setBusy(true)
      try {
        await (next ? fingerprintOn() : fingerprintOff())
      } catch {
        setProblem('The phone could not ask just now. Nothing was changed.')
      } finally {
        setBusy(false)
      }
    },
    [busy, support, fingerprintOn, fingerprintOff],
  )

  /** Require biometrics for payments: ON at once; OFF only with the owner's confirmation — it is the protection itself. */
  const togglePayments = useCallback(
    async (next: boolean) => {
      if (isFidelity || paymentsBusy) return
      setProblem(null)
      if (next || support.state !== 'ready') return void settingsPrefs.set({ paymentsLock: next })
      setPaymentsBusy(true)
      try {
        const asked = await LocalAuthentication.authenticateAsync({ promptMessage: 'Confirm it is you to stop payments asking', cancelLabel: 'Cancel', disableDeviceFallback: false })
        if (asked.success) settingsPrefs.set({ paymentsLock: false })
        else if (!NOT_A_FAILURE.includes(asked.error)) setProblem('The phone did not confirm it was you, so payments above $5,000 still ask.')
      } catch {
        setProblem('The phone could not ask just now. Nothing was changed.')
      } finally {
        setPaymentsBusy(false)
      }
    },
    [paymentsBusy, support.state],
  )

  /*
   * EVERY PROMPT ABOVE IS THE OS PROMPT ONLY: a yes or a no — never a fingerprint, a face or a template.
   *
   * `disableDeviceFallback: false` IS CORRECT HERE, AND IS NOT THE GUARD APP'S RULE. Do not "fix" it to match
   * `identityPrompt` in the guard app, which closes the passcode fallback on every guard identity check: there the
   * EMPLOYER asks "is this the person rostered at this post?", and a passcode a colleague can be told down a telephone
   * defeats the control. Here a resident protects THEIR OWN account on THEIR OWN phone; the phone's own passcode is a
   * legitimate answer (PHONE_CHECK.md, DEV_BUILD_REQUIRED.md), and closing it would lock a resident out of their own
   * app the first time a wet thumb failed.
   */

  /*
   * THE OTHER PHONES (the client, 26 Sep: two phones on one account, each seeing only itself). Read from the server
   * and kept for the next time the screen opens with no connection; signing one out needs a connection and is asked
   * first, in a sheet. A yes closes the sheet and the list is read again, without the phone.
   */
  const sessions =residentApi.useMeSessionsQuery(undefined, { skip: isFidelity || board })
  const savedSessions = useOfflineCache('me.sessions', sessions.data)
  const [endSession] = residentApi.useMeSessionsEndMutation()
  const day = useCallback((iso: string) => sinceWords(iso, now()), [])
  const nowMs = now().getTime()
  // In the board's words: "This device" (the board's place after it is data the server does not have), or "Last active 2 days ago".
  const listed: SessionLine[] | null = savedSessions
    ? (savedSessions.items as SignedInPhone[]).map((p) => {
        const row = phoneRow(p, nowMs, day, Device.modelName)
        return { id: row.id, name: row.name, line: row.current ? THIS_DEVICE : lastActiveLine(p.last_used_at, nowMs), current: row.current, canSignOut: row.canSignOut }
      })
    : null
  const phones: SessionLine[] = board
    ? securityFixture.sessions.map((s, i) => ({ id: i, name: s.name, line: s.detail, current: s.current, canSignOut: !s.current }))
    : listed ?? [{ id: 'this', name: Device.modelName ? phoneLabel(Device.modelName) : 'This phone', line: THIS_DEVICE, current: true, canSignOut: false }]

  const ending = useConfirmFlow<SessionLine>({
    send: async (row) => {
      if (!online) return { ok: false, message: 'Signing another phone out needs a connection. Nothing was changed.' }
      const result = await endSession({ session: row.id as number })
      if ('error' in result) {
        const e = result.error as { status?: unknown; message?: string }
        // Already gone (signed out from it, or by the office): the list is simply out of date.
        if (e.status === 404) {
          void sessions.refetch()
          return { ok: true }
        }
        return { ok: false, message: e.message ?? 'That phone could not be signed out just now. Try again.' }
      }
      void sessions.refetch()
      return { ok: true }
    },
    failed: 'That phone could not be signed out just now. Try again.',
  })

  /*
   * EVERY OTHER PHONE AT ONCE (the client, 27 Sep: "add the button sign out all devices"). Asked first, in a sheet, and
   * online only, like signing one out; this phone stays signed in. Board 19 draws no such button, so the rebuilt
   * screen does not offer it (M42 round 2) — each other phone keeps its own Sign out. Kept here, wired, for the lead.
   */
  const [endOthers] = residentApi.useMeSessionsEndOthersMutation()
  const others = phones.filter((p) => !p.current)
  const endingAll = useConfirmFlow<true>({
    send: async () => {
      if (!online) return { ok: false, message: 'Signing your other phones out needs a connection. Nothing was changed.' }
      const result = await endOthers({})
      if ('error' in result) {
        const e = result.error as { message?: string }
        return { ok: false, message: e.message ?? 'Your other phones could not be signed out just now. Try again.' }
      }
      void sessions.refetch()
      return { ok: true }
    },
    failed: 'Your other phones could not be signed out just now. Try again.',
  })

  return {
    lock: {
      on: board || appLock,
      busy,
      problem,
      unavailable: support.state === 'unavailable' ? support.why : null,
      toggle: (next) => void toggle(next),
    },
    payments: { on: board || paymentsLock, busy: paymentsBusy, toggle: (next) => void togglePayments(next) },
    sessions: phones,
    ending: {
      asking: ending.open,
      working: ending.working,
      problem: ending.problem,
      name: ending.target?.name ?? null,
      online,
      ask: ending.ask,
      confirm: ending.confirm,
      dismiss: ending.cancel,
    },
    endAll: {
      offered: !board && others.length > 0,
      count: others.length,
      asking: endingAll.open,
      working: endingAll.working,
      problem: endingAll.problem,
      ask: () => endingAll.ask(true),
      confirm: endingAll.confirm,
      dismiss: endingAll.cancel,
    },
  }
}

/* ---- 20 · Notifications ------------------------------------------------------------------------- */

type Answer = PermissionAnswer
type NotificationsModule = { getPermissionsAsync: () => Promise<Answer>; requestPermissionsAsync: () => Promise<Answer> }

/** Through the one door in @gs/core/notify — the package's index throws at import inside Expo Go on Android and is never imported here. */
function notificationsModule(): NotificationsModule | null {
  if (Platform.OS === 'web') return null
  const answer = async (ask: boolean): Promise<Answer> => {
    const p = await notificationPermission(ask)
    if (!p) throw new Error('the phone gave no answer')
    return p
  }
  return { getPermissionsAsync: () => answer(false), requestPermissionsAsync: () => answer(true) }
}

/** `null` until the phone has answered: a row never says "Not asked" (or anything else) before it knows. */
type Known = PhonePermission | null
const START: Known = Platform.OS === 'web' ? 'unsupported' : null

const openPhoneSettings = () => void Linking.openSettings().catch(() => undefined)

/**
 * The board's five categories, each over the notifier's own (features/notify/prefs): a switch here sets every one it
 * covers. "Urgent alerts" covers the two that can never be switched off — a panic alert's updates and someone at the
 * gate — so it is drawn locked, as the board draws it. Payment notifications are on no row of the board: they stay as
 * they are.
 */
const BY_CATEGORY: readonly { key: string; title: string; line: string; covers: readonly Category[] }[] = [
  { key: 'urgent', title: 'Urgent alerts', line: 'Panic, emergencies: always on for safety', covers: ['panic', 'gate'] },
  { key: 'security', title: 'Security & access', line: 'Visitor entries, gate activity', covers: ['visitors'] },
  { key: 'maintenance', title: 'Maintenance', line: 'Ticket updates, utility notices', covers: ['maintenance'] },
  { key: 'events', title: 'Events', line: 'Amenity bookings, community events', covers: ['bookings'] },
  { key: 'governance', title: 'Governance', line: 'Meetings, elections, notices', covers: ['meetings', 'voting', 'notices'] },
]

/** One switch row of board 20. */
export type NotificationSwitch = {
  key: string
  title: string
  /** The board's sub-line, word for word — null where the board draws none (the channels). */
  line: string | null
  on: boolean
  /** On, and it cannot be switched off. */
  locked: boolean
  /** Nothing is behind this switch yet: it is drawn as the board draws it, off, and a tap says so, plainly. */
  notYet: string | null
  /** What a tap does — null when locked, or when there is nothing behind it. */
  set: ((on: boolean) => void) | null
}

export type NotificationSettingsModel = {
  categories: NotificationSwitch[]
  channels: NotificationSwitch[]
  quiet: NotificationSwitch
  /** Why the last switch did not change — said in the screen's own sheet, never the phone's dialog. */
  problem: { title: string; line: string } | null
  clearProblem: () => void
}

/** The categories this phone has switched off — what an email must not carry either. */
const mutedNow = (): string[] => {
  const prefs = notificationPrefs.get() as Record<string, boolean>
  return OFFERED_CATEGORIES.filter((cat) => !cat.locked && prefs[cat.key] === false).map((cat) => cat.key)
}

const EMAIL_NEEDS_CONNECTION = 'Email can be switched on or off only with a connection. Try again when you are connected.'

export function useNotificationSettings(): NotificationSettingsModel {
  const board = onBoard('resident-app-20')
  const [chosen, setChosen] = useState(() => notificationPrefs.get())
  const [permission, setPermission] = useState<Known>(START)
  const [asking, setAsking] = useState(false)
  const [quietOn, setQuietOn] = useState(() => quietHours.get())
  const [problem, setProblem] = useState<{ title: string; line: string } | null>(null)
  const alive = useAlive()
  const online = useAppSelector((s) => s.network.online)

  /*
   * EMAIL is the ACCOUNT's switch, and the server's (`me.notifications.email`): an email reaches the person whatever
   * phone they hold. What is drawn is the server's last answer; a change is sent at once, online only, and drawn only
   * once the server has it. The categories switched off go with it, so an email never carries what a push would not.
   */
  const email = residentApi.useMeNotificationsEmailShowQuery(undefined, { skip: board || isFidelity })
  const [saveEmail, emailSave] = residentApi.useMeNotificationsEmailUpdateMutation()
  const emailOn = !!email.data?.on
  const sendEmail = useCallback(async (on: boolean) => {
    if (!online) return setProblem({ title: 'Email', line: EMAIL_NEEDS_CONNECTION })
    const answer = await saveEmail({ on, muted: mutedNow() })
    if ('error' in answer && answer.error && alive.current) {
      const e = answer.error as { code?: string; message?: string }
      // A 404 is a server without email notifications yet (M57 §5.3): said as that, with what to do.
      setProblem({
        title: 'Email',
        line: e.code === 'not_found'
          ? "Your estate's server cannot change email notifications yet. Nothing was changed; try again after the next update."
          : typeof e.message === 'string' && e.message !== '' ? e.message : EMAIL_NEEDS_CONNECTION,
      })
    }
    if (alive.current) void email.refetch()
  }, [online, saveEmail, alive, email])

  const read = useCallback(async () => {
    if (isFidelity) return
    const mod = notificationsModule()
    const answer = mod ? await mod.getPermissionsAsync().catch(() => null) : null
    if (alive.current) setPermission(permissionOf(answer))
  }, [alive])
  useEffect(() => void read(), [read])
  // Allowed (or blocked) in the phone's Settings a moment ago: the row is right when the resident comes back.
  useOnReturn(read)

  const allow = useCallback(async () => {
    const mod = notificationsModule()
    if (!mod || !permission || asking) return
    if (!canAskPhone(permission)) return openPhoneSettings()
    setAsking(true)
    try {
      const answer = await mod.requestPermissionsAsync().catch(() => null)
      if (alive.current && answer) setPermission(permissionOf(answer))
    } finally {
      if (alive.current) setAsking(false)
    }
  }, [alive, asking, permission])

  // The board's own switches, in its fidelity measurement only.
  const drawn = (title: string, rows: readonly { title: string; state: string }[]) => {
    const state = rows.find((row) => row.title === title)?.state
    return { on: state !== 'off', locked: state === 'locked' }
  }

  // A category for a feature this build has switched off is not offered at all (notify/prefs.ts).
  const offered = new Set<Category>(OFFERED_CATEGORIES.map((cat) => cat.key))
  const lockedKeys = new Set<Category>(CATEGORIES.filter((cat) => cat.locked).map((cat) => cat.key))
  const categories = BY_CATEGORY.map((row): NotificationSwitch => {
    const covers = row.covers.filter((key) => offered.has(key))
    const locked = covers.length > 0 && covers.every((key) => lockedKeys.has(key))
    if (board) return { key: row.key, title: row.title, line: row.line, ...drawn(row.title, notificationsFixture.categories), notYet: null, set: null }
    return {
      key: row.key,
      title: row.title,
      line: row.line,
      on: locked || (covers.length > 0 && covers.every((key) => chosen[key])),
      locked,
      notYet: null,
      set: locked || covers.length === 0 ? null : (on) => {
        let next = chosen
        for (const key of covers) next = notificationPrefs.set(key, on)
        setChosen(next)
        // Email on: the server learns what is off now, so an email never carries it either.
        if (emailOn && online) void saveEmail({ on: true, muted: mutedNow() })
      },
    }
  })

  /*
   * THE CHANNELS. Push is the phone's own answer: on when the phone allows this app to notify; a tap asks the phone
   * while it still will, and otherwise opens the phone's Settings — the only place it can be changed. Email is the
   * account's, kept by the server (above). SMS is not drawn: no text-message provider is connected (M50 §4.2), and it
   * comes back when one is.
   */
  const channel = (key: string, title: string, live: Pick<NotificationSwitch, 'on' | 'notYet' | 'set'>): NotificationSwitch =>
    board ? { key, title, line: null, ...drawn(title, notificationsFixture.channels), notYet: null, set: null } : { key, title, line: null, locked: false, ...live }
  const channels = [
    channel('push', 'Push notifications', {
      on: permission === 'granted',
      notYet: permission === 'unsupported' ? 'Notifications are set up in the phone app, not in a browser.' : null,
      set: permission === 'unsupported' ? null : () => void allow(),
    }),
    channel('email', 'Email', {
      on: emailSave.isLoading ? !!emailSave.originalArgs?.on : emailOn,
      notYet: null,
      set: (on) => void sendEmail(on),
    }),
  ]

  /*
   * QUIET HOURS (M50 §4.2 #5): the board's hours, 10:00 PM – 7:00 AM on the estate's clock, kept on this phone and sent
   * with its push registration (features/notify/prefs). Inside them the server pushes nothing to this phone but the
   * urgent alerts, and the notifier shows nothing else while the app is open — so "Urgent alerts still come through"
   * is exactly what happens. The hours are the board's and are not edited here.
   */
  const quiet: NotificationSwitch = {
    key: 'quiet',
    title: notificationsFixture.quiet.title,
    line: notificationsFixture.quiet.detail,
    on: board ? notificationsFixture.quiet.state === 'on' : quietOn,
    locked: false,
    notYet: null,
    set: board ? null : (on) => setQuietOn(quietHours.set(on)),
  }

  return { categories, channels, quiet, problem, clearProblem: () => setProblem(null) }
}

/* ---- 33 · Privacy & data ------------------------------------------------------------------------ */

/**
 * One row of board 33's "Your consents". `status` is the board's pill — "Granted" or "Off" — or null while the phone
 * has not answered yet (never a guess). `tap` says what a tap does; null means the row is a FACT with nothing to open
 * (drawn as plain text, never a dead control).
 */
export type ConsentRow = { key: 'lock' | 'location' | 'marketing'; title: string; line: string; status: string | null; granted: boolean; tap: string | null }

const CLEAR_UNFINISHED = 'Clearing did not finish, so some saved copies may still be on this phone. Try again.'

/** Why "Download my data" could not hand the file over on this phone — never that it does not exist. */
export const DOWNLOAD_NOT_SAVED = `This phone could not save the file. Try again, or write to ${SUPPORT_CONTACT.email} and a copy will be sent to you.`

/** "GeminiSecure-my-data-2026-10-08.pdf" — the server's own name for it, on the estate's calendar. */
export function myDataFileName(at: Date): string {
  const p = siteParts(at)
  return `GeminiSecure-my-data-${p.year}-${String(p.month + 1).padStart(2, '0')}-${String(p.day).padStart(2, '0')}.pdf`
}

type Sharing = { isAvailableAsync: () => Promise<boolean>; shareAsync: (url: string, options?: { mimeType?: string; dialogTitle?: string; UTI?: string }) => Promise<void> }

/** The phone's share sheet, lazily and never on web: a build without it is "could not be saved", not a crash. */
function sharingModule(): Sharing | null {
  if (Platform.OS === 'web') return null
  try {
    const mod = require('expo-sharing') as Partial<Sharing> | undefined
    return mod && typeof mod.shareAsync === 'function' && typeof mod.isAvailableAsync === 'function' ? (mod as Sharing) : null
  } catch {
    return null
  }
}

/** The board's two words for a consent. */
const GRANTED = 'Granted'
const OFF = 'Off'

export function usePrivacy() {
  const board = onBoard('resident-app-33')
  const dispatch = useAppDispatch()
  const online = useAppSelector((s) => s.network.online)
  const waiting = useAppSelector((s) => waitingIn(s.outbox.ops))
  const { appLock } = useSettingsPrefs()
  const [done, setDone] = useState<string | null>(null)
  const [location, setLocation] = useState<Known>(START)
  const askingPhone = useRef(false)
  const alive = useAlive()

  const look = useCallback(() => {
    if (isFidelity || Platform.OS === 'web') return
    void Location.getForegroundPermissionsAsync().then((answer) => { if (alive.current) setLocation(permissionOf(answer)) }).catch(() => { if (alive.current) setLocation('unsupported') })
  }, [alive])
  useEffect(look, [look])
  // Changed in the phone's Settings a moment ago: the pills are right when the resident comes back.
  useOnReturn(look)

  /** A tap on a consent: the app lock opens Security; location ASKS while the phone will still ask, else opens the one place it can be changed. */
  const openPermission = useCallback(
    async (key: ConsentRow['key']) => {
      if (isFidelity || key === 'marketing') return
      if (key === 'lock') return router.push('/settings/security' as never)
      if (!location || location === 'unsupported' || askingPhone.current) return
      if (!canAskPhone(location)) return openPhoneSettings()
      askingPhone.current = true
      try {
        const answer = await Location.requestForegroundPermissionsAsync().catch(() => null)
        if (alive.current && answer) setLocation(permissionOf(answer))
      } finally {
        askingPhone.current = false
      }
    },
    [alive, location],
  )

  /*
   * DOWNLOAD MY DATA (M50 §4.3): the server's PDF of everything it holds about this person (`me.data_export`), fetched
   * fresh with this phone's token and handed to the share sheet — Save to Files, Drive, email. One at a time; what went
   * wrong is said in the screen's own sheet, in the server's words where it gave some.
   */
  const [downloading, setDownloading] = useState(false)
  const [downloadProblem, setDownloadProblem] = useState<string | null>(null)
  const fetching = useRef(false)
  const downloadMyData = useCallback(async () => {
    if (isFidelity || fetching.current) return
    fetching.current = true
    setDownloading(true)
    setDownloadProblem(null)
    try {
      const got = await downloadFile({ path: '/me/data-export', folder: 'my-data', name: myDataFileName(now()), online, fresh: true })
      if (!got.ok) return void (alive.current && setDownloadProblem(got.why === 'unsupported' ? DOWNLOAD_NOT_SAVED : got.error.message))
      const sheet = sharingModule()
      if (!sheet || !(await sheet.isAvailableAsync().catch(() => false))) return void (alive.current && setDownloadProblem(DOWNLOAD_NOT_SAVED))
      await sheet.shareAsync(got.uri, { mimeType: 'application/pdf', dialogTitle: 'Your data in GeminiSecure', UTI: 'com.adobe.pdf' })
    } catch {
      if (alive.current) setDownloadProblem(DOWNLOAD_NOT_SAVED)
    } finally {
      fetching.current = false
      if (alive.current) setDownloading(false)
    }
  }, [alive, online])

  // Asked in a ConfirmSheet, done once; it closes when the copies are gone, and a failure stays in the sheet.
  const clear = useConfirmFlow<true>({
    send: async () => {
      if (isFidelity) return { ok: false, message: CLEAR_UNFINISHED }
      const storage = getStorage()
      // ONLY copies of what the server already has. The queue, drafts, an alert in progress, who is
      // signed in and the choices on these screens are under other keys, and are never listed here.
      const doomed = copiesIn(storage.keys())
      for (const key of doomed) storage.remove(key)
      await storage.flush()
      try {
        await Image.clearMemoryCache()
        await Image.clearDiskCache()
      } catch {
        /* no image cache on this platform */
      }
      // What is held in memory goes too; open screens ask again when there is a connection.
      dispatch(baseApi.util.resetApiState())
      setDone(clearedSentence({ removed: doomed.length, waiting, online }))
      return { ok: true }
    },
    failed: CLEAR_UNFINISHED,
  })

  /*
   * YOUR CONSENTS, in the board's words and its two pills. Neither the app lock nor the phone keeps WHEN a consent was
   * given, so the board's "Granted Jun 12, 2026" is said without its date. The app lock is "Biometric login" — the
   * phone's own lock, on this phone only (Security switches it). Location keeps its own words (the user, 7 October:
   * location text says what the app does — an incident report pins the place by itself, M42 §5 #15). The estate sends
   * no marketing at all, so that row is a plain fact: Off.
   */
  const lockStatus = board || appLock ? GRANTED : OFF
  const locationStatus = board || location === 'granted' ? GRANTED : location === null ? null : OFF
  const consents: ConsentRow[] = [
    { key: 'lock', title: 'Biometric login', line: board ? privacyFixture.consents[0].detail : `${lockStatus} · this device only`, status: lockStatus, granted: lockStatus === GRANTED, tap: 'Opens Security' },
    { key: 'location', title: 'Location for alerts and reports', line: 'Only while you use the app · sent with an alert or an incident report, never otherwise', status: locationStatus, granted: locationStatus === GRANTED, tap: location ? permissionTapHint(location) : null },
    { key: 'marketing', title: 'Marketing communications', line: 'Estate newsletters and partner offers', status: OFF, granted: false, tap: null },
  ]

  return {
    waiting,
    consents,
    openPermission: (key: ConsentRow['key']) => void openPermission(key),
    /** "Download my data": the PDF of everything held about this person, to the share sheet; `problem` when it could not be. */
    download: {
      run: () => void downloadMyData(),
      busy: downloading,
      problem: downloadProblem,
      clearProblem: () => setDownloadProblem(null),
    },
    /** Opens the Privacy Policy — it says how long each record is kept. Null when it opened; otherwise what to say. */
    openPolicy: async (): Promise<string | null> => ((await openLegal('privacy')) ? null : `This phone could not open it. In any browser, go to ${legalAddress('privacy')}`),
    clearing: {
      asking: clear.open,
      working: clear.working,
      problem: clear.problem,
      done,
      warning: clearWarning({ waiting, online }),
      ask: () => { if (!isFidelity) { setDone(null); clear.ask(true) } },
      dismiss: clear.cancel,
      confirm: clear.confirm,
    },
  }
}

/* ---- 38 · Language & accessibility -------------------------------------------------------------- */

/**
 * Board 38, rebuilt to the board (M50 §6.5): Language, Text size, Display (High contrast · Reduce motion) and the
 * screen-reader card — no paragraphs. Every choice is kept on this phone and applied at once by the root layout.
 * The board's words are languageFixture's; the board's moment swaps in only where the thumb sits.
 */
export function useAccessibility() {
  const board = onBoard('resident-app-38')
  const chosen = useSettingsPrefs()

  const at = TEXT_SIZES.findIndex((s) => s.key === chosen.textSize)
  const setSize = (size: TextSize) => settingsPrefs.set({ textSize: size })

  return {
    words: languageFixture,
    size: chosen.textSize,
    sizeLabel: TEXT_SIZES[at]?.label ?? 'Standard',
    sizes: TEXT_SIZES,
    /** 0–1 along the track. */
    fraction: at <= 0 ? 0 : at / (TEXT_SIZES.length - 1),
    /** Where the board draws the thumb (its own 52% of the track) — null on a phone, where the chosen step places it. */
    thumbLeft: board ? languageFixture.thumbLeft : null,
    // The board's moment is a size in the middle (that 52%), so both of its "A"s are live.
    canShrink: board || at > 0,
    canGrow: board || at < TEXT_SIZES.length - 1,
    smaller: () => setSize(stepTextSize(chosen.textSize, -1)),
    larger: () => setSize(stepTextSize(chosen.textSize, 1)),
    setSize,
    reduceMotion: chosen.reduceMotion,
    setReduceMotion: (on: boolean) => settingsPrefs.set({ reduceMotion: on }),
    highContrast: chosen.highContrast,
    setHighContrast: (on: boolean) => settingsPrefs.set({ highContrast: on }),
  }
}

/* ---- 39 · Help & support ------------------------------------------------------------------------ */

/** Board 39's four "Popular topics", in its order — the same four the help on this phone opens with. */
const POPULAR: readonly string[] = ['visitors', 'dues', 'elections', 'panic']

export type HelpModel = {
  query: string
  setQuery: (text: string) => void
  searching: boolean
  /** Not searching: the board's four popular topics. Searching: every topic with an article that mentions each word. */
  topics: Topic[]
  openTopic: string | null
  toggleTopic: (key: string) => void
  openArticle: string | null
  toggleArticle: (key: string) => void
  /**
   * The three ways to get in touch, as board 39 words them: "Call Gemini Security", the company's address, live chat.
   * `call` is null while the console has no number for Gemini Security (the user, 8 Oct 2026) — and then there is no
   * call row at all: no placeholder number, no button that rings nothing.
   */
  contacts: { call: string | null; email: string; chat: string }
  /** Calls Gemini Security's line. Null when the call opened; otherwise what to say. */
  callSupport: () => Promise<string | null>
  /** Writing to the company. Null when the mail app opened; otherwise the address, in words. */
  emailSupport: () => Promise<string | null>
  /** "Start a report": the same mail, its subject the app and this phone — never who, where, or anything from the account. */
  startReport: () => Promise<string | null>
  /** Live chat with Gemini Security's control room (M50 §4.3, features/support). */
  openChat: () => void
}

export function useHelp(): HelpModel {
  const board = onBoard('resident-app-39')
  const [query, setQuery] = useState('')
  const [openTopic, setOpenTopic] = useState<string | null>(null)
  const [openArticle, setOpenArticle] = useState<string | null>(null)

  const searching = query.trim().length > 0
  const topics = useMemo(() => (searching ? searchHelp(query) : TOPICS.filter((topic) => POPULAR.includes(topic.key))), [query, searching])
  const version = appVersion()
  // What to quote about a fault: the app and the phone — never who, where, or anything from the account.
  const details = [`GeminiSecure Resident · ${version}`, Device.modelName ?? (Platform.OS === 'web' ? 'Web browser' : null), [Device.osName ?? (Platform.OS === 'web' ? null : Platform.OS), Device.osVersion].filter(Boolean).join(' ') || null].filter(Boolean).join(' · ')

  // Gemini Security's line as the console set it — none yet, and the row is not drawn (useSupportLine).
  const line = useSupportLine()
  const callSupport = useCallback(
    () => (line.phone ? Linking.openURL(telLink(line.phone)).then(() => null, () => `This device can't place the call. Dial ${line.display ?? line.phone} from any phone.`) : Promise.resolve(null)),
    [line.phone, line.display],
  )

  /*
   * Writing to the company. No dead control: a phone with no mail app is told the address in words rather than given
   * a button that does nothing, which is the same rule LegalLinks follows.
   */
  const noMail = `This phone has no mail app set up. Write to ${SUPPORT_CONTACT.email} from any device.`
  const emailSupport = useCallback(async () => ((await openSupportEmail(`GeminiSecure Resident · ${version}`)) ? null : noMail), [noMail, version])
  const startReport = useCallback(async () => ((await openSupportEmail(`Report a problem · ${details}`)) ? null : `${noMail} Say which app and phone this is: ${details}.`), [details, noMail])

  // The board's own sample contacts, in its fidelity measurement only.
  const sample = (glyph: string) => helpFixture.contacts.find((row) => row.icon === glyph)?.title ?? ''
  // "Live chat · typically replies in N min" once Gemini Security's speed is measured; who answers until then.
  const chatLine = useChatRowLine()

  return {
    query,
    setQuery,
    searching,
    topics,
    openTopic,
    toggleTopic: (key) => setOpenTopic((now) => (now === key ? null : key)),
    openArticle,
    toggleArticle: (key) => setOpenArticle((now) => (now === key ? null : key)),
    contacts: board
      ? { call: sample('call-btn'), email: sample('input-5'), chat: sample('tab-messages') }
      : { call: line.phone ? `Call ${SUPPORT_CONTACT.company}` : null, email: SUPPORT_CONTACT.email, chat: chatLine },
    callSupport,
    emailSupport,
    startReport,
    openChat: () => { if (!isFidelity) router.push('/settings/chat' as never) },
  }
}
