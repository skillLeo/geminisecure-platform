/**
 * Every link the OS hands this app (gs://pass/41, a universal link, Expo Go's exp://…/--/…) passes through
 * here BEFORE expo-router sees it: only a plain in-app path survives, the short links the app publishes
 * (gs://pass/{id} · gs://ticket/{id} · gs://election/{id}) are turned into their screens, and anything
 * else opens the start screen. The SessionGate still guards every route — a link can never take a
 * signed-out phone past sign-in, and the elections screens still ask the server about eligibility first.
 */

import { resolveIncoming } from '@gs/core/notify/links'

export function redirectSystemPath({ path }: { path: string; initial: boolean }): string {
  return resolveIncoming('resident', path)
}
