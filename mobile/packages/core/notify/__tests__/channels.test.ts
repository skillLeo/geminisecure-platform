/**
 * The Android channels — read from the source of `runtime.ts` and `links.ts`, because what matters
 * here is what the code ASKS THE PLATFORM FOR, and the platform is not present in a test.
 *
 * THE RULING THIS ENCODES SPLITS THE DO NOT DISTURB BYPASS BY APP. It replaces
 * an earlier one, which was that neither app may ask for a Do Not Disturb bypass:
 *
 *   GUARD     the safety channel is created WITH `bypassDnd`, from first install, and the app declares
 *             ACCESS_NOTIFICATION_POLICY and asks for it in context behind `flags.askForDndAccess`
 *             (default on). An employer-issued handset; a duress alarm must not be silenced by a Focus
 *             setting. Irreversible half first: a channel's configuration is fixed once a phone has
 *             created it, so the flag has to be right on day one.
 *   RESIDENT  the safety channel is created WITHOUT it, and `apps/resident/app.config.ts` does not
 *             name the permission at all. A resident silencing their own phone is their own choice.
 *
 * WHAT DID NOT CHANGE, AND IS THE POINT OF HALF THE TESTS BELOW. Android honours the flag only while
 * the app holds that permission, and drops it in silence otherwise — so the app may not CLAIM the
 * bypass, only ask for it and then report what Android actually did. `setNotificationChannelAsync`
 * answers with the channel as the platform has it, and `safetyChannelDnd()` returns that and nothing
 * else. And the ids are still settled: the SERVER puts them on every push
 * (`App\Services\Push\PushTypes`), and a rename on one side alone sends a panic to a channel the phone
 * never made, which Android drops to the app's default channel without a sound.
 */

import { existsSync, readFileSync } from 'node:fs'
import path from 'node:path'
import { DND_BYPASS, GENERAL_CHANNEL, SAFETY_CHANNEL, channelFor, channelsFor } from '../links'

const runtime = readFileSync(path.resolve(__dirname, '..', 'runtime.ts'), 'utf8')
/** Only the code: a comment is free to explain a flag, and to name a permission, that the code must not set. */
const strip = (source: string) => source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
const code = strip(runtime)
const links = strip(readFileSync(path.resolve(__dirname, '..', 'links.ts'), 'utf8'))
const configOf = (app: string) => readFileSync(path.resolve(__dirname, '..', '..', '..', '..', 'apps', app, 'app.config.ts'), 'utf8')

describe('the safety channel, split by app', () => {
  it('the guard asks for the Do Not Disturb bypass and the resident does not: one table, one line', () => {
    expect(DND_BYPASS).toEqual({ guard: true, resident: false })
    expect(channelsFor('guard')[0]).toEqual({ id: SAFETY_CHANNEL, name: 'Safety alerts', urgent: true, bypassDnd: true })
    expect(channelsFor('resident')[0]).toEqual({ id: SAFETY_CHANNEL, name: 'Safety alerts', urgent: true, bypassDnd: false })
  })

  it('no channel but the safety one ever carries it, in either app', () => {
    for (const app of ['guard', 'resident'] as const) {
      for (const channel of channelsFor(app).slice(1)) expect(`${app} ${channel.id}: ${channel.bypassDnd}`).toBe(`${app} ${channel.id}: false`)
    }
  })

  it('the runtime sets the flag from the table and never decides it itself', () => {
    // The value passed to Android is the channel's own, so the split cannot drift from links.ts.
    expect(code).toMatch(/bypassDnd:\s*channel\.bypassDnd/)
    // Nothing anywhere hard-codes `bypassDnd: true`, which is how it would reach a resident's phone.
    expect(code).not.toMatch(/bypassDnd:\s*true/)
    expect(links).not.toMatch(/bypassDnd:\s*true/)
  })

  it('called without an app, the safety channel is created WITHOUT the bypass', () => {
    // `show()` calls configureNotifications() bare. Guessing "guard" there would put a Do Not Disturb
    // bypass on a resident's phone — the one mistake here that a later release cannot take back.
    expect(code).toMatch(/channelsOf\s*=\s*\(app\?: AppKind\)[\s\S]*?SAFETY_CHANNEL[^}]*bypassDnd:\s*false/)
  })

  it('is still the loudest channel the app CAN create, and readable on the lock screen', () => {
    expect(code).toMatch(/importance:\s*AndroidImportance\.MAX/)
    expect(code).toMatch(/lockscreenVisibility:\s*AndroidNotificationVisibility\.PUBLIC/)
    expect(code).toMatch(/vibrationPattern/)
  })
})

describe('the app may ask for the bypass; it may never claim it', () => {
  it('what the screen is told is what ANDROID answered, not what was asked for', () => {
    // setNotificationChannelAsync resolves with the channel as the platform has it; the reporter
    // returns that value's own bypassDnd. A phone without the permission answers `off`.
    expect(code).toMatch(/export async function safetyChannelDnd/)
    expect(code).toMatch(/const made = await setNotificationChannelAsync\(safety\.id, channelInput\(safety\)\)/)
    expect(code).toMatch(/return made === null \? 'unknown' : made\.bypassDnd \? 'on' : 'off'/)
  })

  it('the runtime itself never touches the permission or the settings screen: that is a screen\'s job', () => {
    expect(code).not.toMatch(/ACCESS_NOTIFICATION_POLICY|isNotificationPolicyAccessGranted|ACTION_NOTIFICATION_POLICY_ACCESS/)
  })

  it('says in the file why the flag is there, per app, and what it does not promise', () => {
    expect(runtime).toMatch(/ACCESS_NOTIFICATION_POLICY/)
    expect(runtime).toMatch(/fixed once a phone has created it/)
  })
})

describe('the permission itself: the guard declares it, the resident must never gain it', () => {
  it('the Guard App declares ACCESS_NOTIFICATION_POLICY', () => {
    const guard = configOf('guard')
    expect(guard).toMatch(/permissions:\s*\[[^\]]*'ACCESS_NOTIFICATION_POLICY'/)
    expect(guard).not.toMatch(/blockedPermissions:\s*\[[^\]]*ACCESS_NOTIFICATION_POLICY/)
  })

  it('the Resident App does not name it anywhere, and its merged manifest does not hold it', () => {
    expect(`resident config names it: ${/ACCESS_NOTIFICATION_POLICY/.test(configOf('resident'))}`).toBe('resident config names it: false')
    // The written-down merged manifest is what a reviewer reads; gate:store keeps it matching the config.
    const snapshot = readFileSync(path.resolve(__dirname, '..', '..', '..', '..', 'store', 'manifests', 'resident.permissions.json'), 'utf8')
    expect(`resident manifest holds it: ${/ACCESS_NOTIFICATION_POLICY/.test(snapshot)}`).toBe('resident manifest holds it: false')
    // Twelve since M12 §2.9 (RECORD_AUDIO, for speech recognised on the phone), fifteen since M50 §4.2 #1: live audio
    // during an open alert adds FOREGROUND_SERVICE, FOREGROUND_SERVICE_MICROPHONE and MODIFY_AUDIO_SETTINGS (PERMISSIONS.md).
    expect(JSON.parse(snapshot).merged).toHaveLength(15)
  })

  it('the guard\'s merged manifest holds it, and the Guard App\'s PERMISSIONS.md explains it', () => {
    // The Guard App goes to no store (M25), so its snapshot lives with its private distribution, not in store/.
    const snapshot = JSON.parse(readFileSync(path.resolve(__dirname, '..', '..', '..', '..', 'distribution', 'guard', 'guard.permissions.json'), 'utf8'))
    expect(snapshot.merged.map((p: { permission: string }) => p.permission)).toContain('android.permission.ACCESS_NOTIFICATION_POLICY')
    // 11 + the call-style ring's two (USE_FULL_SCREEN_INTENT, REQUEST_IGNORE_BATTERY_OPTIMIZATIONS) + on-duty location
    // sharing's three (ACCESS_BACKGROUND_LOCATION, FOREGROUND_SERVICE, FOREGROUND_SERVICE_LOCATION; M11 §3, D-174),
    // all from app.config.ts — and RECORD_AUDIO, for dictating a report on the phone (M12 §2.9).
    expect(snapshot.merged).toHaveLength(17)
    /*
     * The Guard App's PERMISSIONS.md is a working note and is not published with the source. Where it is present it
     * must explain this permission; where it is absent the assertion is skipped rather than failed, so a fresh checkout
     * is green. It lives beside the app's private distribution, and the store review notes no longer name it: the Guard
     * App goes to no store (M25), so no reviewer meets it, and the store pack does not mention the Guard App at all.
     */
    const root = (name: string) => path.resolve(__dirname, '..', '..', '..', '..', name)
    for (const name of ['distribution/guard/PERMISSIONS.md']) {
      if (!existsSync(root(name))) continue
      expect(`${name} names it: ${/ACCESS_NOTIFICATION_POLICY/.test(readFileSync(root(name), 'utf8'))}`).toBe(`${name} names it: true`)
    }
  })
})

describe('the channel ids are settled, and are the ids the server sends', () => {
  it('the two every phone has', () => {
    expect(SAFETY_CHANNEL).toBe('safety')
    expect(GENERAL_CHANNEL).toBe('general')
  })

  it('every urgent type lands on the safety channel; everything else on its own or on Updates', () => {
    for (const type of ['site_alert', 'site_ring', 'duress_update', 'alertness_check', 'approval_request', 'panic_update'] as const) {
      expect(channelFor(type)).toBe(SAFETY_CHANNEL)
    }
    expect(channelFor('payslip_ready')).toBe('payslips')
    expect(channelFor('resident_report')).toBe(GENERAL_CHANNEL)
    expect(channelFor('a_type_from_a_newer_server')).toBe(GENERAL_CHANNEL)
  })

  it('each app creates the safety channel first, and only one of them is urgent', () => {
    for (const app of ['guard', 'resident'] as const) {
      const channels = channelsFor(app)
      expect(channels[0]!.id).toBe(SAFETY_CHANNEL)
      expect(channels.filter((c) => c.urgent)).toHaveLength(1)
      // Ids are forever: lower case, no spaces, nothing that reads like a version somebody might bump by hand.
      for (const c of channels) expect(c.id).toMatch(/^[a-z_]+$/)
    }
  })

  it('the ring channels are native and never in channelsFor, so `safety`, fixed on installed phones, is never touched', () => {
    // GUARD_RING_DESIGN §7.6: release APKs of 23-25 September are installed, so `safety` is fixed as those phones made
    // it. The call-style ring rings on its own channels, created by apps/guard/modules/guard-ring (RingChannels.kt).
    for (const app of ['guard', 'resident'] as const) {
      const ids = channelsFor(app).map((c) => c.id)
      expect(ids).not.toContain('safety_ring')
      expect(ids).not.toContain('safety_ring_held')
    }
    const native = readFileSync(path.resolve(__dirname, '..', '..', '..', '..', 'apps', 'guard', 'modules', 'guard-ring', 'android', 'src', 'main', 'java', 'jm', 'geminisecure', 'guardring', 'RingChannels.kt'), 'utf8')
    expect(native).toMatch(/const val RING = "safety_ring"/)
    expect(native).toMatch(/const val HELD = "safety_ring_held"/)
    expect(native).toMatch(/USAGE_ALARM/)
    expect(native).toMatch(/DEFAULT_ALARM_ALERT_URI/)
    // The native side never creates `safety`: its settings belong to runtime.ts, and are forever.
    expect(native).not.toMatch(/\bNotificationChannel\(\s*SAFETY/)
  })

  it('the split did NOT cost a new id: the one the server sends is unchanged on both sides', () => {
    // A rename here alone sends every panic to a channel the handset never created. The id was kept because, when
    // the split was ruled, no standalone build had been installed; release APKs have been since (23-25 September),
    // so from here the id is fixed on those phones as well as on the server.
    const pushTypes = path.resolve(__dirname, '..', '..', '..', '..', '..', 'geminisecure', 'app', 'Services', 'Push', 'PushTypes.php')
    let server: string
    try {
      server = readFileSync(pushTypes, 'utf8')
    } catch {
      return // the server repository is not beside this one
    }
    expect(server).toMatch(new RegExp(`const SAFETY = '${SAFETY_CHANNEL}';`))
    expect(server).toMatch(new RegExp(`const GENERAL = '${GENERAL_CHANNEL}';`))
  })
})
