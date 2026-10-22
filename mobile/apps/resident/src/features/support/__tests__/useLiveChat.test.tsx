/**
 * LIVE CHAT, THE SCREEN'S MODEL (M50 §4.3): never an error or an empty conversation while it loads; a message goes
 * through the queue with its own key, and is not lost when it cannot go; a demonstration session sends nothing and
 * says why; and Help & support's row reads the speed from the copy kept, never from a read of its own.
 */

import { act, renderHook } from '@testing-library/react-native'
import { useChatRowLine, useLiveChat } from '../useLiveChat'

jest.mock('expo-router', () => ({ useFocusEffect: jest.fn() }))
let mockKept: unknown = undefined
jest.mock('@gs/core', () => ({
  isFidelity: false,
  readOfflineCache: () => mockKept,
  useOfflineCache: (_key: string, data: unknown) => data,
}))
jest.mock('@gs/core/lib', () => ({ newUuid: () => 'uuid-1' }))

type Read = { data?: unknown; error?: unknown; refetch: jest.Mock }
let mockRead: Read = { refetch: jest.fn() }
jest.mock('@gs/core/api/resident', () => ({ residentApi: { useSupportChatShowQuery: () => mockRead } }))

const mockSubmit = jest.fn(async (_input: unknown) => ({ id: 'op-1' }))
const mockOffer = jest.fn(async (_id: string) => undefined)
jest.mock('@/offline/runtime', () => ({
  submit: (input: unknown) => mockSubmit(input),
  offer: (id: string) => mockOffer(id),
  outbox: { all: () => [], dismiss: jest.fn(async () => undefined) },
}))
const mockState = { network: { online: true }, outbox: { ops: [] } }
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))
let mockRefusal: string | null = null
jest.mock('@/features/auth/useDemo', () => ({ useDemoRefusal: () => mockRefusal }))

const answer = { id: 9, open: true, messages: [{ id: 1, from: 'you', name: '', body: 'Hello?', at: '2026-10-08T12:00:00Z', seen_at: null, client_key: 'k-1' }], typical_reply_minutes: 4, answered_by: 'Gemini Security' }

beforeEach(() => {
  mockRead = { refetch: jest.fn() }
  mockKept = undefined
  mockRefusal = null
  mockSubmit.mockClear()
  mockOffer.mockClear()
})

it('while the conversation is on its way: loading, never an error, never "nothing written yet"', async () => {
  const { result } = await renderHook(() => useLiveChat())
  expect(result.current.status).toBe('loading')
  expect(result.current.errorMessage).toBeNull()
})

it('read: the conversation, the measured speed in the line at the top, and Try again when it could not be read', async () => {
  mockRead = { data: answer, refetch: jest.fn() }
  const ready = (await renderHook(() => useLiveChat())).result
  expect(ready.current.status).toBe('ready')
  expect(ready.current.items.map((i) => [i.body, i.state])).toEqual([['Hello?', 'sent']])
  expect(ready.current.intro).toContain('typically reply in 4 min')

  mockRead = { error: { status: 500, message: 'The server could not answer.' }, refetch: jest.fn() }
  const failed = (await renderHook(() => useLiveChat())).result
  expect(failed.current.status).toBe('error')
  expect(failed.current.errorMessage).toBe('The server could not answer.')
  await act(async () => failed.current.refresh())
  expect(mockRead.refetch).toHaveBeenCalled()
})

it('a message goes through the queue with its own key, at once, and the box empties once it is kept', async () => {
  mockRead = { data: answer, refetch: jest.fn() }
  const { result } = await renderHook(() => useLiveChat())
  await act(async () => result.current.setDraft('  Is the pool open on Sunday?  '))
  expect(result.current.canSend).toBe(true)
  await act(async () => {
    result.current.send()
    await Promise.resolve()
  })
  expect(mockSubmit).toHaveBeenCalledWith({ endpoint: 'support.chat.messages.store', body: { body: 'Is the pool open on Sunday?', client_key: 'uuid-1' }, label: 'Message to Gemini Security', kind: 'message' })
  expect(mockOffer).toHaveBeenCalledWith('op-1')
  expect(result.current.draft).toBe('')
})

it('a demonstration session sends nothing and says why; an empty box sends nothing at all', async () => {
  mockRead = { data: answer, refetch: jest.fn() }
  mockRefusal = 'You are exploring a demonstration community, so nothing here can be changed.'
  const { result } = await renderHook(() => useLiveChat())
  await act(async () => result.current.setDraft('Hello'))
  await act(async () => {
    result.current.send()
    await Promise.resolve()
  })
  expect(mockSubmit).not.toHaveBeenCalled()
  expect(result.current.problem).toBe(mockRefusal)
  expect(result.current.draft).toBe('Hello')

  mockRefusal = null
  await act(async () => result.current.setDraft('   '))
  expect(result.current.canSend).toBe(false)
})

it("Help & support's row: the speed from the copy kept, or who answers: it never reads the conversation itself", async () => {
  expect((await renderHook(() => useChatRowLine())).result.current).toBe('Live chat · answered by Gemini Security')
  mockKept = answer
  expect((await renderHook(() => useChatRowLine())).result.current).toBe('Live chat · typically replies in 4 min')
})
