/**
 * WHAT ON-DUTY SHARING KEEPS ON THE PHONE — plain storage, no React, so the background task can read it when
 * Android starts the app with no screen to hand it a position (dutyTracker.ts).
 *
 *   duty     the shift being shared right now, and whose it is. Present from clock-in to clock-out and nothing
 *            else: the background task stops itself the moment it finds none.
 *   status   the server's last answer to `tracking.status` (the flag, the policy, the officer's answer, the
 *            sampling), for the officer it belongs to.
 *   queue    fixes not yet kept by the server, each with its shift — bounded (trackingPolicy.bounded).
 *
 * Not a secret, so not SecureStore; nothing here is a token. Sign-out removes all of it (dutyTracker.stopDuty).
 */

import { getStorage, jsonStore } from '@gs/core/lib'
import { bounded, type Fix, type QueuedFix, type TrackingStatus } from './trackingPolicy'

export type Duty = { owner: string; shiftId: number | string; startedAt: string }

/**
 * Kept, like the offline queue, when a session ends without the officer choosing it (revoked, or the idle window):
 * fixes taken on the shift are theirs and go after they sign in again — the server still checks each against the
 * shift. The DUTY is not kept: with no session nothing may be sampled, and the background task, finding no duty,
 * stops the service by itself.
 */
export const KEEP_ON_SESSION_END = ['v1:tracking.queue'] as const

const duty = () => jsonStore<Duty>(getStorage(), 'tracking.duty', 'v1')
const status = () => jsonStore<{ owner: string; status: TrackingStatus; at: string }>(getStorage(), 'tracking.status', 'v1')
const queue = () => jsonStore<{ owner: string; items: QueuedFix[] }>(getStorage(), 'tracking.queue', 'v1')
const recent = () => jsonStore<Fix[]>(getStorage(), 'tracking.recent', 'v1')
/** When the server last answered a batch of this phone's positions (M22 §1.1) — what Location sharing says. */
const sent = () => jsonStore<{ at: string }>(getStorage(), 'tracking.sent', 'v1')

/** How many of the latest fixes are kept to tell moving from standing still. */
const RECENT_MAX = 8

export const trackingStore = {
  duty: (): Duty | null => duty().get(),
  setDuty: (next: Duty): Promise<void> => duty().setDurable(next),
  clearDuty: (): void => duty().remove(),

  status(owner: string | null | undefined): TrackingStatus | null {
    const saved = status().get()
    return saved && owner && saved.owner === owner ? saved.status : null
  },
  setStatus: (owner: string, next: TrackingStatus): void => status().set({ owner, status: next, at: new Date().toISOString() }),

  /** Whose fixes are waiting — after clock-out there is no duty to say so, and they are still to be sent. */
  queueOwner: (): string | null => queue().get()?.owner ?? null,

  /** This officer's waiting fixes. Anybody else's are not theirs to send, and are dropped on read. */
  queue(owner: string): QueuedFix[] {
    const saved = queue().get()
    return saved && saved.owner === owner ? saved.items : []
  },
  async setQueue(owner: string, items: QueuedFix[]): Promise<void> {
    await queue().setDurable({ owner, items: bounded(items) })
  },
  async push(owner: string, item: QueuedFix): Promise<void> {
    await trackingStore.setQueue(owner, [...trackingStore.queue(owner), item])
    recent().set([...(recent().get() ?? []), item.fix].slice(-RECENT_MAX))
  },

  recent: (): Fix[] => recent().get() ?? [],

  lastSentAt: (): string | null => sent().get()?.at ?? null,
  markSent: (at: Date = new Date()): void => sent().set({ at: at.toISOString() }),

  /** Sign-out: nothing of the officer's sharing stays on the phone. */
  clearAll(): void {
    duty().remove()
    status().remove()
    queue().remove()
    recent().remove()
    sent().remove()
  },
}
