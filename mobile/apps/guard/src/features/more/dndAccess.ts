/**
 * "Let a duress alarm through Do Not Disturb" — the GUARD APP's row on More → Notifications
 * (guard-app-36). Every word of it, and the one decision behind it, with nothing in here that touches
 * a phone, so what the screen offers and what it claims can both be proved by a test.
 *
 * WHY THIS EXISTS AT ALL.
 * Android silences every notification while the phone is in Do Not Disturb, in a Focus mode, or in the
 * bed-time schedule most handsets turn on by themselves — including the channel a duress alarm, a panic
 * at the officer's own site and an alertness check arrive on. One thing lifts that, and only one:
 * the app's safety channel carrying `bypassDnd`, which Android honours only while the app holds
 * **Notification Policy Access** (`ACCESS_NOTIFICATION_POLICY`), granted by the person in the phone's
 * own Settings. This is an employer-issued handset carried on duty, and a lone officer under threat is
 * the case that permission exists for. The Resident App asks for none of this and has no such row.
 *
 * THE TWO HALVES, AND WHICH ONE IS REVERSIBLE.
 *   IRREVERSIBLE  the channel is created WITH the flag, from first install, because a notification
 *                 channel's configuration is fixed once a phone has created it
 *                 (`packages/core/notify/links.ts` `DND_BYPASS`, applied in `notify/runtime.ts`).
 *   REVERSIBLE    the ASKING, which is this file, behind `flags.askForDndAccess` (default on). If a
 *                 reviewer objects, that flag goes off in a JS update: the row disappears, nothing
 *                 sends a guard to the Do Not Disturb settings, Android drops the flag in silence and
 *                 nothing in the app claims otherwise.
 *
 * NOTHING HERE CLAIMS ANYTHING IT HAS NOT CHECKED. The state the row is drawn from is read back out of
 * Android (`safetyChannelDnd`), never inferred from having asked: on a phone that has not granted the
 * access the answer is `off`, and the row says so and offers the way to change it. `unknown` — iOS,
 * web, or a phone that would not answer — draws NO ROW, because a control that leads nowhere is worse
 * than silence (gate `no-dead-controls`).
 */

import { flags } from '@gs/core/lib'
import type { DndBypass } from '@gs/core/notify'

/**
 * The phone's own screen for "which apps may change Do Not Disturb"
 * (`Settings.ACTION_NOTIFICATION_POLICY_ACCESS_SETTINGS`). The app OPENS it and nothing more — it never
 * changes anybody's Do Not Disturb, and there is no API by which it could without the guard granting
 * this first. Sent with `Linking.sendIntent` from the screen; Android only.
 */
export const DND_SETTINGS_ACTION = 'android.settings.NOTIFICATION_POLICY_ACCESS_SETTINGS'

/** The row on "On this phone": what it says, and which control it offers. `null` = draw no row at all. */
export type DndRow = { title: string; detail: string; control: 'on' | 'allow' }

/** The three alerts the bypass really covers — the locked ones, the ones a guard cannot switch off. */
const COVERS = 'a duress alarm, a panic at your site and an alertness check'

/**
 * `bypass` is what Android answered for the safety channel, not what the app asked for.
 * `asking` is `flags.askForDndAccess` — passed in so a test can put it either way without a rebuild.
 */
export function dndRow(bypass: DndBypass, asking: boolean = flags.askForDndAccess): DndRow | null {
  // The phone cannot say (iOS, the web preview, an Android that would not answer): offer nothing.
  if (bypass === 'unknown') return null
  if (bypass === 'on') {
    return {
      title: 'Alerts come through Do Not Disturb',
      detail: `On. This phone will sound ${COVERS} even in Do Not Disturb, a Focus mode or bed time. Everything else from this app stays silenced with the rest.`,
      control: 'on',
    }
  }
  // Ruled off: the channel keeps the flag it was built with, and the app simply stops asking for it.
  if (!asking) return null
  return {
    title: 'Let a duress alarm through Do Not Disturb',
    detail: `This is a work phone. Do Not Disturb, a Focus mode and bed time silence everything on it: including ${COVERS}. Tap to let those three through, in the phone's own settings. This app never changes your Do Not Disturb itself, and every other notification stays silenced.`,
    control: 'allow',
  }
}

/**
 * The sentence the screen shows when the guard came back from that settings screen and nothing changed.
 * It names where they are going, rather than pretending the tap failed — on some handsets the switch is
 * two levels down, and a guard who did not find it should be told where to look, not told it broke.
 */
export const DND_NOT_GRANTED =
  "Not changed yet. In the phone's settings, find GeminiSecure Guard under Do Not Disturb access (some phones call it “Notification policy access”) and switch it on."

/** The phone would not open that screen at all. Said once, honestly, with the manual route. */
export const DND_NO_SETTINGS_SCREEN =
  "This phone would not open that settings screen. Open Settings → Notifications → Do Not Disturb → App exceptions (or Special app access → Do Not Disturb access) and allow GeminiSecure Guard there."
