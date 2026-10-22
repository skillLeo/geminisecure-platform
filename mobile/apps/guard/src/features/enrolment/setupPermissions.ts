/**
 * Setup step 4 — "Before you start work". Every word the step shows and every decision it makes,
 * with nothing in it that touches a phone, so the whole step can be proved by a test.
 *
 * WHY THE STEP EXISTS AT ALL. Two things in this app used to happen to a guard without a sentence
 * of warning:
 *
 *   ALERTS  On Android 13+ and on every iPhone, notifications are OFF until the app asks. Until the
 *           guard says yes the server has no address for this handset (`device_push_tokens`), so a
 *           duress alert, a panic at the site and an alertness check all stop at the phone's door in
 *           silence. The only place this app ever asked was More → Notifications, which a guard has
 *           no reason to open. Panic and duress come above everything else, so the ask belongs
 *           where every guard goes exactly once: setup.
 *
 *   WHERE YOU ARE  The clock-in screen asks the phone for location the moment it opens, and setup
 *           used to end on that screen — so the first thing a newly enrolled guard saw was the
 *           operating system's location dialog with nothing on screen explaining it. Google's policy
 *           and Apple's review guidance both want the disclosure IN THE APP, BEFORE the system prompt, with an explicit
 *           action to continue. That disclosure is the second half of this step, and until the guard
 *           has passed it no file in this app may put the location prompt up (`onboarding.locationDisclosed`).
 *
 * THE STEP ASKS FOR ONE OF THEM AND DISCLOSES THE OTHER, deliberately. Notifications have no later
 * moment that could be called "in context" — there is no tap that means "alert me about a duress
 * alarm" — so the phone is asked here. Location does have one: the guard taps Clock in, at a post,
 * and the prompt belongs there. Setup states the reason; the prompt follows in context, once.
 *
 * NO BOARD DRAWS IT (guard-app-29/-17/-30 are steps 1–3; guard-app-36 is Notifications). It is built
 * from those boards' own parts — the step chrome and note block from -29, the ruled card and its icon
 * rows from -30, the kit's primary and outline buttons — and it follows the Resident App's "Before we
 * get started" screen, which is the pattern PERMISSIONS.md and STORE_COMPLIANCE.md already describe.
 *
 * THE RULES IT FOLLOWS
 *   - the reason is on screen BEFORE the phone's own prompt (the "prominent disclosure")
 *   - refusing never strands a guard: setup finishes either way, and what will NOT work is said
 *     plainly rather than glossed over
 *   - it is put ONCE per enrolment (`onboarding.notificationsAskedAt` / `locationDisclosedAt`), never
 *     on a launch. A rebind clears the whole record, so a new guard on this handset is asked once too
 *   - granting alerts registers the push token through the ONE path that already exists: the shared
 *     `notificationPermission(true)` nudges `usePushRegistration`. There is no second path.
 */

import type { IconName } from '@gs/core/ui'

/** The shape `notificationPermission()` answers with; `null` when the phone cannot say. */
export type PhoneAnswer = { granted: boolean; status: string; canAskAgain: boolean } | null

export type Outcome = 'idle' | 'granted' | 'declined' | 'blocked' | 'unavailable'

/** What is stored once the question has been put — every outcome but "not asked yet". */
export type Answer = Exclude<Outcome, 'idle'>

type Tone = 'green-400' | 'amber-500'

/** 'ask' — nothing has been answered yet. 'answered' — the phone has spoken, or the guard skipped it. */
export type Phase = 'ask' | 'answered'

/** The four things this app really sends to a phone that is not open. Every one is a live feature. */
export const ALERTS: { icon: IconName; title: string; detail: string }[] = [
  { icon: 'shield-alert', title: 'A duress or panic alert', detail: "Yours, another officer's, or a resident's at this site" },
  { icon: 'qa-tile', title: 'Somebody at the gate', detail: 'A visitor or a walk-up waiting on you' },
  { icon: 'tab-messages', title: 'A message from the control room', detail: 'And site broadcasts' },
  { icon: 'why-note', title: 'An alertness check', detail: 'You have two minutes to answer one' },
]

/**
 * Said BEFORE the phone's location dialog can appear anywhere in this app: what is read, when, who it
 * is for, and what it is NOT. On-duty sharing in the background (M11 §3) is NOT asked for here: it has its
 * own screen with its own words (features/tracking/screens/TrackingConsentScreen.tsx), and this says so
 * rather than promise "never in the background" to an officer who may agree to exactly that.
 */
export const LOCATION_DISCLOSURE =
  'Your position is read when you start a shift, when you end one, when you report an incident and when you raise an alert, so the office can confirm you were at your post, and so help can find you. At those moments it is read only while this app is open. Sharing your location in the background while you are on duty is a separate question, asked on its own screen after you have read what it records, and nothing is read after you clock out.'

/** What a refusal really costs, in the order a guard meets it. Nothing here is softened. */
export const LOCATION_WITHOUT =
  "Say no and you can still work. The app cannot confirm you are at your post, so a shift is started with a supervisor's PIN instead, and an incident report goes without a map pin. Your duress alert is still sent: just without a position."

/**
 * The phone's answer → where the alerts half of this step stands. Never 'idle': the question has been
 * put by the time this is called. A phone that cannot be asked is not a refusal.
 */
export function outcomeOf(answer: PhoneAnswer): Answer {
  if (!answer) return 'unavailable'
  if (answer.granted) return 'granted'
  return answer.canAskAgain ? 'declined' : 'blocked'
}

export type StepCopy = {
  title: string
  sub: string
  alerts: {
    heading: string
    /** Shown under the four rows while nothing has been answered; null afterwards. */
    note: string | null
    /** Where it stands, once it has been answered; null before. */
    line: { tone: Tone; text: string } | null
  }
  location: { heading: string; disclosure: string; without: string }
  primaryLabel: string
  /** The second control. Never blocks the primary, and is null when there is nothing useful to offer. */
  secondary: { label: string; action: 'skip' | 'ask' | 'settings' } | null
}

const WHERE = 'More → Notifications'

/**
 * Every word of the step. `expoGo` is the preview build, where remote push does not exist at all
 * (Expo SDK 53+) — promising "even when the app is closed" there would be the same lie in a new place.
 */
export function stepCopy(phase: Phase, alerts: Outcome, expoGo = false): StepCopy {
  const closed = expoGo
    ? 'In this preview build the app can only alert you while it is open. The installed app reaches you any time.'
    : 'They reach this phone even when the app is closed and the screen is off.'

  const line: StepCopy['alerts']['line'] =
    phase === 'ask'
      ? null
      : alerts === 'granted'
        ? { tone: 'green-400', text: `Alerts are on. You can change them any time in ${WHERE}.` }
        : alerts === 'blocked'
          ? { tone: 'amber-500', text: `Alerts are off, and only the phone's own Settings can turn them back on now. ${WHERE} takes you straight there.` }
          : alerts === 'unavailable'
            ? { tone: 'amber-500', text: `This phone cannot be asked here. Check ${WHERE} on the phone you use on duty.` }
            : { tone: 'amber-500', text: `Alerts are off. While they are, this app cannot tell you about any of the four above unless you have it open. Turn them on whenever you like in ${WHERE}.` }

  const secondary: StepCopy['secondary'] =
    phase === 'ask'
      ? { label: 'Skip for now', action: 'skip' }
      : alerts === 'blocked'
        ? { label: 'Open phone settings', action: 'settings' }
        : alerts === 'declined'
          ? { label: 'Ask me again', action: 'ask' }
          : null

  return {
    title: 'Before you start work',
    sub: 'Two things this phone does for you on duty. Neither one is a condition of using the app, and you can change both later.',
    alerts: {
      heading: 'Alerts on this phone',
      note: phase === 'ask' ? `Your phone will ask you next. These four are what this app sends. ${closed}` : null,
      line,
    },
    location: { heading: 'Where you are', disclosure: LOCATION_DISCLOSURE, without: LOCATION_WITHOUT },
    primaryLabel: phase === 'ask' ? 'Allow alerts' : 'Finish setup',
    secondary,
  }
}

/** "Skip for now" is a real answer: it is recorded, and nothing asks again by itself. */
export const SKIPPED: Answer = 'declined'

/** Once is once. A date already written is the only reason not to put the question. */
export const shouldAsk = (askedAt: string | null | undefined): boolean => !askedAt

/** Setup never stops at this step, whatever the phone said. Here so a test can say so out loud. */
export const blocksSetup = (_phase: Phase, _alerts: Outcome): false => false
