/**
 * The native ring's decision table (apps/guard/modules/guard-ring/spec/decisions.json) — the one JSON that JUnit runs
 * against the Kotlin. Jest cannot run Kotlin, so it holds the TABLE and the SOURCE to each other: the table is well
 * formed, every decision and screen it names exists in the Kotlin, the channel it describes is the channel the Kotlin
 * creates, and no message the server builds carries words expo-notifications would present by itself.
 * (GUARD_RING_DESIGN §15.2; the departure from "the handler in jest" is the design's §0.3 item 1.)
 */

import { existsSync, readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'

const MODULE = path.resolve(__dirname, '..', '..', '..', '..', 'modules', 'guard-ring')
const KT = path.join(MODULE, 'android', 'src', 'main', 'java', 'jm', 'geminisecure', 'guardring')
const read = (...p: string[]) => readFileSync(path.join(...p), 'utf8')
const table = JSON.parse(read(MODULE, 'spec', 'decisions.json'))
const kotlin = (file: string) => read(KT, file)
const enumOf = (source: string, name: string): string[] => {
  const body = new RegExp(`enum class ${name}(?:\\([^)]*\\))?\\s*\\{([\\s\\S]*?)\\}`).exec(source)?.[1] ?? ''
  return body.split(/[,;\n]/).map((s) => s.trim().replace(/\(.*$/, '')).filter((s) => /^[A-Z_]+$/.test(s))
}

type Row = Record<string, unknown> & { id: string }

describe('the table is well formed', () => {
  it('has every section, and every row an id of its own', () => {
    for (const section of ['decider', 'launch', 'delete', 'pending', 'send', 'restore', 'actions']) expect(Array.isArray(table[section])).toBe(true)
    const ids = ['decider', 'launch', 'delete', 'pending', 'send', 'restore', 'actions'].flatMap((s) => (table[s] as Row[]).map((r) => r.id))
    expect(new Set(ids).size).toBe(ids.length)
  })

  it('covers D1 to D28 of the design, and the actions', () => {
    const ids = new Set(['decider', 'launch', 'delete', 'pending', 'send', 'restore'].flatMap((s) => (table[s] as Row[]).map((r) => r.id)))
    for (let n = 1; n <= 28; n++) expect(`D${n}: ${ids.has(`D${n}`)}`).toBe(`D${n}: true`)
    expect((table.actions as Row[]).length).toBeGreaterThanOrEqual(20)
  })
})

describe('every decision, receipt and screen the table names exists in the Kotlin', () => {
  const decisions = enumOf(kotlin('RingDecider.kt'), 'Decision')
  const screens = enumOf(kotlin('RingActions.kt'), 'Screen')
  const receipts = [...kotlin('RingDecider.kt').matchAll(/[A-Z_]+\("([a-z_]+)"\)/g)].map((m) => m[1])

  it('decisions', () => {
    expect(decisions.length).toBeGreaterThan(20)
    for (const section of ['decider', 'launch', 'delete', 'pending', 'send', 'restore']) {
      for (const row of table[section] as Row[]) {
        if (row.decision === 'NONE') continue
        expect(`${row.id} ${row.decision}: ${decisions.includes(row.decision as string)}`).toBe(`${row.id} ${row.decision}: true`)
      }
    }
  })

  it('receipts are the server\'s event names', () => {
    const server = ['shown', 'full_screen', 'opened', 'held', 'dismissed', 'stopped', 'suppressed', 'fallback']
    expect(receipts.sort()).toEqual([...server].sort())
    for (const section of ['decider', 'launch', 'delete']) {
      for (const row of table[section] as Row[]) if (row.receipt) expect(server).toContain(row.receipt)
    }
  })

  it('screens', () => {
    for (const row of table.actions as Row[]) expect(`${row.id} ${row.screen}: ${screens.includes(row.screen as string)}`).toBe(`${row.id} ${row.screen}: true`)
  })
})

describe('the channel the table describes is the one the Kotlin creates', () => {
  const channels = kotlin('RingChannels.kt')
  it('Call-style alerts: high, the alarm stream, the phone\'s own alarm tone, the bypass asked for, public', () => {
    const ring = table.channels.ring
    expect(channels).toContain(`const val RING = "${ring.id}"`)
    expect(channels).toContain(`const val RING_NAME = "${ring.name}"`)
    expect(channels).toMatch(/NotificationChannel\(RING, RING_NAME, NotificationManager\.IMPORTANCE_HIGH\)/)
    expect(channels).toMatch(/setUsage\(AudioAttributes\.USAGE_ALARM\)/)
    expect(channels).toMatch(/setContentType\(AudioAttributes\.CONTENT_TYPE_SONIFICATION\)/)
    expect(channels).toMatch(/Settings\.System\.DEFAULT_ALARM_ALERT_URI/)
    expect(channels).toMatch(/ring\.setBypassDnd\(true\)/)
    expect(channels).toContain(`longArrayOf(${ring.vibration.join(', ')})`)
  })

  it('Unanswered alerts: silent', () => {
    expect(channels).toContain(`const val HELD = "${table.channels.held.id}"`)
    expect(channels).toMatch(/held\.setSound\(null, null\)/)
    expect(channels).toMatch(/held\.enableVibration\(false\)/)
  })

  it('never creates `safety`, which belongs to runtime.ts and whose settings are fixed on installed phones', () => {
    expect(table.channels.neverCreated).toEqual(['safety'])
    expect(channels).not.toMatch(/\bNotificationChannel\(\s*SAFETY/)
    expect(channels).not.toMatch(/\bNotificationChannel\(\s*"safety"/)
  })

  it('the ring is insistent, an alarm, full screen, and times out by the system at its hold', () => {
    const notifier = kotlin('RingNotifier.kt')
    expect(notifier).toMatch(/Notification\.FLAG_INSISTENT/)
    expect(notifier).toMatch(/NotificationCompat\.CATEGORY_ALARM/)
    expect(notifier).toMatch(/setFullScreenIntent\(screen\(context, r\.id, Launch\.FSI\), true\)/)
    expect(notifier).toMatch(/setTimeoutAfter\(ringForMs/)
    // Every PendingIntent is immutable.
    expect(notifier).toMatch(/PendingIntent\.FLAG_IMMUTABLE/)
    expect(notifier).not.toMatch(/FLAG_MUTABLE/)
  })
})

describe('the messages the server builds (spec/contract)', () => {
  const dir = path.join(MODULE, 'spec', 'contract')
  const fixtures = readdirSync(dir).filter((f) => f.endsWith('.json') && f !== 'ready-bits.json')

  it('no data-only message carries a title, a message or a body in its data, or at its top', () => {
    for (const name of ['site_ring.json', 'ring_stop.json', 'ring_test.json']) {
      const m = JSON.parse(read(dir, name))
      for (const key of ['title', 'message', 'body']) expect(`${name} data.${key}: ${key in m.data}`).toBe(`${name} data.${key}: false`)
      for (const key of ['title', 'body', 'sound', 'channelId', 'tag']) expect(`${name} ${key}: ${key in m}`).toBe(`${name} ${key}: false`)
      expect(m.priority).toBe('high')
    }
  })

  it('the visible re-ring is today\'s site_alert with a tag, so an older app routes it as today', () => {
    const m = JSON.parse(read(dir, 'visible_ring.json'))
    expect(m.data).toEqual({ type: 'site_alert', id: '4812', kind: 'medical' })
    expect(m.tag).toBe('ring-4812')
    expect(m.channelId).toBe('safety')
    const stop = JSON.parse(read(dir, 'visible_stop.json'))
    expect(stop.data).toEqual({ type: 'ring_stop', id: '4812' })
    expect(stop.tag).toBe(m.tag)
  })

  it('a received fixture, when there is one, holds no token and no ticket (G25)', () => {
    for (const name of fixtures.filter((f) => f.endsWith('.received.json'))) {
      const text = read(dir, name)
      expect(text).not.toMatch(/ExponentPushToken\[/)
      expect(text).not.toMatch(/"v1\.[A-Za-z0-9_-]+\./)
    }
  })

  it('the readiness bits are the Kotlin\'s bits', () => {
    const bits = JSON.parse(read(dir, 'ready-bits.json')).bits as Record<string, string>
    const sound = kotlin('RingSound.kt')
    const names: Record<string, string> = { notifications: 'NOTIFICATIONS', ring_channel: 'RING_CHANNEL', full_screen: 'FULL_SCREEN', dnd_bypass: 'DND_BYPASS', battery: 'BATTERY', background: 'BACKGROUND', alarm_volume_ok: 'ALARM_VOLUME_OK', alarm_tone: 'ALARM_TONE', screen_lock: 'SCREEN_LOCK', alarm_volume_on: 'ALARM_VOLUME_ON' }
    for (const [bit, text] of Object.entries(bits)) {
      const key = text.split(':')[0]!
      expect(sound).toContain(`const val BIT_${names[key]} = ${bit}`)
    }
  })
})

describe('the app and the module agree', () => {
  const config = read(path.resolve(__dirname, '..', '..', '..', '..'), 'app.config.ts')

  it('app.config.ts lists both permissions and registers the plugin after expo-notifications', () => {
    expect(config).toMatch(/permissions: \[[^\]]*'USE_FULL_SCREEN_INTENT'[^\]]*'REQUEST_IGNORE_BATTERY_OPTIMIZATIONS'/)
    expect(config.indexOf("'./modules/guard-ring/app.plugin.js'")).toBeGreaterThan(config.indexOf("'expo-notifications',"))
    // The ring never draws over other apps: that stays blocked.
    expect(config).toMatch(/'android\.permission\.SYSTEM_ALERT_WINDOW'/)
    // Nor does it start a foreground service. The app's only one is on-duty location sharing (M11 §3, D-174), and the
    // permissions for it are the LOCATION service's — nothing in the ring module declares a service of any type.
    expect(config).toMatch(/permissions: \[[^\]]*'FOREGROUND_SERVICE_LOCATION'/)
    const ringManifest = read(path.resolve(__dirname, '..', '..', '..', '..', 'modules', 'guard-ring', 'android', 'src', 'main'), 'AndroidManifest.xml')
    expect(ringManifest).not.toMatch(/foregroundServiceType/)
  })

  it('the Kotlin opens the app on its own scheme', () => {
    const scheme = /scheme: '([a-z]+)'/.exec(config)?.[1]
    expect(kotlin('RingNotifier.kt')).toContain(`const val APP_SCHEME = "${scheme}"`)
  })

  it('the plugin refuses to build an app that forgot a permission', () => {
    const plugin = require(path.join(MODULE, 'plugin', 'withGuardRing.cjs')) as ((c: unknown) => unknown) & { REQUIRED_PERMISSIONS: string[] }
    expect(plugin.REQUIRED_PERMISSIONS).toEqual(['USE_FULL_SCREEN_INTENT', 'REQUEST_IGNORE_BATTERY_OPTIMIZATIONS'])
    expect(() => plugin({ name: 'x', slug: 'x', android: { permissions: ['CAMERA'] } })).toThrow(/USE_FULL_SCREEN_INTENT and REQUEST_IGNORE_BATTERY_OPTIMIZATIONS/)
    expect(() => plugin({ name: 'x', slug: 'x', android: { permissions: ['USE_FULL_SCREEN_INTENT', 'REQUEST_IGNORE_BATTERY_OPTIMIZATIONS'], blockedPermissions: ['android.permission.USE_FULL_SCREEN_INTENT'] } })).toThrow(/USE_FULL_SCREEN_INTENT/)
    expect(() => plugin({ name: 'x', slug: 'x', android: { permissions: ['USE_FULL_SCREEN_INTENT', 'REQUEST_IGNORE_BATTERY_OPTIMIZATIONS'] } })).not.toThrow()
  })

  it('the module has no TypeScript of its own: JS reaches it only through features/ring/native.ts', () => {
    const walk = (dir: string): string[] => readdirSync(dir, { withFileTypes: true }).flatMap((d) => (d.isDirectory() ? (['android', 'node_modules'].includes(d.name) ? [] : walk(path.join(dir, d.name))) : [path.join(dir, d.name)]))
    expect(walk(MODULE).filter((f) => /\.tsx?$/.test(f))).toEqual([])
    // Its library manifest is empty: everything is in the app manifest, where gate:store reads it.
    expect(read(MODULE, 'android', 'src', 'main', 'AndroidManifest.xml').replace(/<!--[\s\S]*?-->/g, '').trim()).toBe('<manifest />')
    expect(existsSync(path.join(MODULE, 'expo-module.config.json'))).toBe(true)
  })
})
