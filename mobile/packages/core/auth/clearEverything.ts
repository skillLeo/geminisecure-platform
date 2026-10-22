/**
 * Logout leaves nothing behind: the token, the key-value store, the image cache
 * and the RTK Query cache all go, so the next person on this phone never sees the
 * last person's data.
 *
 * ONE EXCEPTION, AND ONLY WHEN THE PERSON DID NOT CHOOSE TO LEAVE. A guard's offline
 * outbox may hold reports, scans, clock events — and a duress alert — that have not
 * reached the server. Two things end a session without anybody choosing to:
 *
 *   revoked   the server revoked the token: a rebind, a new sign-in on this install, the office
 *   expired   nothing was sent from this phone for thirty minutes and the idle window
 *             closed by itself (M6, Q-M42). Nobody did anything and nothing is wrong
 *
 * Wiping the outbox on either would break the promise the sync screen makes — "nothing
 * needs a manual retry, and nothing gets lost" — and in the expired case it would throw
 * away work purely because a phone sat in a pocket. So both keep every key under
 * `outbox:`; they are sent after the SAME guard enrols again and discarded if a different
 * guard does (the outbox records whose it is).
 *
 * A deliberate sign-out clears it too — the app warns first when it is not empty.
 * Signing out must leave nothing behind that a later sign-in could inherit.
 *
 * AND WHAT SAYS WHAT THE QUEUE HOLDS (`keep`, B118). A resident's panic held in the queue has a record beside it
 * — when it was pressed, whether it went, whose it is — and wiping that on an idle-window sign-out left the alert
 * in the queue with nothing on any screen to say it existed: it was sent after the next sign-in, unseen, and a
 * second press made a second alert. The app names the keys that go with its queue; they are kept exactly when
 * the queue is, and cleared exactly when it is.
 */

import { Image } from 'expo-image'
import type { ThunkDispatch, UnknownAction } from '@reduxjs/toolkit'
import { baseApi } from '../api/baseApi'
import { discardAllDownloads } from '../api/download'
import { dataLight } from '../lib/dataLight'
import { getStorage } from '../lib/storage'
import { forgetSiteZone } from '../lib/siteTime'
import { stopLive } from '../live/client'
import { savedCursors } from '../live/cursors'
import { OUTBOX_PREFIX } from '../offline/constants'
import { discardAllUploads } from '../offline/media'
import { secureCache } from './secureCache'
import { tokenStorage, type AppKind } from './tokenStorage'

export { OUTBOX_PREFIX }

export async function clearEverything(
  app: AppKind,
  dispatch: ThunkDispatch<unknown, unknown, UnknownAction>,
  reason: 'revoked' | 'expired' | 'signed_out',
  /** Full storage keys that go with the queue — kept on a sign-out nobody chose, cleared on a chosen one. */
  keep: readonly string[] = [],
): Promise<void> {
  // Live first (M11 Part C): a catch-up answer landing after the store is cleared would save the last person's
  // cursors again. Then their cursors themselves — named, although no `keep` list could ever hold them.
  stopLive()
  savedCursors.clear()
  await tokenStorage.clear(app)
  // What was kept in the secure store (a guard's own payslips) goes on EVERY kind of logout.
  await secureCache.clear().catch(() => undefined)

  const storage = getStorage()
  // Written as "anything but a chosen sign-out", so a reason added later keeps the queue by default
  // rather than silently destroying it — the safer way round for a queue that can hold a duress alert.
  if (reason !== 'signed_out') {
    for (const key of storage.keys()) if (!key.startsWith(OUTBOX_PREFIX) && !keep.includes(key)) storage.remove(key)
    await storage.flush()
  } else {
    await storage.clearAll()
    // The queue is gone, so the photos that were waiting in it go too. (On a revoked or expired session they stay with the queue.)
    await discardAllUploads()
  }
  // Files fetched with the last person's token (a notice's attachment) go on EITHER kind of logout. Never throws.
  await discardAllDownloads()
  // A preference held in memory must not outlive the storage it was read from.
  dataLight.reset()
  // The site's zone is the last principal's display fact: the next one's site decides it again.
  forgetSiteZone()

  // The image cache is best-effort: it does not exist on web, and a failure here must not block a logout.
  try {
    await Image.clearMemoryCache()
    await Image.clearDiskCache()
  } catch {
    /* nothing to clear */
  }

  dispatch(baseApi.util.resetApiState())
}
