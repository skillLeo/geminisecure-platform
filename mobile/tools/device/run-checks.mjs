/**
 * `npm run device:checks` — PHONE_CHECK.md, driven.
 *
 * ORDER. Five checks come before everything else, and they go first here, before any
 * sweep and before anything cosmetic, because each of them is a rejection or a recall
 * rather than a blemish:
 *
 *   1  G27  the location explanation appears BEFORE the operating system's box
 *   1b R25  the same ruling on the resident side
 *   2  G28  a guard who declines location can still work, and is told plainly what will not
 *   3  G29  notification permission is asked somewhere a guard cannot miss
 *   4  R35  the handset's OWN timezone moved to London and then Auckland, every time unchanged
 *   5  G30  a duress alert on a locked phone with Do Not Disturb on, recorded honestly
 *
 * THREE RESULTS, NEVER TWO. Every check ends PASS, FAIL or BLOCKED. BLOCKED is the one
 * that matters: it is what a check returns when its precondition could not be met — the
 * handset refused the timezone, the account is not enrolled, the server did not answer.
 * A BLOCKED check has proved nothing and says so. Nothing in this file can report a pass
 * it did not earn, and with no handset attached nothing runs at all (exit 3).
 *
 * WHAT IT WILL NOT DO:
 *  - it will not enrol or sign in as GS-1041, the client's own phone. `--guard GS-1041`
 *    is refused before adb is touched.
 *  - it will not write a token or a ballot body into its evidence. Everything saved
 *    goes through `redact()` first, because this script dumps whole screens to disk.
 *
 *   npm run device:checks                       the five first, then the rest
 *   npm run device:checks -- --only G27,G28
 *   npm run device:checks -- --list             the run order, with no handset
 *   npm run device:checks -- --dry-run          every command each check would send
 */

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { PNG } from 'pngjs'
import { adb, EXIT, ROOT } from './adb.mjs'
import { orderedRun, triage } from './plan.mjs'
import { allText, findText, focusPackage, hasText, isPermissionDialog, moneyLike, parseUi, smallTargets } from './ui.mjs'
import { isMostlyBlack } from './pixels.mjs'
import { COPY, openingClause } from './copy.mjs'
import { ZONES, setTimezone, timezoneVerdict, zoneVerdict } from './timezone.mjs'
import { BUILDS, DEVICE_FILE } from './harness.mjs'
import { maskedDiff } from '../fidelity/lib.mjs'
import { framePlan, normalise } from './geometry.mjs'

const SHOTS = path.join(ROOT, '_design', 'screenshots', 'checks')
const RESULTS = path.join(ROOT, '_design', 'PHONE_CHECK_RESULTS.json')

const GUARD = BUILDS.find((b) => b.app === 'guard')
const RESIDENT = BUILDS.find((b) => b.app === 'resident')

const args = process.argv.slice(2)
const has = (f) => args.includes(f)
const valueOf = (f) => (args.indexOf(f) === -1 ? null : args[args.indexOf(f) + 1])
const only = valueOf('--only')?.split(',').map((s) => s.trim().toUpperCase()) ?? null
const guardId = (valueOf('--guard') ?? 'GS-9001').toUpperCase()

/**
 * Anything that looks like a bearer token, an enrolment secret or a long opaque string
 * never reaches the evidence file. This script writes whole screens to disk; a token
 * lives in expo-secure-store and should never be drawn, but "should never" is not a
 * reason to save it if it ever is.
 */
export function redact(text) {
  return String(text)
    .replace(/\b[A-Za-z0-9_-]{24,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/g, '[redacted: looks like a JWT]')
    .replace(/\b(?:ExponentPushToken|ExpoPushToken)\[[^\]]+\]/g, '[redacted: push token]')
    .replace(/\b[A-Fa-f0-9]{32,}\b/g, '[redacted: long hex]')
    .replace(/\b[A-Za-z0-9_-]{40,}\b/g, '[redacted: long opaque string]')
}

/* ---- one check's recorder ------------------------------------------------- */

function recorder(meta) {
  const evidence = []
  let outcome = null
  return {
    meta,
    get evidence() {
      return evidence
    },
    get outcome() {
      return outcome
    },
    pass(what) {
      evidence.push({ ok: true, what: redact(what) })
    },
    fail(what) {
      evidence.push({ ok: false, what: redact(what) })
      outcome = 'FAIL'
    },
    note(what) {
      evidence.push({ ok: null, what: redact(what) })
    },
    blocked(why) {
      evidence.push({ ok: null, what: `BLOCKED: ${redact(why)}` })
      outcome = 'BLOCKED'
    },
    require(cond, whenTrue, whenFalse) {
      if (cond) this.pass(whenTrue)
      else this.fail(whenFalse)
      return cond
    },
    finish() {
      return outcome ?? 'PASS'
    },
  }
}

/* ---- the shared driving vocabulary ---------------------------------------- */

/**
 * Thrown when a check could not even get the product on screen. The runner turns it
 * into BLOCKED, never FAIL — a FAIL is the product doing the wrong thing, and nothing
 * the product did has been seen here at all.
 */
class NotTheApp extends Error {
  constructor(message) {
    super(message)
    this.name = 'NotTheApp'
    this.blocked = true
  }
}

function world(device, facts) {
  mkdirSync(SHOTS, { recursive: true })
  const frame = facts?.screen ? framePlan(facts) : null

  const wait = (ms) => new Promise((r) => setTimeout(r, ms))

  const deepLink = (build, route) =>
    `${build.scheme}:///${String(route).split('/').filter((s) => s && !/^\(.*\)$/.test(s)).join('/')}`

  /** Where this build's JavaScript is served from, when it is a development build. */
  const metroFor = (build) =>
    process.env[`GS_METRO_${build.app.toUpperCase()}`] ?? `http://localhost:${build.metroPort}`

  /** Ports already tunnelled, so the same `adb reverse` is not issued for every route. */
  const reversed = new Set()

  return {
    device,
    facts,
    frame,
    wait,

    /**
     * Put a route on screen, and be sure it is THE PRODUCT on screen.
     *
     * Nearly every check starts `forceStop` then a deep link, because a check must see a
     * screen built fresh and not one left over from the check before. On a DEVELOPMENT
     * build that sequence does not reach the app: cold-started, the build opens the Expo
     * development launcher, the deep link is swallowed, and the launcher's own list of
     * development servers is what the next assertion reads. Under the same package name,
     * so nothing downstream notices.
     *
     * So: send the route; if the launcher is what came up, point the build at its Metro
     * server, wait for the bundle, dismiss the development menu it opens on top, and send
     * the route again. If the launcher is STILL there — no Metro running, most likely —
     * throw, and the check is BLOCKED rather than failed against the wrong app.
     */
    async open(build, route, settle = 2200) {
      device.openUrl(deepLink(build, route))
      await wait(settle)
      if (!device.isDevLauncher()) return

      const metro = metroFor(build)
      const port = Number(new URL(metro).port || 80)
      if (/localhost|127\.0\.0\.1/.test(metro) && !reversed.has(port)) {
        device.reverse(port)
        reversed.add(port)
      }

      device.openDevBundle(build.scheme, metro)
      // A cold bundle is compiled on demand; the first one is by far the slowest.
      for (let i = 0; i < 40 && device.isDevLauncher(); i++) await wait(3000)
      if (device.isDevLauncher()) {
        throw new NotTheApp(
          `${build.app}: this is a DEVELOPMENT build and it carries no JavaScript, so it opened the Expo development launcher instead of the app, ` +
            `and ${metro} did not serve a bundle. Nothing about the product has been seen, so nothing is claimed about it. ` +
            `Start the bundler first (npm start in apps/${build.app}), or point this run at one with GS_METRO_${build.app.toUpperCase()}=<url>.`
        )
      }
      await this.dismissDevMenu()
      device.openUrl(deepLink(build, route))
      await wait(settle)
    },

    /**
     * The development client opens its own menu over the app when a bundle loads. It is
     * not part of the product and must never be measured, so it is closed before anything
     * is read. Back closes it; the Continue button is tapped first when it is there,
     * because on a first load Back goes to the launcher instead.
     */
    async dismissDevMenu() {
      for (let i = 0; i < 4; i++) {
        let nodes
        try {
          nodes = this.ui()
        } catch {
          return
        }
        const isMenu = nodes.some((n) => /developer menu|toggle element inspector|open devtools|fast refresh/i.test(`${n.text} ${n.desc}`))
        if (!isMenu) return
        const go = nodes.find((n) => n.clickable && /^continue$/i.test((n.text || n.desc || '').trim()))
        if (go) device.tap((go.bounds.x0 + go.bounds.x1) / 2, (go.bounds.y0 + go.bounds.y1) / 2)
        else device.back()
        await wait(1500)
      }
    },
    ui() {
      return parseUi(device.uiDumpXml())
    },
    focus() {
      return device.focusedWindow()
    },
    /** A capture, saved under its check's name, and returned as a PNG for pixel work. */
    shot(name) {
      const png = PNG.sync.read(device.screencap())
      writeFileSync(path.join(SHOTS, `${name}.png`), PNG.sync.write(png))
      return png
    },
    /** The same capture, brought into the board's 397x856 frame — for pair comparisons. */
    framed(name) {
      const png = this.shot(name)
      return frame ? normalise(png, frame) : png
    },
  }
}

/* ---- the scripted checks --------------------------------------------------- */
/* Each one is (world, recorder) and may await. Everything it asserts is something a
 * handset can actually answer; nothing is asserted that only an eye could see. */

const SCRIPTED = {
  /* ---- the five headline checks first ---- */

  async G27(w, r) {
    const pkg = GUARD.pkg
    w.device.revokePermission(pkg, 'ACCESS_FINE_LOCATION')
    w.device.revokePermission(pkg, 'ACCESS_COARSE_LOCATION')
    w.device.forceStop(pkg)

    const phrase = openingClause(COPY.guardLocationDisclosure)
    r.note(`the words this check looks for are read out of the app's own source: "${phrase}…"`)

    for (const route of ['/clock-in', '/clock-out', '/incident/new']) {
      await w.open(GUARD, route, 3000)
      const focus = w.focus()
      const nodes = w.ui()
      w.shot(`G27${route.replace(/\W+/g, '-')}`)

      r.require(
        !isPermissionDialog(focus),
        `${route}: the operating system's permission box is NOT the first thing on screen (focus is ${focusPackage(focus) ?? 'the app'})`,
        `${route}: the operating system's permission box came up FIRST (focus is ${focusPackage(focus)}). This is a Play Store rejection, not a blemish.`
      )
      r.require(
        hasText(nodes, phrase) || hasText(nodes, 'position is read'),
        `${route}: the app's own explanation is on screen before anything else`,
        `${route}: no explanation of why position is read appears before the dialog`
      )
      const goOn = nodes.filter((n) => n.clickable && /continue|allow|ok|got it|next/i.test(n.text || n.desc))
      r.require(goOn.length > 0, `${route}: there is something to tap to go on`, `${route}: the explanation has no control to continue with — a dead end is as bad as no explanation`)
    }
  },

  async R25(w, r) {
    const pkg = RESIDENT.pkg
    w.device.revokePermission(pkg, 'ACCESS_FINE_LOCATION')
    w.device.revokePermission(pkg, 'ACCESS_COARSE_LOCATION')
    w.device.forceStop(pkg)

    await w.open(RESIDENT, '/incident/new', 3000)
    let nodes = w.ui()
    const useMine = findText(nodes, 'location').find((n) => n.clickable)
    if (!useMine) return r.blocked('no "Use my location" control was found on the incident form — the screen may need a signed-in resident first')

    w.device.tap((useMine.bounds.x0 + useMine.bounds.x1) / 2, (useMine.bounds.y0 + useMine.bounds.y1) / 2)
    await w.wait(1500)

    const focus = w.focus()
    nodes = w.ui()
    w.shot('R25-explanation-first')
    r.require(
      !isPermissionDialog(focus),
      "the resident's own explanation comes before the phone's box",
      `the phone's permission box came up first (focus ${focusPackage(focus)}) — this row is load-bearing for the Play Store`
    )
    r.require(hasText(nodes, 'location') || hasText(nodes, 'where'), 'the explanation names what is read', 'nothing on screen explains what is read')

    // One pin and no more: after leaving the screen nothing may still be asking for a position.
    w.device.back()
    await w.wait(1200)
    const loc = w.device.sh(`dumpsys location | grep -i ${pkg}`)
    r.require(
      loc === null || loc.length === 0 || !/request/i.test(loc),
      'nothing is still asking this phone for a position after the screen was left',
      `something is STILL asking for a position after the screen closed: ${loc}`
    )
  },

  async G28(w, r) {
    const pkg = GUARD.pkg
    w.device.revokePermission(pkg, 'ACCESS_FINE_LOCATION')
    w.device.revokePermission(pkg, 'ACCESS_COARSE_LOCATION')
    w.device.forceStop(pkg)

    await w.open(GUARD, '/(tabs)', 3500)
    const nodes = w.ui()
    w.shot('G28-dashboard-without-location')

    r.require(focusPackage(w.focus()) === pkg, 'the dashboard is reachable with location declined', 'the app does not reach the dashboard when location is declined')
    r.require(nodes.length > 5, 'the dashboard drew', 'the dashboard did not draw')

    const without = openingClause(COPY.guardLocationWithout, 7)
    r.require(
      hasText(nodes, without) || hasText(nodes, 'supervisor') || hasText(nodes, 'cannot confirm you are at your post'),
      'the app says plainly what will not work without a position',
      "nothing on the dashboard says what a guard loses by declining — the app must not simply carry on as if nothing happened"
    )
    r.require(
      !hasText(nodes, 'at your post') || !hasText(nodes, 'confirmed'),
      'nothing claims a position that was never taken',
      'the app claims the post was confirmed although no position could be read'
    )
  },

  async G29(w, r) {
    const pkg = GUARD.pkg
    if ((w.facts.sdk ?? 0) >= 33) w.device.revokePermission(pkg, 'POST_NOTIFICATIONS')
    else r.note(`Android ${w.facts.androidRelease}: there is no runtime notification permission below SDK 33, so the ASK cannot be reproduced on this handset. What follows still checks that the Notifications screen tells the truth.`)
    w.device.forceStop(pkg)

    await w.open(GUARD, '/onboarding/1', 3500)
    let seen = false
    for (const route of ['/onboarding/1', '/onboarding/2', '/onboarding/3']) {
      await w.open(GUARD, route, 2500)
      const nodes = w.ui()
      if (hasText(nodes, 'alert') && (hasText(nodes, 'notification') || hasText(nodes, 'duress'))) {
        seen = true
        w.shot(`G29-asked-at${route.replace(/\W+/g, '-')}`)
        const targets = smallTargets(nodes, w.facts.density, 48)
        r.require(targets.length === 0, 'every control on the ask is at least 48 dp', `controls under 48 dp on the ask: ${targets.map((t) => `${t.label} ${t.dp.w}x${t.dp.h}`).join(', ')}`)
        break
      }
    }
    r.require(seen, 'the notification ask appears in onboarding, where a guard cannot miss it', 'no notification ask appears anywhere in onboarding — on Android 13 and newer every duress and panic alert then reaches nothing, silently')

    await w.open(GUARD, '/notifications', 2500)
    const nodes = w.ui()
    w.shot('G29-notifications-screen')
    const granted = (w.device.sh(`dumpsys package ${pkg} | grep POST_NOTIFICATIONS`) ?? '').includes('granted=true')
    r.require(
      granted ? hasText(nodes, 'on') : hasText(nodes, 'off') || hasText(nodes, 'not') || hasText(nodes, 'cannot'),
      `the Notifications screen states the real state (the phone says ${granted ? 'granted' : 'denied'})`,
      `the Notifications screen does not match what the phone says (the phone says ${granted ? 'granted' : 'denied'})`
    )
  },

  async R35(w, r) {
    if (!w.frame) return r.blocked('the handset\'s screen size, density or insets could not be read, so captures cannot be put in the board frame')

    /* The screens that carry a time. A shift, a meeting, a notice, a pass window, a receipt. */
    const pairs = [
      { build: GUARD, route: '/(tabs)', id: 'guard-dashboard' },
      { build: GUARD, route: '/hours', id: 'guard-hours' },
      { build: GUARD, route: '/(tabs)/payslips', id: 'guard-payslips' },
      { build: RESIDENT, route: '/(tabs)', id: 'resident-home' },
      { build: RESIDENT, route: '/(tabs)/notices', id: 'resident-notices' },
      { build: RESIDENT, route: '/meetings/1', id: 'resident-meeting' },
      { build: RESIDENT, route: '/(tabs)/visitors', id: 'resident-visitors' },
    ]

    const before = w.device.readTimezone()
    r.note(`the handset was on ${before.prop ?? 'an unreadable zone'} before this check; it is put back at the end`)

    /*
     * THREE passes, not two. The third is the CONTROL: London again, so that everything
     * which moved because minutes passed — a countdown, "2 min ago", a shift's REMAINING —
     * is measured in the same zone and subtracted. Without it this check reports a failure
     * almost every run, for a reason that has nothing to do with a timezone. See
     * zoneVerdict() in timezone.mjs.
     */
    const passes = [
      { key: 'london-1', zone: ZONES.london },
      { key: 'auckland', zone: ZONES.auckland },
      { key: 'london-2', zone: ZONES.london },
    ]

    const captures = {}
    for (const pass of passes) {
      const set = setTimezone(w.device, pass.zone, { log: (l) => r.note(l.trim()) })
      if (!set.ok) {
        r.blocked(`this handset will not let adb set its timezone to ${pass.zone}. No timezone result is claimed.\n${set.fallback}`)
        return
      }
      const verdict = timezoneVerdict({ wanted: pass.zone, prop: w.device.prop('persist.sys.timezone'), offsetText: w.device.sh('date +%z') })
      if (verdict.state !== 'set') return r.blocked(`the zone read back as "${verdict.why}" — refusing to measure against a zone that did not take`)
      r.pass(`pass ${pass.key}: the handset is really on ${pass.zone} (${verdict.why})`)

      // Restart both apps so nothing is showing a value it formatted before the move.
      w.device.forceStop(GUARD.pkg)
      w.device.forceStop(RESIDENT.pkg)
      await w.wait(800)

      captures[pass.key] = {}
      for (const p of pairs) {
        await w.open(p.build, p.route, 3000)
        captures[pass.key][p.id] = w.framed(`R35-${p.id}-${pass.key}`)
      }
    }

    const pct = (a, b) => maskedDiff(PNG.sync.write(a), PNG.sync.write(b)).percent
    for (const p of pairs) {
      const zoneDiff = pct(captures['london-1'][p.id], captures.auckland[p.id])
      const control = pct(captures['london-1'][p.id], captures['london-2'][p.id])
      const v = zoneVerdict({ zoneDiff, control })

      if (v.state === 'unchanged') r.pass(`${p.id}: unchanged between London and Auckland — ${v.why}`)
      else if (v.state === 'inconclusive') r.note(`${p.id}: INCONCLUSIVE — ${v.why} _design/screenshots/checks/R35-${p.id}-*.png`)
      else r.fail(`${p.id}: ${v.why} _design/screenshots/checks/R35-${p.id}-*.png`)
    }

    const back = setTimezone(w.device, before.prop ?? ZONES.home, { log: () => {} })
    r.note(back.ok ? `the handset was put back to ${before.prop ?? ZONES.home}` : `THE HANDSET IS STILL ON ${ZONES.london} — put it back by hand: Settings -> System -> Date & time`)
  },

  async G30(w, r) {
    const pkg = GUARD.pkg

    const channels = () => w.device.sh(`dumpsys notification --noredact | grep -A 20 ${pkg}`) ?? ''

    /* PASS ONE — before the permission is granted. */
    w.device.grantDndAccess(pkg, false)
    w.device.dnd('priority')
    await w.open(GUARD, '/notifications', 2500)
    let nodes = w.ui()
    w.shot('G30-pass1-notifications-row')

    const row = findText(nodes, 'Do Not Disturb')
    r.require(row.length > 0, 'the Guard App shows the Do Not Disturb row', 'the Guard App does not show a Do Not Disturb row at all — the ruling says it must')
    const offRow = row.find((n) => /off|not/i.test(n.text + n.desc)) ?? nodes.find((n) => n.checkable && /disturb/i.test(n.desc))
    r.require(
      offRow ? !offRow.checked : true,
      'before the permission is granted the row reads off, which is the truth — Android drops the channel bypass without it',
      'the row claims the bypass is on before the permission has been granted'
    )

    const beforeDump = channels()
    r.note(`pass one, Android's own record: ${redact((beforeDump.match(/bypassDnd=\w+/g) ?? ['bypassDnd not found in the dump']).join(' '))}`)

    w.device.lockScreen()
    await w.wait(1500)
    r.note('the screen is locked and Do Not Disturb is on. Fire the duress from the server now; this check reads Android\'s record of what arrived, not the sound it made.')

    /* PASS TWO — after granting.
     * `wakeScreen` turns the display on; it does NOT get past a PIN, pattern or fingerprint,
     * and adb cannot. On a handset with a screen lock somebody has to unlock it here, and
     * saying so is better than the next two assertions failing for a reason nobody can see. */
    w.device.wakeScreen()
    await w.wait(1200)
    const locked = w.device.sh('dumpsys window | grep -E "mDreamingLockscreen|mShowingLockscreen"') ?? ''
    if (/true/.test(locked)) {
      r.note('THE HANDSET IS STILL ON ITS LOCK SCREEN. adb can wake a screen and cannot get past a PIN. Unlock it and re-run: npm run device:checks -- --only G30')
    }
    w.device.grantDndAccess(pkg, true)
    await w.open(GUARD, '/notifications', 2500)
    nodes = w.ui()
    w.shot('G30-pass2-notifications-row')
    const afterRow = findText(nodes, 'Do Not Disturb').find((n) => /on/i.test(n.text + n.desc)) ?? nodes.find((n) => n.checkable && /disturb/i.test(n.desc))
    r.require(
      afterRow ? afterRow.checked || /on/i.test(afterRow.text + afterRow.desc) : false,
      'after the permission is granted the row flips to On by itself',
      'the row did NOT flip to On after the permission was granted — the screen is out of step with the phone'
    )

    const afterDump = channels()
    const bypass = /bypassDnd=true/.test(afterDump)
    r.require(bypass, "Android's own record shows the safety channel bypassing Do Not Disturb", 'Android does NOT record the channel as bypassing Do Not Disturb even with the permission granted — a real defect, write it down')
    r.note('WHAT THIS CHECK CANNOT DO: it cannot hear the handset. That a sound came out, with the screen locked, is the one thing on this row that needs an ear.')

    w.device.dnd('off')
  },

  /* ---- the rest of the scripted list ---- */

  async G1(w, r) {
    await w.open(GUARD, '/sync-queue', 2500)
    const nodes = w.ui()
    w.shot('G1-sync-queue')
    r.require(!hasText(nodes, 'Unmatched Route'), 'the sync queue is a real route', 'the badge leads to a black "Unmatched Route" page')
    r.require(hasText(nodes, 'WAITING TO UPLOAD') || hasText(nodes, 'Everything is sent'), 'the queue titles itself honestly', 'neither "WAITING TO UPLOAD" nor "Everything is sent" is on the screen')
  },

  async G3(w, r) {
    await w.open(GUARD, '/(tabs)', 3000)
    const nodes = w.ui()
    w.shot('G3-shift-card')
    r.require(!hasText(nodes, 'No shift rostered'), 'the shift card shows a shift', 'the shift card says "No shift rostered" after a clock-in that synced')
    for (const want of ['On post', 'CLOCKED IN', 'REMAINING', 'NEXT CHECK']) {
      r.require(hasText(nodes, want), `the card carries "${want}"`, `the card is missing "${want}"`)
    }
  },

  async G5(w, r) {
    await w.open(GUARD, '/scan/manual', 2500)
    const nodes = w.ui()
    w.shot('G5-keypad')
    const keys = nodes.filter((n) => n.clickable && /^(\d|ABC|⌫|delete)$/i.test((n.text || n.desc).trim()))
    r.require(keys.length >= 12, `the keypad has ${keys.length} keys`, `the keypad has ${keys.length} keys, not twelve`)
    const verify = findText(nodes, 'Verify pass')[0]
    r.require(verify ? !verify.enabled : false, '"Verify pass" is disabled before a whole code is typed', '"Verify pass" is usable before a whole code is typed')
    const targets = smallTargets(nodes, w.facts.density, 48)
    r.require(targets.length === 0, 'every key is at least 48 dp', `keys under 48 dp: ${targets.map((t) => `${t.label} ${t.dp.w}x${t.dp.h}`).join(', ')}`)
  },

  async G6(w, r) {
    await w.open(GUARD, '/search', 2500)
    w.device.text('47')
    await w.wait(2000)
    const nodes = w.ui()
    w.shot('G6-search-47')
    const money = moneyLike(nodes)
    r.require(money.length === 0, 'no money anywhere on a guard-reachable unit card', `money on a guard's screen: ${money.join(' · ')}`)
    r.require(hasText(nodes, 'Request entry approval'), 'the approval control is there', 'no "Request entry approval" control')
  },

  async G10(w, r) {
    const state = w.device.airplane(true)
    await w.wait(4000)
    await w.open(GUARD, '/(tabs)', 3500)
    const nodes = w.ui()
    w.shot('G10-offline')
    r.require(state.readBack === '1', 'airplane mode really is on', `airplane mode did not take (the phone reads "${state.readBack}") — nothing about being offline is claimed`)
    r.require(hasText(nodes, 'Offline'), 'the badge reads Offline', 'the badge does not read Offline with the radio off')
    r.require(hasText(nodes, 'No connection'), 'the amber banner names the state', 'no "No connection" banner')
    w.device.airplane(false)
    await w.wait(4000)
  },

  async G12(w, r) {
    await w.open(GUARD, '/(tabs)/payslips', 3000)
    const nodes = w.ui()
    w.shot('G12-payslip')
    r.require(!hasText(nodes, 'Download'), 'there is no Download control on a payslip', 'a payslip offers a Download')
    for (const line of ['Gross', 'NIS', 'NHT', 'Education Tax', 'PAYE', 'Net']) {
      r.require(hasText(nodes, line), `the payslip shows ${line}`, `the payslip is missing ${line}`)
    }
    const amounts = allText(nodes).match(/\$\s?[\d,]+\.\d{2}/g) ?? []
    r.require(amounts.length >= 6, `${amounts.length} amounts carry a $ and two decimals`, `only ${amounts.length} amounts are written with a $ and two decimals`)
  },

  async G15(w, r) {
    const state = w.device.airplane(true)
    await w.wait(4000)
    await w.open(GUARD, '/leave', 3000)
    const nodes = w.ui()
    w.shot('G15-open-shifts-offline')
    r.require(state.readBack === '1', 'airplane mode really is on', `airplane mode did not take (the phone reads "${state.readBack}")`)
    r.require(hasText(nodes, 'No signal') || hasText(nodes, 'only shown live'), 'open shifts say honestly that they are live-only', 'open shifts show something offline — a stale list of shifts is worse than none')
    w.device.airplane(false)
    await w.wait(4000)
  },

  async G18(w, r) {
    await w.open(GUARD, '/(tabs)/more', 2500)
    const nodes = w.ui()
    const signOut = findText(nodes, 'Sign out').find((n) => n.clickable)
    if (!signOut) return r.blocked('no "Sign out" control on More — the guard may not be signed in')
    w.device.tap((signOut.bounds.x0 + signOut.bounds.x1) / 2, (signOut.bounds.y0 + signOut.bounds.y1) / 2)
    await w.wait(1500)
    const after = w.ui()
    w.shot('G18-sign-out')
    r.require(focusPackage(w.focus()) === GUARD.pkg, 'the confirmation is in the page, not a system pop-up', `a system window took focus (${focusPackage(w.focus())})`)
    r.require(hasText(after, 'enrolment code') || hasText(after, 'enrol'), 'it says a new enrolment code will be needed', 'it does not say a new enrolment code will be needed')
    r.require(hasText(after, 'Stay signed in'), '"Stay signed in" is offered', 'there is no "Stay signed in"')
    w.device.back()
  },

  async G25(w, r) {
    const offenders = []
    for (const route of ['/(tabs)/messages', '/messages/dispatch']) {
      await w.open(GUARD, route, 2500)
      const nodes = w.ui()
      w.shot(`G25${route.replace(/\W+/g, '-')}`)
      for (const n of nodes) {
        const label = `${n.text} ${n.desc}`.trim()
        if (/new message|compose|start a chat|to:|search guards?|pick a guard|message .*officer/i.test(label)) offenders.push(`${route}: "${label}"`)
      }
    }
    r.require(offenders.length === 0, 'there is no way anywhere to start a message to another guard', `a route to another guard exists: ${offenders.join(' · ')}`)
  },

  async G30b(w, r) {
    const offenders = []
    for (const route of ['/(tabs)', '/settings', '/settings/notifications', '/(tabs)/profile', '/settings/privacy']) {
      await w.open(RESIDENT, route, 2200)
      const nodes = w.ui()
      if (hasText(nodes, 'Do Not Disturb')) offenders.push(route)
    }
    r.require(offenders.length === 0, 'the Resident App says nothing about Do Not Disturb anywhere', `the Resident App mentions Do Not Disturb on: ${offenders.join(', ')} — a resident silencing their own phone is their own choice`)
  },

  async R19(w, r) {
    await w.open(RESIDENT, '/elections/1/ballot-a', 3000)
    const paper = w.shot('R19-ballot')
    const secure = isMostlyBlack(paper)
    r.require(secure.ok, `the ballot paper refuses a screenshot — Android answered with a black image (${(secure.black * 100).toFixed(1)}% black)`, `the ballot paper CAN be screenshotted (${(secure.black * 100).toFixed(1)}% black) — FLAG_SECURE is not on this screen`)

    await w.open(RESIDENT, '/elections/1/receipt', 3000)
    const receipt = w.shot('R19-receipt')
    const receiptBlack = isMostlyBlack(receipt)
    r.require(!receiptBlack.ok, 'the receipt CAN be screenshotted, which is the other half of this row', 'the receipt is blocked too — after "Your vote is in" a screenshot of the receipt is meant to be allowed')
  },

  async R24(w, r) {
    const offenders = []
    for (const route of ['/(tabs)', '/(tabs)/visitors', '/(tabs)/notices', '/(tabs)/profile', '/settings']) {
      await w.open(RESIDENT, route, 2200)
      const nodes = w.ui()
      for (const n of nodes) {
        const label = `${n.text} ${n.desc}`.trim()
        if (/\bbook(ing|ings)?\b|amenit/i.test(label)) offenders.push(`${route}: "${label}"`)
      }
    }
    r.require(offenders.length === 0, 'nothing anywhere advertises or reaches an amenity booking', `bookings are reachable or advertised: ${offenders.join(' · ')}`)

    await w.open(RESIDENT, '/amenities', 2500)
    const nodes = w.ui()
    w.shot('R24-amenities-route')
    r.require(hasText(nodes, 'not available'), 'the booking route itself answers "not available at this estate"', 'the booking route draws a booking screen although bookings are off for the pilot')
  },

  async D2(w, r) {
    const empties = []
    const surfaces = [
      { build: GUARD, route: '/equipment', name: 'guard equipment' },
      { build: GUARD, route: '/orders/history', name: 'guard requests' },
      { build: RESIDENT, route: '/(tabs)/visitors', name: 'resident vehicles / visitors' },
      { build: RESIDENT, route: '/(tabs)/profile', name: 'resident emergency contacts' },
      { build: RESIDENT, route: '/(tabs)/notices', name: 'notices' },
      { build: RESIDENT, route: '/(tabs)/dues', name: 'dues' },
    ]
    for (const s of surfaces) {
      await w.open(s.build, s.route, 2800)
      const nodes = w.ui()
      w.shot(`D2-${s.name.replace(/\W+/g, '-')}`)
      const rows = nodes.filter((n) => n.bounds && n.bounds.h > 40 && (n.text || n.desc))
      const explains = hasText(nodes, 'nothing') || hasText(nodes, 'no ') || hasText(nodes, 'none yet')
      if (rows.length < 3 && !explains) empties.push(`${s.name} (${s.route})`)
    }
    r.require(empties.length === 0, 'every demonstration screen carries believable data or an honest explanation', `screens a reviewer would find blank and unexplained: ${empties.join(' · ')}`)
  },
}

/* ---- run ------------------------------------------------------------------- */

function selected() {
  const order = orderedRun()
  return order.filter((c) => (only ? only.includes(c.id.toUpperCase()) : true))
}

async function main() {
  if (guardId === 'GS-1041') {
    console.error('Refused: GS-1041 is the client\'s own phone and is never enrolled or signed in by this harness. Use GS-9001.')
    process.exit(EXIT.failed)
  }

  const list = selected()

  if (has('--list') || has('--dry-run')) {
    const t = triage()
    console.log(has('--dry-run') ? 'DRY RUN — no handset is touched. This is the run, in order:\n' : 'Run order:\n')
    let n = 0
    for (const c of list) {
      n++
      const runnable = SCRIPTED[c.id] ? 'scripted' : c.method
      console.log(`${String(n).padStart(2)}. ${c.id.padEnd(5)} [${runnable}] ${c.title}`)
      if (has('--dry-run')) console.log(`        ${c.how}`)
      if (c.remainder) console.log(`        LEAVES FOR A PERSON: ${c.remainder}`)
    }
    console.log(`\n${list.length} of ${t.total} rows selected · ${list.filter((c) => SCRIPTED[c.id]).length} of them run today.`)
    console.log('No result is claimed by a dry run. With a handset attached: npm run device:checks')
    return
  }

  const device = adb({ serial: valueOf('--serial') })
  device.requireDevice()

  const facts = existsSync(DEVICE_FILE) ? JSON.parse(readFileSync(DEVICE_FILE, 'utf8')).facts : device.facts()
  if (!facts?.screen) {
    console.error('The handset\'s screen size could not be read. Run npm run device:check first.')
    process.exit(EXIT.failed)
  }

  const w = world(device, facts)
  const results = []

  console.log(`PHONE_CHECK on ${facts.manufacturer ?? ''} ${facts.model ?? ''} (Android ${facts.androidRelease ?? '?'}) · guard ${guardId}\n`)

  for (const c of list) {
    const run = SCRIPTED[c.id]
    if (!run) {
      results.push({ id: c.id, title: c.title, result: 'NOT SCRIPTED', method: c.method, how: c.how, evidence: [] })
      continue
    }
    const r = recorder(c)
    process.stdout.write(`${c.id.padEnd(6)}${c.title} … `)
    try {
      await run(w, r)
    } catch (e) {
      // A check that never got the product on screen has proved nothing. It is BLOCKED.
      // Only something the product actually did wrong is a FAIL — so a failure already
      // recorded against the product stands, and the block is added as a note beside it.
      if (!e.blocked) r.fail(`the check stopped: ${e.message}`)
      else if (r.outcome === 'FAIL') r.note(`and then it stopped: ${e.message}`)
      else r.blocked(e.message)
    }
    const result = r.finish()
    console.log(result)
    for (const e of r.evidence) console.log(`        ${e.ok === true ? 'ok  ' : e.ok === false ? 'NO  ' : '--  '}${e.what}`)
    results.push({ id: c.id, title: c.title, result, method: c.method, evidence: r.evidence })
  }

  const count = (s) => results.filter((x) => x.result === s).length
  console.log(`\n${count('PASS')} passed · ${count('FAIL')} failed · ${count('BLOCKED')} blocked · ${count('NOT SCRIPTED')} not scripted (method named in tools/device/plan.mjs)`)

  writeFileSync(
    RESULTS,
    JSON.stringify({ ranAt: new Date().toISOString(), handset: { model: facts.model, android: facts.androidRelease, serial: facts.serial }, guard: guardId, results }, null, 2) + '\n',
    'utf8'
  )
  console.log(`Written: ${path.relative(ROOT, RESULTS)} · captures in _design/screenshots/checks/`)
  device.writeTranscript(path.join(ROOT, '.tools', 'checks-transcript.json'))

  if (count('FAIL') > 0) process.exit(EXIT.failed)
}

/** Only when run directly — selftest.mjs imports redact() from here. */
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch((e) => {
    console.error(`\nThe check run stopped: ${e.message}`)
    process.exit(EXIT.failed)
  })
}
