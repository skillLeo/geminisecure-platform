/**
 * "ARE YOU SURE?" — ONE MACHINE, SO THE RULES ARE WRITTEN ONCE (INV-12, DESIGN §12.1).
 *
 * Every destructive or irreversible act in both apps is asked in a ConfirmSheet (ui/ConfirmSheet.tsx), and every
 * one of them is driven by this:
 *
 *   idle ──ask(x)──▶ asking ──confirm──▶ working ──yes──▶ idle (closed)
 *                      ▲ │               │    ▲
 *                      │ │         cancel│    │ask(any)        ──no───▶ problem ──confirm──▶ working
 *                      │ │               ▼    │                            └──cancel──▶ idle
 *                      │ │          working, HIDDEN ──yes──▶ idle · ──no──▶ problem (shown again)
 *                      │ └──cancel──▶ idle
 *                      └──────────── ask(y) replaces an unanswered question
 *
 *   ONE THING     it is asked about one target, and that target is what is sent — never whatever row happens
 *                 to be selected by the time the press lands.
 *   ONCE          confirm while working is a no-op, decided synchronously (two taps in one tick send once).
 *   NEVER TRAPS   Cancel while the answer is awaited HIDES the question and cancels nothing: the write is on its
 *                 way and carries on (DESIGN INV-12, "may be dismissed while working"). The sheet stands over the
 *                 whole app — the Home tab's panic button with it — and a slow write can take most of a minute,
 *                 so nobody is ever held in front of it. Hidden, it is still that write: a refusal brings it back
 *                 with the words, a yes closes it, and asking again — about anyone — shows it, never a second one.
 *   HONEST        a refusal keeps it open with the words to show; it closes only when the answer is yes. A send
 *                 that throws is a problem too — never a silent close. What a throw means is the caller's to
 *                 say (`failed`); the default claims nothing it cannot know.
 */

import { useCallback, useEffect, useRef, useState } from 'react'

export type ConfirmOutcome = { ok: true } | { ok: false; message: string }

export type ConfirmPhase = 'idle' | 'asking' | 'working' | 'problem'

/** `hidden`: a write on its way whose question was put out of sight (only ever while `working`). */
export type ConfirmState<T> = { readonly phase: ConfirmPhase; readonly target: T | null; readonly problem: string | null; readonly hidden: boolean }

export type ConfirmEvent<T> = { type: 'ask'; target: T } | { type: 'cancel' } | { type: 'start' } | { type: 'settle'; outcome: ConfirmOutcome }

export const CONFIRM_IDLE: ConfirmState<never> = Object.freeze({ phase: 'idle', target: null, problem: null, hidden: false })

/** Said when the act threw instead of answering: the phone cannot know whether it happened, so it does not say. */
export const CONFIRM_FAILED = 'That did not finish, and this phone cannot tell whether it went through. Check, then try again.'

/** The whole machine, pure. An event that does not apply returns the SAME state. */
export function confirmStep<T>(state: ConfirmState<T>, event: ConfirmEvent<T>): ConfirmState<T> {
  switch (event.type) {
    case 'ask':
      // While an answer is awaited the question stays about what was sent; asking again only brings it into sight.
      if (state.phase === 'working') return state.hidden ? { ...state, hidden: false } : state
      return { phase: 'asking', target: event.target, problem: null, hidden: false }
    case 'cancel':
      if (state.phase === 'idle') return state
      // The write is on its way: out of sight, never called off.
      if (state.phase === 'working') return state.hidden ? state : { ...state, hidden: true }
      return CONFIRM_IDLE
    case 'start':
      return state.phase === 'asking' || state.phase === 'problem' ? { phase: 'working', target: state.target, problem: null, hidden: false } : state
    case 'settle':
      if (state.phase !== 'working') return state
      return event.outcome.ok ? CONFIRM_IDLE : { phase: 'problem', target: state.target, problem: event.outcome.message, hidden: false }
  }
}

export type ConfirmFlow<T> = {
  /** What the question is about — kept while the answer is awaited (hidden or not) and while a refusal is shown. */
  target: T | null
  /** asking, working in sight, or showing a refusal: the sheet is up. False while a write runs out of sight. */
  open: boolean
  /** A write is on its way — in sight or not. */
  working: boolean
  /** The refusal, in the words to show, or null. */
  problem: string | null
  ask: (target: T) => void
  /** Closes an unanswered question or a refusal; while a write runs, only puts it out of sight. */
  cancel: () => void
  confirm: () => void
}

type Options<T> = {
  /** Does the act. Resolves `{ ok: true }` only when it was done; `{ ok: false, message }` says why not. */
  send: (target: T) => ConfirmOutcome | Promise<ConfirmOutcome>
  /** What to say when `send` throws, for this act. */
  failed?: string
}

export function useConfirmFlow<T extends NonNullable<unknown>>({ send, failed = CONFIRM_FAILED }: Options<T>): ConfirmFlow<T> {
  const [state, setState] = useState<ConfirmState<T>>(CONFIRM_IDLE)
  // The truth, synchronously: a second press in the same tick must see "working" before React has re-rendered.
  const live = useRef<ConfirmState<T>>(state)
  const latest = useRef({ send, failed })
  useEffect(() => {
    latest.current = { send, failed }
  })

  const step = useCallback((event: ConfirmEvent<T>) => {
    const next = confirmStep(live.current, event)
    if (next === live.current) return
    live.current = next
    setState(next)
  }, [])

  const confirm = useCallback(() => {
    const before = live.current
    if (before.phase !== 'asking' && before.phase !== 'problem') return
    const target = before.target as T
    step({ type: 'start' })
    void (async () => {
      let outcome: ConfirmOutcome
      try {
        outcome = await latest.current.send(target)
      } catch {
        outcome = { ok: false, message: latest.current.failed }
      }
      step({ type: 'settle', outcome })
    })()
  }, [step])

  const ask = useCallback((target: T) => step({ type: 'ask', target }), [step])
  const cancel = useCallback(() => step({ type: 'cancel' }), [step])

  return {
    target: state.target,
    open: state.phase !== 'idle' && !state.hidden,
    working: state.phase === 'working',
    problem: state.problem,
    ask,
    cancel,
    confirm,
  }
}
