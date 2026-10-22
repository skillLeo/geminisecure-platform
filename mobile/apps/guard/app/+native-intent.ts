/**
 * Every link the OS hands this app (gsguard://…, a universal link, Expo Go's exp://…/--/…) passes through
 * here BEFORE expo-router sees it: only a plain in-app path survives, anything else opens the start screen.
 * The SessionGate still guards every route — a link can never take a signed-out phone past enrolment.
 */

import { resolveIncoming } from '@gs/core/notify/links'

export function redirectSystemPath({ path }: { path: string; initial: boolean }): string {
  return resolveIncoming('guard', path)
}
