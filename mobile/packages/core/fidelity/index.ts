/**
 * Fidelity mode — web only, development only.
 *
 * `npm run fidelity` opens each route as `<route>?fidelity=<screen-id>` with
 * EXPO_PUBLIC_FIDELITY=1. In that mode the app lays out with the BOARD's insets
 * (the boards draw a 54px status bar and an 86px tab bar = 60 + 26), serves the
 * board's own data from fixtures, freezes the clock at the board's moment, and
 * sets `html[data-fidelity-ready]` once the drawn state is fully rendered.
 *
 * None of this can run on a phone: every entry point checks Platform.OS === 'web'
 * and the env flag, and the flag is never set in eas.json.
 */

import { useEffect } from 'react'
import { Platform } from 'react-native'
import type { Metrics } from 'react-native-safe-area-context'
import { layout } from '../theme/spacing'

/**
 * Read ONCE, at load: the harness opens a fresh page per screen, and in-app
 * navigation drops the query string, so re-reading it later would switch the mode
 * off halfway through a render. Both keys are required — the env flag alone must
 * not freeze the app for someone simply using the web build.
 */
const webQuery: URLSearchParams | null =
  Platform.OS === 'web' && process.env.EXPO_PUBLIC_FIDELITY === '1' && typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : null

let requested: string | null = webQuery?.get('fidelity') ?? null
let requestedState: string | null = webQuery?.get('state') ?? null

export let isFidelity: boolean = requested !== null

/** The board screen the harness asked for, e.g. "guard-app-03", or null. */
export function fidelityScreenId(): string | null {
  return requested
}

/** The state the gallery asked that screen to draw (M43 §3: loading, empty, error, offline…), or null for the board's own. */
export function fidelityState(): string | null {
  return requested === null ? null : requestedState
}

/**
 * A GALLERY BUILD ONLY (EXPO_PUBLIC_GALLERY=1, which no eas.json profile sets): the dev-only gallery
 * (M45 §3) puts a phone into the same mode, so the emulator can photograph each screen with the
 * design's content and its real insets. The flag is inlined at build time; in any other build this
 * function is empty and fidelity mode cannot be entered on a phone at all.
 */
export function enterFidelity(screenId: string | null, state: string | null = null): void {
  if (process.env.EXPO_PUBLIC_GALLERY !== '1' || Platform.OS === 'web') return
  requested = screenId
  requestedState = screenId === null ? null : state
  isFidelity = screenId !== null
}

/** The boards' moment, as the server would hold it: a UTC instant that reads 9:41 AM on Friday 4 September 2026 in Kingston. */
export const BOARD_NOW_ISO = '2026-09-04T14:41:00Z'

/** `new Date()` everywhere in the app goes through this, so fidelity mode can freeze it. */
export function now(): Date {
  return isFidelity ? new Date(BOARD_NOW_ISO) : new Date()
}

/** Safe-area metrics that reproduce the board's status bar and home-indicator strip. */
export const fidelityMetrics: Metrics = {
  frame: { x: 0, y: 0, width: layout.canvasWidth, height: layout.canvasHeight },
  insets: { top: layout.boardStatusBar, bottom: layout.boardBottomInset, left: 0, right: 0 },
}

/**
 * Call from a screen once fonts, data and layout are all in their final state.
 * Two animation frames later the harness is told it may take its screenshot.
 */
export function useFidelityReady(ready: boolean): void {
  useEffect(() => {
    if (!isFidelity || !ready) return
    // On a phone (a gallery build) the capture script watches the log for this line instead.
    if (Platform.OS !== 'web') {
      if (process.env.EXPO_PUBLIC_GALLERY === '1') console.log(`[gallery] ready ${requested}${requestedState ? ` ${requestedState}` : ''}`)
      return
    }
    if (typeof document === 'undefined') return
    let raf2 = 0
    const raf1 = requestAnimationFrame(() => {
      raf2 = requestAnimationFrame(() => {
        document.documentElement.setAttribute('data-fidelity-ready', '1')
      })
    })
    return () => {
      cancelAnimationFrame(raf1)
      cancelAnimationFrame(raf2)
    }
  }, [ready])
}
