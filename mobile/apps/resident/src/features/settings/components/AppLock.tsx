/**
 * The app lock — what Settings → Security → "Unlock with Face ID / fingerprint" switches on.
 *
 * MOUNT ONCE, in the root layout, AFTER the navigator:   <AppLock />
 * Until it is mounted the Security screen draws the switch as unavailable (prefs.wired) — a lock that
 * is promised and not there is worse than no lock.
 *
 * WHAT IT IS. A cover over the app, lifted when the PHONE says its owner is holding it
 * (expo-local-authentication's OS prompt — fingerprint, face or the phone's passcode). The app receives a
 * yes or a no. Nothing biometric is read, kept or sent, and the session token is not involved: this is
 * privacy for a phone left on a table, not a credential.
 *
 * WHAT IT NEVER COVERS. The emergency alert (/panic) and the emergency numbers. Someone in danger is not
 * asked for a fingerprint, and someone else holding the phone can still call for help with it.
 *
 * WHAT IT HIDES. A ConfirmSheet is a Modal, drawn above this cover; while the cover is down it says so
 * (`setCovered('app-lock', …)`, @gs/core's overlayCover) and every sheet hides until the owner is confirmed.
 *
 * WHEN. On a cold start, and when the app comes back after a minute or more in the background.
 *   THE COLD START IS DECIDED WHEN THE SESSION LEAVES 'booting', NOT AT FIRST RENDER. The phone's storage
 *   is read into memory behind the splash (bootSession → storage.hydrate()); at first render it is still
 *   empty, the choice reads "off", and an app that decided then would never lock. The decision is made
 *   DURING the render in which the status changes (settingsLogic.lockOnStatus) — not in an effect — so the
 *   cover is already down in the very frame the navigator first shows a signed-in screen.
 *   Switching the lock ON in Settings does not lock the app there and then: the phone has just confirmed
 *   its owner. A sign-in during this launch does not either — a fresh one-time code is stronger proof.
 * IF THE PHONE CAN NO LONGER ASK (its screen lock was removed): the cover lifts and the choice is switched
 * off — a resident is never shut out of their own gate by a setting.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { AppState, BackHandler, Keyboard, Linking, Platform, StyleSheet, View } from 'react-native'
import { router, usePathname } from 'expo-router'
import * as LocalAuthentication from 'expo-local-authentication'
import { BrandMark, Pressable, ResidentButton, Text, c, isFidelity, now, setCovered } from '@gs/core'
import { useAppSelector } from '@/store'
import { appLockOn, settingsPrefs, useSettingsPrefs, wired } from '../prefs'
import { EMERGENCY, lockOnReturn, lockOnStatus, telLink } from '../settingsLogic'

/** A browser has no screen lock to ask, and the fidelity harness measures the boards, not a cover. */
const CAN_LOCK = !isFidelity && Platform.OS !== 'web'

export function AppLock() {
  const status = useAppSelector((s) => s.session.status)
  const { appLock } = useSettingsPrefs()
  const pathname = usePathname()
  // Mounted while the session is still booting (the usual case): nothing is decided yet — see WHEN, above.
  // Mounted later than that: the storage is already readable, so the choice can be trusted at once.
  const [locked, setLocked] = useState<boolean>(() => CAN_LOCK && status === 'active' && appLockOn())
  const [was, setWas] = useState(status)
  const [asking, setAsking] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const leftAt = useRef<number | null>(null)
  const prompted = useRef(false)
  const answering = useRef(false)

  // The session status changed: decided here, while rendering, so no signed-in screen is ever painted uncovered.
  if (was !== status) {
    setWas(status)
    const next = lockOnStatus(was, status, CAN_LOCK && appLockOn())
    if (next !== null && next !== locked) setLocked(next)
  }

  useEffect(() => {
    wired.appLock = true
    return () => { wired.appLock = false }
  }, [])

  // A session ended from somewhere else (revoked, signed out on another screen) wipes the stored choices:
  // whoever draws them — the type layer's text size above all — is told to look again.
  useEffect(() => {
    if (status === 'signedOut') settingsPrefs.reset()
  }, [status])

  useEffect(() => {
    if (!CAN_LOCK) return
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'background') leftAt.current ??= now().getTime()
      else if (state === 'active') {
        // The phone's own passcode screen also puts the app in the background: coming back from THAT is the
        // answer arriving, not the resident returning — the answer decides, not the clock.
        if (!answering.current && lockOnReturn(leftAt.current, now().getTime(), appLockOn())) { prompted.current = false; setLocked(true) }
        leftAt.current = null
      }
    })
    return () => sub.remove()
  }, [])

  const unlock = useCallback(async () => {
    if (answering.current) return
    answering.current = true
    setAsking(true)
    setProblem(null)
    try {
      const level = await LocalAuthentication.getEnrolledLevelAsync()
      if (level === LocalAuthentication.SecurityLevel.NONE) {
        // Nothing on this phone can confirm anyone any more. The choice is withdrawn, not the resident.
        settingsPrefs.set({ appLock: false })
        setLocked(false)
        return
      }
      /*
       * `disableDeviceFallback: false` IS CORRECT HERE. This is a resident unlocking their own screen
       * on their own phone, where the phone's own passcode is a legitimate answer — and the only one
       * left when a thumb is wet or a face is in the dark. It is deliberately NOT the guard app's rule
       * (`identityPrompt`), which closes the passcode on every guard identity check because
       * there the EMPLOYER is asking "is this the person rostered here" and a shared passcode defeats
       * it. Same API call, opposite question. See the longer note in `../useSettings.ts`.
       */
      const result = await LocalAuthentication.authenticateAsync({ promptMessage: 'Unlock GeminiSecure', cancelLabel: 'Cancel', disableDeviceFallback: false })
      if (result.success) {
        leftAt.current = null
        setLocked(false)
      } else if (result.error !== 'user_cancel' && result.error !== 'system_cancel' && result.error !== 'app_cancel') setProblem('The phone did not confirm it was you. Try again.')
    } catch {
      setProblem('The phone could not ask just now. Try again.')
    } finally {
      answering.current = false
      setAsking(false)
    }
  }, [])

  const covering = CAN_LOCK && status === 'active' && appLock && locked && !pathname.startsWith('/panic')

  // A confirmation sheet is a Modal — a window ABOVE this cover. While the cover is down every sheet hides, so a
  // question asked before the phone went into a pocket can never be answered by whoever picks it up (INV-12).
  useEffect(() => {
    setCovered('app-lock', covering)
    return () => setCovered('app-lock', false)
  }, [covering])

  // Ask once by itself when the cover comes down; after that the button asks.
  useEffect(() => {
    if (!covering || prompted.current) return
    prompted.current = true
    Keyboard.dismiss()
    void unlock()
  }, [covering, unlock])

  // Android's back button must not walk the screens underneath the cover: while it is down, back leaves the app.
  useEffect(() => {
    if (!covering) return
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      BackHandler.exitApp()
      return true
    })
    return () => sub.remove()
  }, [covering])

  if (!covering) return null

  return (
    <View style={s.cover} role="dialog" aria-modal aria-label="GeminiSecure is locked">
      <BrandMark variant="authBadge" size={56} />
      <Text face="Poppins-SemiBold" size={19} color="navy-900" align="center" role="heading" style={{ marginTop: 18 }}>{'GeminiSecure is locked'}</Text>
      <Text face="Inter-Regular" size={13} lh={1.5} color="slate-600" align="center" style={{ marginTop: 6 }}>{'Confirm it is you with your fingerprint, your face or the phone\'s passcode.'}</Text>
      {problem ? <Text face="Inter-SemiBold" size={12.5} lh={1.5} color="amber-700" align="center" role="alert" style={{ marginTop: 10 }}>{problem}</Text> : null}
      <ResidentButton label="Unlock" onPress={() => void unlock()} loading={asking} style={s.wide} />
      {/* Never behind the lock, never disabled: the emergency alert, and the two numbers as plain phone calls. */}
      <ResidentButton variant="danger" label="Emergency alert" onPress={() => router.push('/panic' as never)} style={s.wide} />
      <View style={s.calls}>
        {EMERGENCY.map((e) => (
          <Pressable key={e.key} onPress={() => void Linking.openURL(telLink(e.number)).catch(() => setProblem(`This device can't place the call. Dial ${e.number} from any phone.`))} role="link" aria-label={`Call ${e.label}, ${e.number.split('').join(' ')}`} hitSlop={10} style={s.call}>
            <Text face="Inter-Bold" size={13} color="red-700">{`${e.label} · ${e.number}`}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  )
}

const s = StyleSheet.create({
  cover: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, zIndex: 50, backgroundColor: c('white'), alignItems: 'center', justifyContent: 'center', paddingHorizontal: 28 },
  wide: { alignSelf: 'stretch', marginTop: 14 },
  calls: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'center', gap: 18, marginTop: 22 },
  call: { minHeight: 44, justifyContent: 'center' },
})
