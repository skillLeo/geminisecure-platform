/**
 * One alert at this site, followed while it is open — where the `site_alert` push and banner lead (M10 §1.3).
 *
 *   READ     `alerts.show`, polled every few seconds while the alert is open and fetched fresh each time the
 *            screen opens. Every answer is kept on the phone with its time (siteAlert.ts), so with no signal
 *            the last one is shown WITH ITS AGE and a note that it may be out of date.
 *   WRITE    "En route" and "On scene" go through `submit` — captured on the phone first with the
 *            Idempotency-Key and X-Device-Time of the TAP, then sent, and sent again with the same key the
 *            moment signal returns (the catalogue allows both in the queue and in `sync/batch`). What
 *            happened to the tap is the outbox's state, said in a sentence under the buttons.
 *   REFUSED  a read the server refuses (own alert, not on shift, not at this site) is a sentence and nothing
 *            else — no stale answer, no buttons: the server has said this handset does not follow it.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { now } from '@gs/core/fidelity'
import { isApiError, isRetryable } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { time12s } from '@gs/core/lib'
import { isClosed } from '@gs/core/safety'
import { drainNow, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { actLine, alertId, isDrillAlert, keepAnswer, keptAlert, kindTitle, refusalSentence, responderLine, responseActions, responseSteps, staleLine, whereLine, type Act, type ActLine, type AlertView, type KeptAlert, type ResponseStep, type Step } from './siteAlert'

const POLL_MS = 5000

const ENDPOINT = { en_route: 'alerts.en_route', on_scene: 'alerts.on_scene' } as const

export type SiteAlertModel =
  | { status: 'bad_id' }
  | { status: 'looking' }
  /** Nothing about this alert on the phone, and no signal to ask. */
  | { status: 'no_signal' }
  /** The server said this handset does not follow this alert — in a sentence. `own` offers the Duress screen. */
  | { status: 'refused'; sentence: string; own: boolean }
  | {
      status: 'ready'
      /** A DRILL, from the answer's own `is_drill` (`isDrillAlert`): the first thing on the screen. */
      drill: boolean
      title: string
      where: string
      responder: string | null
      steps: Step[]
      closed: boolean
      enRoute: boolean
      onScene: boolean
      reason: string | null
      /** What happened to the last tap of each, in a sentence. */
      acts: Partial<Record<ResponseStep, ActLine>>
      /** The last answer is not fresh: its age, and that it may be out of date. */
      stale: string | null
      busy: ResponseStep | null
      problem: string | null
      record: (step: ResponseStep) => void
    }

export function useSiteAlert(rawId: unknown): SiteAlertModel {
  const id = alertId(rawId)
  const online = useAppSelector((s) => s.network.online)
  const mirrored = useAppSelector((s) => s.outbox.ops)

  const [kept, setKept] = useState<KeptAlert | null>(() => (id ? keptAlert(id, now()) : null))
  const open = !(kept?.answer && isClosed(kept.answer))
  const query = guardApi.useAlertsShowQuery({ alert: id ?? 0 }, { skip: id === null, pollingInterval: open ? POLL_MS : 0, refetchOnMountOrArgChange: true })

  // Every answer is kept with the time it came — the fulfilled time, so a poll that brought the same answer still renews its age.
  useEffect(() => {
    if (id && query.data) setKept(keepAnswer(id, query.data, now()))
  }, [id, query.data, query.fulfilledTimeStamp])

  /* ---- the taps, as the outbox holds them ---- */
  // `mirrored` is the signal that the outbox changed; the acts themselves (with their params and answers) are read from it.
  const acts = useMemo(() => {
    const mine = id ? outbox.all().filter((op) => (op.endpoint === ENDPOINT.en_route || op.endpoint === ENDPOINT.on_scene) && String(op.params.alert) === id) : []
    const last = (step: ResponseStep) => mine.filter((op) => op.endpoint === ENDPOINT[step]).pop() ?? null
    return { en_route: last('en_route'), on_scene: last('on_scene') }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id, mirrored])

  /*
   * A tap the server answers WHILE THIS SCREEN IS OPEN carries the alert as it now stands: shown at once,
   * before the refetch lands. One answered before the screen opened is older than the fresh read the
   * screen makes on opening, so it is never replayed over it.
   */
  const absorbed = useRef<Set<string> | null>(null)
  useEffect(() => {
    if (!id) return
    const answered = [acts.en_route, acts.on_scene].filter((op) => op !== null && op.state === 'confirmed')
    if (absorbed.current === null) {
      absorbed.current = new Set(answered.map((op) => op!.id))
      return
    }
    for (const op of answered) {
      if (!op || absorbed.current.has(op.id)) continue
      absorbed.current.add(op.id)
      const answer = op.result as AlertView | null
      if (answer && typeof answer === 'object' && 'can_on_scene' in answer) setKept(keepAnswer(id, answer, now()))
    }
  }, [id, acts])

  // The age of an old answer keeps counting while there is no signal to replace it.
  const fresh = online && !query.error && query.data !== undefined
  const [, setTick] = useState(0)
  useEffect(() => {
    if (fresh) return
    const timer = setInterval(() => setTick((n) => n + 1), 30_000)
    return () => clearInterval(timer)
  }, [fresh])

  const [busy, setBusy] = useState<ResponseStep | null>(null)
  const [problem, setProblem] = useState<string | null>(null)

  const record = useCallback(
    async (step: ResponseStep) => {
      if (!id || busy) return
      setBusy(step)
      setProblem(null)
      try {
        const place = kept?.where ?? kept?.answer?.where ?? null
        const op = await submit({
          endpoint: ENDPOINT[step],
          params: { alert: id },
          body: null,
          label: `${step === 'en_route' ? 'On my way' : 'At the scene'} · ${place ? `Alert at ${place}` : kindTitle(kept?.kind)}`,
          kind: 'alert',
        })
        await drainNow()
        // A drain already under way when the tap was saved may have finished without it: offer it once more.
        if (outbox.all().find((o) => o.id === op.id)?.state === 'pending' && online) await drainNow()
      } catch {
        setProblem('This could not be saved on the phone. Radio dispatch now, and check the phone has free storage.')
      } finally {
        setBusy(null)
      }
    },
    [id, busy, kept, online],
  )

  if (!id) return { status: 'bad_id' }

  const error = isApiError(query.error) ? query.error : null
  // A refusal is an answer about THIS handset: it replaces whatever the phone had.
  if (error && !isRetryable(error) && error.status !== 401) {
    return { status: 'refused', sentence: refusalSentence(error, 'show'), own: error.code === 'own_alert' }
  }

  const answer = kept?.answer ?? null
  if (!answer) {
    if (query.isLoading || query.isFetching || (online && !error)) return { status: 'looking' }
    return { status: 'no_signal' }
  }

  const waiting = { en_route: acts.en_route?.state === 'pending' || acts.en_route?.state === 'syncing', on_scene: acts.on_scene?.state === 'pending' || acts.on_scene?.state === 'syncing' }
  const actions = responseActions(answer, waiting)
  const lineFor = (step: ResponseStep): ActLine | null => {
    const op = acts[step]
    return op ? actLine({ state: op.state, attempts: op.attempts, error: op.error } as Act, step, online) : null
  }
  const enRouteLine = lineFor('en_route')
  const onSceneLine = lineFor('on_scene')

  return {
    status: 'ready',
    drill: isDrillAlert(kept),
    title: kindTitle(answer.kind),
    where: whereLine(answer.kind, answer.where),
    responder: responderLine(answer),
    steps: responseSteps(answer, (iso) => time12s(iso)),
    closed: isClosed(answer),
    enRoute: actions.enRoute,
    onScene: actions.onScene,
    reason: actions.reason,
    acts: { ...(enRouteLine ? { en_route: enRouteLine } : null), ...(onSceneLine ? { on_scene: onSceneLine } : null) },
    stale: staleLine(kept?.answeredAt ?? null, now(), fresh),
    busy,
    problem,
    record: (step) => void record(step),
  }
}
