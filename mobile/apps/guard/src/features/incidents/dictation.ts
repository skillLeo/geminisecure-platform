/**
 * Speech-to-text for the incident report (board guard-app-08's mic; M12 §2.9).
 *
 * The one implementation is `@gs/core/speech`: tap, speak, the words shown as they are heard, then appended to
 * the report where they are edited like typing — recognised ON THE DEVICE (Build Spec GD-8: an incident account
 * is never sent to a speech service), so it works on a patrol with no signal. This file is only the report's own
 * words. Nothing here is ever a reason a report cannot be filed.
 */

import { useDictation as useSpeech, type Dictation, type DictationWords } from '@gs/core/speech'

export type { Dictation }

export const DICTATION_UNAVAILABLE = "Dictation needs the installed app: type your report, or use the keyboard's own microphone"

const WORDS: DictationWords = {
  unavailable: DICTATION_UNAVAILABLE,
  stopped: 'Dictation stopped on this phone. Type your report instead: nothing you wrote is lost.',
  couldNotStart: 'Dictation could not start on this phone. Type your report instead.',
  micOff: 'The microphone is switched off for this app. Type your report, or allow the microphone in the phone settings to dictate.',
}

export function useDictation(onHeard: (words: string) => void): Dictation {
  return useSpeech(onHeard, WORDS)
}
