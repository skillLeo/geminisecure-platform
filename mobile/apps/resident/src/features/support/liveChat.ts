/**
 * "LIVE CHAT" — THE RESIDENT AND GEMINI SECURITY, AS HELP & SUPPORT'S CHAT DRAWS IT (board resident-app-39; M50 §4.3).
 *
 * Two sources, one conversation, oldest first:
 *   the server's   `support.chat.show`: what the resident wrote, and Gemini Security's answers;
 *   the phone's    what the resident wrote that the server has not answered for yet — the queue's own acts
 *                  (`support.chat.messages.store`), matched to the server's rows by their `client_key`.
 *
 * Under each of the resident's own, what is TRUE about it, and nothing more:
 *   Waiting for a connection   queued with no signal: it sends by itself the moment there is one, and is never dropped
 *   Sending…                   on its way
 *   Sent                       the server has it; nobody at Gemini Security has been shown it yet
 *   Delivered                  the control room's screen has shown it
 *   Not sent                   the server refused it, in its own words
 *
 * "TYPICALLY REPLIES IN …" IS THE SERVER'S MEASUREMENT, NEVER A PROMISE: until there is one, the Help row says who
 * answers. Pure: tested with no phone.
 */

import type { OutboxOp } from '@gs/core/offline'
import type { Api } from '@gs/core/api'

export const CHAT_ENDPOINT = 'support.chat.messages.store'

/** The longest message the server keeps (LiveChat::MAX_BODY). */
export const CHAT_MAX = 1000

export type ChatState = 'waiting' | 'sending' | 'sent' | 'delivered' | 'failed'

export type ChatItem = {
  key: string
  from: 'you' | 'support'
  /** The staff member's first name on an answer, when Gemini Security gave one. */
  name: string | null
  body: string
  /** When it reached the server — null while it is still on the phone. */
  at: string | null
  /** Only on the resident's own. */
  state: ChatState | null
  /** Why it was not sent, in the server's words. */
  note: string | null
  /** The queue's act, for "Try again" on a refusal another try could fix. */
  opId: string | null
}

export const STATE_WORDS: Record<ChatState, string> = {
  waiting: 'Waiting for a connection: it sends by itself',
  sending: 'Sending…',
  sent: 'Sent',
  delivered: 'Delivered',
  failed: 'Not sent',
}

type Op = Pick<OutboxOp, 'id' | 'endpoint' | 'body' | 'state' | 'error'>
type ServerMessage = Api.SupportChatShowResponse['messages'][number]

const keyOf = (op: Op): string => (typeof op.body?.client_key === 'string' ? op.body.client_key : op.id)

/** Refusals another try cannot fix: the demonstration community, and nothing to send. */
const FINAL = new Set(['demonstration_community', 'message_empty'])

export function chatThreadOf(server: readonly ServerMessage[] | null | undefined, ops: readonly Op[], online: boolean): ChatItem[] {
  const rows = server ?? []
  const known = new Set(rows.map((m) => m.client_key).filter((key): key is string => typeof key === 'string' && key !== ''))

  const items: ChatItem[] = rows.map((m) => ({
    key: `m${m.id}`,
    from: m.from,
    name: m.from === 'support' && typeof m.name === 'string' && m.name !== '' ? m.name : null,
    body: m.body,
    at: m.at,
    state: m.from === 'you' ? (m.seen_at ? 'delivered' : 'sent') : null,
    note: null,
    opId: null,
  }))

  for (const op of ops) {
    if (op.endpoint !== CHAT_ENDPOINT || known.has(keyOf(op))) continue
    const refused = op.state === 'refused'
    items.push({
      key: `q${op.id}`,
      from: 'you',
      name: null,
      body: typeof op.body?.body === 'string' ? op.body.body : '',
      at: null,
      state: refused ? 'failed' : op.state === 'confirmed' ? 'sent' : online ? 'sending' : 'waiting',
      note: refused ? (op.error?.message ?? 'The server did not accept it.') : null,
      opId: refused && !FINAL.has(op.error?.code ?? '') ? op.id : null,
    })
  }

  return items
}

/** Who an answer is from: "Gemini Security · Marlon", or the company alone when no name was given. */
export const answerFrom = (name: string | null): string => (name ? `Gemini Security · ${name}` : 'Gemini Security')

/**
 * Board 39's row under "Contact us". The board says "Live chat · typically replies in 5 min"; the app says the speed the
 * server MEASURED, and until it has measured one, who answers instead — never a promise nobody has kept yet.
 */
export function chatRowLine(typicalMinutes: number | null | undefined): string {
  return typeof typicalMinutes === 'number' && typicalMinutes > 0 ? `Live chat · typically replies in ${typicalMinutes} min` : 'Live chat · answered by Gemini Security'
}

/** The line at the top of the chat: who reads it, how fast when that is known, and what it is not for. */
export function chatIntro(typicalMinutes: number | null | undefined): string {
  const speed = typeof typicalMinutes === 'number' && typicalMinutes > 0 ? ` They typically reply in ${typicalMinutes} min.` : ''
  return `Gemini Security's control room answers here.${speed} Not for emergencies: press the panic button or call 119.`
}
