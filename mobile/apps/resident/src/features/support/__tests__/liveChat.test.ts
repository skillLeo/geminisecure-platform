/**
 * LIVE CHAT, THE THREAD (M50 §4.3) — pure: the server's conversation with what is still on its way from the phone, each of
 * the resident's own saying what is true about it; and the Help row's line, measured or plain, never promised.
 */

import { CHAT_ENDPOINT, STATE_WORDS, answerFrom, chatIntro, chatRowLine, chatThreadOf } from '../liveChat'

const server = [
  { id: 1, from: 'you' as const, name: '', body: 'How do I add my helper?', at: '2026-10-08T12:00:00Z', seen_at: '2026-10-08T12:01:00Z', client_key: 'k-1' },
  { id: 2, from: 'support' as const, name: 'Marlon', body: 'Profile → Household → Add household member.', at: '2026-10-08T12:03:00Z', seen_at: null, client_key: '' },
  { id: 3, from: 'you' as const, name: '', body: 'Thanks!', at: '2026-10-08T12:04:00Z', seen_at: null, client_key: 'k-2' },
]
const op = (id: string, over: Record<string, unknown> = {}) => ({ id, endpoint: CHAT_ENDPOINT, body: { body: `queued ${id}`, client_key: `c-${id}` }, state: 'pending', error: null, ...over }) as never

describe('the conversation', () => {
  it('draws the server\'s messages: Delivered once Gemini Security was shown one, Sent before; an answer with its sender', () => {
    const items = chatThreadOf(server, [], true)
    expect(items.map((i) => [i.from, i.state, i.name])).toEqual([['you', 'delivered', null], ['support', null, 'Marlon'], ['you', 'sent', null]])
    expect(answerFrom('Marlon')).toBe('Gemini Security · Marlon')
    expect(answerFrom(null)).toBe('Gemini Security')
  })

  it('adds what is still on the phone, waiting with no signal, sending with one, and never a queued copy the server already has', () => {
    const queued = [op('a'), op('b', { body: { body: 'Thanks!', client_key: 'k-2' } })]
    expect(chatThreadOf(server, queued, false).slice(3).map((i) => [i.body, i.state])).toEqual([['queued a', 'waiting']])
    expect(chatThreadOf(server, queued, true).slice(3).map((i) => i.state)).toEqual(['sending'])
    expect(STATE_WORDS.waiting).toBe('Waiting for a connection: it sends by itself')
  })

  it('a refusal says the server\'s words, with Try again only where another try could help', () => {
    const items = chatThreadOf([], [
      op('x', { state: 'refused', error: { code: 'server_error', message: 'The server could not answer.' } }),
      op('y', { state: 'refused', error: { code: 'demonstration_community', message: 'This is a demonstration community, so nobody reads its Live chat.' } }),
    ], true)
    expect(items.map((i) => [i.state, i.note, i.opId])).toEqual([
      ['failed', 'The server could not answer.', 'x'],
      ['failed', 'This is a demonstration community, so nobody reads its Live chat.', null],
    ])
  })

  it('leaves out what is not a Live chat message', () => {
    expect(chatThreadOf([], [op('z', { endpoint: 'tickets.store' })], true)).toEqual([])
  })
})

describe('the speed it states', () => {
  it('is the measured one, or who answers, never the board\'s "5 min" with nothing behind it', () => {
    expect(chatRowLine(4)).toBe('Live chat · typically replies in 4 min')
    for (const none of [null, undefined, 0]) expect(chatRowLine(none)).toBe('Live chat · answered by Gemini Security')
    expect(chatIntro(4)).toBe("Gemini Security's control room answers here. They typically reply in 4 min. Not for emergencies: press the panic button or call 119.")
    expect(chatIntro(null)).not.toMatch(/typically/)
  })
})
