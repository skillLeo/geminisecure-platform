/**
 * The Guard App's queue, sent when the OS wakes the app with no screen (M11 §1.3; core offline/backgroundSync.ts):
 * the scans, clock-outs, incident photos and gate entries saved at a post with no signal go when the signal is back,
 * even if the guard swiped the app away.
 *
 * Imported FIRST by index.ts, beside the on-duty position task, so it exists whenever the bundle loads. Woken like
 * that, nothing has booted: the session is read from the phone's secure store exactly as a launch reads it, and then
 * the same queue the screens use is sent the same way (`drainNow`). Nobody enrolled, nothing to send.
 */

import { bootSession } from '@gs/core/auth'
import { defineOutboxTask } from '@gs/core/offline'
import { remindIfHeadless } from '@/features/tracking/dutyTracker'
import { store } from '@/store'
import { drainNow, outbox, startOfflineRuntime } from './runtime'

defineOutboxTask(async () => {
  // Android runs this right after an update or a restart: on duty, the officer is told sharing waits to be opened.
  await remindIfHeadless()
  if (store.getState().session.status === 'booting') await bootSession('guard', store.dispatch).catch(() => undefined)
  if (store.getState().session.status !== 'active') return true
  // As a launch does it: the queue adopted for this guard, a held duress offered first, then everything else.
  await startOfflineRuntime()
  await drainNow()
  return outbox.pendingCount() === 0
})
