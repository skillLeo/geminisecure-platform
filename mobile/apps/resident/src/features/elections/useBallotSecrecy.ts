/**
 * What every marking screen (page A, page B, review) does for the secrecy of the paper, from the
 * moment it is on the stack to the moment it leaves:
 *
 *   - counts itself in and out, so the marks are wiped when the LAST marking screen leaves;
 *   - wipes the marks when the app goes to the background (a phone left on a table, a handset
 *     passed to someone else, the app switcher);
 *   - wipes the marks when the gate shuts (the server says: not eligible, already voted, poll
 *     closed, no such election) — a connection that comes and goes does NOT shut it;
 *   - asks the OS to refuse screenshots and screen recording while it is up (on Android this also
 *     blanks the app-switcher preview). A screenshot of a marked paper is the vote, saved to a
 *     photo library that is usually synced to a cloud;
 *   - on iOS, asks the OS to blur the app-switcher snapshot as well. iOS writes that snapshot to
 *     DISK, and takes it before the background wipe can be drawn — without this, a picture of the
 *     marked paper would outlive the marks. The request is not keyed by the OS, so it is counted
 *     here: on with the first marking screen, off with the last.
 *
 * Nothing here reads the marks.
 */

import { useEffect } from 'react'
import { AppState, Platform } from 'react-native'
import * as ScreenCapture from 'expo-screen-capture'
import { isFidelity } from '@gs/core'
import { ballotBox } from './ballotBox'
import { gateShut, type GateModel } from './paper'

let shielded = 0

function shield(): void {
  shielded += 1
  if (shielded === 1 && Platform.OS === 'ios') void ScreenCapture.enableAppSwitcherProtectionAsync(1).catch(() => undefined)
}

function unshield(): void {
  shielded = Math.max(0, shielded - 1)
  if (shielded === 0 && Platform.OS === 'ios') void ScreenCapture.disableAppSwitcherProtectionAsync().catch(() => undefined)
}

export function useBallotSecrecy(screen: 'page-a' | 'page-b' | 'review', gate: GateModel['state']): void {
  // The server says this household has no paper to mark now (not eligible, already voted, poll closed, no
  // such election): the holding screen draws no choice, and none is kept in memory behind it either.
  const shut = gateShut(gate)
  useEffect(() => {
    if (!isFidelity && shut) ballotBox.wipe('gate-shut')
  }, [shut])

  useEffect(() => {
    if (isFidelity) return
    ballotBox.enter()
    const watching = AppState.addEventListener('change', (state) => {
      if (state === 'background') ballotBox.wipe('background')
    })
    return () => {
      watching.remove()
      ballotBox.leave()
    }
  }, [screen])

  // The shield is up while there is a PAPER to protect, and comes down when the gate shuts. After a vote is in,
  // the marking screens stay mounted underneath the receipt (the navigator keeps them) — but their gate now says
  // "voted", they draw no choice and hold no mark, so a household may screenshot "Your vote is in". Before this
  // the shield stayed on under the receipt and results until "Done".
  useEffect(() => {
    if (isFidelity || shut) return
    const key = `ballot-${screen}`
    if (Platform.OS !== 'web') void ScreenCapture.preventScreenCaptureAsync(key).catch(() => undefined)
    shield()
    return () => {
      if (Platform.OS !== 'web') void ScreenCapture.allowScreenCaptureAsync(key).catch(() => undefined)
      unshield()
    }
  }, [screen, shut])
}
