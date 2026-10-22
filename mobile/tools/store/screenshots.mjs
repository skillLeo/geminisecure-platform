/**
 * The store's SCREENSHOTS — taken from the real app, at the device sizes each store asks for (M5 §13),
 * signed in to the DEMONSTRATION ESTATE with the credentials a reviewer is given (M6 §3.2).
 *
 *   node tools/store/screenshots.mjs                        the store app, every device class
 *   node tools/store/screenshots.mjs --device ios-6.7
 *   node tools/store/screenshots.mjs --only resident-app-05,resident-app-12
 *   node tools/store/screenshots.mjs --source fixtures      the OLD behaviour: the design boards' data
 *   node tools/store/screenshots.mjs --list                 print the chosen screens and sizes, take nothing
 *
 * ONE APP GOES TO THE STORES (M25). The client decided on 1 October 2026 that the Guard App is distributed
 * privately and listed in no store, so it is not photographed for one: `STORE_APPS` below is the Resident
 * App alone, and `store/screenshots/` holds nothing else. The Guard App's choices and its sign-in walk are kept
 * in this file, unused, so a private store's pictures could be taken the same way if the client ever wants one
 * (`distribution/guard/README.md`); `--app guard --pass-code DEMO1-1234` then writes them OUTSIDE the store pack.
 *
 * WHAT CHANGED, AND WHY (M6 §3.2). Until now this opened each route with `?fidelity=<screen-id>`, which
 * fills the screen from the design BOARD's fixtures. Those pictures were of the real app, at the real
 * sizes, and were not empty — but they showed names and figures a reviewer would never find, because a
 * reviewer signs in to the demonstration estate (`php artisan demo:seed`, `STORE_REVIEW_NOTES.md`). A
 * listing that shows one product and a reviewer who finds another is itself a rejection. So the default
 * is now `--source demo`: the harness walks in through each app's OWN front door with the published
 * credentials and photographs what the reviewer will see. `--source fixtures` keeps the old behaviour
 * for fidelity work; it prints, in the manifest and on the console, that those copies must not be sent.
 *
 * HOW IT SIGNS IN, AND WHY IT NAVIGATES THE WAY IT DOES.
 *
 *   guard      the enrolment code from STORE_REVIEW_NOTES.md, then the four first-run steps.
 *   resident   the email address and the static six-digit code, then the permissions step.
 *
 * On web a token is held IN MEMORY ONLY and never in localStorage (`packages/core/auth/tokenStorage.ts`),
 * which is deliberate and must not change. So a full page load signs the session out, and `page.goto`
 * per screen — what this file used to do — would photograph the sign-in screen twelve times over. Each
 * device therefore gets ONE page: sign in once, then move between screens the way the app itself moves,
 * through the history, with no reload.
 *
 * WHAT "READY" MEANS WITHOUT FIDELITY MODE. `html[data-fidelity-ready]` is set by `useFidelityReady`,
 * which does nothing unless `?fidelity=` is in the URL — so in demo mode there is no such flag. Instead
 * every screen names a PROOF: a sentence that can be on it only if the screen has really drawn AND has
 * the demonstration estate's own data in it. The picture is not taken until that sentence is on the page,
 * so a screen that came up empty fails the run rather than being quietly photographed empty.
 *
 * TWO SCREENS ARE TYPED INTO BEFORE THE PICTURE, AND NOTHING IS SENT: the walk-up form and the visitor
 * invitation. Both are forms, and a photograph of an empty form demonstrates nothing. The words typed are
 * the demonstration estate's own, they go in through the app's real inputs, and neither form is
 * submitted — no pass is issued and no request is raised. Nothing here draws anything the app did not.
 *
 * Both Metro servers must be running (8081 guard, 8082 resident — see _design/SCREEN_ROUTES.json) and the
 * API they are built against must be up with `php artisan demo:seed` run against it. Nothing here starts
 * or stops a server, and nothing here talks to the API except through the app.
 */

import { chromium } from 'playwright'
import { mkdirSync, readFileSync, readdirSync, writeFileSync, rmSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const OUT = path.join(ROOT, 'store', 'screenshots')
const routes = JSON.parse(readFileSync(path.join(ROOT, '_design', 'SCREEN_ROUTES.json'), 'utf8'))

const args = process.argv.slice(2)
const flag = (name) => {
  const i = args.indexOf(name)
  return i === -1 ? null : args[i + 1]
}
const onlyApp = flag('--app')
const onlyDevice = flag('--device')
const onlyIds = flag('--only')?.split(',').map((s) => s.trim()) ?? null
const SOURCE = flag('--source') ?? 'demo'
const LIST = args.includes('--list')

if (!['demo', 'fixtures'].includes(SOURCE)) {
  console.log('--source must be `demo` (the demonstration estate) or `fixtures` (the design boards)')
  process.exit(1)
}

/* ── the credentials, quoted from STORE_REVIEW_NOTES.md ───────────────────────────────────────────
 * Public on purpose: they open one estate of invented data and nothing else, and they are printed in the
 * review notes so that a reviewer can sign in. If any of them ever differs from what `php artisan
 * demo:seed` prints, what it prints is right — change this and the review notes, never the server.
 */
const REVIEWER = {
  guardCode: 'DEMO-2026',
  residentEmail: 'reviewer@demo.geminisecure.app',
  residentCode: '246810',
  estate: 'Seaview Demo Estate',
}

/* ── the device classes each store asks for ───────────────────────────────────────────────────────
 * The viewport is the phone's LOGICAL size and the scale factor its pixel density, so what comes out
 * is the exact pixel size the console accepts — and the layout is the one that phone really gets,
 * not a small screenshot stretched.
 *
 * Apple: 6.7"/6.9" is the required display set; 6.5" is still accepted and is what older listings
 * fall back to. Both are portrait; neither app has a landscape layout (app.config orientation).
 * Google Play: a phone screenshot must be between 320 and 3840 px on each side and no narrower than
 * 16:9 — 1080 x 1920 is exactly 9:16, so it is accepted everywhere and is over Play's 1080 px bar
 * for the "prominent placement" set.
 */
const DEVICES = {
  'ios-6.7': { store: 'App Store', width: 430, height: 932, scale: 3, label: 'iPhone 6.7" — 1290 x 2796' },
  'ios-6.5': { store: 'App Store', width: 414, height: 896, scale: 3, label: 'iPhone 6.5" — 1242 x 2688' },
  'android-phone': { store: 'Google Play', width: 360, height: 640, scale: 3, label: 'Android phone — 1080 x 1920 (9:16)' },
}

/* ── the screens, in the order they should be uploaded ────────────────────────────────────────────
 * Chosen to answer, in the first three pictures a shopper sees, the three questions a reviewer asks:
 * what does it do, who is it for, and does it work. Routes come from _design/SCREEN_ROUTES.json, so
 * a screen that moves is not screenshotted at a stale address.
 *
 * The alert screen is deliberately NOT here: a picture of it, stripped of the sentences around it,
 * is the easiest way for a reviewer to read the product as something it is not (M5 §7).
 */
const CHOSEN = {
  guard: [
    ['guard-app-03', 'shift-dashboard', 'The shift, the post and what is open — on one screen.'],
    ['guard-app-04', 'scan-visitor-pass', 'Scan a visitor pass at the gate. Valid or not, in one look.'],
    ['guard-app-31', 'walk-up-entry', 'A visitor with no pass: ask the household, record the answer.'],
    ['guard-app-07', 'patrol', 'Patrol checkpoints, scanned and timed.'],
    ['guard-app-24', 'gate-activity', 'Every entry and exit, written down and kept.'],
    ['guard-app-12', 'payslip', 'Hours, shifts and payslips, without a trip to the office.'],
  ],
  resident: [
    ['resident-app-05', 'home', 'Your community at a glance.'],
    ['resident-app-07', 'invite-a-visitor', 'Invite a visitor and send them a pass for the gate.'],
    ['resident-app-08', 'visitor-tracking', 'See who has arrived, who is expected and who was turned away.'],
    ['resident-app-12', 'notices', 'Notices from the community, in one place.'],
    ['resident-app-09', 'dues', 'Maintenance dues, clearly itemised.'],
    /*
     * NOT "Book an amenity" (resident-app-21), although the board is one of the best-looking in the set:
     * bookings ship switched OFF for the pilot (flags.amenityBookings), so a listing
     * that showed it would advertise a screen the person cannot reach — which is what Guideline 2.3.3 is
     * about, and a rejection. Put it back the day the flag goes on.
     * Maintenance is shown instead: it is on every day, and it is the second thing a resident uses.
     *
     * …and for the same reason it is resident-app-26 (a request being TRACKED) and not resident-app-11
     * (the form): board 11's state draws the microphone mid-dictation, and speech recognition is not in
     * this build — `expo-speech-recognition` is not installed, so the shipped screen draws that mic
     * disabled with the sentence saying so (apps/resident/src/features/maintenance/dictation.ts), and
     * neither app holds the microphone permission at all (PERMISSIONS.md). A store picture of a
     * listening microphone would promise a feature that does not exist AND contradict the privacy
     * declarations. Board 26 shows the part that matters anyway: the problem being dealt with.
     */
    ['resident-app-26', 'track-a-request', 'Report a problem and follow it until it is done.'],
  ],
}

/** Why this screen is in the listing at all — written into the manifest beside each picture. */
const WHY = {
  'guard-app-03': 'The first picture answers "what is this": one officer, one watch, one post — the whole job on one screen.',
  'guard-app-04': 'The second answers "does it work": a pass really checked, with a verdict and the two things the officer can do about it.',
  'guard-app-31': 'The third answers "who is it for": the case a gate actually spends its day on — somebody at the barrier with no pass.',
  'guard-app-07': 'Patrol is the other half of manned guarding, and the half a community pays to be able to audit.',
  'guard-app-24': 'The gate log is the record the whole product exists to produce, and it can never be edited or removed.',
  'guard-app-12': 'An officer is not a customer: pay and hours in the same app are what makes them carry it.',
  'resident-app-05': 'The first picture answers "what is this": the household\'s balance, its gate, its notices and its vote in one place.',
  'resident-app-07': 'The second answers "what is it for": letting somebody through the gate without being telephoned about it.',
  'resident-app-08': 'The third answers "does it work": who came, who is expected, and who was turned away.',
  'resident-app-12': 'Notices are what a community office sends and what a resident misses; they are the daily reason to open the app.',
  'resident-app-09': 'Dues are the other daily reason, and the figures are read back out of the estate\'s own ledger.',
  'resident-app-26': 'A problem reported and followed to done — the part of estate management residents judge an office by.',
}

/* ── how each screen is reached on the DEMONSTRATION estate, and what proves it arrived ───────────
 *
 * `route`    where the screen lives, when it is not simply the board's own route.
 * `prepare`  what a reviewer does on the way in — typing a pass code, filling a form, opening a row.
 *            It never sends anything: no pass is issued, no request is filed, no entry is recorded.
 * `proof`    a sentence that is on the screen only when the demonstration estate's own data is on it.
 *            The picture is not taken until it is there, so an empty screen fails the run.
 * `shows`    what the picture shows, for store/screenshots/manifest.json.
 * `reached`  how a reviewer gets there with the credentials in STORE_REVIEW_NOTES.md.
 * `after`    anything to do once the picture is taken (only the pass code, read off the pass itself).
 */

/**
 * The active demonstration pass's code, read off the resident's own pass screen. Never hard-coded:
 * `demo:seed` re-issues the pass whenever the last one has run out, so a code written down here would
 * be wrong within a day. `--pass-code DEMO1-1234` is for retaking the guard pictures ALONE, when the
 * resident screens are not being taken in the same run and there is nothing to read it off.
 */
let passCode = flag('--pass-code')

const type = async (page, label, value) => {
  await page.fill(`input[aria-label="${label}"]`, value)
  await page.waitForTimeout(250)
}

/**
 * Press a control once it is there AND enabled, which is not the same as "is on the page".
 *
 * Several of these buttons are drawn disabled and turn on a beat later — "Verify and continue" the
 * moment the sixth digit lands, "Continue" the moment the equipment box is ticked. Looking once and
 * moving on photographs the wrong screen and blames the next selector for it, so a required press
 * WAITS for the control to become pressable and says which one it was if it never does. An optional
 * press (a step that only appears on some devices) looks briefly and carries on.
 */
const tap = async (page, selector, { wait = 2500, timeout = 20000, required = true } = {}) => {
  /*
   * Alternatives are passed as an ARRAY, never as one comma-separated string: half these controls are
   * found by an aria-label, and a label as ordinary as "Looks correct, continue" has a comma in it.
   * "Visible and enabled" is then put on each alternative in turn.
   */
  const target = (Array.isArray(selector) ? selector : [selector]).map((part) => `${part}:visible:not([aria-disabled="true"])`).join(', ')
  try {
    await page.waitForSelector(target, { timeout: required ? timeout : 2000 })
  } catch {
    if (required) throw new Error(`nothing pressable matched ${selector}`)
    return false
  }
  await page.locator(target).first().click()
  await page.waitForTimeout(wait)
  return true
}

const DEMO = {
  'guard-app-03': {
    route: '/(tabs)',
    proof: 'ON WITH YOU TODAY',
    shows: "The officer's dashboard on the demonstration estate: the watch rostered at Demo Main Gate, the colleague on duty, the standing orders waiting to be acknowledged, and the quick actions along the bottom.",
    reached: 'It is where the app opens once the enrolment code DEMO-2026 has been typed and the four first-run steps are done.',
  },
  'guard-app-04': {
    route: '/scan/manual',
    /*
     * The reviewer's own walk. The demonstration passes are not printed on paper anywhere a camera can
     * be pointed, so STORE_REVIEW_NOTES.md sends the reviewer to Manual entry and the code printed under
     * the pass. The code used here is the one read off the resident's own pass screen earlier in this
     * run — so the two listings show the same pass, and nothing is hard-coded to go stale.
     */
    prepare: async (page) => {
      if (!passCode) throw new Error('no pass code: take the resident screens in the same run, or give one with --pass-code (the Resident App shows it on the expected visitor\'s pass)')
      await type(page, 'Pass code', passCode)
      await tap(page, '[role="button"][aria-label="Verify pass"]', { wait: 4000 })
    },
    proof: 'VALID PASS',
    shows: "A real demonstration pass checked at the gate by typing the code printed under its QR: the pass is valid, who it is for and what window it covers, and the officer is offered Admit or Deny. Nothing was admitted — the picture is the verdict.",
    reached: 'Home → Scan visitor → Manual entry, with the code shown on the pass in the Resident App (Visitors → the expected visitor).',
  },
  'guard-app-31': {
    route: '/walk-up',
    // A form. Typed into, never sent — a photograph of an empty form demonstrates nothing.
    prepare: async (page) => {
      await type(page, 'Unit visited', 'Lot 1')
      await type(page, 'Visitor name', 'Marcia Grant')
      await type(page, 'Purpose of visit', 'Family visit')
    },
    proof: 'Send request to resident',
    shows: 'A visitor at the gate with no pass, being written up so the household can be asked. Typed into and NOT sent: no request was raised on the demonstration estate.',
    reached: 'Home → Search unit → Lot 1 → Request entry approval, or Home → Scan visitor → the walk-up control.',
  },
  'guard-app-07': {
    route: '/(tabs)/patrol',
    proof: 'Gatehouse (demonstration checkpoint)',
    shows: "The demonstration estate's four patrol checkpoints and the round walked against them this morning, each scan timed on the estate's own clock.",
    reached: 'Tab bar → Patrol.',
  },
  'guard-app-24': {
    route: '/gate-activity',
    proof: 'Blue Mountain Plumbing',
    shows: "Today's gate log on the demonstration estate: the estate's totals, and the entry this officer recorded themselves. Nothing in this log can be edited or removed afterwards.",
    reached: "Home → today's gate activity.",
  },
  'guard-app-12': {
    route: '/(tabs)/payslips',
    proof: 'Net pay',
    shows: "The demonstration officer's approved payslip, calculated by the real payroll engine against Jamaica's current statutory rates — the deductions add up because they are the real ones.",
    reached: 'Tab bar → Payslips.',
  },
  'resident-app-05': {
    route: '/(tabs)',
    // NOT the estate's name: that is also on the sign-in screen, under "Explore a demo community",
    // so it would pass on a session that had quietly been signed out.
    proof: 'Quick actions',
    shows: "The household's home screen on the demonstration estate: what is owed, the visitor expected at the gate today, the estate's latest notice and the open committee election. The amber strip is the app saying, on every screen, that this is a demonstration community.",
    reached: 'It is where the app opens once signed in.',
  },
  'resident-app-07': {
    route: '/visitors/invite',
    prepare: async (page) => {
      await type(page, 'Visitor name', 'Marcia Grant')
      await type(page, 'Purpose of visit', 'Family visit')
    },
    proof: 'Generate QR pass',
    shows: 'A visitor being invited: who, why and the window they are expected in. Typed into and NOT sent — no pass was issued on the demonstration estate.',
    reached: 'Home → Invite visitor.',
  },
  'resident-app-08': {
    route: '/(tabs)/visitors',
    proof: 'Marcia Grant (demonstration visitor)',
    shows: "The household's visitors on the demonstration estate — who is expected today, and under History the passes already used at the gate or cancelled.",
    reached: 'Tab bar → Visitors.',
    /*
     * On the way past, read the active pass's code off its own screen. It is what the Guard App's
     * picture is then taken with, so both listings show the SAME pass rather than two invented ones —
     * and the code is never written into this file, where it would go stale on the next seed.
     */
    after: async (page, goTo) => {
      if (passCode) return
      await tap(page, '[role="button"][aria-label^="Marcia Grant"]', { wait: 4500, required: false })
      const text = await page.evaluate(() => document.body.innerText)
      passCode = text.match(/\b[A-Z]{4}\d-\d{4}\b/)?.[0] ?? null
      await goTo('/(tabs)/visitors')
    },
  },
  'resident-app-12': {
    route: '/(tabs)/notices',
    proof: 'Water lock-off Thursday (demonstration notice)',
    shows: "The demonstration estate's notices, urgent first, with what has been read and what has not.",
    reached: 'Tab bar → Notices.',
  },
  'resident-app-09': {
    route: '/(tabs)/dues',
    proof: 'Monthly maintenance',
    shows: "The household's dues on the demonstration estate, read back out of the estate's double-entry ledger: what is outstanding, what was settled last month, and the statement behind both.",
    reached: 'Tab bar → Dues.',
  },
  'resident-app-26': {
    route: '/requests',
    // The ticket's id belongs to the estate, so it is opened the way a resident opens it — by pressing
    // the row — rather than guessed at in a URL that would be wrong on the next estate.
    prepare: async (page) => { await tap(page, '[role="link"][aria-label^="Maintenance:"]', { wait: 4500, timeout: 30000 }) },
    proof: 'Street light out by the demonstration gate',
    shows: 'A maintenance request on the demonstration estate followed from reported to in progress, with its number, its category and where it has got to.',
    reached: 'Home → Maintenance → the request.',
  },
}

/* ── run ──────────────────────────────────────────────────────────────────────────────────────── */

const FREEZE = `
*, *::before, *::after {
  animation-duration: 0s !important; animation-delay: 0s !important;
  animation-iteration-count: 1 !important;
  transition-duration: 0s !important; transition-delay: 0s !important;
  caret-color: transparent !important;
}`

// M25: the apps photographed for the stores. The Guard App is taken only when asked for by name, and never into store/.
const STORE_APPS = ['resident']
const apps = onlyApp ? Object.keys(CHOSEN).filter((a) => a === onlyApp) : STORE_APPS
/*
 * Resident first in demo mode: the Guard App's pass picture is taken with the code read off the
 * resident's own pass screen, so that screen has to have been visited before the guard's is taken.
 */
const order = SOURCE === 'demo' ? ['resident', 'guard'].filter((a) => apps.includes(a)) : apps
const devices = Object.keys(DEVICES).filter((d) => (onlyDevice ? d === onlyDevice : true))

if (apps.length === 0) { console.log(`--app must be one of: ${Object.keys(CHOSEN).join(', ')}`); process.exit(1) }
if (devices.length === 0) { console.log(`--device must be one of: ${Object.keys(DEVICES).join(', ')}`); process.exit(1) }

if (LIST) {
  console.log(SOURCE === 'demo'
    ? 'source: the DEMONSTRATION ESTATE, signed in as the reviewer (STORE_REVIEW_NOTES.md)\n'
    : 'source: the design boards\' fixtures (EXPO_PUBLIC_FIDELITY=1) — NOT the copies to submit\n')
  for (const device of devices) console.log(`${device.padEnd(16)}${DEVICES[device].label}`)
  console.log('')
  for (const app of order) {
    for (const [i, [id, slug, caption]] of CHOSEN[app].entries()) {
      const where = (SOURCE === 'demo' ? DEMO[id]?.route : null) ?? routes.routes[id]?.route
      console.log(`${app.padEnd(9)}${String(i + 1).padStart(2, '0')}  ${id.padEnd(16)}${(where ?? '— NO ROUTE —').padEnd(22)}${caption}`)
    }
  }
  process.exit(0)
}

/* ── the two front doors ──────────────────────────────────────────────────────────────────────── */

/**
 * TAKE THE WEB-ONLY STAGE OFF BEFORE THE PICTURE.
 *
 * `AppRoot` boxes the app to 397pt wide and centres it on `page-bg` when it runs on web, so that a
 * desktop browser shows the phone's layout rather than a stretched one. A phone has no such stage — on
 * iOS and Android `Platform.OS === 'web'` is false and the app fills the screen — so leaving it in would
 * run a pale margin down both sides of a picture whose whole job is to be that phone. (The fidelity
 * harness never hit this: in fidelity mode `AppRoot` returns the content unboxed.)
 *
 * This REMOVES a browser affordance; it does not add anything the app does not draw. The stage is found
 * by its computed width rather than by a class name, because react-native-web generates the class.
 */
const unbox = (page) => page.evaluate((width) => {
  for (const el of document.querySelectorAll('div')) {
    if (getComputedStyle(el).maxWidth !== `${width}px`) continue
    el.style.maxWidth = 'none'
    if (el.parentElement) el.parentElement.style.backgroundColor = 'transparent'
  }
}, 397)

/** Move between screens the way the app does — through the history, with no reload (see the header). */
const navigator = (page) => async (route) => {
  await page.evaluate((r) => {
    window.history.pushState(null, '', r)
    window.dispatchEvent(new PopStateEvent('popstate', { state: null }))
  }, route)
  await page.waitForTimeout(3000)
}

async function signInGuard(page, base) {
  await page.goto(`${base}/`, { waitUntil: 'domcontentloaded', timeout: 240000 })
  await page.waitForSelector('input[aria-label="Enrolment code"]', { timeout: 180000 })
  await type(page, 'Enrolment code', REVIEWER.guardCode)
  await tap(page, '[role="button"][aria-label="Enrol this phone"]', { wait: 6000 })

  /*
   * The four first-run steps, in the words each one uses. The equipment step will not let the officer
   * past until they have ticked what they were issued, so anything tickable is ticked first — which is
   * what the officer does at the office. The identity step needs nothing here: a browser has no
   * fingerprint sensor, and the step says so itself and offers Continue (which is also true of a review
   * handset with no biometrics enrolled).
   */
  await tap(page, '[role="button"][aria-label="Looks correct, continue"]', { wait: 4000 })
  await tap(page, '[role="button"][aria-label="Continue"]', { wait: 4000 })
  const boxes = page.locator('[role="checkbox"][aria-checked="false"]:visible')
  for (let i = 0; i < await boxes.count(); i++) { await boxes.nth(i).click(); await page.waitForTimeout(350) }
  await tap(page, '[role="button"][aria-label="Continue"]', { wait: 4000 })
  await tap(page, '[role="button"][aria-label="Allow alerts"]', { wait: 4000, required: false })
  await tap(page, '[role="button"][aria-label="Finish setup"]', { wait: 6000 })

  // Setup ends on the clock-in screen with the officer's own name on it. If that is not what is there,
  // the walk went wrong and every picture after this would be of a sign-in screen.
  await page.waitForFunction(() => document.body.innerText.includes('GS-DEMO-1'), null, { timeout: 60000 })
}

async function signInResident(page, base) {
  await page.goto(`${base}/`, { waitUntil: 'domcontentloaded', timeout: 240000 })
  await page.waitForSelector('[role="link"][aria-label="Already have an account? Log in"]', { timeout: 180000 })
  await tap(page, '[role="link"][aria-label="Already have an account? Log in"]', { wait: 4000 })

  await type(page, 'Email address', REVIEWER.residentEmail)
  await tap(page, '[role="button"][aria-label^="Your estate:"]', { wait: 2500 })
  // The estate list is drawn by the kit's picker; the row it offers is whichever of these roles that
  // picker uses, so all of them are offered and the first that is pressable is pressed.
  await tap(page, [
    `[role="option"]:has-text("${REVIEWER.estate}")`,
    `[role="radio"]:has-text("${REVIEWER.estate}")`,
    `[role="menuitem"]:has-text("${REVIEWER.estate}")`,
    `[role="button"]:has-text("${REVIEWER.estate}")`,
  ], { wait: 2500 })
  await tap(page, '[role="button"][aria-label="Log in"]', { wait: 6000 })

  await page.waitForSelector('input[aria-label="Six-digit sign-in code"]', { timeout: 120000 })
  await type(page, 'Six-digit sign-in code', REVIEWER.residentCode)
  // Optional: the sixth digit submits the code by itself, so on a fast run the button is already gone.
  await tap(page, '[role="button"][aria-label="Verify and continue"]', { wait: 8000, required: false })

  // The permissions step. Nothing is granted: a browser has no notifications and no camera, and the
  // screen says so itself — which is also why no picture is taken of it.
  await tap(page, '[role="button"][aria-label="Continue"]', { wait: 5000 })

  // The amber strip is on every screen of a demonstration session and on none of the sign-in screens,
  // so it is the honest answer to "are we actually in?".
  await page.waitForFunction(() => document.body.innerText.includes('Demonstration community'), null, { timeout: 60000 })
}

const SIGN_IN = { guard: signInGuard, resident: signInResident }

/* ── take them ────────────────────────────────────────────────────────────────────────────────── */

const browser = await chromium.launch()
const taken = []
const failed = []

for (const app of order) {
  const surface = `${app}-app`
  const base = routes.apps[surface]
  const picks = CHOSEN[app].map((p, i) => [...p, i + 1]).filter(([id]) => (onlyIds ? onlyIds.includes(id) : true))

  for (const device of devices) {
    const { width, height, scale } = DEVICES[device]
    // An app that goes to no store is never photographed into the store pack (M25).
    const dir = path.join(STORE_APPS.includes(app) ? OUT : path.join(ROOT, 'distribution', app, 'screenshots'), app, device)
    mkdirSync(dir, { recursive: true })
    /*
     * THE OLD SET IS NOT DESTROYED UNTIL THERE IS A NEW ONE TO PUT IN ITS PLACE. This used to wipe the
     * folder here, before signing in — so a run that was interrupted, or that failed at the sign-in
     * (a cold bundle, a server restarted underneath it), left the folder EMPTY and the previous, good
     * pictures gone. Losing yesterday's submittable set because today's run was cancelled is not a
     * trade anybody would choose. The wipe now happens lazily, at the moment the first new picture is
     * about to be written, so the worst an interrupted run can leave behind is a mixture — and the
     * manifest, which is only written on a clean full run, says plainly when that has happened.
     */
    let cleared = false
    const clearOnce = () => {
      if (cleared || onlyIds) return
      cleared = true
      for (const stale of existsSync(dir) ? readdirSync(dir) : []) {
        if (stale.endsWith('.png')) rmSync(path.join(dir, stale), { force: true })
      }
    }

    // ONE context per device in demo mode, because the session cannot survive a reload (see the header).
    const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: scale, permissions: ['notifications'] })
    const page = await context.newPage()
    /*
     * A COLD METRO WEB BUNDLE TAKES LONGER THAN PLAYWRIGHT'S DEFAULT. The navigations below already
     * carry their own generous timeouts, but every OTHER call — fill, click, waitForSelector without
     * one — falls back to Playwright's 30 seconds, and on the first run of the day the bundle is still
     * compiling well past that. The failure then reads as a broken selector, which is what it is not:
     * the element arrives, just after the harness has given up on it. One default, set once.
     */
    page.setDefaultTimeout(120000)
    page.setDefaultNavigationTimeout(240000)
    const goTo = navigator(page)

    if (SOURCE === 'demo') {
      try {
        await SIGN_IN[app](page, base)
      } catch (e) {
        /*
         * Say what the screen was showing. The commonest cause is not a broken selector at all: the
         * enrolment code, the sign-in code and the demonstration door share one throttle of ten a
         * minute per address (`api-enrol`), so running this twice in quick succession is refused by
         * the server and the app says so on the screen. Printing the screen turns that into one line.
         */
        let where = ''
        try {
          const seen = (await page.evaluate(() => document.body.innerText)).replace(/\s+/g, ' ').trim().slice(0, 160)
          where = ` · at ${page.url()} showing "${seen}"`
        } catch { /* the page is gone */ }
        failed.push(`${app}/${device}: could not sign in to the demonstration estate — ${String(e.message).split('\n')[0]}${where}`)
        await context.close()
        continue
      }
    }

    for (const [id, slug, , position] of picks) {
      const board = routes.routes[id]
      const demo = DEMO[id]
      const route = (SOURCE === 'demo' ? demo?.route : null) ?? board?.route
      if (!route) { failed.push(`${app}/${device}/${id}: no route in _design/SCREEN_ROUTES.json`); continue }

      try {
        if (SOURCE === 'demo') {
          await goTo(route)
          if (demo.prepare) await demo.prepare(page)
          // The proof: the picture is not taken until the demonstration estate's own data is on screen.
          await page.waitForFunction((needle) => document.body.innerText.includes(needle), demo.proof, { timeout: 60000 })
        } else {
          // Same waits as the fidelity harness: Metro can be rebuilding a phone's bundle first, and the
          // app's own data-fidelity-ready flag is the only honest signal that the screen is fully drawn.
          const sep = route.includes('?') ? '&' : '?'
          const response = await page.goto(`${base}${route}${sep}fidelity=${id}`, { waitUntil: 'domcontentloaded', timeout: 240000 })
          if (response && response.status() >= 400) throw new Error(`HTTP ${response.status()}`)
          await page.waitForSelector('html[data-fidelity-ready]', { timeout: 45000 })
        }

        if (SOURCE === 'demo') await unbox(page)
        await page.evaluate(() => document.fonts.ready)
        await page.evaluate(() => window.scrollTo(0, 0))
        await page.waitForTimeout(400)
        await page.addStyleTag({ content: FREEZE })
        const file = path.join(dir, `${String(position).padStart(2, '0')}-${slug}.png`)
        clearOnce() // the previous set goes only now, with a replacement about to be written
        await page.screenshot({ path: file, clip: { x: 0, y: 0, width, height }, timeout: 120000 })
        taken.push({ app, device, id, file: path.relative(ROOT, file).replace(/\\/g, '/'), px: `${width * scale}x${height * scale}` })
        if (SOURCE === 'demo' && demo.after) await demo.after(page, goTo)
      } catch (e) {
        // Say WHERE it stopped and what was on the screen: "timed out" alone sends the next person
        // hunting a broken selector when the app was simply showing something else.
        let where = ''
        try {
          const seen = (await page.evaluate(() => document.body.innerText)).replace(/\s+/g, ' ').trim().slice(0, 140)
          where = ` · at ${page.url()} showing "${seen}"`
        } catch { /* the page is gone */ }
        failed.push(`${app}/${device}/${id}: ${String(e.message).split('\n')[0]}${where}`)
      }
    }

    await context.close()
  }
}

await browser.close()

/* ── what was taken, written beside the pictures ──────────────────────────────────────────────── */

if (taken.length > 0 && !onlyIds && !onlyApp && !onlyDevice) {
  // The LOCAL day, not the UTC one: this is a date a person reads beside a picture, and a run late in
  // a Jamaican evening is not "yesterday" to anybody looking at it.
  const d = new Date()
  const today = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  const manifest = {
    note: SOURCE === 'demo'
      ? 'Taken from the real app by tools/store/screenshots.mjs, signed in to the DEMONSTRATION ESTATE with the credentials in STORE_REVIEW_NOTES.md — the same estate, the same accounts and the same figures a reviewer finds (M6 §3.2). Run `php artisan demo:seed` and then this, before every submission.'
      : 'Taken from the real app by tools/store/screenshots.mjs from the design boards\' fixtures. These are NOT the copies to submit: they show names and figures no reviewer can reach. Retake with --source demo.',
    source: SOURCE === 'demo'
      ? "the demonstration estate (php artisan demo:seed), signed in through each app's own front door"
      : 'the design boards\' fixtures (EXPO_PUBLIC_FIDELITY=1)',
    method: SOURCE === 'demo'
      ? 'Browser capture. react-native-web at each store\'s device size and pixel density, driven with Playwright through the app\'s own controls: signed in with the review email address and the static six-digit code, then each screen reached the way the app reaches it. No handset was reachable for this run, so these are browser captures rather than device captures (M6 §3.2, §4) — retake on hardware the moment one answers.'
      : 'Browser capture at each store\'s device size, from the board fixtures.',
    taken_at: new Date().toISOString(),
    date: today,
    data: SOURCE === 'demo'
      ? 'Every name, figure and time in these pictures is a row on the demonstration estate, served by the API the shipped app talks to. Nothing is mocked and nothing is drawn.'
      : 'EXPO_PUBLIC_FIDELITY=1 — the design boards\' own sample content, fictional and reproducible; the clock is frozen at 2026-09-04T09:41:00-05:00.',
    nothing_switched_off: 'Amenity bookings ship OFF for the pilot (flags.amenityBookings) and are in no picture; nor is the alert screen (M5 §7); nor a listening microphone (board 11).',
    nothing_sent: SOURCE === 'demo'
      ? 'One of the six screens is a form and was typed into before the picture — the visitor invitation. It was not submitted: no pass was issued on the demonstration estate.'
      : null,
    devices: Object.fromEntries(Object.entries(DEVICES).map(([k, v]) => [k, { store: v.store, pixels: `${v.width * v.scale}x${v.height * v.scale}`, logical: `${v.width}x${v.height}@${v.scale}x`, label: v.label }])),
    screens: Object.fromEntries(Object.entries(CHOSEN).filter(([app]) => STORE_APPS.includes(app)).map(([app, list]) => [app, list.map(([id, slug, caption], i) => ({
      order: i + 1,
      screen: id,
      slug,
      route: (SOURCE === 'demo' ? DEMO[id]?.route : null) ?? routes.routes[id]?.route ?? null,
      caption,
      shows: SOURCE === 'demo' ? (DEMO[id]?.shows ?? null) : null,
      why: WHY[id] ?? null,
      reachable_by_reviewer: SOURCE === 'demo' ? (DEMO[id]?.reached ?? null) : null,
      method: SOURCE === 'demo' ? "browser capture, driven through the app's own controls on the demonstration estate" : 'browser capture from the board fixture',
      date: today,
    }))])),
    files: taken.map((t) => ({ app: t.app, device: t.device, screen: t.id, pixels: t.px, file: t.file })),
  }
  writeFileSync(path.join(OUT, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n')
}

const pad = (s, n) => String(s).padEnd(n)
console.log(pad('APP', 11) + pad('DEVICE', 16) + pad('SCREEN', 17) + pad('PIXELS', 12) + 'FILE')
console.log('-'.repeat(96))
for (const t of taken) console.log(pad(t.app, 11) + pad(t.device, 16) + pad(t.id, 17) + pad(t.px, 12) + t.file)
console.log('-'.repeat(96))
console.log(`${taken.length} screenshot(s) from the real app · ${failed.length} not taken`)
console.log(SOURCE === 'demo'
  ? 'Taken on the DEMONSTRATION ESTATE with the credentials in STORE_REVIEW_NOTES.md. These are the copies to submit — until a handset is reachable, when they should be retaken on hardware (M6 §4).'
  : 'These are FIXTURE screenshots and must not be submitted. Retake them with --source demo.')

if (failed.length > 0) {
  console.log('\nNot taken:')
  for (const f of failed) console.log(`  ${f}`)
  console.log(`\nBoth Metro servers must be running: ${Object.values(routes.apps).join(' ')}`)
  if (SOURCE === 'demo') console.log('…and `php artisan demo:seed` must have been run against the API they are built against.')
  process.exit(1)
}
