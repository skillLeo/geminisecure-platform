/**
 * "Share live audio" on the WEB build (the design harness): there is no recorder here and nothing is sent. The switch
 * is drawn as the board draws it, and the phone builds use LiveAudioHost.native.tsx.
 */

export const AUDIO_ENDPOINT = 'duress.audio.store'

export async function microphone(_ask: boolean): Promise<'granted' | 'denied' | 'unavailable'> {
  return 'unavailable'
}

export function LiveAudioHost() {
  return null
}
