/**
 * WHAT A DEMONSTRATION SESSION IS TOLD WHEN IT TRIES TO CHANGE SOMETHING.
 *
 * "Explore a demo community" opens a READ-ONLY session: the server hands back the Resident App's
 * abilities with every `:write` struck out, so every act is refused by the route's own middleware
 * before a controller is reached. That part was always right. What was wrong was everything the
 * person then read.
 *
 * TWO FAULTS, AND THE SECOND IS THE SERIOUS ONE.
 *
 *   1. The sentence. A refusal arrived as `403 missing_ability`, whose message is "This handset's
 *      token does not carry the ability this endpoint needs." An App Store reviewer taps "Invite
 *      visitor" on the demonstration and reads a developer's error. They are not wrong to call
 *      that a broken app, and `STORE_REVIEW_NOTES.md` claimed the app said why. It did not.
 *
 *   2. THE QUEUE. Nine of these acts are queueable writes that go through `submit()` — a
 *      maintenance report, a security report, a household member, a vehicle, an emergency
 *      contact, a change of details, an RSVP, a notice read, a pass cancelled or shared. Those
 *      are captured on the phone FIRST and sent afterwards, so the screen said "saved" and then,
 *      seconds later, "not accepted" with the sentence above. A demonstration session was being
 *      told its act had been saved when nothing could ever come of it — which is the one thing
 *      this app is not allowed to do: never fake an offline success.
 *
 * WHAT IS DONE ABOUT IT, AND WHY IT IS THIS AND NOT HIDING THE BUTTONS.
 *
 * Every control stays visible and every form stays usable to the last tap. The demonstration
 * exists so a reviewer can SEE THE PRODUCT WORKING (Guideline 4.2), and a hidden button teaches
 * them nothing — they would not learn that the app issues visitor passes at all. A form they can
 * fill in, validate and submit teaches them the whole feature; only the act at the end is
 * refused, in ordinary words, in the place that screen already shows its problems.
 *
 * ONE SENTENCE, EVERYWHERE. A reviewer meets one idea rather than ten wordings of it, and this is
 * the only place it is written down.
 */

/** The one sentence. Never about tokens, abilities or endpoints — it is about a demonstration. */
export const DEMO_CANNOT_WRITE =
  'You are exploring a demonstration community, so nothing here can be changed. The screens and the figures are real; only the act itself is refused, so the demonstration stays as the next person will find it. Tap Leave at the top of the screen to sign in to your own estate.'

/**
 * The sentence for this session, or null when the act may go ahead.
 *
 * Pure, and the only thing that decides it is whether this is the read-only demonstration door.
 * The FULL demonstration account (the static `246810` sign-in a reviewer is also given) is NOT
 * refused here: it holds the app's ordinary write abilities on purpose, so that a reviewer can
 * issue a pass and cast the demonstration ballot and watch them actually happen.
 */
export function demoRefusal(demo: boolean): string | null {
  return demo ? DEMO_CANNOT_WRITE : null
}
