import { now } from '../fidelity'
import { newUuid } from './uuid'

const pad = (n: number, width = 2): string => String(Math.trunc(Math.abs(n))).padStart(width, '0')

/*
 * WHETHER THE PHONE'S CLOCK CAN BE BELIEVED (M12 §1.4). `X-Device-Time` is fixed when an act is captured, so on its
 * own it cannot tell a wrong clock from an act that waited for signal. Every request therefore also says what the
 * clock reads AS IT IS SENT (`X-Device-Sent-At`), which run of the app this is (`X-Device-Run`, new each launch) and
 * its steady clock (`X-Device-Mono`, milliseconds since the app started — nobody can set it). A queued clock-in
 * carries the run and the steady clock of its CAPTURE; the server compares the two and knows whether the wall clock
 * was moved while it waited (app/Api/DeviceClock.php).
 */
const RUN = newUuid()

/** This run of the app: the same id from launch until the process ends. */
export const deviceRun = (): string => RUN

/** The steady clock, in whole milliseconds since this run began — or null where the runtime has none. */
export function steadyMs(): number | null {
  const perf = (globalThis as { performance?: { now?: () => number } }).performance
  return typeof perf?.now === 'function' ? Math.round(perf.now()) : null
}

/**
 * THE PHONE'S OWN CLOCK — deliberately, and the only outbound value in the product that reads it.
 *
 * `X-Device-Time` is a FACT ABOUT THE HANDSET, not a time to display: the moment the act happened by the
 * clock the person holding the phone was looking at, with that phone's own zone offset. The server never
 * corrects it; it answers `server_time`, `device_time` and `clock_skewed`, and a guard whose clock is
 * wrong is shown that it is wrong — it is never silently rewritten. So `getTimezoneOffset()` and the
 * local-time getters below are correct here and nowhere else: everything a human READS goes through
 * lib/siteTime.ts and the SITE's zone, and every wall-clock time the phone SENDS (a booking
 * slot, a pass window) is built with `atSiteZone`.
 *
 * A queued act keeps the time it was captured, not the time it was finally sent.
 */
export function deviceTimeIso(date: Date = now()): string {
  // The HANDSET's offset — see above. Never used to render anything.
  const offset = -date.getTimezoneOffset() // minutes east of UTC
  const sign = offset >= 0 ? '+' : '-'
  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
    `T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}` +
    `${sign}${pad(offset / 60)}:${pad(offset % 60)}`
  )
}
