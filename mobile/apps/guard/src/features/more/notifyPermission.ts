/**
 * What guard-app-36 (More → Notifications) says about the phone's own permission — the words only,
 * with nothing in here that touches a phone, so what the screen claims can be proved by a test.
 *
 * WHY IT IS ITS OWN FILE. This screen is where a guard who said no during setup (step 4) is meant to
 * find out and change it, so it has to tell the truth about three different things at once: whether
 * the phone allows alerts, whether this app ever asked, and what the category switches below actually
 * do while alerts are off. They do nothing: the server honours a mute, but a phone that does not allow
 * notifications receives none of them either way, and a screen full of ON switches over a silenced
 * phone is the same false promise as a panic alert that never arrives.
 */

/** What the phone says, as the screen reads it. `undetermined` = it can still be asked. */
export type Permission = 'granted' | 'denied' | 'undetermined' | 'unsupported'

/** The "Notifications from this app" row: what it says, and which control it offers. */
export type PermissionRow = { detail: string; control: 'on' | 'allow' | 'settings' | 'none' }

export function permissionRow(permission: Permission, everAsked: boolean): PermissionRow {
  if (permission === 'granted') return { detail: "Allowed in the phone's settings", control: 'on' }
  if (permission === 'unsupported') return { detail: 'Not available in a web preview', control: 'none' }
  if (permission === 'denied') return { detail: "Turned off in the phone's settings: tap to open them", control: 'settings' }
  // Asked in setup and not allowed — say so plainly rather than "not asked yet", which would not be true.
  return { detail: everAsked ? 'You chose not to allow alerts when you set up: tap to allow' : 'Not allowed yet: tap to allow', control: 'allow' }
}

/**
 * The banner at the head of the screen when this phone is silent. It names what will not reach the
 * guard, in the words the rest of the app uses for those things, and it is shown however the app got
 * here — a refusal during setup, a refusal on this screen, or alerts switched off later in Settings.
 */
export function offBanner(permission: Permission): string | null {
  if (permission === 'granted' || permission === 'unsupported') return null
  return 'Alerts are off on this phone. Until they are on, this app cannot tell you about a duress or panic alert, an alertness check, somebody at the gate or a message from the control room unless you have it open.'
}

/** The paragraph under the card. What it promises depends on whether the phone allows anything at all. */
export function footerNote(permission: Permission, expoGo: boolean): string {
  const locked = 'Duress alerts, alertness check-ins and a resident’s "happening now" report cannot be switched off.'
  const base =
    permission === 'granted'
      ? `These choices are kept on this phone, and the server is told them so it can respect them when the app is closed. ${locked}`
      : `These choices are kept on this phone, and they only take effect once alerts are allowed above, until then nothing reaches this phone while the app is closed. ${locked}`
  return expoGo ? `${base} In this preview build the app can only notify you while it is open.` : base
}
