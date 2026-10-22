/**
 * `npm run device:check` — the first thing that runs the moment a handset answers.
 *
 * Six steps, in order, and it stops at the first one that genuinely failed:
 *   1. is anything attached, and is it authorised?
 *   2. what is it — make, model, Android version, screen, density, the system-bar
 *      insets, and its current timezone;
 *   3. is the frame arithmetic possible on this handset (geometry.mjs), and what does
 *      it cost in coverage;
 *   4. fetch both development builds (cached — a re-run downloads nothing);
 *   5. install both, keeping any existing data;
 *   6. write _design/DEVICE.json, which every later script reads instead of asking
 *      the handset again.
 *
 * SAFE TO RE-RUN. Downloads are skipped when the file is already there and the right
 * size; `adb install -r` keeps the app's data, so an enrolment survives; nothing is
 * deleted; and a handset that is already up to date is reported as already up to date
 * rather than reinstalled.
 *
 * HONEST WHEN IT FAILS. Each step reports what it got, not what it hoped for. A
 * refused install prints Android's own Failure line. No handset at all exits 3 with
 * the four taps written out.
 *
 *   npm run device:check
 *   npm run device:check -- --dry-run        every command it would send, with nothing attached
 *   npm run device:check -- --no-install     confirm and record only
 *   npm run device:check -- --serial <id>    when more than one handset is attached
 */

import { createWriteStream, existsSync, mkdirSync, statSync, writeFileSync } from 'node:fs'
import { Readable } from 'node:stream'
import { pipeline } from 'node:stream/promises'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { adb, EXIT, ROOT } from './adb.mjs'
import { framePlan } from './geometry.mjs'
import { extraExcluded, coverageLine } from './device-diff.mjs'

/**
 * The two development builds. They carry their own package ids
 * (`.dev`), so they sit BESIDE anything already on the phone — Expo Go, or a store
 * build — and replace nothing.
 *
 * THESE ARE DEVELOPMENT BUILDS AND THEY CARRY NO JAVASCRIPT. Neither APK contains an
 * `index.android.bundle`; both are the native shell plus the Expo development launcher.
 * On its own, installed and tapped, such a build shows a list of development servers and
 * not one screen of GeminiSecure. The app appears only when a Metro server is serving
 * the bundle — `npm start` in `apps/guard` (port 8081) and in `apps/resident` (8082) —
 * which is why each build carries the port its own package.json starts on. `metroPort`
 * is ignored by any build that already has its JavaScript inside it.
 */
export const BUILDS = [
  { app: 'guard', pkg: 'jm.geminisecure.guard.dev', scheme: 'gsguard', metroPort: 8081, url: 'https://expo.dev/artifacts/eas/PwDsdrNdG4bIdrYdGBz37FAHuofazEnIVJrPit0s5yE.apk' },
  { app: 'resident', pkg: 'jm.geminisecure.resident.dev', scheme: 'gs', metroPort: 8082, url: 'https://expo.dev/artifacts/eas/nMycDEEfQzpGC2VdJTmWgnqGXXM-WCrlMwVt4Y31x04.apk' },
]

const APK_DIR = path.join(ROOT, '.tools', 'apks')
export const DEVICE_FILE = path.join(ROOT, '_design', 'DEVICE.json')

const args = process.argv.slice(2)
const has = (f) => args.includes(f)
const valueOf = (f) => (args.indexOf(f) === -1 ? null : args[args.indexOf(f) + 1])

/** Fetch an APK once. Returns where it is and whether this run downloaded it. */
export async function fetchBuild(build, { dryRun = false } = {}) {
  mkdirSync(APK_DIR, { recursive: true })
  const file = path.join(APK_DIR, `${build.app}-dev.apk`)

  if (existsSync(file) && statSync(file).size > 1_000_000) {
    return { file, downloaded: false, bytes: statSync(file).size }
  }
  if (dryRun) return { file, downloaded: false, bytes: null, wouldDownload: build.url }

  const res = await fetch(build.url)
  if (!res.ok) throw new Error(`${build.app}: the build would not download — HTTP ${res.status} ${res.statusText}`)
  await pipeline(Readable.fromWeb(res.body), createWriteStream(file))

  const bytes = statSync(file).size
  if (bytes < 1_000_000) throw new Error(`${build.app}: downloaded ${bytes} bytes — that is not an APK. Left at ${file} to look at.`)
  return { file, downloaded: true, bytes }
}

async function main() {
  const dryRun = has('--dry-run')
  const device = adb({ dryRun, serial: valueOf('--serial') })

  console.log(dryRun ? 'DRY RUN — nothing is sent to any handset. Every line below is what WOULD be sent.\n' : 'GeminiSecure device harness\n')

  /* 1 — is anything there */
  console.log('1. Handset')
  const found = device.requireDevice()
  console.log(`   ${dryRun ? 'would use the one attached handset' : `${found.serial}  ${found.model ?? ''}  (${found.state})`}`)

  /* 2 — what is it */
  console.log('\n2. What it is')
  const facts = device.facts()
  if (facts.dryRun) {
    console.log('   nothing is asked in a dry run, and nothing is assumed. With a handset attached this reads:')
    console.log('   model · manufacturer · Android release · SDK · wm size · wm density · status-bar and navigation-bar insets · persist.sys.timezone')
  } else {
    console.log(`   ${facts.manufacturer ?? '?'} ${facts.model ?? '?'} · Android ${facts.androidRelease ?? '?'} (SDK ${facts.sdk ?? '?'})`)
    console.log(`   screen ${facts.screen ? `${facts.screen.width}x${facts.screen.height}` : '?'} px · density ${facts.density ?? '?'} · ${facts.dpWidth ?? '?'} x ${facts.dpHeight ?? '?'} dp`)
    console.log(`   insets: status bar ${facts.insets.statusBarPx} px, navigation bar ${facts.insets.navBarPx} px (from ${facts.insets.source ?? 'a fallback'})`)
    console.log(`   timezone ${facts.timezone ?? '?'}`)
    for (const f of facts.fallbacks) console.log(`   FALLBACK USED: ${f}`)
  }

  /* 3 — can the board's frame be reached from this handset */
  console.log('\n3. The board frame on this handset')
  let plan = null
  if (facts.dryRun) {
    console.log('   needs a real screen size, density and insets — a dry run has none and will not invent them.')
    console.log('   `npm run device:selftest` proves this arithmetic on four made-up handsets instead.')
  } else {
    try {
      plan = framePlan(facts)
      console.log(`   scale ${plan.scale.toFixed(4)} · the capture becomes ${plan.scaledWidth}x${plan.scaledHeight}, anchored at the top of the app's content (offset ${plan.offsetY})`)
      console.log(`   ${coverageLine(extraExcluded(plan.bands))}`)
      for (const n of plan.notes) console.log(`   NOTE: ${n}`)
      for (const b of plan.bands) console.log(`   excluded band "${b.name}" rows ${Math.round(b.y0)}-${Math.round(b.y1)}: ${b.reason}`)
    } catch (e) {
      console.log(`   cannot be worked out on this handset: ${e.message}`)
    }
  }

  /* 4 + 5 — the builds */
  const installs = []
  if (has('--no-install')) {
    console.log('\n4. Builds — skipped (--no-install)')
  } else {
    console.log('\n4. Builds')
    for (const build of BUILDS) {
      try {
        const got = await fetchBuild(build, { dryRun })
        if (got.wouldDownload) console.log(`   ${build.app}: would download ${got.wouldDownload}\n            -> ${got.file}`)
        else console.log(`   ${build.app}: ${got.downloaded ? 'downloaded' : 'already here'} ${(got.bytes / 1_000_000).toFixed(1)} MB  ${got.file}`)
        build.file = got.file
      } catch (e) {
        console.log(`   ${build.app}: FAILED — ${e.message}`)
        installs.push({ app: build.app, ok: false, note: e.message })
      }
    }

    console.log('\n5. Install (-r, so any enrolment on the phone survives)')
    for (const build of BUILDS) {
      if (!build.file) continue
      const already = device.isInstalled(build.pkg)
      if (already) console.log(`   ${build.app}: ${build.pkg} is already installed (version ${device.versionOf(build.pkg) ?? '?'}) — reinstalling over it keeps its data`)
      const r = device.install(build.file)
      console.log(`   ${build.app}: ${dryRun ? 'would install' : r.ok ? 'installed' : 'FAILED'}${r.output && !r.ok ? ` — ${r.output.split('\n')[0]}` : ''}`)
      installs.push({ app: build.app, pkg: build.pkg, ok: dryRun ? null : r.ok, note: r.output || null })
    }
  }

  /* 6 — write it down */
  console.log('\n6. Recording')
  const record = {
    recordedAt: new Date().toISOString(),
    dryRun,
    serial: facts.serial,
    facts,
    frame: plan,
    builds: BUILDS.map((b) => ({ app: b.app, pkg: b.pkg, scheme: b.scheme, url: b.url })),
    installs,
  }
  if (dryRun) {
    console.log(`   would write ${DEVICE_FILE}`)
  } else {
    mkdirSync(path.dirname(DEVICE_FILE), { recursive: true })
    writeFileSync(DEVICE_FILE, JSON.stringify(record, null, 2) + '\n', 'utf8')
    console.log(`   ${DEVICE_FILE}`)
  }
  device.writeTranscript(path.join(ROOT, '.tools', 'device-transcript.json'))

  const failed = installs.filter((i) => i.ok === false)
  console.log('')
  if (failed.length > 0) {
    console.log(`${failed.length} of ${installs.length} installs failed. Nothing further is claimed about this handset.`)
    process.exit(EXIT.failed)
  }
  console.log(dryRun ? 'Dry run complete. Attach a handset and run it again without --dry-run.' : 'Ready. Next: npm run device:checks    (the 61 checks, the five headline ones first)')
}

/** Only when run directly — selftest.mjs and fidelity.mjs import BUILDS from here. */
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch((e) => {
    console.error(`\nThe harness stopped: ${e.message}`)
    process.exit(EXIT.failed)
  })
}
