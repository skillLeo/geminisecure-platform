/**
 * The notification runtime both apps share — the only file that touches expo-notifications.
 *
 *   configureNotifications(app)  the foreground handler and the Android channels — "Safety alerts",
 *                     "Updates", and one per category the app can switch off. The ids are the ones the
 *                     SERVER puts on a push (links.ts `channelFor`), so a remote push and a local banner
 *                     for the same thing sound and rank the same.
 *   safetyChannelDnd(app)  whether a duress or panic alert really does sound through Do Not Disturb on
 *                     this phone — read back from Android, never assumed. See the channel block below.
 *   show()            a LOCAL notification for something the app learned while it was running. Works in
 *                     Expo Go. Honours this phone's preferences (the caller passes `allowed`), never
 *                     shows the same `key` twice, never repeats what a remote push already showed, and
 *                     never asks for permission by itself — asking is a screen's job (Permissions,
 *                     Notification settings), at a moment that makes sense.
 *   onOpen()          a tap on a notification — local or a push opened from the lock screen — → the route
 *                     from links.ts (type + id → path). A payload never carries a URL, so it can only
 *                     ever open one of the app's own screens.
 *   pushToken()       the Expo push token for the server to send to — a development build only.
 *                     In Expo Go (no remote push since SDK 53) it answers why not, and nothing pretends.
 *   onPushTokenChange()  the OS rolled the device token: register again.
 *   giveUpDeviceToken()  sign-out when the server could not be told: nothing more reaches this install.
 *
 * Nothing here logs a title, a body or a token: a notification can carry a message from the control room.
 *
 * WHY THE DEEP IMPORTS. `import … from 'expo-notifications'` (the package's index) also runs its push
 * auto-registration side effect, and since SDK 53 that THROWS at import inside Expo Go on Android — the
 * app then never gets past its first screen (the client's phone, 2026-09-20). Local notifications still
 * work in Expo Go, so this file takes exactly the modules it needs from `expo-notifications/build/*`,
 * none of which touch the push half, and loads the push modules (the Expo token, the token listener, the
 * unregister call) lazily and ONLY outside Expo Go — each of them throws there when CALLED on Android.
 * Nothing else in either app may import the package — `tools/gates/expo-go-safety.mjs` enforces it.
 */

import { Platform } from 'react-native'
import { setNotificationHandler } from 'expo-notifications/build/NotificationsHandler'
import { setNotificationChannelAsync } from 'expo-notifications/build/setNotificationChannelAsync'
import { getPermissionsAsync, requestPermissionsAsync } from 'expo-notifications/build/NotificationPermissions'
import { scheduleNotificationAsync } from 'expo-notifications/build/scheduleNotificationAsync'
import { addNotificationResponseReceivedListener, getLastNotificationResponseAsync } from 'expo-notifications/build/NotificationsEmitter'
import { dismissAllNotificationsAsync } from 'expo-notifications/build/dismissAllNotificationsAsync'
import { dismissNotificationAsync } from 'expo-notifications/build/dismissNotificationAsync'
import { AndroidImportance, AndroidNotificationVisibility, type NotificationChannelInput } from 'expo-notifications/build/NotificationChannelManager.types'
import { AndroidNotificationPriority, type Notification, type NotificationResponse } from 'expo-notifications/build/Notifications.types'
import Constants from 'expo-constants'
import { isExpoGo } from '../lib/env'
import { createDedupe } from './dedupe'
import { recentlyShown } from './recentlyShown'
import { GENERAL_CHANNEL, SAFETY_CHANNEL, channelFor, channelsFor, linkFor, type AppKind, type Channel, type Payload } from './links'
import { nudgePushRegistration } from './registration'

export type ShowInput = { key: string; title: string; body: string; data: Payload; urgent?: boolean }

// Kept on the phone for ten minutes, so the sync cursor's deliberate overlap never buzzes twice across a restart (B056).
const shown = recentlyShown()
const dedupe = createDedupe()
let configuredFor: AppKind | 'none' | null = null

const SHOW = { shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: false }
const DROP = { shouldShowBanner: false, shouldShowList: false, shouldPlaySound: false, shouldSetBadge: false }

/** A push from the server, as opposed to a banner this app scheduled itself. */
const isRemote = (notification: Notification): boolean => (notification.request.trigger as { type?: string } | null)?.type === 'push'

/*
 * ════ THE ANDROID CHANNELS — AND WHAT "SAFETY ALERTS" MAY PROMISE, PER APP ════════════════════════
 *
 * MAX importance is the loudest a channel can be: it rings, it vibrates with the pattern below, and it
 * shows as a heads-up banner over whatever is on the screen. PUBLIC lets the words be read on the lock
 * screen without unlocking. Both are the app's to set, on both apps, and both are set.
 *
 * `bypassDnd` IS SPLIT BY APP, and that split is a ruling, not a preference. The table is
 * in links.ts (`DND_BYPASS`), in one line, because this is the part that CANNOT BE PUT RIGHT LATER: a
 * notification channel's configuration is fixed once a phone has created it, and after that an app may
 * change its name, its description and its group and nothing else. Getting this wrong on day one costs
 * a NEW channel id on every phone AND the matching constant on the server, shipped in one release.
 *
 *   GUARD      asks for the bypass, from first install. An employer-issued handset, and a lone officer
 *              under threat is exactly the case Android's Notification Policy Access exists for.
 *   RESIDENT   does not, and its app.config.ts does not declare the permission. A resident silencing
 *              their own phone is their own choice.
 *
 * WHY IT MUST BE SET NOW, AND WHY IT IS STILL HONEST. Android honours the flag only while the app holds
 * ACCESS_NOTIFICATION_POLICY, which the person grants in the phone's Settings; without it the flag is
 * dropped in silence, and code that claimed the bypass anyway would be promising a guard something it
 * cannot keep — which is what was wrong before. So nothing here CLAIMS anything: the channel is asked
 * for, and `setNotificationChannelAsync` answers with the channel AS ANDROID ACTUALLY HAS IT (the native
 * module returns `NotificationManager.getNotificationChannel`, not the object we built, precisely
 * because "the created channel may differ from this value"). `safetyChannelDnd()` reports that answer
 * and nothing else, and the one screen that mentions Do Not Disturb reads it.
 *
 * The ask is re-made at every start-up and on every call to `safetyChannelDnd()`. That is deliberate:
 * it costs nothing, and it is how a phone that grants the permission LATER picks the bypass up, without
 * the guard having to reinstall anything.
 *
 * THE IDS ARE STILL SETTLED, AND `safety` IS STILL `safety`. It is the id the SERVER puts on every
 * urgent push (`App\Services\Push\PushTypes::SAFETY`); renaming it here alone would send every panic to
 * a channel the handset never created, which Android quietly demotes to the app's default — the exact
 * failure this comment has always been about. The worry, when the bypass was ruled on, was that saying
 * "yes" to it would cost a new channel id,
 * and it did not, because at that time no store or development build of either app had been installed.
 * THAT IS NO LONGER TRUE: release APKs of 23, 24 and 25 September 2026 are on client phones, so `safety`
 * is now fixed on them exactly as those builds created it. Nothing may count on changing it again — which
 * is why the Guard App's call-style ring rings on channels of its own, created natively
 * (apps/guard/modules/guard-ring, RingChannels.kt), and never through this file.
 */

const channelInput = (channel: Channel): NotificationChannelInput =>
  channel.urgent
    ? { name: channel.name, importance: AndroidImportance.MAX, vibrationPattern: [0, 300, 200, 300], lockscreenVisibility: AndroidNotificationVisibility.PUBLIC, bypassDnd: channel.bypassDnd }
    : { name: channel.name, importance: AndroidImportance.DEFAULT, lockscreenVisibility: AndroidNotificationVisibility.PRIVATE }

/**
 * Called without an app (the `show()` safety net), only the two channels both apps have are created,
 * and the safety one is created WITHOUT the bypass — never guess which app this is: the guard app's
 * notifier always passes its name at start-up, and a channel that arrived without the flag is corrected
 * by the next call that does. Guessing wrong in the other direction would put a Do Not Disturb bypass
 * on a resident's phone, which is the one mistake here that cannot be taken back.
 */
const channelsOf = (app?: AppKind): Channel[] =>
  app ? channelsFor(app) : [
    { id: SAFETY_CHANNEL, name: 'Safety alerts', urgent: true, bypassDnd: false },
    { id: GENERAL_CHANNEL, name: 'Updates', urgent: false, bypassDnd: false },
  ]

/** Whether a duress or panic alert really does sound through Do Not Disturb on this phone. `unknown` = not Android, or the phone would not say. */
export type DndBypass = 'on' | 'off' | 'unknown'

/**
 * Re-states the safety channel and answers with what Android DID — never with what was asked for.
 * On the guard app this is also the call that applies the bypass the moment the permission is granted,
 * so the screen that sends a guard to the phone's Settings calls it again when they come back.
 */
export async function safetyChannelDnd(app: AppKind): Promise<DndBypass> {
  if (Platform.OS !== 'android') return 'unknown'
  const safety = channelsFor(app).find((channel) => channel.id === SAFETY_CHANNEL)
  if (!safety) return 'unknown'
  const made = await setNotificationChannelAsync(safety.id, channelInput(safety)).catch(() => null)
  return made === null ? 'unknown' : made.bypassDnd ? 'on' : 'off'
}

/**
 * Foreground behaviour + Android channels. Safe to call more than once; does nothing on web.
 * Called with the app at start-up (the notifier components); `show()` calls it bare as a safety net.
 */
export async function configureNotifications(app?: AppKind): Promise<void> {
  if (Platform.OS === 'web') return
  if (configuredFor !== null && (app === undefined || configuredFor === app)) return
  const first = configuredFor === null
  configuredFor = app ?? 'none'

  if (first) {
    setNotificationHandler({
      // A remote push that arrives while the app is OPEN is dropped when the local notifier has already
      // announced the same EVENT — the same (type, id, kind), in the same words; otherwise it is shown, and the
      // local notifier will not repeat it. "You are dispatched" after the local raise banner is a new event (B134).
      handleNotification: async (notification) => {
        try {
          if (!isRemote(notification)) return SHOW
          return dedupe.remoteArrived(notification.request.content.data as Payload | undefined, notification.request.content) ? SHOW : DROP
        } catch {
          return SHOW
        }
      },
    })
  }

  if (Platform.OS === 'android') {
    for (const channel of channelsOf(app)) await setNotificationChannelAsync(channel.id, channelInput(channel)).catch(() => undefined)
  }
}

export type NotificationPermission = { granted: boolean; status: string; canAskAgain: boolean }

/** What the phone says about notifications — the ONE door the screens use (they never import the package). `null` on web or if the phone cannot say. */
export async function notificationPermission(ask = false): Promise<NotificationPermission | null> {
  if (Platform.OS === 'web') return null
  const p = await (ask ? requestPermissionsAsync() : getPermissionsAsync()).catch(() => null)
  // Just allowed: whoever keeps the push registration should look again — there may be a token now.
  if (ask && p?.granted) nudgePushRegistration()
  return p ? { granted: !!p.granted, status: String(p.status), canAskAgain: !!p.canAskAgain } : null
}

export async function notificationsAllowed(): Promise<boolean> {
  return !!(await notificationPermission())?.granted
}

/** Shows it once. Returns whether it was shown — false when not permitted, switched off, on web, already shown, or already pushed. */
export async function show(input: ShowInput, allowed: boolean): Promise<boolean> {
  if (Platform.OS === 'web' || !allowed || shown.has(input.key)) return false
  if (!(await notificationsAllowed())) return false
  // The server's push for this same event got here first: one banner is enough.
  if (!dedupe.localAllowed(input.data, input)) { shown.add(input.key); return false }
  shown.add(input.key)
  await configureNotifications()
  try {
    await scheduleNotificationAsync({
      // Its own key as its identifier, so it can be taken down again when it stops being true (dismiss).
      identifier: input.key,
      content: { title: input.title, body: input.body, data: { ...input.data }, sound: true, priority: input.urgent ? AndroidNotificationPriority.MAX : AndroidNotificationPriority.DEFAULT },
      trigger: Platform.OS === 'android' ? { channelId: input.urgent ? SAFETY_CHANNEL : channelFor(input.data.type) } : null,
    })
    return true
  } catch {
    shown.delete(input.key)
    return false
  }
}

/** A tap on a notification (now, or the one that launched the app) → `go(path)`. Returns the unsubscribe. */
export function onOpen(app: AppKind, go: (path: string) => void): () => void {
  if (Platform.OS === 'web') return () => undefined
  const open = (response: NotificationResponse | null | undefined) => {
    const path = linkFor(app, response?.notification.request.content.data as Payload | undefined)
    if (path) go(path)
  }
  try {
    void getLastNotificationResponseAsync().then(open).catch(() => undefined)
    const sub = addNotificationResponseReceivedListener(open)
    return () => sub.remove()
  } catch {
    return () => undefined // a phone that cannot listen still runs; the notification just opens the app
  }
}

export type PushToken = { ok: true; token: string; platform: 'ios' | 'android' } | { ok: false; reason: 'web' | 'expo_go' | 'not_permitted' | 'no_project' | 'failed' }

/** The EAS project the Expo token is issued under — from app config (`extra.eas.projectId`, set by EAS or EXPO_PUBLIC_EAS_PROJECT_ID). */
const easProjectId = (): string | null => {
  const id = (Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined)?.eas?.projectId ?? Constants.easConfig?.projectId
  return typeof id === 'string' && id.length > 0 ? id : null
}

/** The token a server would send to. Honest about why there is none. */
export async function pushToken(): Promise<PushToken> {
  if (Platform.OS === 'web') return { ok: false, reason: 'web' }
  if (isExpoGo) return { ok: false, reason: 'expo_go' }
  if (!(await notificationsAllowed())) return { ok: false, reason: 'not_permitted' }
  const projectId = easProjectId()
  if (!projectId) return { ok: false, reason: 'no_project' }
  try {
    // Loaded here and nowhere else: this module pulls in the push auto-registration that throws inside Expo Go (see the header).
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const { getExpoPushTokenAsync } = require('expo-notifications/build/getExpoPushTokenAsync') as typeof import('expo-notifications/build/getExpoPushTokenAsync')
    const result = await getExpoPushTokenAsync({ projectId })
    return { ok: true, token: result.data, platform: Platform.OS === 'ios' ? 'ios' : 'android' }
  } catch {
    return { ok: false, reason: 'failed' }
  }
}

/** The OS rolled this install's device token (rare, and it happens): `fn()` — register again. Returns the unsubscribe. Silent in Expo Go and on web. */
export function onPushTokenChange(fn: () => void): () => void {
  if (Platform.OS === 'web' || isExpoGo) return () => undefined
  try {
    // Lazy, and only outside Expo Go: calling it there throws on Android (see the header).
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const { addPushTokenListener } = require('expo-notifications/build/TokenEmitter') as typeof import('expo-notifications/build/TokenEmitter')
    const sub = addPushTokenListener(() => fn())
    return () => sub.remove()
  } catch {
    return () => undefined // a phone that cannot listen still runs; the next start registers again
  }
}

/** Sign-out when the server could not be told: hand the device token back, so nothing more is delivered to this install. Best effort. */
export async function giveUpDeviceToken(): Promise<void> {
  if (Platform.OS === 'web' || isExpoGo) return
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const { unregisterForNotificationsAsync } = require('expo-notifications/build/unregisterForNotificationsAsync') as typeof import('expo-notifications/build/unregisterForNotificationsAsync')
    await unregisterForNotificationsAsync()
  } catch {
    // nothing to do: the address moves to whoever registers on this phone next
  }
}

/** Take one notification down once what it said is no longer true (a guard's "open the app" once it is open). Never throws. */
export async function dismiss(key: string): Promise<void> {
  shown.delete(key)
  if (Platform.OS === 'web') return
  await dismissNotificationAsync(key).catch(() => undefined)
}

/** For tests and for sign-out: forget what was shown, and clear what is on the lock screen (it may name a message). */
export async function clearNotifications(): Promise<void> {
  shown.clear()
  dedupe.clear()
  if (Platform.OS === 'web') return
  await dismissAllNotificationsAsync().catch(() => undefined)
}
