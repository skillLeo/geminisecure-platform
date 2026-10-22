/**
 * THE RESIDENT'S OWN PANIC, FROM THE SOCKET (M11 Part C, DESIGN §2.4 A, §8.4).
 *
 * The server tells the resident's own channel (`resident.{t}.{a}`) about every step of a panic they raised:
 * reach recorded, acknowledged by the control room, an officer dispatched, on the way, there, resolved,
 * closed as a false alarm. Each event is a REFERENCE (the alert id and the step), so the panic screen's own
 * read (`duress.show`) is asked again and the timeline moves while it is open, with no pull-to-refresh.
 *
 * Live never raises a banner and never says a text or a call went out: the timeline shows what the server
 * says, and nothing more.
 */

import { invalidateRecord, type LiveHandler } from '@gs/core/live'

const stepped: LiveHandler = (event) => {
  if (event.i) invalidateRecord('alerts', event.i)
}

export const alertHandlers: Record<string, LiveHandler[]> = {
  'alert.*': [stepped],
}
