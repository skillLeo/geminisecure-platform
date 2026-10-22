/**
 * THE HOUSEHOLD'S VISITORS, FROM THE SOCKET (M11 Part C, DESIGN §2.9 I).
 *
 *   pass.*       on `household.{t}.{h}`: a pass issued, changed, shared, cancelled — by any member of the
 *                household, on any phone — or its visitor admitted at the gate: the Visitors list is read
 *                again, so "Arrived 6:42 PM" shows while the screen is open
 *   approval.*   a guard asking at the gate for a walk-up, the answer another member gave, the visitor
 *                admitted: the household's own read, which carries the open requests, is asked again
 *
 * References only: which pass, which step. The list is read again through the resident's own scope.
 */

import { invalidateRecord, type LiveHandler } from '@gs/core/live'

const passes: LiveHandler = () => {
  invalidateRecord('passes', 'LIST')
}

const approvals: LiveHandler = () => {
  invalidateRecord('household', 'me.household')
}

export const gateHandlers: Record<string, LiveHandler[]> = {
  'pass.*': [passes],
  'approval.*': [approvals],
}
