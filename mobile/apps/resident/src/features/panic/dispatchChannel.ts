/**
 * "MESSAGE DISPATCH…" — THE LIVE CHANNEL WITH DISPATCH, AS THE PANIC SCREEN DRAWS IT (board resident-app-06; M50 §4.2 #2).
 *
 * Two sources, one thread, oldest first:
 *   the server's   the alert's own `messages` (duress.show): what the resident wrote, and the control room's replies;
 *   the phone's    what the resident wrote that the server has not answered for yet — the queue's own acts
 *                  (`duress.messages.store`), matched to the server's rows by their `client_key`.
 *
 * Under each of the resident's own, what is TRUE about it, and nothing more:
 *   Waiting for signal   queued with no connection: it goes by itself the moment there is one, and is never dropped
 *   Sending…             on its way
 *   Sent                 the server has it, and the control room has not been shown it yet
 *   Delivered            the control room's screen has shown it
 *   Not sent             the server refused it, in its own words — an alert closed meanwhile says what to do instead
 *
 * A message written while the alert itself is still held on the phone (no signal at the press) waits BEHIND the alert:
 * the queue sends it once the alert has an id (`{ $from: <the alert's act>, key: 'id' }`). Pure: tested with no phone.
 */

import type { OutboxOp } from '@gs/core/offline'
import type { AlertMessage } from '@gs/core/safety'

export const MESSAGE_ENDPOINT = 'duress.messages.store'

/** The longest message the server keeps (PanicMessages::MAX_BODY). */
export const MESSAGE_MAX = 500

export type ThreadState = 'waiting' | 'sending' | 'sent' | 'delivered' | 'failed'

export type ThreadItem = {
  key: string
  from: 'you' | 'dispatch'
  /** The dispatcher's first name on a reply, when the control room gave one. */
  name: string | null
  body: string
  /** Only on the resident's own. */
  state: ThreadState | null
  /** Why it was not sent, in the server's words. */
  note: string | null
  /** The queue's act, for "Try again" on a refusal that another try could fix (never on a closed alert). */
  opId: string | null
}

export const STATE_WORDS: Record<ThreadState, string> = {
  waiting: 'Waiting for signal: it sends by itself',
  sending: 'Sending…',
  sent: 'Sent',
  delivered: 'Delivered',
  failed: 'Not sent',
}

type Op = Pick<OutboxOp, 'id' | 'endpoint' | 'params' | 'body' | 'state' | 'error'>

/** Which alert this thread is: its id once the server has it, and the queue's act while it is held on the phone. */
export type ThreadOf = { alertId: string | null; alertOp: string | null }

const keyOf = (op: Op): string => (typeof op.body?.client_key === 'string' ? op.body.client_key : op.id)

/** Whether a queued message belongs to this alert — by its id, or, written while the alert was held, by the alert's act. */
export function belongsTo(op: Op, alert: ThreadOf): boolean {
  if (op.endpoint !== MESSAGE_ENDPOINT) return false
  const target = op.params?.alert
  if (target && typeof target === 'object') return alert.alertOp !== null && target.$from === alert.alertOp
  return alert.alertId !== null && target !== undefined && String(target) === alert.alertId
}

export function threadOf(server: readonly AlertMessage[] | null | undefined, ops: readonly Op[], alert: ThreadOf, online: boolean): ThreadItem[] {
  const rows = server ?? []
  const known = new Set(rows.map((m) => m.client_key).filter((key): key is string => typeof key === 'string' && key !== ''))

  const items: ThreadItem[] = rows.map((m) => ({
    key: `m${m.id}`,
    from: m.from,
    name: m.from === 'dispatch' ? m.name : null,
    body: m.body,
    state: m.from === 'you' ? (m.seen_at ? 'delivered' : 'sent') : null,
    note: null,
    opId: null,
  }))

  for (const op of ops) {
    if (!belongsTo(op, alert) || known.has(keyOf(op))) continue
    const refused = op.state === 'refused'
    items.push({
      key: `q${op.id}`,
      from: 'you',
      name: null,
      body: typeof op.body?.body === 'string' ? op.body.body : '',
      state: refused ? 'failed' : op.state === 'confirmed' ? 'sent' : online ? 'sending' : 'waiting',
      // A 404 (M57 §5.3): the server could not take a message on this alert, said plainly, with what to do instead.
      note: refused
        ? op.error?.code === 'not_found'
          ? 'This alert cannot take messages from this phone. If you still need help, press the panic button again or call 119.'
          : (op.error?.message ?? 'The server did not accept it.')
        : null,
      opId: refused && op.error?.code !== 'alert_closed' ? op.id : null,
    })
  }

  return items
}

/** "Dispatch:" — or "Kemar (dispatch):" when the control room gave a name — the board's own bubble, word for word. */
export const replyFrom = (name: string | null): string => (name ? `${name} (dispatch):` : 'Dispatch:') // framing-ok: the client's board copy — dispatch is the estate's own security control room
