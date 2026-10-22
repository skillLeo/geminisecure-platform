/**
 * The way out of a report and into an emergency — NEVER BLOCKED.
 *
 * A security report goes to the estate office's queue. Someone in danger must not be left filling a
 * form, so both incident screens carry this in every state — loading, offline, refused, not found:
 *   - the emergency alert (/panic), which reaches the people who can come;
 *   - a plain phone call to 119 (police), which needs no data connection and no session.
 * It reads nothing from the form, the session, the network or the outbox, so nothing can disable it.
 */

import { useCallback, useState } from 'react'
import { Linking } from 'react-native'
import { router } from 'expo-router'
import { EMERGENCY_NUMBER } from './incidents'

export type Emergency = {
  number: string
  openAlert: () => void
  call: () => void
  /** A device that cannot place a call (a tablet, a browser): said plainly, with the number to dial. */
  callTrouble: string | null
}

export function useEmergency(): Emergency {
  const [callTrouble, setCallTrouble] = useState<string | null>(null)

  const call = useCallback(() => {
    setCallTrouble(null)
    Linking.openURL(`tel:${EMERGENCY_NUMBER}`).catch(() => setCallTrouble(`This device can't place the call. Dial ${EMERGENCY_NUMBER} from any phone.`))
  }, [])

  const openAlert = useCallback(() => router.push('/panic' as never), [])

  return { number: EMERGENCY_NUMBER, openAlert, call, callTrouble }
}
