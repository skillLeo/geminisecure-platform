/**
 * CHANGING THE HANDSET'S OWN TIMEZONE — check R35, and the single most important
 * ruling on this project.
 *
 * THE ONE CLOCK RULE: the server stores UTC, every API time is a
 * full instant, and everything a person reads is rendered in the SITE's timezone by
 * IANA name. The consequence a client can see is that a phone carried to London or
 * Auckland must show a Jamaican shift at the same Jamaican time it showed at the gate.
 * The five-hours-early bug the client reported was exactly this, and a test suite
 * cannot catch it coming back, because a test suite runs on a machine whose clock the
 * test set. Only a handset whose OWN timezone has moved can.
 *
 * So this is not "assert a string". It is: set the handset to Europe/London, capture
 * the screens; set it to Pacific/Auckland, capture the same screens; and require the
 * two sets to be IDENTICAL outside the mask. The handset's own status-bar clock DOES
 * change, and it is inside the existing mask, which is why the mask has to be the one
 * from tools/fidelity/lib.mjs and not a new looser one.
 *
 * CAN adb DO IT? Honestly: it depends on the handset, and this file does not pretend
 * otherwise. There are four routes, none of them guaranteed on a locked-down retail
 * build, and none of them reports its own success truthfully:
 *
 *   1. `settings put global auto_time_zone 0` — the shell holds WRITE_SECURE_SETTINGS,
 *      so this one nearly always takes. It changes nothing by itself; without it the
 *      network puts the zone straight back and the next rung looks like it failed.
 *   2. `cmd time_zone_detector suggest_manual_time_zone --zone_id <zone>` — Android 11
 *      and newer. Needs SUGGEST_EXTERNAL_TIME, which the shell holds on some builds
 *      and not others. Prints nothing useful either way.
 *   3. `service call alarm <n> s16 <zone>` — IAlarmManager.setTimeZone. The
 *      transaction number is not stable across API levels (33 and 3 are the two that
 *      have been seen), the call needs SET_TIME_ZONE, and — this is the trap — it
 *      returns `Result: Parcel(00000000)` whether it worked or was refused.
 *   4. `setprop persist.sys.timezone <zone>` — root only. On a retail handset it is
 *      refused; it is in the ladder because an emulator or a rooted test device does
 *      it instantly.
 *
 * BECAUSE NONE OF THEM REPORTS TRUTHFULLY, NOTHING HERE TRUSTS AN EXIT CODE. Every
 * rung is followed by a READ-BACK of two independent things — the system property and
 * what `date +%z` says the offset is — and the zone is only called changed when both
 * agree with what was asked for. If the ladder runs out, the harness says so in one
 * sentence and prints the manual fallback: four taps on the phone, then re-run with
 * `--resume`, and the sweep carries on from there. That is a worse outcome than
 * automation and a far better one than a green tick nobody earned.
 */

/** The two zones the check names, plus Kingston to put the handset back. */
export const ZONES = {
  home: 'America/Jamaica',
  london: 'Europe/London',
  auckland: 'Pacific/Auckland',
}

/** The ladder, in the order it is climbed. */
export const RUNGS = ['auto-off', 'cmd-time-zone-detector', 'service-call-33', 'service-call-3', 'setprop']

/**
 * What `date +%z` SHOULD say, if the handset really moved to this zone. Pure, and the
 * reason the check survives daylight saving: Europe/London is +0100 in July and +0000
 * in January, and asserting a fixed "+0100" would be the very mistake the one-clock
 * rule exists to forbid.
 *
 * @returns {number} minutes east of UTC
 */
export function expectedOffsetMinutes(zone, at = new Date()) {
  const name = new Intl.DateTimeFormat('en-US', { timeZone: zone, timeZoneName: 'longOffset' })
    .formatToParts(at)
    .find((p) => p.type === 'timeZoneName')?.value
  if (!name) throw new Error(`unknown zone: ${zone}`)
  if (/^(GMT|UTC)$/.test(name)) return 0
  const m = /^(?:GMT|UTC)([+-])(\d{2}):(\d{2})$/.exec(name)
  if (!m) throw new Error(`could not read an offset out of "${name}" for ${zone}`)
  return (m[1] === '-' ? -1 : 1) * (Number(m[2]) * 60 + Number(m[3]))
}

/** `+0100` / `-0500` as the handset's `date +%z` prints it. */
export function parseOffset(text) {
  const m = /([+-])(\d{2})(\d{2})/.exec(String(text ?? ''))
  if (!m) return null
  return (m[1] === '-' ? -1 : 1) * (Number(m[2]) * 60 + Number(m[3]))
}

/**
 * Did the handset move? Two independent readings, and a verdict that refuses to be
 * optimistic: anything short of both agreeing is `partial`, which the harness treats
 * as "not done" and falls back from.
 *
 * @param {{wanted: string, prop: string|null, offsetText: string|null, at?: Date}} r
 * @returns {{state: 'set'|'partial'|'unchanged', why: string, wantedOffset: number, sawOffset: number|null}}
 */
export function timezoneVerdict({ wanted, prop, offsetText, at = new Date() }) {
  const wantedOffset = expectedOffsetMinutes(wanted, at)
  const sawOffset = parseOffset(offsetText)
  const propMatches = prop === wanted
  const offsetMatches = sawOffset !== null && sawOffset === wantedOffset

  if (propMatches && offsetMatches) return { state: 'set', why: `persist.sys.timezone is ${wanted} and date says ${fmt(sawOffset)}`, wantedOffset, sawOffset }
  if (offsetMatches && !propMatches) return { state: 'partial', why: `the offset is right (${fmt(sawOffset)}) but persist.sys.timezone still reads ${prop ?? 'nothing'} — another zone shares this offset, so this is not proof`, wantedOffset, sawOffset }
  if (propMatches && !offsetMatches) return { state: 'partial', why: `persist.sys.timezone reads ${wanted} but date says ${offsetText ?? 'nothing'}, not ${fmt(wantedOffset)} — the running processes have not taken it`, wantedOffset, sawOffset }
  return { state: 'unchanged', why: `asked for ${wanted} (${fmt(wantedOffset)}); the handset says ${prop ?? 'nothing'} / ${offsetText ?? 'nothing'}`, wantedOffset, sawOffset }
}

const fmt = (min) => `${min < 0 ? '-' : '+'}${String(Math.floor(Math.abs(min) / 60)).padStart(2, '0')}${String(Math.abs(min) % 60).padStart(2, '0')}`

/**
 * Climb the ladder until a read-back agrees, or run out. Never claims anything an
 * exit code told it.
 *
 * @param {ReturnType<import('./adb.mjs').adb>} device
 */
export function setTimezone(device, zone, { log = console.log } = {}) {
  const attempts = []
  for (const rung of RUNGS) {
    let issued
    try {
      issued = device.tryTimezoneRung(rung, zone)
    } catch (e) {
      attempts.push({ rung, issued: false, error: e.message, verdict: null })
      continue
    }
    if (device.dryRun) {
      attempts.push({ rung, issued: true, verdict: { state: 'unknown', why: 'dry run — no handset was asked' } })
      if (rung === 'auto-off') continue
      continue
    }
    if (rung === 'auto-off') {
      attempts.push({ rung, issued: issued.status === 0, verdict: null })
      continue // it is a precondition, not a way of setting the zone
    }

    const verdict = timezoneVerdict({ wanted: zone, prop: device.prop('persist.sys.timezone'), offsetText: device.sh('date +%z') })
    attempts.push({ rung, issued: issued.status === 0, verdict })
    log(`  ${rung}: ${verdict.state} — ${verdict.why}`)
    if (verdict.state === 'set') return { ok: true, zone, rung, attempts }
  }
  return { ok: false, zone, rung: null, attempts, fallback: manualFallback(zone) }
}

/**
 * THE CONTROL PASS, and why the check would be wrong without it.
 *
 * "Capture in London, capture in Auckland, require them identical" is almost right and
 * would report a failure roughly every time. Minutes pass between the two passes, and a
 * screen legitimately moves on its own while they do: a countdown ticks, "2 min ago"
 * becomes "4 min ago", a shift's REMAINING falls. None of that is the zone.
 *
 * So the sweep captures THREE times — London, Auckland, London again — and the third
 * pass is the control: London against London is everything that moved because time
 * passed and nothing else. A screen only fails when the London/Auckland difference is
 * bigger than its own control by more than `epsilon`, which is the part only the zone
 * can explain.
 *
 * And when the control itself is large, the screen is INCONCLUSIVE, not a pass: a screen
 * that redraws half of itself every minute cannot answer this question, and saying so is
 * the honest result. Calling it a pass because the arithmetic came out under a threshold
 * would be the same mistake as widening the budget.
 *
 * @param {{zoneDiff: number, control: number, epsilon?: number, ceiling?: number}} r percentages
 */
export function zoneVerdict({ zoneDiff, control, epsilon = 0.05, ceiling = 1 }) {
  if (control > ceiling) {
    return { state: 'inconclusive', why: `this screen moves ${control.toFixed(2)}% on its own between two captures in the SAME zone, which is more than the ${ceiling}% a zone comparison can see past. No verdict is claimed for it.` }
  }
  const attributable = zoneDiff - control
  if (attributable > epsilon) {
    return { state: 'changed', why: `London vs Auckland differs by ${zoneDiff.toFixed(3)}%, and the same-zone control only accounts for ${control.toFixed(3)}%. ${attributable.toFixed(3)}% is the handset's zone and nothing else — this is the five-hours-early bug.` }
  }
  return { state: 'unchanged', why: `London vs Auckland differs by ${zoneDiff.toFixed(3)}% and the same-zone control is ${control.toFixed(3)}%, so nothing moved that the clock ticking does not already explain` }
}

/** What to say to a person when every rung has been refused. */
export function manualFallback(zone) {
  return [
    `This handset will not let adb change its timezone. Nothing has been faked and no timezone result is claimed.`,
    ``,
    `On the phone, four taps:`,
    `  Settings -> System -> Date & time`,
    `  turn OFF "Set time zone automatically"`,
    `  Time zone -> ${zone}`,
    ``,
    `Then, on the laptop:  npm run device:checks -- --only R35 --resume`,
    `The sweep reads the zone back before it captures anything, so if the taps did not take, it stops and says so rather than measuring the wrong thing.`,
  ].join('\n')
}
