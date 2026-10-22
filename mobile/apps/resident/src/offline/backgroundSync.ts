/**
 * The Resident App's queue, sent when the OS wakes the app with no screen (M11 §1.3; core offline/backgroundSync.ts).
 *
 * Imported FIRST by index.ts, so the task exists whenever the bundle loads — including a start with no route. Woken
 * like that, nothing has booted: the session is read from the phone's secure store exactly as a launch reads it, and
 * then the same queue the screens use is sent the same way (`drainNow`). A demonstration, or nobody signed in, has
 * nothing of theirs to send.
 */

import { bootSession } from '@gs/core/auth'
import { defineOutboxTask } from '@gs/core/offline'
import { store } from '@/store'
import { draftOwner, sendableDrafts } from '@/features/visitors/draftStore'
import { drainNow, outbox, startOfflineRuntime } from './runtime'

defineOutboxTask(async () => {
  if (store.getState().session.status === 'booting') await bootSession('resident', store.dispatch).catch(() => undefined)
  const session = store.getState().session
  if (session.status !== 'active' || session.demo) return true
  // As a launch does it: the queue adopted for this account, a held panic offered first, then everything else —
  // the invitation drafts last (drainNow sends them after the queue).
  await startOfflineRuntime()
  await drainNow()
  return outbox.pendingCount() === 0 && sendableDrafts(draftOwner(session.principal?.id)).length === 0
})
