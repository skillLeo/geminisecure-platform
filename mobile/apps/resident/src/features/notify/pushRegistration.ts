/**
 * This phone's push registration — the Resident App's wiring of packages/core/notify/registration.ts.
 *
 * Online only: `push.register` / `push.unregister` go straight to the API, never through the offline
 * outbox, and are never retried — a failure is tried again at the next start. Nothing here logs; the
 * key-value store holds nothing but "registered for <owner>".
 */

import { residentApi } from '@gs/core/api/resident'
import { getStorage, jsonStore } from '@gs/core/lib'
import { createPushRegistration, giveUpDeviceToken, mutableCategories, pushToken } from '@gs/core/notify'
import { store } from '@/store'
import { notificationPrefs } from './prefs'

export { quietReport } from './prefs'

const marker = () => jsonStore<{ owner: string }>(getStorage(), 'push.registered', 'v1')

export const pushRegistration = createPushRegistration({
  pushToken,
  register: async (body) => {
    const answer = await store.dispatch(residentApi.endpoints.pushRegister.initiate(body, { track: false }))
    return !('error' in answer && answer.error)
  },
  unregister: async () => {
    const answer = await store.dispatch(residentApi.endpoints.pushUnregister.initiate(undefined, { track: false }))
    return !('error' in answer && answer.error)
  },
  giveUpDeviceToken,
  marker: { get: () => marker().get(), set: (value) => marker().set(value), remove: () => marker().remove() },
})

/** The switchable categories this phone has OFF — what the server must not push here. The gate and panic are not switchable. */
export const mutedCategories = (): string[] => {
  const prefs = notificationPrefs.get() as Record<string, boolean>
  return mutableCategories('resident').filter((category) => prefs[category] === false)
}
