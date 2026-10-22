/**
 * THE TRIAGE — every row of PHONE_CHECK.md, and how it gets answered on hardware.
 *
 * The list of things a person still has to look at has to be very
 * short. That is the whole point of this file: it goes through PHONE_CHECK.md row by
 * row and puts each one in exactly one of four places, with the mechanism named, so
 * the human list is what is LEFT rather than what nobody got to.
 *
 *   scripted     written in run-checks.mjs and runs on a handset today.
 *   automatable  answerable by the same three mechanisms, with the command named;
 *                triaged and not yet scripted. Nothing here is a hope: each one says
 *                which of the three it uses.
 *   off-device   answerable, but in the console in a browser, not on the phone.
 *   human        genuinely needs an eye, an ear, a second phone, an iPhone, or a
 *                handset in a state adb cannot put it in. Each says which.
 *
 * THE THREE MECHANISMS, all in this folder:
 *   ui      `uiautomator dump` — every piece of text, every bound, enabled/checked.
 *   pixels  `screencap` — colour, and FLAG_SECURE's black answer.
 *   state   the phone's own settings over adb — airplane mode, Do Not Disturb,
 *           notification-policy access, font scale, timezone, permissions.
 *
 * COUNT. The plan of record is 61 checks. PHONE_CHECK.md as it stands today has 70 numbered rows
 * (G1-G30 plus G30b, R1-R35, D1-D4) — the list grew after that plan was written. All 70 are
 * triaged here, and the totals printed by `npm run device:plan` are of 70, not 61, so
 * the two numbers can be reconciled rather than quietly differing.
 */

/** @typedef {'scripted'|'automatable'|'off-device'|'human'} Method */

export const CHECKS = [
  /* ---- Guard app ------------------------------------------------------------ */
  { id: 'G1', app: 'guard', title: 'Sync queue opens from the badge', method: 'scripted', mech: 'ui', how: 'deep link to the queue, assert "WAITING TO UPLOAD" or "Everything is sent" and that "Unmatched Route" is nowhere' },
  { id: 'G2', app: 'guard', title: 'Queued clock-in syncs on pull', method: 'automatable', mech: 'ui+state', how: 'airplane on, clock in, airplane off, `input swipe` down, poll the row text Queued -> Syncing -> CONFIRMED; if it stays Queued, capture the amber reason', needs: 'a queued clock-in first, so it runs after G10' },
  { id: 'G3', app: 'guard', title: 'Shift card after sync', method: 'scripted', mech: 'ui', how: 'assert the post name, an "On post" badge and CLOCKED IN / REMAINING / NEXT CHECK; assert NOT "No shift rostered"' },
  { id: 'G4', app: 'guard', title: 'Camera permission then viewfinder', method: 'automatable', mech: 'ui+state', how: 'revoke CAMERA, open /scan, assert our own explanation has focus before permissioncontroller does, grant, assert the frame corners and the Flash / Manual entry controls exist', remainder: 'that the torch physically lights and the preview is a live image — hardware, needs an eye' },
  { id: 'G5', app: 'guard', title: 'Manual-entry keypad', method: 'scripted', mech: 'ui', how: 'assert twelve key nodes, then that "Verify pass" is enabled=false until a whole code is typed and enabled=true after' },
  { id: 'G6', app: 'guard', title: 'Search unit 47 — and no money', method: 'scripted', mech: 'ui', how: 'type 47, assert the unit/household/resident card and the approval button; assert moneyLike() finds nothing anywhere on the screen' },
  { id: 'G7', app: 'guard', title: 'Back button dims while pressed', method: 'automatable', mech: 'pixels', how: '`input motionevent DOWN` on the control, screencap while it is held, `UP` — the two captures must differ inside the control bounds and nowhere else' },
  { id: 'G8', app: 'guard', title: 'Standing orders gate the acknowledge', method: 'automatable', mech: 'ui', how: 'assert the tick box and button are enabled=false, `input swipe` to the end, assert both flip to enabled=true' },
  { id: 'G9', app: 'guard', title: 'Clock-out tiles and honest geofence row', method: 'automatable', mech: 'ui+state', how: 'revoke ACCESS_FINE_LOCATION, open clock-out, assert the hours and checkpoint tiles and that the geofence row says "not checked" rather than a distance' },
  { id: 'G10', app: 'guard', title: 'Offline badge and banner', method: 'scripted', mech: 'state+ui', how: '`cmd connectivity airplane-mode enable`, assert the badge reads Offline and the amber banner names a queue count; assert a quick action still opens' },
  { id: 'G11', app: 'guard', title: 'Offline message queues, then sends', method: 'automatable', mech: 'ui+state', how: 'airplane on, type, send, assert "Waiting to send"; airplane off, poll until it is gone; assert the text box bottom is above the keyboard top from the input-method window bounds' },
  { id: 'G12', app: 'guard', title: 'Payslip figures and no Download', method: 'scripted', mech: 'ui', how: 'assert Gross/NIS/NHT/Education Tax/PAYE/Net each with $ and two decimals; assert the word "Download" appears nowhere' },
  { id: 'G13', app: 'guard', title: 'My hours', method: 'automatable', mech: 'ui', how: 'assert seven day rows and the three tiles HOURS WORKED / NIGHT HOURS / SHIFTS' },
  { id: 'G14', app: 'guard', title: 'Leave date chips', method: 'automatable', mech: 'ui', how: 'tap "First day", assert a horizontally scrollable strip of day nodes appears, tap one, assert the form moves to "Last day"' },
  { id: 'G15', app: 'guard', title: 'Open shifts refuse to show a stale list offline', method: 'scripted', mech: 'state+ui', how: 'airplane on, open the list, assert the no-signal sentence and that no shift rows are present' },
  { id: 'G16', app: 'guard', title: 'Equipment tiles, three across, equal widths', method: 'automatable', mech: 'ui', how: 'bounds arithmetic on the six tiles: three distinct x positions, two distinct y, widths equal within 2 px; tap one and assert it alone is selected' },
  { id: 'G17', app: 'guard', title: 'Alertness consent screen', method: 'automatable', mech: 'ui', how: 'assert four paragraph nodes and both buttons, and that every clickable node is at least 48 dp (smallTargets)' },
  { id: 'G18', app: 'guard', title: 'Sign-out confirms in the page', method: 'scripted', mech: 'ui', how: 'tap Sign out, assert the focused window is still the app package (not a system dialog), the new-enrolment-code sentence is present, and "Stay signed in" is the solid control' },
  { id: 'G19', app: 'guard', title: 'Help topics open in place; 119 rows dial', method: 'automatable', mech: 'ui', how: 'tap a topic, assert the answer node appears under the question; tap an Emergency row, assert the focused window becomes the dialler, then BACK' },
  { id: 'G20', app: 'guard', title: 'Clock in with no fingerprint enrolled', method: 'human', mech: '—', how: '—', why: 'adb cannot remove an enrolled fingerprint on a retail build (`cmd fingerprint` and `locksettings` need root or a debuggable system image). A person has to delete the fingerprint in Settings, or use a second handset with none enrolled. Everything after that point — the override being offered, the sentence, the shift not starting quietly — is then scripted as G21.' },
  { id: 'G21', app: 'guard', title: 'Supervisor override: wrong PIN then right', method: 'automatable', mech: 'ui', how: '`input text` a wrong PIN, assert the message is the server\'s sentence and contains no SQL and no "something went wrong"; type the right one, assert the shift card carries the override mark' },
  { id: 'G22', app: 'guard', title: 'The console shows the override', method: 'off-device', mech: 'browser', how: 'playwright against the console (the pattern tools/fidelity/console-check.mjs already uses): the shift row flagged, the supervisor named, the Head of Security alerted' },
  { id: 'G23', app: 'guard', title: 'Expiring and expired licences look different', method: 'automatable', mech: 'pixels+ui', how: 'find the two licence rows in the tree, average the colour of each status chip with regionColour, and require colourDistance above 40 — two ambers fail' },
  { id: 'G24', app: 'guard', title: 'Sign-in & security is honest', method: 'automatable', mech: 'ui', how: 'assert the page says what cannot be changed on the phone and that no control claims to change a PIN the phone does not hold' },
  { id: 'G25', app: 'guard', title: 'No way to message another guard', method: 'scripted', mech: 'ui', how: 'walk every messages surface and assert no node offers a recipient picker, a guard search or a tap-a-name route; the only thread is the control room' },
  { id: 'G26', app: 'guard', title: 'Close account explains the employer owns it', method: 'automatable', mech: 'ui', how: 'assert the employer sentence and the remove-from-this-phone offer, and that nothing claims to delete an employment record' },
  { id: 'G27', app: 'guard', title: 'Location explanation BEFORE the OS box', method: 'scripted', first: 1, mech: 'state+ui', how: 'revoke both location permissions and force-stop; open clock-in, clock-out and the incident form in turn; at each, assert the focused window is still the app and our own explanation is on screen; only after tapping its continue control may permissioncontroller take focus' },
  { id: 'G28', app: 'guard', title: 'A guard who declines location can still work', method: 'scripted', first: 2, mech: 'state+ui', how: 'leave the permission revoked, dismiss the OS box, assert the dashboard is reachable and that a plain sentence names what will not work — and that nothing claims a position was taken' },
  { id: 'G29', app: 'guard', title: 'Notification permission is asked where it cannot be missed', method: 'scripted', first: 3, mech: 'state+ui', how: 'revoke POST_NOTIFICATIONS, force-stop, walk onboarding; assert the ask appears without hunting for it; decline, then assert the Notifications screen states the real state rather than promising alerts' },
  { id: 'G30', app: 'guard', title: 'Duress on a locked phone in Do Not Disturb', method: 'scripted', first: 5, mech: 'state+pixels', how: 'PASS ONE, before granting: `cmd notification disallow_dnd`, `set_dnd priority`, lock, fire the duress from the server, and read `dumpsys notification --noredact` for the channel\'s bypassDnd and whether the post was suppressed; assert the Notifications row reads off. PASS TWO: `cmd notification allow_dnd`, re-read, assert the row flips to On by itself and the post is no longer suppressed', remainder: 'that a sound actually came out of the handset with the screen locked — an ear. The record either way is captured; the noise is not.' },
  { id: 'G30b', app: 'resident', title: 'Resident app says nothing about Do Not Disturb', method: 'scripted', mech: 'ui', how: 'crawl every resident screen and assert the phrase "Do Not Disturb" appears nowhere, and that the panic channel is created without bypass' },

  /* ---- Resident app --------------------------------------------------------- */
  { id: 'R1', app: 'resident', title: 'Log in fields and button', method: 'automatable', mech: 'ui+pixels', how: 'assert two fields and a solid Log in control (a filled background, not bare text, by regionColour), and that the biometrics button is enabled=false with its reason readable' },
  { id: 'R2', app: 'resident', title: 'Six-box code screen', method: 'automatable', mech: 'ui', how: 'assert six boxes and that the input method is showing; type a wrong code, assert the server sentence and that the boxes are empty again' },
  { id: 'R3', app: 'resident', title: 'Permission rows show the real answer', method: 'automatable', mech: 'state+ui', how: 'revoke one and grant another, then assert each row reads Allowed / Not yet to match, and that Continue is enabled either way' },
  { id: 'R4', app: 'resident', title: 'Panic button at every scroll position', method: 'automatable', mech: 'ui', how: 'assert the greeting, estate and date; scroll the home list in steps and assert the panic node keeps the same bounds at every step' },
  { id: 'R5', app: 'resident', title: 'Panic needs a 1.5 s hold', method: 'automatable', mech: 'ui', how: '`motionevent DOWN`, `UP` after 600 ms — assert nothing was sent; again holding 1800 ms — assert the timeline appears and 119 stays clickable throughout' },
  { id: 'R6', app: 'resident', title: 'Invite categories and pick rows', method: 'automatable', mech: 'ui', how: 'assert four segment nodes on one row (equal y), tap Day, assert the chip strip appears, assert the generate control' },
  { id: 'R7', app: 'resident', title: 'The QR pass scans from another phone', method: 'human', mech: '—', how: '—', why: 'a second handset\'s camera. The capture can be checked for a crisp high-contrast square and the short code read out of the tree, but decoding the QR would need a decoder this repo does not carry, and "another phone can read it" is the actual claim.' },
  { id: 'R8', app: 'resident', title: 'Pass still draws offline', method: 'automatable', mech: 'state+ui', how: 'airplane on, open an existing pass, assert the code region is still drawn and the saved-copy line is present' },
  { id: 'R9', app: 'resident', title: 'Offline invite is a draft, not a pass', method: 'automatable', mech: 'state+ui', how: 'airplane on, generate, assert the amber DRAFT card, the CANNOT enter sentence, and both Send now and Discard' },
  { id: 'R10', app: 'resident', title: 'Gate notification arrives within ~15 s', method: 'automatable', mech: 'state+ui', how: 'raise a gate request from the server for this unit, poll the tree for the banner, assert under 15 s, tap it and assert the approve/deny screen with a countdown' },
  { id: 'R11', app: 'resident', title: 'Four dues chips on one row', method: 'automatable', mech: 'ui', how: 'bounds: four chip nodes sharing a y range, none wrapping, none clipped at the screen edge' },
  { id: 'R12', app: 'resident', title: 'No card field anywhere; Pay says the gateway is not integrated', method: 'automatable', mech: 'ui', how: 'open Pay maintenance fee, assert no EditText node exists until "Pay another amount" is tapped (then exactly one); tap Pay, assert the dialog reads "The payment gateway is not integrated yet." and that no payment success or receipt screen follows' },
  { id: 'R13', app: 'resident', title: 'Receipt title clear of the status bar; share is text', method: 'automatable', mech: 'ui+state', how: 'assert the title node y0 is below the status-bar inset read from the handset; tap Share, assert the system resolver takes focus, then BACK' },
  { id: 'R14', app: 'resident', title: 'Maintenance photo from the camera or the library', method: 'automatable', mech: 'ui', how: 'tap the tile, assert the system photo picker takes focus, pick one, assert the thumbnail with its small clear control; assert the chips row scrolls', remainder: 'a real camera photograph of a real scene — the camera path ends at hardware' },
  { id: 'R15', app: 'resident', title: 'Offline maintenance never says Submitted', method: 'automatable', mech: 'state+ui', how: 'airplane on, submit, assert "Saved on this phone" and "Pending sync" and that "Submitted" is absent; airplane off, poll until the request number appears' },
  { id: 'R16', app: 'resident', title: '119 dials in airplane mode', method: 'automatable', mech: 'state+ui', how: 'airplane on, open report incident, tap Call 119, assert the dialler takes focus, BACK' },
  { id: 'R17', app: 'resident', title: 'Notice loses its unread dot; opens offline', method: 'automatable', mech: 'state+ui', how: 'open a notice, go back, assert the dot node is gone; airplane on, open it again, assert it still opens with the saved-copy line' },
  { id: 'R18', app: 'resident', title: 'RSVP offline saves and sends itself', method: 'automatable', mech: 'state+ui', how: 'airplane on, answer, assert the choice is marked and "Saved on this phone"; airplane off, poll until it is confirmed' },
  { id: 'R19', app: 'resident', title: 'The ballot paper refuses a screenshot', method: 'scripted', mech: 'pixels', how: 'screencap the ballot: FLAG_SECURE makes Android answer with a black image, so isMostlyBlack must be true. Then after the vote, screencap the receipt: it must NOT be black — the second half is the one that is easy to forget' },
  { id: 'R20', app: 'resident', title: 'Ballot marks are cleared on background', method: 'automatable', mech: 'state+ui', how: 'mark the paper, KEYCODE_HOME, re-open, assert no mark is set and the privacy sentence is present' },
  { id: 'R21', app: 'resident', title: 'App lock, with emergency without unlocking', method: 'automatable', mech: 'state+ui', how: 'turn app lock on, force-stop, re-open, assert the locked screen and its three controls; tap Emergency alert and assert the panic screen opened without an unlock' },
  { id: 'R22', app: 'resident', title: 'Largest text size', method: 'automatable', mech: 'state+ui', how: '`settings put system font_scale 1.3`, walk the screens, assert every text node grew and that no node\'s bounds run past the screen edge or overlap a sibling', remainder: 'the final eye on "nothing looks wrong" — overlap arithmetic catches clipping, not ugliness' },
  { id: 'R23', app: 'resident', title: 'The two safety switches are on and locked on', method: 'automatable', mech: 'ui', how: 'assert both switch nodes are checked=true and enabled=false, and tapping each leaves checked=true' },
  { id: 'R24', app: 'resident', title: 'No amenity booking anywhere', method: 'scripted', mech: 'ui', how: 'crawl every resident surface including search and assert no node mentions a booking, and that the booking routes answer "not available at this estate"' },
  { id: 'R25', app: 'resident', title: 'Incident location: explanation first, ONE pin', method: 'scripted', first: 1.5, firstNote: 'the resident half of headline item 1 — the same ruling, the other app', mech: 'state+ui', how: 'revoke location, open the form, tap Use my location, assert our explanation has focus before permissioncontroller; grant, assert a single pin; leave the screen and assert `dumpsys location` lists no ongoing request from the package' },
  { id: 'R26', app: 'resident', title: 'Cancelling after a pin keeps nothing', method: 'automatable', mech: 'ui', how: 'cancel, re-open the form, assert no location node is present' },
  { id: 'R27', app: 'resident', title: 'Attachments open; another estate\'s never does', method: 'automatable', mech: 'ui+api', how: 'open the attachment and assert the right file; then ask the API directly for an attachment id belonging to another estate and require the same 404 an unknown id gets' },
  { id: 'R28', app: 'resident', title: 'A read count rises for you only', method: 'automatable', mech: 'ui+browser', how: 'read the notice on the phone, then read the count in the console before and after — it must move by one, not by the household or the estate' },
  { id: 'R29', app: 'resident', title: 'See all meetings, to the end', method: 'automatable', mech: 'ui', how: 'swipe until the tree stops changing, assert the oldest meeting is present and nothing froze' },
  { id: 'R30', app: 'resident', title: 'See all requests, to the end', method: 'automatable', mech: 'ui', how: 'the same, for maintenance requests' },
  { id: 'R31', app: 'resident', title: 'Delete my account: two taps, typed, never queued', method: 'automatable', mech: 'state+ui', how: 'count the taps from Settings, assert the kept/deleted list and the typed confirmation; airplane on, assert it refuses with a connection sentence and that nothing was queued' },
  { id: 'R32', app: 'resident', title: 'A completed deletion, on a throwaway account', method: 'automatable', mech: 'state+ui', how: 'the same flow carried through on a seeded throwaway resident, then assert the signed-out screen and that signing in again does not bring it back', needs: 'a throwaway account seeded first — destructive, so it runs last and never against the test resident' },
  { id: 'R33', app: 'resident', title: 'Only the primary can add or remove a member', method: 'automatable', mech: 'ui', how: 'sign in as a non-primary and assert neither control exists; sign in as the primary and assert both do; remove one, sync, assert it stays removed' },
  { id: 'R34', app: 'resident', title: 'Due soon is seven days', method: 'automatable', mech: 'ui', how: 'seed dues at 6, 7 and 8 days out and assert exactly the first two carry the due-soon mark' },
  { id: 'R35', app: 'resident', title: 'The phone in London, then Auckland — every time unchanged', method: 'scripted', first: 4, mech: 'state+pixels', how: 'set the handset to Europe/London, capture the time-bearing screens; set it to Pacific/Auckland, capture the same ones; require every pair to be identical outside the existing mask. The handset\'s own status-bar clock DOES change and is inside that mask, which is why the mask must be the one in tools/fidelity/lib.mjs' },

  /* ---- Reviewer mode -------------------------------------------------------- */
  { id: 'D1', app: 'demo', title: 'The demo account signs in first time', method: 'automatable', mech: 'ui', how: 'sign in with the reviewer credentials on a handset with no enrolment and assert the home screen' },
  { id: 'D2', app: 'demo', title: 'Believable data on every reachable screen', method: 'scripted', mech: 'ui', how: 'crawl every route and flag any list surface whose tree holds no rows and no explanation — the four empty-state screens called out in review are exactly this test' },
  { id: 'D3', app: 'demo', title: 'Writes refused by the SERVER', method: 'automatable', mech: 'ui+api', how: 'attempt each write from the app and assert the refusal sentence; then send the same request straight to the API with the demo token and require the server to refuse it too' },
  { id: 'D4', app: 'demo', title: 'The demonstration estate only', method: 'automatable', mech: 'ui+api', how: 'assert the estate name everywhere, and that the demo token is refused for every other estate id' },
]

/** Things that are not numbered rows, kept visible so they are not lost. */
export const UNNUMBERED_HUMAN = [
  { what: 'Face ID on an iPhone', why: 'there is no iPhone on this project. The entitlement is in the config and the purpose string is written; the prompt itself has never been seen.' },
  { what: 'The iPhone half of R19 (a blurred app-switcher preview) and of G30 (Time Sensitive through a Focus mode)', why: 'same — no iPhone.' },
  { what: 'SMS fallback over a real SIM', why: 'needs a SIM with credit and a second number; an emulator cannot send one.' },
]

/** Pure: the counts the triage summary reports. */
export function triage(checks = CHECKS) {
  const by = (m) => checks.filter((c) => c.method === m)
  const remainders = checks.filter((c) => c.remainder)
  return {
    total: checks.length,
    scripted: by('scripted').length,
    automatable: by('automatable').length,
    offDevice: by('off-device').length,
    human: by('human').length,
    humanRemainders: remainders.length,
    /** What a person is left holding: whole rows plus the remainder of a row that is otherwise automated. */
    humanList: [
      ...by('human').map((c) => ({ id: c.id, what: c.title, why: c.why })),
      ...remainders.map((c) => ({ id: `${c.id} (remainder)`, what: c.remainder, why: 'the rest of this row is automated' })),
      ...UNNUMBERED_HUMAN.map((u) => ({ id: '—', what: u.what, why: u.why })),
    ],
  }
}

/** The five headline checks, in the order they are run. */
export function firstFive(checks = CHECKS) {
  return checks.filter((c) => c.first).sort((a, b) => a.first - b.first || a.id.localeCompare(b.id))
}

export function orderedRun(checks = CHECKS) {
  const first = firstFive(checks)
  const rest = checks.filter((c) => !c.first)
  return [...first, ...rest]
}

/* ---- printed by `npm run device:plan` ------------------------------------- */

if (process.argv[1] && process.argv[1].endsWith('plan.mjs')) {
  const t = triage()
  const pad = (s, n) => String(s).padEnd(n)

  console.log('THE FIVE HEADLINE CHECKS, in order — these run before anything else:')
  for (const c of firstFive()) {
    const n = Number.isInteger(c.first) ? `${c.first}.` : `${Math.floor(c.first)}b`
    console.log(`  ${pad(n, 4)}${pad(c.id, 6)}${c.title}  [${c.method}]${c.firstNote ? `\n         ${c.firstNote}` : ''}`)
  }

  console.log('\nEVERY ROW OF PHONE_CHECK.md')
  console.log(pad('ID', 6) + pad('APP', 10) + pad('METHOD', 13) + pad('MECH', 14) + 'CHECK')
  console.log('-'.repeat(110))
  for (const c of CHECKS) console.log(pad(c.id, 6) + pad(c.app, 10) + pad(c.method, 13) + pad(c.mech, 14) + c.title)

  console.log('-'.repeat(110))
  console.log(`${t.total} rows · ${t.scripted} scripted · ${t.automatable} automatable, method named · ${t.offDevice} answered in the console · ${t.human} need a person`)

  console.log('\nWHAT IS LEFT FOR A PERSON — the list that has to stay short:')
  for (const h of t.humanList) console.log(`  ${h.id}  ${h.what}\n      ${h.why}`)
  console.log(`\n${t.humanList.length} items. Nothing on that list is waiting on code; each one is an eye, an ear, a second handset or an iPhone.`)
}
