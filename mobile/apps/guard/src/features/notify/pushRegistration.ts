/**
 * This handset's push registration — the Guard App's wiring of packages/core/notify/registration.ts.
 *
 * Online only: `push.register` / `push.unregister` go straight to the API, never through the offline
 * outbox, and are never retried — a failure is tried again at the next start. Nothing here logs; the
 * key-value store holds nothing but "registered for <owner>".
 */

import { guardApi } from '@gs/core/api/guard'
import { getStorage, jsonStore } from '@gs/core/lib'
import { createPushRegistration, giveUpDeviceToken, mutableCategories, pushToken } from '@gs/core/notify'
import { notificationPrefs } from '@/features/more/prefs'
import { GuardRing } from '@/features/ring/native'
import { serverReport } from '@/features/ring/readiness'
import { store } from '@/store'

const marker = () => jsonStore<{ owner: string }>(getStorage(), 'push.registered', 'v1')

export const pushRegistration = createPushRegistration({
  pushToken,
  register: async (body) => {
    const answer = await store.dispatch(guardApi.endpoints.pushRegister.initiate(body, { track: false }))
    return !('error' in answer && answer.error)
  },
  unregister: async () => {
    const answer = await store.dispatch(guardApi.endpoints.pushUnregister.initiate(undefined, { track: false }))
    return !('error' in answer && answer.error)
  },
  giveUpDeviceToken,
  marker: { get: () => marker().get(), set: (value) => marker().set(value), remove: () => marker().remove() },
  // The call-style readiness report (features/ring): what this phone can do, read now. None without the module
  // (Expo Go, iOS, an older build) — and then the server sends this phone the ordinary visible alerts only.
  ringReport: async () => (GuardRing ? serverReport(await GuardRing.readiness()) : undefined),
})

/** The switchable categories this phone has OFF — what the server must not push here. Duress and alertness are not switchable. */
export const mutedCategories = (): string[] => {
  const prefs = notificationPrefs.get() as Record<string, boolean>
  return mutableCategories('guard').filter((category) => prefs[category] === false)
}
