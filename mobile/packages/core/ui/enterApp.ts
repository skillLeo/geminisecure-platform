/**
 * INTO THE APP, WITH NOTHING BEHIND IT (found on the emulator, 26 Sep, on both apps).
 *
 * Signing in, or finishing a guard's setup, went on with `router.replace` — which swaps only the screen on top.
 * The log-in screen (resident) or setup steps 1–3 (guard) stayed underneath, so Back from Home, or a guard's Home
 * button on Clock In, went back to "Welcome back · Log in" while signed in, or into setup again. Here the history
 * is cleared first, then the app's first screen replaces what is left, so Back from it leaves the app.
 *
 * AND OUT OF IT THE SAME WAY (found on the emulator, 28 Sep, M22). A sign-out landed on Log in (resident) or
 * enrolment (guard) with `router.replace` too, so every screen the session had open stayed mounted underneath:
 * Home went on asking the server for the household every ten seconds with no token, refused 401 each time, for as
 * long as the phone sat signed out (until the next sign-in cleared them on its way in). Both SessionGates now leave
 * through here.
 */

import { router, type Href } from 'expo-router'

export function enterApp(href: Href): void {
  try {
    if (router.canDismiss()) router.dismissAll()
  } catch {
    // No stack to clear (a first screen, or a test with no navigator): the replace below is all there is to do.
  }
  router.replace(href)
}
