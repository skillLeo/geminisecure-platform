/**
 * THE ONE PLACE THAT TALKS TO A HANDSET.
 *
 * Everything else in tools/device/ goes through this file, for three reasons:
 *
 *  1. **Dry run.** Every call is describable. `--dry-run` runs the whole harness with
 *     no phone attached and prints the exact command it WOULD have sent, in order.
 *     That is how this code was proved on a laptop with nothing plugged in.
 *  2. **Honest refusal.** `requireDevice()` never invents a device. With nothing
 *     attached it prints what to do (the four taps, the cable, the Allow box) and
 *     exits 3 — a distinct code, so a caller can tell "no phone" from "the phone
 *     said no".
 *  3. **Nothing is ever assumed about the handset.** Model, Android version, screen
 *     size, density and the system-bar insets are READ, never defaulted, and every
 *     value that had to fall back to a default is recorded as a fallback and printed.
 *
 * NOTHING HERE WRITES TO THE HANDSET UNLESS IT IS ASKED TO. `install`, `setTimezone`,
 * `airplane`, `dnd` and `grantDndAccess` change the phone; each says so on the console
 * when it runs, so a transcript of a run shows every change that was made to somebody's
 * phone and in what order.
 *
 * Never used against the client's own handset enrolled as GS-1041 — the check scripts
 * refuse that guard id; this file simply never signs anybody in.
 */

import { spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
export const ADB = path.join(ROOT, '.tools', 'platform-tools', 'adb.exe')

/** Exit codes a caller can act on. 3 is "no handset", and only ever that. */
export const EXIT = { ok: 0, failed: 1, noDevice: 3, adbMissing: 4 }

export class AdbError extends Error {
  constructor(message, result) {
    super(message)
    this.name = 'AdbError'
    this.result = result
  }
}

/**
 * @param {{dryRun?: boolean, serial?: string|null, quiet?: boolean, timeoutMs?: number}} opts
 */
export function adb(opts = {}) {
  const dryRun = opts.dryRun === true
  const quiet = opts.quiet === true
  const timeoutMs = opts.timeoutMs ?? 120000
  let serial = opts.serial ?? null

  /** Every command this run issued, in order — the dry-run transcript and the real one. */
  const log = []

  const say = (line) => {
    if (!quiet) console.log(line)
  }

  /** Raw adb call. Returns { status, stdout, stderr }. Never throws on a non-zero status. */
  function raw(args, { writes = false, label = null } = {}) {
    const full = serial ? ['-s', serial, ...args] : args
    log.push({ args: full, writes, label })

    if (dryRun) {
      say(`  would run   adb ${full.join(' ')}${writes ? '   [WRITES TO THE PHONE]' : ''}`)
      return { status: 0, stdout: '', stderr: '', dryRun: true }
    }
    if (!existsSync(ADB)) throw new AdbError(`adb is not at ${ADB}`, null)

    if (writes) say(`  phone <-    adb ${full.join(' ')}`)
    const r = spawnSync(ADB, full, { encoding: 'utf8', timeout: timeoutMs, windowsHide: true })
    if (r.error) throw new AdbError(`adb ${full.join(' ')}: ${r.error.message}`, r)
    return { status: r.status ?? 1, stdout: (r.stdout ?? '').trim(), stderr: (r.stderr ?? '').trim(), dryRun: false }
  }

  /** adb call that must succeed. */
  function ok(args, o = {}) {
    const r = raw(args, o)
    if (r.status !== 0) throw new AdbError(`adb ${args.join(' ')} exited ${r.status}: ${r.stderr || r.stdout}`, r)
    return r
  }

  const shell = (cmd, o = {}) => raw(['shell', cmd], o)

  return {
    dryRun,
    log,
    get serial() {
      return serial
    },

    /* ---- who is attached ------------------------------------------------- */

    /**
     * Parsed `adb devices -l`. Returns every line, WITH its state, so "unauthorized"
     * (the Allow box has not been tapped) is reported as itself and never as absence.
     * @returns {{serial: string, state: string, product: string|null, model: string|null}[]}
     */
    devices() {
      if (!existsSync(ADB)) throw new AdbError(`adb is not at ${ADB}`, null)
      const r = spawnSync(ADB, ['devices', '-l'], { encoding: 'utf8', timeout: 30000, windowsHide: true })
      log.push({ args: ['devices', '-l'], writes: false, label: 'list' })
      return parseDevices(r.stdout ?? '')
    },

    /**
     * The one gate every device script opens with. Exits 3 with instructions when no
     * handset answers — it never guesses, and it never reports a check as passed or
     * failed because there was nothing to ask.
     */
    requireDevice() {
      if (dryRun) {
        serial = serial ?? 'DRY-RUN-NO-DEVICE'
        return { serial, state: 'dry-run', model: null, product: null }
      }
      if (!existsSync(ADB)) {
        console.error(`adb is not at ${ADB}. Nothing ran.`)
        process.exit(EXIT.adbMissing)
      }
      const all = this.devices()
      const ready = all.filter((d) => d.state === 'device')
      const unauthorized = all.filter((d) => d.state === 'unauthorized')

      if (ready.length === 1) {
        serial = serial ?? ready[0].serial
        return ready.find((d) => d.serial === serial) ?? ready[0]
      }
      if (ready.length > 1) {
        if (serial && ready.some((d) => d.serial === serial)) return ready.find((d) => d.serial === serial)
        console.error(`${ready.length} handsets are attached. Name one with --serial <id>:`)
        for (const d of ready) console.error(`  ${d.serial}  ${d.model ?? ''}`)
        process.exit(EXIT.noDevice)
      }
      if (unauthorized.length > 0) {
        console.error('A handset is attached but has NOT been authorised. Nothing ran.')
        console.error('On the phone: a box says "Allow USB debugging?" — tap Allow (tick "Always allow from this computer").')
        console.error('If no box appeared: unplug, replug, unlock the phone, and look again.')
        process.exit(EXIT.noDevice)
      }
      console.error('No handset answers. Nothing ran — no check has passed and no check has failed.')
      console.error('')
      console.error('On the phone, one minute:')
      console.error('  Settings -> About phone -> tap "Build number" seven times')
      console.error('  back -> System -> Developer options -> USB debugging ON')
      console.error('  plug the cable in -> tap Allow on the phone')
      console.error('')
      console.error('Then: npm run device:check')
      console.error('To see exactly what this harness would do, with no phone: npm run device:check -- --dry-run')
      process.exit(EXIT.noDevice)
    },

    /* ---- what it is ------------------------------------------------------ */

    prop(name) {
      const r = shell(`getprop ${name}`)
      return r.status === 0 && r.stdout ? r.stdout : null
    },

    /** A read-only shell line. Returns null rather than a guess when it could not be read. */
    sh(cmd) {
      const r = shell(cmd)
      return !dryRun && r.status === 0 ? r.stdout : null
    },

    /**
     * Model, Android version, API level, screen size, density and the system-bar
     * insets. Every field carries where it came from; anything that fell back to a
     * default is listed in `fallbacks` and printed, because a defaulted inset silently
     * shifts every device screenshot by a few pixels and would look like a fidelity
     * regression.
     */
    facts() {
      if (dryRun) {
        // The dry run must NOT pretend to know a handset. These are the SHAPE only, and
        // every consumer prints `dryRun: true` beside any number derived from them.
        return {
          dryRun: true,
          serial: serial ?? 'DRY-RUN-NO-DEVICE',
          model: null,
          manufacturer: null,
          androidRelease: null,
          sdk: null,
          screen: null,
          density: null,
          insets: null,
          fallbacks: ['every field — no handset was asked'],
        }
      }
      const fallbacks = []
      const size = parseWmSize(shell('wm size').stdout)
      const density = parseWmDensity(shell('wm density').stdout)
      const insets = parseInsets(shell('dumpsys window displays').stdout)

      if (!size) fallbacks.push('screen size (wm size said nothing)')
      if (!density) fallbacks.push('density (wm density said nothing)')
      if (insets.statusBarPx === null) {
        insets.statusBarPx = Math.round(24 * ((density ?? 160) / 160))
        fallbacks.push(`status-bar inset (dumpsys gave none; assumed 24dp = ${insets.statusBarPx}px)`)
      }
      if (insets.navBarPx === null) {
        insets.navBarPx = Math.round(24 * ((density ?? 160) / 160))
        fallbacks.push(`navigation-bar inset (dumpsys gave none; assumed 24dp = ${insets.navBarPx}px)`)
      }

      return {
        dryRun: false,
        serial: serial ?? this.devices().find((d) => d.state === 'device')?.serial ?? null,
        model: this.prop('ro.product.model'),
        manufacturer: this.prop('ro.product.manufacturer'),
        androidRelease: this.prop('ro.build.version.release'),
        sdk: this.prop('ro.build.version.sdk') ? Number(this.prop('ro.build.version.sdk')) : null,
        screen: size,
        density,
        dpWidth: size && density ? Number(((size.width * 160) / density).toFixed(1)) : null,
        dpHeight: size && density ? Number(((size.height * 160) / density).toFixed(1)) : null,
        insets,
        timezone: this.prop('persist.sys.timezone'),
        fallbacks,
      }
    },

    /* ---- apps ------------------------------------------------------------ */

    isInstalled(pkg) {
      const r = shell(`pm list packages ${pkg}`)
      if (dryRun) return false
      return r.status === 0 && r.stdout.split(/\r?\n/).some((l) => l.trim() === `package:${pkg}`)
    },

    versionOf(pkg) {
      const r = shell(`dumpsys package ${pkg} | grep versionName`)
      if (r.status !== 0) return null
      const m = /versionName=(\S+)/.exec(r.stdout)
      return m ? m[1] : null
    },

    /** `-r` keeps the app's data, so re-running the harness does not wipe an enrolment. */
    install(apkPath, { reinstall = true } = {}) {
      const args = ['install', ...(reinstall ? ['-r'] : []), apkPath]
      const r = raw(args, { writes: true, label: `install ${path.basename(apkPath)}` })
      const said = `${r.stdout}\n${r.stderr}`
      return { ok: r.status === 0 && !/Failure/.test(said), output: said.trim() }
    },

    launch(pkg, activity = '.MainActivity') {
      return raw(['shell', `am start -n ${pkg}/${activity}`], { writes: true, label: `launch ${pkg}` })
    },

    /** Deep link — how a check script navigates, e.g. gsguard:///clock-in */
    openUrl(url) {
      const quoted = `'${url.replace(/'/g, "'\\''")}'`
      return raw(['shell', `am start -a android.intent.action.VIEW -d ${quoted}`], { writes: true, label: `open ${url}` })
    },

    forceStop(pkg) {
      return raw(['shell', `am force-stop ${pkg}`], { writes: true, label: `force-stop ${pkg}` })
    },

    revokePermission(pkg, permission) {
      return raw(['shell', `pm revoke ${pkg} android.permission.${permission}`], { writes: true, label: `revoke ${permission}` })
    },

    grantPermission(pkg, permission) {
      return raw(['shell', `pm grant ${pkg} android.permission.${permission}`], { writes: true, label: `grant ${permission}` })
    },

    /* ---- looking at the screen ------------------------------------------- */

    /**
     * A PNG of the screen, as bytes. `exec-out` (not `shell`) so Windows does not
     * turn every 0x0A in the PNG into 0x0D 0x0A and corrupt it — the classic
     * adb-screenshot-on-Windows bug.
     *
     * A window with FLAG_SECURE (the ballot paper, R19) comes back BLACK. That is not
     * a failure of this function; `isBlack()` in ui.mjs is how a check asserts it.
     */
    screencap() {
      if (dryRun) {
        log.push({ args: ['exec-out', 'screencap', '-p'], writes: false, label: 'screencap' })
        say('  would run   adb exec-out screencap -p   (PNG of the screen)')
        return null
      }
      const full = serial ? ['-s', serial, 'exec-out', 'screencap', '-p'] : ['exec-out', 'screencap', '-p']
      log.push({ args: full, writes: false, label: 'screencap' })
      const r = spawnSync(ADB, full, { encoding: 'buffer', timeout: timeoutMs, windowsHide: true })
      if (r.error) throw new AdbError(`screencap: ${r.error.message}`, r)
      const buf = r.stdout
      if (!buf || buf.length < 8 || buf[0] !== 0x89 || buf[1] !== 0x50) {
        throw new AdbError(`screencap did not return a PNG (${buf ? buf.length : 0} bytes)`, r)
      }
      return buf
    },

    /**
     * The accessibility tree, as XML. This is how a check reads TEXT off a screen.
     *
     * THREE RUNGS, because `/dev/tty` is not dependable. `uiautomator dump /dev/tty`
     * only returns the XML when the adb shell has a terminal attached. Run from a
     * script — which is the only way this harness ever runs it — there is no terminal,
     * so uiautomator writes the tree into the void and prints its success line
     * ("UI hierchary dumped to: /dev/tty", Android's own typo) and nothing else. That
     * is what an Android 14 handset does, and it is not an error, so the status is 0
     * and only the missing `<hierarchy` gives it away.
     *
     * So: try the compressed tty dump, then the plain one, then dump to a FILE on the
     * handset and read it back. The file rung always works; the tty rungs are kept
     * ahead of it because they are one command instead of three and do work from an
     * interactive shell. The file is written under the shell user's own cache directory
     * and deleted after it is read.
     */
    uiDumpXml() {
      if (dryRun) {
        log.push({ args: ['shell', 'uiautomator dump /data/local/tmp/gs-ui.xml'], writes: false, label: 'ui dump' })
        log.push({ args: ['shell', 'cat /data/local/tmp/gs-ui.xml'], writes: false, label: 'ui dump read back' })
        say('  would run   adb shell uiautomator dump <file> && adb shell cat <file>   (the screen as text)')
        return null
      }
      const r = shell('uiautomator dump --compressed /dev/tty')
      if (r.status === 0 && r.stdout.includes('<hierarchy')) return r.stdout

      const r2 = shell('uiautomator dump /dev/tty')
      if (r2.status === 0 && r2.stdout.includes('<hierarchy')) return r2.stdout

      const remote = '/data/local/tmp/gs-ui-dump.xml'
      const r3 = shell(`uiautomator dump --compressed ${remote}`)
      const read = shell(`cat ${remote}`)
      shell(`rm -f ${remote}`)
      if (read.status === 0 && read.stdout.includes('<hierarchy')) return read.stdout

      throw new AdbError(
        `uiautomator dump failed on all three routes — tty: ${r2.stderr || r2.stdout || r.stderr || '(nothing)'}; file: ${r3.stderr || r3.stdout || '(nothing)'}; read back: ${read.stderr || '(nothing)'}`,
        read,
      )
    },

    /**
     * Is the thing on screen the Expo DEVELOPMENT LAUNCHER rather than the product?
     *
     * This matters more than it looks. A development build is a shell: it carries the
     * native code and NO JavaScript at all (there is no `index.android.bundle` inside
     * either APK). Cold-started, it opens `DevLauncherActivity` — a list of development
     * servers — under the SAME package name as the app. So `focusPackage()` answers
     * "yes, the app is on top", every text assertion then fails against the launcher's
     * own words, and a check reports FAIL when the product was never on screen.
     *
     * A FAIL means the product did the wrong thing. This is how a check tells the
     * difference and returns BLOCKED instead.
     */
    isDevLauncher() {
      const focus = this.focusedWindow()
      return typeof focus === 'string' && /devlauncher|devmenu/i.test(focus)
    },

    /**
     * Ask a development build to load its JavaScript from a Metro server and open the
     * app proper. `<scheme>://expo-development-client/?url=<metro>` is the dev client's
     * own deep link. A release or preview build never needs this and never sees it.
     */
    openDevBundle(scheme, metroUrl) {
      const url = `${scheme}://expo-development-client/?url=${encodeURIComponent(metroUrl)}`
      return raw(['shell', `am start -a android.intent.action.VIEW -d "${url}"`], { writes: true, label: `load the bundle from ${metroUrl}` })
    },

    /**
     * `adb reverse` — the handset's own localhost:<port> reaches THIS machine's. It is
     * how a phone on a USB cable, and an emulator, both reach a Metro server or the dev
     * API without anybody typing a LAN address that changes with the wifi.
     */
    reverse(port, hostPort = port) {
      const r = raw(['reverse', `tcp:${port}`, `tcp:${hostPort}`], { writes: false, label: `reverse ${port}` })
      return r.status === 0
    },

    /** Which window has focus — how "is the OS permission box on top?" is answered. */
    focusedWindow() {
      const r = shell('dumpsys window | grep -E "mCurrentFocus|mFocusedApp"')
      if (dryRun || r.status !== 0) return null
      const m = /mCurrentFocus=\S+\s+\S+\s+([^}\s]+)/.exec(r.stdout) ?? /mCurrentFocus=.*?([A-Za-z0-9_.]+\/[A-Za-z0-9_.]+)/.exec(r.stdout)
      return m ? m[1] : r.stdout.split(/\r?\n/)[0] ?? null
    },

    /* ---- driving it ------------------------------------------------------ */

    tap(x, y) {
      return raw(['shell', `input tap ${Math.round(x)} ${Math.round(y)}`], { writes: true, label: `tap ${Math.round(x)},${Math.round(y)}` })
    },
    swipe(x1, y1, x2, y2, ms = 300) {
      return raw(['shell', `input swipe ${Math.round(x1)} ${Math.round(y1)} ${Math.round(x2)} ${Math.round(y2)} ${ms}`], { writes: true, label: 'swipe' })
    },
    /** A press held open — how the press-state of a control is photographed (G7). */
    touchDown(x, y) {
      return raw(['shell', `input motionevent DOWN ${Math.round(x)} ${Math.round(y)}`], { writes: true, label: 'touch down' })
    },
    touchUp(x, y) {
      return raw(['shell', `input motionevent UP ${Math.round(x)} ${Math.round(y)}`], { writes: true, label: 'touch up' })
    },
    text(s) {
      // input text has no escape for a space; %s is the documented stand-in.
      const safe = s.replace(/ /g, '%s').replace(/(["'$`\\])/g, '\\$1')
      return raw(['shell', `input text ${safe}`], { writes: true, label: 'type' })
    },
    key(name) {
      return raw(['shell', `input keyevent ${name}`], { writes: true, label: `key ${name}` })
    },
    back() {
      return this.key('KEYCODE_BACK')
    },
    home() {
      return this.key('KEYCODE_HOME')
    },
    lockScreen() {
      return raw(['shell', 'input keyevent KEYCODE_SLEEP'], { writes: true, label: 'lock the screen' })
    },
    wakeScreen() {
      return raw(['shell', 'input keyevent KEYCODE_WAKEUP'], { writes: true, label: 'wake the screen' })
    },

    /* ---- the phone's own settings (each one is a change to somebody's phone) ---- */

    /**
     * Airplane mode. `cmd connectivity` is the only route that works from the adb
     * shell on Android 11+; the old `settings put global airplane_mode_on` no longer
     * takes effect without a broadcast the shell may not send. The result is READ BACK
     * and returned, so a caller never assumes it worked.
     */
    airplane(on) {
      const r = raw(['shell', `cmd connectivity airplane-mode ${on ? 'enable' : 'disable'}`], { writes: true, label: `airplane ${on ? 'on' : 'off'}` })
      const back = shell('settings get global airplane_mode_on')
      return { issued: r.status === 0, readBack: dryRun ? null : back.stdout, asked: on }
    },

    /** Do Not Disturb. `cmd notification set_dnd on|priority|alarms|none|off`. */
    dnd(mode) {
      const r = raw(['shell', `cmd notification set_dnd ${mode}`], { writes: true, label: `Do Not Disturb ${mode}` })
      return { issued: r.status === 0, asked: mode, output: r.stdout || r.stderr }
    },

    /**
     * Notification-policy access — the permission behind the Guard App's DND bypass.
     * Granting it from the shell is what makes G30's before/after
     * automatable without a human walking into the phone's settings twice.
     */
    grantDndAccess(pkg, allow = true) {
      const r = raw(['shell', `cmd notification ${allow ? 'allow_dnd' : 'disallow_dnd'} ${pkg}`], { writes: true, label: `${allow ? 'allow' : 'disallow'} DND access for ${pkg}` })
      return { issued: r.status === 0, output: r.stdout || r.stderr }
    },

    /** Everything Android currently holds about posted notifications — the honest record for G30. */
    notificationDump() {
      return shell('dumpsys notification --noredact').stdout
    },

    fontScale(scale) {
      return raw(['shell', `settings put system font_scale ${scale}`], { writes: true, label: `font scale ${scale}` })
    },

    /* ---- the timezone, which is the whole of check R35 -------------------- */

    /**
     * READ the handset's timezone. Two sources, because they can disagree: the system
     * property (what the platform holds) and `date` (what a process sees).
     */
    readTimezone() {
      return {
        prop: this.prop('persist.sys.timezone'),
        date: dryRun ? null : shell('date').stdout,
        auto: dryRun ? null : shell('settings get global auto_time_zone').stdout,
      }
    },

    /**
     * SET the handset's timezone. See timezone.mjs for the ladder and why each rung
     * exists; this is only the issuing. Nothing here claims success — the caller reads
     * it back and decides.
     */
    tryTimezoneRung(rung, zone) {
      const cmds = {
        'auto-off': `settings put global auto_time_zone 0`,
        'service-call-33': `service call alarm 33 s16 ${zone}`,
        'service-call-3': `service call alarm 3 s16 ${zone}`,
        'cmd-time-zone-detector': `cmd time_zone_detector suggest_manual_time_zone --zone_id ${zone}`,
        'setprop': `setprop persist.sys.timezone ${zone}`,
      }
      const cmd = cmds[rung]
      if (!cmd) throw new Error(`unknown timezone rung: ${rung}`)
      return raw(['shell', cmd], { writes: true, label: `timezone: ${rung}` })
    },

    /* ---- files ----------------------------------------------------------- */

    pull(remote, local) {
      mkdirSync(path.dirname(local), { recursive: true })
      return raw(['pull', remote, local])
    },

    /** Write the command transcript, so a run can be read back later. */
    writeTranscript(file) {
      mkdirSync(path.dirname(file), { recursive: true })
      writeFileSync(file, JSON.stringify({ dryRun, serial, commands: log }, null, 2) + '\n', 'utf8')
      return file
    },
  }
}

/* ---- pure parsers: these are what the self-test exercises ---------------- */

/** @returns {{serial: string, state: string, product: string|null, model: string|null}[]} */
export function parseDevices(stdout) {
  return String(stdout)
    .split(/\r?\n/)
    .slice(1)
    .map((l) => l.trim())
    .filter((l) => l.length > 0 && !l.startsWith('*'))
    .map((l) => {
      const parts = l.split(/\s+/)
      const get = (k) => parts.find((p) => p.startsWith(`${k}:`))?.slice(k.length + 1) ?? null
      return { serial: parts[0], state: parts[1] ?? 'unknown', product: get('product'), model: get('model') }
    })
    .filter((d) => d.serial && d.state !== 'unknown')
}

/** `Physical size: 1080x2400` — and `Override size:` wins when present, because that is what is drawn. */
export function parseWmSize(stdout) {
  const s = String(stdout)
  const override = /Override size:\s*(\d+)x(\d+)/.exec(s)
  const physical = /Physical size:\s*(\d+)x(\d+)/.exec(s)
  const m = override ?? physical
  return m ? { width: Number(m[1]), height: Number(m[2]), source: override ? 'override' : 'physical' } : null
}

/** `Physical density: 440` — again, an override wins. */
export function parseWmDensity(stdout) {
  const s = String(stdout)
  const override = /Override density:\s*(\d+)/.exec(s)
  const physical = /Physical density:\s*(\d+)/.exec(s)
  const m = override ?? physical
  return m ? Number(m[1]) : null
}

/**
 * The system-bar insets, in DEVICE PIXELS, out of `dumpsys window displays`.
 *
 * Three shapes have to be handled because Android changed the dump twice:
 *   Android 13/14  `InsetsState: ... source [statusBars]: frame=[0,0][1080,110]`
 *   Android 11/12  `mDisplayCutout=DisplayCutout{... safeInsets=Rect(0, 110 - 0, 0) ...}`
 *   older          `stable=(0,63,0,126)`
 * Anything it cannot find comes back null and the CALLER records a fallback — it never
 * quietly guesses, because a wrong status-bar inset shifts every device screenshot.
 */
export function parseInsets(stdout) {
  const s = String(stdout)
  const out = { statusBarPx: null, navBarPx: null, source: null }

  const status = /source\s*\[?statusBars?\]?[^\n]*frame=\[\d+,\d+\]\[\d+,(\d+)\]/i.exec(s)
  const nav = /source\s*\[?navigationBars?\]?[^\n]*frame=\[\d+,(\d+)\]\[\d+,(\d+)\]/i.exec(s)
  if (status) {
    out.statusBarPx = Number(status[1])
    out.source = 'InsetsState'
  }
  if (nav) {
    out.navBarPx = Number(nav[2]) - Number(nav[1])
    out.source = out.source ?? 'InsetsState'
  }

  if (out.statusBarPx === null) {
    const safe = /safeInsets=Rect\(\s*\d+\s*,\s*(\d+)\s*-\s*\d+\s*,\s*(\d+)\s*\)/.exec(s)
    if (safe) {
      out.statusBarPx = Number(safe[1])
      if (out.navBarPx === null && Number(safe[2]) > 0) out.navBarPx = Number(safe[2])
      out.source = 'DisplayCutout.safeInsets'
    }
  }
  if (out.statusBarPx === null) {
    const stable = /stable=\((\d+),(\d+),(\d+),(\d+)\)/.exec(s)
    if (stable) {
      out.statusBarPx = Number(stable[2])
      if (out.navBarPx === null) out.navBarPx = Number(stable[4])
      out.source = 'stable'
    }
  }
  return out
}
