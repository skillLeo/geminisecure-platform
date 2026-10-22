/**
 * THE GATE ON THE GUARD'S PHONE, FROM THE SOCKET (M11 Part C, DESIGN §2.9 I).
 *
 *   pass.cancelled, pass.used   on `estate.{t}.passes`: the pass — named by its public pass id, which the
 *                               revoked list already carries, never its token — is refused on this handset
 *                               from now on, before any pull and with no signal after it. A pass cancelled
 *                               while the guard stands at the gate is refused on the next scan
 *   gate.*                      on `estate.{t}.guards`: another guard's admission, exit, override or refusal —
 *                               the gate activity list is read again
 *   approval.*                  on `approval.{t}.{id}`: the household answered the walk-up (or it expired) —
 *                               the waiting screen's read is asked again, at once rather than at its next poll
 *
 * Live never raises a banner and never records an admission: the outbox is the only path for the guard's own.
 */

import { invalidateRecord, type LiveHandler } from '@gs/core/live'
import { gateKnowledge } from '@/features/gate/knowledge'

const revoked: LiveHandler = (event) => {
  gateKnowledge.learnRevoked(event.i, event.t === 'pass.used' ? 'used' : 'cancelled')
}

const gateRow: LiveHandler = () => {
  invalidateRecord('gate', 'LIST')
}

const approval: LiveHandler = (event) => {
  if (event.i) invalidateRecord('gate', event.i)
}

export const gateHandlers: Record<string, LiveHandler[]> = {
  'pass.cancelled': [revoked],
  'pass.used': [revoked],
  'gate.*': [gateRow],
  'approval.*': [approval],
}
