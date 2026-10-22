/**
 * The rules behind gate:ring-native (tools/gates/guard-ring-native.mjs), pure, so tools/gates/selftest.mjs can prove
 * each one on a bad input. Every function answers a list of problems; an empty list is a pass.
 *
 * THE CALL-STYLE RING TAKES expo-notifications' OWN SLOT (GUARD_RING_DESIGN §2.1): the app manifest removes
 * `ExpoFirebaseMessagingService` and declares its subclass `GuardRingMessagingService` with the same MESSAGING_EVENT
 * filter at the same priority. That is safe only while expo-notifications still ships that class OPEN, with an
 * overridable `onMessageReceived`, and still declares it at -1. An Expo upgrade that changed either would silence
 * EVERY push the Guard App gets — today's panic included — without an error anywhere. These rules turn that into a
 * red gate.
 */

export const EXPO_SERVICE = 'expo.modules.notifications.service.ExpoFirebaseMessagingService'
export const RING_SERVICE = 'jm.geminisecure.guardring.GuardRingMessagingService'
export const RING_ACTIVITY = 'jm.geminisecure.guardring.RingActivity'
export const RING_RECEIVER = 'jm.geminisecure.guardring.RingActionReceiver'
export const MESSAGING_EVENT = 'com.google.firebase.MESSAGING_EVENT'
export const RING_PERMISSIONS = ['android.permission.USE_FULL_SCREEN_INTENT', 'android.permission.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS']

const stripComments = (s) => String(s ?? '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')

/** 1 and 2: the class the ring extends, and its manifest slot, as expo-notifications ships them. */
export function upstreamProblems({ serviceKt, libraryManifest }) {
  const problems = []
  const kt = stripComments(serviceKt)
  if (!/\bopen\s+class\s+ExpoFirebaseMessagingService\b/.test(kt)) problems.push('expo-notifications: ExpoFirebaseMessagingService is no longer an `open class` — GuardRingMessagingService cannot extend it, and every Guard App push would stop. Re-read the ring before upgrading.')
  if (/\bfinal\s+override\s+fun\s+onMessageReceived\b/.test(kt) || !/\boverride\s+fun\s+onMessageReceived\s*\(/.test(kt)) problems.push('expo-notifications: onMessageReceived is final or gone — the ring cannot take messages first.')
  const xml = String(libraryManifest ?? '').replace(/<!--[\s\S]*?-->/g, '')
  const service = /<service\b[^>]*android:name="\.service\.ExpoFirebaseMessagingService"[^>]*>([\s\S]*?)<\/service>/.exec(xml)
  if (!service) problems.push('expo-notifications: its manifest no longer declares .service.ExpoFirebaseMessagingService — the slot the ring takes has moved.')
  else if (!/<intent-filter\b[^>]*android:priority="-1"[^>]*>[\s\S]*?com\.google\.firebase\.MESSAGING_EVENT/.test(service[1])) problems.push('expo-notifications: ExpoFirebaseMessagingService no longer listens for MESSAGING_EVENT at priority -1 — the ring must take the same slot, or two services would compete.')
  return problems
}

const attr = (node, name) => node?.$?.[name]
const named = (list, name) => (list ?? []).filter((n) => attr(n, 'android:name') === name)

/**
 * 3: the introspected app manifest (what `expo config --type introspect` evaluates the plugins to). The ring's
 * service, screen and receiver are written HERE by its plugin, so the gates can see them.
 */
export function appManifestProblems(manifest, { app = 'guard' } = {}) {
  const problems = []
  const application = manifest?.application?.[0]
  const services = application?.service ?? []
  const activities = application?.activity ?? []
  const receivers = application?.receiver ?? []
  const perms = (manifest?.['uses-permission'] ?? []).filter((p) => attr(p, 'tools:node') !== 'remove').map((p) => attr(p, 'android:name'))

  if (app !== 'guard') {
    for (const name of [RING_SERVICE, RING_ACTIVITY, RING_RECEIVER]) if (named([...services, ...activities, ...receivers], name).length) problems.push(`${app}: ${name} is declared — the call-style ring belongs to the Guard App only`)
    if (named(services, EXPO_SERVICE).some((s) => attr(s, 'tools:node') === 'remove')) problems.push(`${app}: expo-notifications' messaging service is removed — this app has no ring to take its place, so it would receive no push at all`)
    return problems
  }

  const removal = named(services, EXPO_SERVICE)
  if (removal.length !== 1 || attr(removal[0], 'tools:node') !== 'remove') problems.push(`guard: the app manifest must REMOVE ${EXPO_SERVICE} (tools:node="remove") — two MESSAGING_EVENT services at one priority is an undocumented choice`)
  const ring = named(services, RING_SERVICE)
  if (ring.length !== 1) problems.push(`guard: ${RING_SERVICE} must be declared exactly once in the app manifest (found ${ring.length})`)
  else {
    if (attr(ring[0], 'android:exported') !== 'false') problems.push(`guard: ${RING_SERVICE} must not be exported`)
    const filters = ring[0]['intent-filter'] ?? []
    const ok = filters.some((f) => attr(f, 'android:priority') === '-1' && (f.action ?? []).some((a) => attr(a, 'android:name') === MESSAGING_EVENT))
    if (!ok) problems.push(`guard: ${RING_SERVICE} must listen for ${MESSAGING_EVENT} at priority -1 — the slot expo-notifications' service had`)
  }
  const others = services.filter((s) => attr(s, 'tools:node') !== 'remove' && attr(s, 'android:name') !== RING_SERVICE && (s['intent-filter'] ?? []).some((f) => (f.action ?? []).some((a) => attr(a, 'android:name') === MESSAGING_EVENT)))
  if (others.length) problems.push(`guard: another MESSAGING_EVENT service is declared (${others.map((s) => attr(s, 'android:name')).join(', ')}) — which one Firebase picks is undocumented`)
  const screen = named(activities, RING_ACTIVITY)
  if (screen.length !== 1) problems.push(`guard: ${RING_ACTIVITY} must be declared exactly once (found ${screen.length})`)
  else {
    if (attr(screen[0], 'android:showWhenLocked') !== 'true') problems.push(`guard: ${RING_ACTIVITY} must be shown over the lock screen (android:showWhenLocked="true")`)
    if (attr(screen[0], 'android:exported') !== 'false') problems.push(`guard: ${RING_ACTIVITY} must not be exported`)
  }
  const receiver = named(receivers, RING_RECEIVER)
  if (receiver.length !== 1 || attr(receiver[0], 'android:exported') !== 'false') problems.push(`guard: ${RING_RECEIVER} must be declared once and not exported`)
  for (const p of RING_PERMISSIONS) if (!perms.includes(p)) problems.push(`guard: ${p} is missing from the app manifest — the ring cannot put its screen up, or ask for the battery exemption`)
  return problems
}

/** The module's own library manifest stays empty: a library manifest is merged without any gate reading it. */
export function libraryManifestProblems(xml) {
  const body = String(xml ?? '').replace(/<!--[\s\S]*?-->/g, '')
  return /<(service|activity|receiver|provider|uses-permission)\b/.test(body) ? ['guard-ring: the module\'s own AndroidManifest.xml declares something — write it into the app manifest from plugin/withGuardRing.cjs, where gate:store can see it'] : []
}

/** 4: the decision table is well formed, and no message the server builds carries presentational words. */
export function contractProblems({ table, fixtures }) {
  const problems = []
  for (const section of ['decider', 'launch', 'delete', 'pending', 'send', 'restore', 'actions']) {
    if (!Array.isArray(table?.[section]) || table[section].length === 0) problems.push(`spec/decisions.json: section "${section}" is missing or empty`)
    else for (const row of table[section]) if (typeof row?.id !== 'string' || (typeof row.decision !== 'string' && typeof row.screen !== 'string')) problems.push(`spec/decisions.json: a "${section}" row has no id or no decision`)
  }
  for (const { name, json } of fixtures ?? []) {
    if (name === 'ready-bits.json') continue
    if (/\.received\.json$/.test(name)) {
      const text = JSON.stringify(json)
      if (/ExponentPushToken\[/.test(text)) problems.push(`spec/contract/${name}: holds a push token — redact it`)
      if (/"v1\.[A-Za-z0-9_-]+\./.test(text)) problems.push(`spec/contract/${name}: holds a ring ticket — redact it`)
      let body = null
      try { body = typeof json?.body === 'string' ? JSON.parse(json.body) : null } catch { problems.push(`spec/contract/${name}: body is not JSON`) }
      if (body && ('title' in body || 'message' in body)) problems.push(`spec/contract/${name}: the received body carries title or message`)
      continue
    }
    const data = json?.data ?? {}
    const dataOnly = !('title' in (json ?? {}))
    if (dataOnly) for (const key of ['title', 'message', 'body']) if (key in data) problems.push(`spec/contract/${name}: data carries "${key}" — expo-notifications would present it as a banner by itself`)
    if (dataOnly && json?.priority !== 'high') problems.push(`spec/contract/${name}: a data-only ring must be priority high — Expo's Android default is normal`)
  }
  return problems
}
