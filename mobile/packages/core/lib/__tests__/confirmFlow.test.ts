/**
 * J-CS-2 — ONE WAY TO ASK "ARE YOU SURE?", SO THE RULES ARE WRITTEN ONCE.
 *
 * The user's report (24 September): a Remove that drew its question below the fold, with buttons that named
 * half a person. Every destructive act in both apps now asks in a ConfirmSheet, and every one of them is driven
 * by this machine: it is asked about ONE thing, sends it ONCE, stays open with the server's words when the
 * answer is no, and closes only when the answer is yes.
 */

import { act, renderHook } from '@testing-library/react-native'
import { CONFIRM_FAILED, CONFIRM_IDLE, confirmStep, useConfirmFlow, type ConfirmOutcome, type ConfirmState } from '../confirmFlow'

type Who = { id: number; name: string }
const SOFT: Who = { id: 7, name: 'Soft Dev' }
const ANDREA: Who = { id: 8, name: 'Andrea Fletcher' }
const REFUSED: ConfirmOutcome = { ok: false, message: 'That member has already been removed.' }

describe('the machine', () => {
  const idle = CONFIRM_IDLE as ConfirmState<Who>

  it('idle → asking → working → closed, when the answer is yes', () => {
    const asking = confirmStep(idle, { type: 'ask', target: SOFT })
    expect(asking).toEqual({ phase: 'asking', target: SOFT, problem: null, hidden: false })
    const working = confirmStep(asking, { type: 'start' })
    expect(working).toEqual({ phase: 'working', target: SOFT, problem: null, hidden: false })
    expect(confirmStep(working, { type: 'settle', outcome: { ok: true } })).toEqual(CONFIRM_IDLE)
  })

  it('working → problem when the answer is no: the question stays, with who it is about and the words why', () => {
    const working = confirmStep(confirmStep(idle, { type: 'ask', target: SOFT }), { type: 'start' })
    const problem = confirmStep(working, { type: 'settle', outcome: REFUSED })
    expect(problem).toEqual({ phase: 'problem', target: SOFT, problem: 'That member has already been removed.', hidden: false })
    // Asked again from there, the old words go while the new attempt runs.
    expect(confirmStep(problem, { type: 'start' })).toEqual({ phase: 'working', target: SOFT, problem: null, hidden: false })
  })

  it('nothing redirects or restarts it while the answer is awaited: not another question, not a second start', () => {
    const working = confirmStep(confirmStep(idle, { type: 'ask', target: SOFT }), { type: 'start' })
    expect(confirmStep(working, { type: 'ask', target: ANDREA })).toBe(working)
    expect(confirmStep(working, { type: 'start' })).toBe(working)
  })

  it('Cancel while the answer is awaited HIDES it and never cancels it: the write carries on (INV-12)', () => {
    // The sheet stands over the whole app, the Home tab's panic button included: it must never trap anybody for
    // the seconds a slow write takes. Hidden, it is still that write, about that person, waiting for its answer.
    const working = confirmStep(confirmStep(idle, { type: 'ask', target: SOFT }), { type: 'start' })
    const hidden = confirmStep(working, { type: 'cancel' })
    expect(hidden).toEqual({ phase: 'working', target: SOFT, problem: null, hidden: true })
    expect(confirmStep(hidden, { type: 'cancel' })).toBe(hidden)
    expect(confirmStep(hidden, { type: 'start' })).toBe(hidden)
  })

  it('a hidden write comes back to be seen: asked again, or answered no; a yes simply closes it', () => {
    const hidden = confirmStep(confirmStep(confirmStep(idle, { type: 'ask', target: SOFT }), { type: 'start' }), { type: 'cancel' })
    // Tapping Remove again — on anyone — shows the question that is still being answered, never a second one.
    expect(confirmStep(hidden, { type: 'ask', target: ANDREA })).toEqual({ phase: 'working', target: SOFT, problem: null, hidden: false })
    // A refusal is never lost because the question was out of sight when it came.
    expect(confirmStep(hidden, { type: 'settle', outcome: REFUSED })).toEqual({ phase: 'problem', target: SOFT, problem: 'That member has already been removed.', hidden: false })
    expect(confirmStep(hidden, { type: 'settle', outcome: { ok: true } })).toEqual(CONFIRM_IDLE)
  })

  it('nothing is started that was never asked, and a stray answer changes nothing', () => {
    expect(confirmStep(idle, { type: 'start' })).toBe(idle)
    expect(confirmStep(idle, { type: 'settle', outcome: { ok: true } })).toBe(idle)
    const asking = confirmStep(idle, { type: 'ask', target: SOFT })
    expect(confirmStep(asking, { type: 'settle', outcome: REFUSED })).toBe(asking)
  })

  it('Cancel from a question or a refusal closes it; a new question replaces an unanswered one', () => {
    const asking = confirmStep(idle, { type: 'ask', target: SOFT })
    expect(confirmStep(asking, { type: 'cancel' })).toEqual(CONFIRM_IDLE)
    expect(confirmStep(asking, { type: 'ask', target: ANDREA }).target).toEqual(ANDREA)
    const problem = confirmStep(confirmStep(asking, { type: 'start' }), { type: 'settle', outcome: REFUSED })
    expect(confirmStep(problem, { type: 'cancel' })).toEqual(CONFIRM_IDLE)
  })
})

describe('useConfirmFlow', () => {
  /** A send whose answer the test gives when it chooses. */
  function deferred() {
    let answer: (outcome: ConfirmOutcome) => void = () => undefined
    const send = jest.fn((_who: Who) => new Promise<ConfirmOutcome>((resolve) => { answer = resolve }))
    return { send, answer: (outcome: ConfirmOutcome) => answer(outcome) }
  }
  const flush = async () => { await act(async () => { for (let i = 0; i < 5; i++) await Promise.resolve() }) }

  it('opens on ask, about the one thing asked; Cancel closes it and sends nothing', async () => {
    const send = jest.fn(async (_who: Who): Promise<ConfirmOutcome> => ({ ok: true }))
    const { result } = await renderHook(() => useConfirmFlow<Who>({ send }))
    expect(result.current.open).toBe(false)
    expect(result.current.target).toBeNull()

    await act(async () => result.current.ask(SOFT))
    expect(result.current.open).toBe(true)
    expect(result.current.target).toEqual(SOFT)
    expect(result.current.working).toBe(false)

    await act(async () => result.current.cancel())
    expect(result.current.open).toBe(false)
    expect(result.current.target).toBeNull()
    expect(send).not.toHaveBeenCalled()
  })

  it('confirm sends ONCE: a second press while the first is on its way is a no-op', async () => {
    const { send, answer } = deferred()
    const { result } = await renderHook(() => useConfirmFlow<Who>({ send }))
    await act(async () => result.current.ask(SOFT))

    await act(async () => {
      result.current.confirm()
      result.current.confirm() // the same tick: a double tap
    })
    expect(result.current.working).toBe(true)
    await act(async () => result.current.confirm()) // and again, once the sheet shows it is busy
    expect(send).toHaveBeenCalledTimes(1)
    expect(send).toHaveBeenCalledWith(SOFT)

    // While the answer is awaited, Cancel hides the question but never cancels the write: it is on its way.
    await act(async () => result.current.cancel())
    expect(result.current.open).toBe(false)
    expect(result.current.working).toBe(true)
    expect(result.current.target).toEqual(SOFT)
    await act(async () => result.current.confirm())
    expect(send).toHaveBeenCalledTimes(1)

    answer({ ok: true })
    await flush()
    expect(result.current.open).toBe(false)
    expect(result.current.working).toBe(false)
    expect(result.current.target).toBeNull()
  })

  it('a refusal that comes while the question is hidden brings it back, with the words', async () => {
    const { send, answer } = deferred()
    const { result } = await renderHook(() => useConfirmFlow<Who>({ send }))
    await act(async () => result.current.ask(SOFT))
    await act(async () => result.current.confirm())
    await act(async () => result.current.cancel())
    expect(result.current.open).toBe(false)
    answer(REFUSED)
    await flush()
    expect(result.current.open).toBe(true)
    expect(result.current.problem).toBe('That member has already been removed.')
    expect(result.current.target).toEqual(SOFT)
  })

  it('asking again while a hidden write runs shows that write: it never starts another', async () => {
    const { send, answer } = deferred()
    const { result } = await renderHook(() => useConfirmFlow<Who>({ send }))
    await act(async () => result.current.ask(SOFT))
    await act(async () => result.current.confirm())
    await act(async () => result.current.cancel())
    await act(async () => result.current.ask(ANDREA))
    expect(result.current.open).toBe(true)
    expect(result.current.working).toBe(true)
    expect(result.current.target).toEqual(SOFT)
    answer({ ok: true })
    await flush()
    expect(send).toHaveBeenCalledTimes(1)
    expect(send).toHaveBeenCalledWith(SOFT)
  })

  it('a refusal keeps it open with the message; the next press tries again and success closes it', async () => {
    const { send, answer } = deferred()
    const { result } = await renderHook(() => useConfirmFlow<Who>({ send }))
    await act(async () => result.current.ask(SOFT))
    await act(async () => result.current.confirm())
    answer(REFUSED)
    await flush()
    expect(result.current.open).toBe(true)
    expect(result.current.working).toBe(false)
    expect(result.current.problem).toBe('That member has already been removed.')
    expect(result.current.target).toEqual(SOFT)

    await act(async () => result.current.confirm())
    expect(result.current.problem).toBeNull()
    answer({ ok: true })
    await flush()
    expect(send).toHaveBeenCalledTimes(2)
    expect(result.current.open).toBe(false)
    expect(result.current.problem).toBeNull()
  })

  it('a send that throws is a problem in the sheet, never a silent close', async () => {
    const send = jest.fn(async (_who: Who): Promise<ConfirmOutcome> => { throw new Error('boom') })
    const { result } = await renderHook(() => useConfirmFlow<Who>({ send }))
    await act(async () => result.current.ask(SOFT))
    await act(async () => result.current.confirm())
    await flush()
    expect(result.current.open).toBe(true)
    expect(result.current.problem).toBe(CONFIRM_FAILED)
    expect(CONFIRM_FAILED).not.toMatch(/nothing (was|has been) (changed|done)/i) // it cannot know that
  })

  it('a caller may say what a throw means for its own act', async () => {
    const send = jest.fn(async (_who: Who): Promise<ConfirmOutcome> => { throw new Error('boom') })
    const { result } = await renderHook(() => useConfirmFlow<Who>({ send, failed: 'Signing out did not finish.' }))
    await act(async () => result.current.ask(SOFT))
    await act(async () => result.current.confirm())
    await flush()
    expect(result.current.problem).toBe('Signing out did not finish.')
  })

  it('a synchronous send works the same way', async () => {
    const send = jest.fn((_who: Who): ConfirmOutcome => ({ ok: true }))
    const { result } = await renderHook(() => useConfirmFlow<Who>({ send }))
    await act(async () => result.current.ask(ANDREA))
    await act(async () => result.current.confirm())
    await flush()
    expect(send).toHaveBeenCalledWith(ANDREA)
    expect(result.current.open).toBe(false)
  })

  it('confirm with nothing asked sends nothing', async () => {
    const send = jest.fn(async (_who: Who): Promise<ConfirmOutcome> => ({ ok: true }))
    const { result } = await renderHook(() => useConfirmFlow<Who>({ send }))
    await act(async () => result.current.confirm())
    await flush()
    expect(send).not.toHaveBeenCalled()
  })

  it('sends with the send function of the latest render (what it closes over is current)', async () => {
    const first = jest.fn(async (_who: Who): Promise<ConfirmOutcome> => ({ ok: true }))
    const second = jest.fn(async (_who: Who): Promise<ConfirmOutcome> => ({ ok: true }))
    const view = await renderHook(({ send }: { send: (who: Who) => Promise<ConfirmOutcome> }) => useConfirmFlow<Who>({ send }), { initialProps: { send: first } })
    await act(async () => view.result.current.ask(SOFT))
    await view.rerender({ send: second })
    await act(async () => view.result.current.confirm())
    await flush()
    expect(first).not.toHaveBeenCalled()
    expect(second).toHaveBeenCalledTimes(1)
  })
})
