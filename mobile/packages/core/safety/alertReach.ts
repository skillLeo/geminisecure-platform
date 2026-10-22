/**
 * WHAT THE PERSON IS TOLD ABOUT WHO THIS ACTUALLY REACHED.
 *
 * "If the alert genuinely reaches no handset, the resident must be told so, plainly, on the
 * panic screen, with the 119 call control given prominence. A panic that silently reaches
 * nobody is the worst failure this product can have. Never show 'help is coming' when it is not."
 *
 * The server counts honestly (`App\Services\Push\PanicFanOut` writes `alerted_handsets`,
 * `alerted_via` and `escalated_contacts` onto the alert). This is the only place that turns
 * those numbers into words, so there is exactly one wording to review and one to change.
 *
 * THREE STATES, AND THEY ARE NOT THE SAME SENTENCE.
 * ================================================
 *   reaching   the server has not finished working out who it reached (`alerted_handsets` is
 *              null, or the alert was saved by a build that predates the field). NOT "nobody":
 *              a screen that read a missing number as zero would frighten somebody whose
 *              gatehouse is on its way.
 *   reached    at least one handset. Only here may the screen imply that somebody is coming,
 *              and even here it says how many and stops.
 *   nobody     zero handsets. Said plainly, in the first sentence, with 119 given prominence.
 *              An escalation contact having been EMAILED is said too — but as its own fact,
 *              never as "help is coming", because an email is read when somebody next opens a
 *              mailbox.
 *
 * AND A TEXT IS NOT A HANDSET (Q-M09). The community's own emergency numbers — its
 * president, its property office, its head of security, the control room — are also sent
 * a text message when a panic is raised. That number is carried here as `texts`, it is
 * read from `sms_delivered` (what a provider ACCEPTED) and never from `sms_recipients`
 * (how many were tried), it is said as its own sentence, and it can never change `state`,
 * `handsets`, `reachedNobody` or `mayPromiseHelp`. It is 0 on every alert today: no
 * text-message provider has been bought, so the server records the message instead of
 * sending it and reports 0 — and 0 is what this file shows, which is why nothing on the
 * screen says a text went out. (What the screen DOES say, in every state, is that the
 * household's own emergency contacts are not texted automatically: no server code texts them
 * yet. That sentence is decided in `householdContacts.ts`, and it is the household's count —
 * never this community count — that may one day change it.)
 *
 * IF THE SERVER STARTS SENDING `sms: { provider_connected, attempted, delivered }` (M10 §1 —
 * a server change that may or may not have landed), its `delivered` is read in preference to
 * `sms_delivered`, and `provider_connected: false` forces the count to 0 whatever else is sent.
 * `attempted` is never read, for the same reason `sms_recipients` is not. Nothing here needs
 * the new field: without it the answer is exactly what it was.
 *
 * AND "ON DUTY" MEANS WHAT THE COUNT MEANS (M10 §1). The server no longer climbs a ladder: it
 * pushes the officers clocked on AND the officers rostered at the estate today, together, and
 * `alerted_handsets` counts both. `alerted_via: on_duty` says somebody among them was clocked
 * on — not that every phone counted was on duty — so the sentence says both groups.
 *
 * A HANDSET MEANS THE SERVER SENT TO IT. Not that it buzzed, not that anybody looked: Expo, the
 * carrier, the phone and Do Not Disturb are all downstream. So nothing below says "an officer
 * has seen this" or "help is on the way". Seen is the timeline's acknowledged step, because a
 * person had to press something for it; "help is on the way" is `responseHeadline`'s alone
 * (alertProgress.ts), and only once the server has recorded an officer being DISPATCHED.
 *
 * Pure: no imports, no clock, no network. Tested with the world switched off.
 */

import type { AlertAnswer } from './raiseAlert'

/** Which tier of the server's ladder answered. Anything else is read as unknown. */
export type ReachTier = 'on_duty' | 'rostered' | 'escalation' | 'none'

export type AlertReach = {
  state: 'reaching' | 'reached' | 'nobody'
  /** How many security handsets the server sent to. Null while it is still working it out. */
  handsets: number | null
  tier: ReachTier | null
  /** People told off-app (an email, a console) because no handset was reached. Never "help". */
  escalated: number
  /**
   * TEXT MESSAGES A PROVIDER ACTUALLY ACCEPTED (Q-M09) — never the number of numbers on
   * file, and never added to `handsets`. 0 on every alert today: the server has no SMS
   * provider yet and records what it would have sent instead of sending it, so the only
   * honest number is zero and the screen says nothing about texts at all.
   */
  texts: number
  /** The control room has the alert from the moment it is recorded, whoever is on shift. */
  consoleAlerted: boolean
  /** THE ONE QUESTION THE SCREEN ASKS. True only when the server has SAID zero. */
  reachedNobody: boolean
  /** True when `handsets` is the call-style ring's count — every officer rung because the raise reached none (M18). */
  viaRing: boolean
  /** Whether the screen may imply anybody is coming. False unless a handset was reached. */
  mayPromiseHelp: boolean
  headline: string
  detail: string
}

const TIERS: readonly string[] = ['on_duty', 'rostered', 'escalation', 'none']

const tierOf = (value: unknown): ReachTier | null => (typeof value === 'string' && TIERS.includes(value) ? (value as ReachTier) : null)

const phones = (n: number): string => `${n} officer phone${n === 1 ? '' : 's'}`

const count = (value: unknown): number => (typeof value === 'number' && Number.isFinite(value) && value > 0 ? Math.trunc(value) : 0)

/**
 * HOW MANY TEXT MESSAGES A PROVIDER ACTUALLY ACCEPTED for this alert — the only number that may
 * ever put the words "text message" on the panic screen as something that happened. 0 unless the
 * server positively says more: a null, a missing field, a nonsense value and a provider the server
 * says is not connected are all 0.
 */
export function textsDelivered(answer: Pick<AlertAnswer, 'sms_delivered' | 'sms'> | null | undefined): number {
  const sms = answer?.sms !== null && typeof answer?.sms === 'object' ? answer.sms : null
  if (sms?.provider_connected === false) return 0
  return count(sms?.delivered ?? answer?.sms_delivered)
}

/**
 * @param emergencyNumber the real local emergency number the screen is offering (119 in Jamaica),
 *   so the sentence and the button can never name different numbers.
 */
export function alertReach(
  answer:
    | Pick<AlertAnswer, 'alerted_handsets' | 'alerted_via' | 'escalated_contacts' | 'console_alerted' | 'sms_delivered' | 'sms' | 'rang_handsets'>
    | null
    | undefined,
  emergencyNumber: string,
): AlertReach {
  const raw = answer?.alerted_handsets
  const handsets = typeof raw === 'number' && Number.isFinite(raw) && raw >= 0 ? Math.trunc(raw) : null
  const tier = tierOf(answer?.alerted_via)
  const escalated = count(answer?.escalated_contacts)

  /*
   * TEXTS ARE COUNTED FROM WHAT WAS DELIVERED AND FROM NOTHING ELSE (Q-M09) — `sms_delivered`,
   * or `sms.delivered` when the server sends that (`textsDelivered` above).
   *
   * The server also sends `sms_recipients` — how many of the community's emergency
   * numbers were ATTEMPTED — and this function deliberately never reads it. "Four numbers
   * were tried" is not a fact about anybody being told, and on this platform today it
   * would be four while the true count of delivered messages is nought: there is no SMS
   * provider, so the server writes the message down and reports 0. Reading the attempted
   * count here is exactly the failure this whole file exists to prevent, one channel
   * along.
   *
   * It never touches `handsets`, `state` or `mayPromiseHelp` either. A text to the
   * President's mobile is not an officer being sent to the gate.
   */
  const texts = textsDelivered(answer)
  const texted = texts > 0 ? `${texts} of your community’s emergency numbers ${texts === 1 ? 'was' : 'were'} also sent a text message.` : ''

  // The control room has it because the alert exists. The server says so explicitly; a saved
  // answer from an older build did not, and the fact was true then too.
  const consoleAlerted = answer?.console_alerted !== false

  if (handsets === null) {
    return {
      state: 'reaching',
      handsets: null,
      tier,
      escalated,
      texts,
      consoleAlerted,
      reachedNobody: false,
      viaRing: false,
      mayPromiseHelp: false,
      headline: 'Reaching your community’s officers',
      detail: ['Your alert is recorded and the control room has it. This screen will say how many officer phones it reached.', texted].filter(Boolean).join(' '),
    }
  }

  /*
   * NOBODY AT THE RAISE, AND THEN EVERY OFFICER RUNG (M18 §1.6). When the panic's first push reaches no phone, the
   * server does not stop there: its call-style ring rings every active officer of the community at once. Before M18
   * this screen went on saying "No officer's phone was reached" while an officer's phone was ringing (production,
   * alerts 18 and 19). `rang_handsets` is how many phones that ring was SENT to — sent, like `alerted_handsets`, so
   * the words claim no more than that, and 119 stays in the sentence because a ring to an off-duty officer is not
   * somebody at the gate.
   */
  const rang = count(answer?.rang_handsets)
  if (handsets === 0 && rang > 0) {
    return {
      state: 'reached',
      handsets: rang,
      tier,
      escalated,
      texts,
      consoleAlerted,
      reachedNobody: false,
      viaRing: true,
      mayPromiseHelp: true,
      headline: `Ringing ${phones(rang)}`,
      detail: [
        `No officer was on duty with a phone that could be reached, so every officer of your community is being rung: ${phones(rang)} so far. The control room has your alert on screen.`,
        texted,
        `If you are in danger, call ${emergencyNumber} as well.`,
      ]
        .filter(Boolean)
        .join(' '),
    }
  }

  if (handsets === 0) {
    return {
      state: 'nobody',
      handsets: 0,
      tier,
      escalated,
      texts,
      consoleAlerted,
      reachedNobody: true,
      viaRing: false,
      mayPromiseHelp: false,
      headline: 'No officer’s phone was reached',
      detail: [
        /*
         * NOT "nobody was on duty". Zero handsets means no officer's phone could be SENT a
         * notification — and an officer can be clocked on at the gate with a phone that has no
         * push address at all (found on a test phone, 2026-09-22: on duty, app open, and this
         * screen told the resident nobody was there). The sentence claims only what the count proves.
         */
        'No officer’s phone could be sent a notification: nobody is on duty at your community, or the phones on duty could not be reached.',
        escalated > 0
          ? `Your community’s escalation contact has been emailed: that is not the same as somebody being on their way.`
          : 'There was nobody to escalate to either.',
        // Said as its own fact and never as reassurance: a text is a message waiting to be
        // read, and this branch's whole job is to stop the screen implying anybody is coming.
        texted,
        consoleAlerted ? 'The control room has your alert on screen.' : '',
        `Do not wait for this. Call ${emergencyNumber} now.`,
      ]
        .filter(Boolean)
        .join(' '),
    }
  }

  return {
    state: 'reached',
    handsets,
    tier,
    escalated,
    texts,
    consoleAlerted,
    reachedNobody: false,
    viaRing: false,
    mayPromiseHelp: true,
    headline: `Sent to ${phones(handsets)}`,
    detail: [
      tier === 'rostered'
        ? `Nobody was clocked on, so it went to the ${phones(handsets)} of the officers rostered at your community today. The control room has it too.`
        : `${phones(handsets)} at your community ${handsets === 1 ? 'was' : 'were'} alerted, the officers on duty and those rostered there today, and the control room has it too.`,
      texted,
    ]
      .filter(Boolean)
      .join(' '),
  }
}
