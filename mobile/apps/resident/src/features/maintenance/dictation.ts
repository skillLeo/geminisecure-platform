/**
 * The words a maintenance request's microphone says (board resident-app-11's mic; M12 §2.9).
 *
 * The listening itself is Report incident's level-reading recogniser (features/incidents/dictation.ts), so the board's
 * waveform is drawn from what the microphone hears (M42 §5 #10); @gs/core/speech, which the Guard App shares, does
 * not ask for the level and is left as it is. Recognised ON THE PHONE (what a resident says about their home is never
 * sent to a speech service), so it works with no connection. This file is only the request's own words. Nothing here
 * is ever a reason a request cannot be sent.
 */

import type { Dictation, DictationWords } from '@gs/core/speech'

export type { Dictation }

export const DICTATION_UNAVAILABLE = "Speaking your request isn't available in this version of the app: type it below, or use the microphone on your keyboard."

/** The request's own words for the microphone's refusals — New maintenance request passes them to the level-reading dictation. */
export const REQUEST_WORDS: DictationWords = {
  unavailable: DICTATION_UNAVAILABLE,
  stopped: 'Listening stopped on this phone. Type the details instead: nothing you wrote is lost.',
  couldNotStart: 'Listening could not start on this phone. Type the details instead.',
  micOff: 'The microphone is switched off for this app. Type the details, or allow the microphone in your phone settings to speak them.',
}
