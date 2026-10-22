/**
 * A DRAFT SENDS ITSELF, AND ONLY EVER BECOMES ONE PASS (M11 §1.3, §1.5; draftStore.ts).
 *
 * The M11 §1.7 airplane test (26 Sep): an invitation written with no signal waited for "Send now" after the
 * connection came back. The runtime now sends the drafts after the queue. Proved here, with the real store:
 *   - every send of one draft carries the SAME Idempotency-Key (the draft's id) and the time it was written, so a
 *     send whose answer was lost and is sent again is answered with the first pass, never a second;
 *   - signed → the draft is gone; no signal or a busy server → kept, and sent again later by itself;
 *   - refused (the window has passed, the household is restricted) → kept, with the server's words on it, and
 *     not sent again by itself;
 *   - two sends of one draft at once make one request;
 *   - oldest first, and a lost connection stops the round.
 */

import { getStorage } from '@gs/core/lib'
import { adoptDrafts, draftOwner, draftsOf, readDrafts, sendDraft, sendDrafts, sendableDrafts, writeDrafts, STILL_NO_CONNECTION, type IssuePass } from '../draftStore'
import type { Draft } from '../passes'

const ME = draftOwner(1)!
const draft = (id: string, over: Partial<Draft> = {}): Draft => ({
  owner: ME,
  id,
  category: 'single',
  visitor_name: `Guest ${id}`,
  visitor_phone: '',
  purpose: 'Family visit',
  vehicle_plate: 'PC 1234',
  valid_from: '2026-09-26T14:00:00Z',
  valid_to: '2026-09-26T16:00:00Z',
  createdAt: '2026-09-26T13:00:00.000Z',
  deviceTime: '2026-09-26T08:00:00.000-05:00',
  ...over,
})

const apiError = (status: number | string, code: string, message: string) => ({ error: { status, code, message, fieldErrors: {}, retryAfter: null } })
const NO_SIGNAL = apiError('network', 'network_unreachable', 'offline')

beforeEach(async () => {
  await getStorage().clearAll()
})

it('sends the draft with its own id as the Idempotency-Key and the moment it was written: the same on every try', async () => {
  writeDrafts([draft('7f1c2d3e-0000-4000-8000-000000000001')])
  const bodies: Record<string, unknown>[] = []
  const issue: IssuePass = async (body) => {
    bodies.push(body)
    return bodies.length === 1 ? NO_SIGNAL : { data: { id: 55 } }
  }
  expect(await sendDraft('7f1c2d3e-0000-4000-8000-000000000001', issue)).toEqual({ kind: 'waiting', message: STILL_NO_CONNECTION })
  expect(await sendDraft('7f1c2d3e-0000-4000-8000-000000000001', issue)).toEqual({ kind: 'sent' })
  expect(bodies).toHaveLength(2)
  expect(bodies[0]).toEqual(bodies[1])
  expect(bodies[0]).toMatchObject({
    category: 'single',
    visitor_name: 'Guest 7f1c2d3e-0000-4000-8000-000000000001',
    purpose: 'Family visit',
    vehicle_plate: 'PC 1234',
    __stamp: { idempotencyKey: '7f1c2d3e-0000-4000-8000-000000000001', deviceTime: '2026-09-26T08:00:00.000-05:00' },
  })
  // An empty phone is not sent as an empty string.
  expect(bodies[0]).not.toHaveProperty('visitor_phone')
  expect(readDrafts()).toEqual([])
})

it('a draft written before drafts kept their device time is sent with the moment it was created', async () => {
  writeDrafts([draft('a', { deviceTime: undefined })])
  let stamp: unknown
  await sendDraft('a', async (body) => { stamp = body.__stamp; return { data: { id: 1 } } })
  expect(stamp).toEqual({ idempotencyKey: 'a', deviceTime: '2026-09-26T13:00:00.000Z' })
})

it.each([
  ['no signal', NO_SIGNAL],
  ['the first try still being handled', apiError(409, 'request_in_progress', 'Retry in a moment with the same key.')],
  ['the server failing', apiError(503, 'server_error', 'Down.')],
  ['too many tries', apiError(429, 'too_many_requests', 'Slow down.')],
])('%s: kept, with no refusal on it, and sent again by itself', async (_why, answer) => {
  writeDrafts([draft('a')])
  const outcome = await sendDraft('a', async () => answer)
  expect(outcome.kind).toBe('waiting')
  expect(readDrafts()[0]!.problem).toBeUndefined()
  expect(sendableDrafts(ME).map((d) => d.id)).toEqual(['a'])
})

it.each([
  ['the window has passed', apiError(422, 'validation_failed', 'The pass must end in the future.')],
  ['the household is restricted', apiError(409, 'access_restricted', 'Visitor passes for this household are on hold. Contact the estate office.')],
  ['the first try died unanswered', apiError(409, 'idempotent_answer_missing', 'This was already sent, but the server stopped before it could answer.')],
])('%s: kept, with the server’s words on it, and NOT sent again by itself', async (_why, answer) => {
  writeDrafts([draft('a'), draft('b')])
  const asked: string[] = []
  const issue: IssuePass = async (body) => {
    asked.push(String((body.__stamp as { idempotencyKey: string }).idempotencyKey))
    return body.visitor_name === 'Guest a' ? answer : { data: { id: 2 } }
  }
  expect(await sendDrafts(issue, ME)).toBe(1)
  expect(readDrafts()).toEqual([expect.objectContaining({ id: 'a', problem: answer.error.message })])
  expect(sendableDrafts(ME)).toEqual([])
  // The next round does not send it again: the resident reads why, then sends or discards it.
  await sendDrafts(issue, ME)
  expect(asked).toEqual(['a', 'b'])
  // "Send now" still sends it when the resident asks.
  expect(await sendDraft('a', async () => ({ data: { id: 3 } }))).toEqual({ kind: 'sent' })
  expect(readDrafts()).toEqual([])
})

it('the session ending while it was asked writes nothing back', async () => {
  writeDrafts([draft('a')])
  expect(await sendDraft('a', async () => apiError(401, 'unauthenticated', 'Sign in again.'))).toEqual({ kind: 'gone' })
  expect(readDrafts()[0]!.problem).toBeUndefined()
})

it('two sends of one draft at once make ONE request', async () => {
  writeDrafts([draft('a')])
  let calls = 0
  let answer: (v: { data: unknown }) => void = () => undefined
  const issue: IssuePass = () => { calls++; return new Promise((resolve) => { answer = resolve }) }
  const first = sendDraft('a', issue)
  const second = await sendDraft('a', issue)
  expect(second.kind).toBe('waiting')
  answer({ data: { id: 9 } })
  expect(await first).toEqual({ kind: 'sent' })
  expect(calls).toBe(1)
})

it('oldest first; a lost connection stops the round and keeps the rest', async () => {
  writeDrafts([draft('a'), draft('b'), draft('c')])
  const asked: string[] = []
  const issue: IssuePass = async (body) => {
    asked.push(String(body.visitor_name))
    return body.visitor_name === 'Guest b' ? NO_SIGNAL : { data: { id: 1 } }
  }
  expect(await sendDrafts(issue, ME)).toBe(1)
  expect(asked).toEqual(['Guest a', 'Guest b'])
  expect(readDrafts().map((d) => d.id)).toEqual(['b', 'c'])
})

describe('whose a draft is (kept through a sign-out nobody chose, like the queue)', () => {
  const THEM = draftOwner(2)!

  it('only this person’s drafts are drawn or sent: one kept for somebody else never is', async () => {
    writeDrafts([draft('mine'), draft('theirs', { owner: THEM })])
    expect(draftsOf(ME).map((d) => d.id)).toEqual(['mine'])
    expect(draftsOf(THEM).map((d) => d.id)).toEqual(['theirs'])
    expect(draftsOf(null)).toEqual([])
    const asked: string[] = []
    await sendDrafts(async (body) => { asked.push(String(body.visitor_name)); return { data: { id: 1 } } }, ME)
    expect(asked).toEqual(['Guest mine'])
    expect(readDrafts().map((d) => d.id)).toEqual(['theirs'])
  })

  it('a session starting adopts its own and deletes anyone else’s', () => {
    writeDrafts([draft('mine'), draft('theirs', { owner: THEM })])
    adoptDrafts(ME)
    expect(readDrafts().map((d) => d.id)).toEqual(['mine'])
  })

  it('a draft written before drafts recorded an owner becomes the starting session’s', () => {
    writeDrafts([draft('old', { owner: undefined })])
    adoptDrafts(ME)
    expect(readDrafts()).toEqual([expect.objectContaining({ id: 'old', owner: ME })])
  })

  it('nobody signed in adopts nothing, and deletes nothing', () => {
    writeDrafts([draft('mine')])
    adoptDrafts(null)
    expect(readDrafts().map((d) => d.id)).toEqual(['mine'])
  })
})

it('a draft discarded meanwhile is gone, and nothing is sent for it', async () => {
  const issue = jest.fn<ReturnType<IssuePass>, Parameters<IssuePass>>(async () => ({ data: { id: 1 } }))
  expect(await sendDraft('nope', issue)).toEqual({ kind: 'gone' })
  expect(issue).not.toHaveBeenCalled()
})
