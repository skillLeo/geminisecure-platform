/**
 * Recording what the guard decided about a walk-up visitor — shared by the form (no signal:
 * the estate's policy) and the waiting screen (approved · declined · no answer).
 *
 *   ADMIT ON AN APPROVAL   gate.entry { approval_id }           the household said yes
 *   ADMIT, OWN DECISION    gate.entry { subject }               the server writes `guard decision`
 *   ADMIT ON AN OVERRIDE   gate.override { subject, reason }    against the gate's advice — the household's
 *                                                               visitors are held (M21 §1.7) — with a reason
 *   DENY                   gate_events.store { verdict: deny }  a denial is a record too
 *
 * Every one goes through submit(): captured on the phone FIRST with the time it happened,
 * sent now if there is signal and later if not. What happened to it afterwards — Pending sync ·
 * Syncing · Confirmed · Not accepted, with the server's sentence — is read from the outbox.
 * The gate log is append-only: nothing here edits or withdraws a record.
 */

import { useCallback, useRef, useState } from 'react'
import * as Haptics from 'expo-haptics'
import type { EndpointName } from '@gs/core/api'
import { entryCategory } from '@/features/gate/verdict'
import { recordMoment } from '@/features/tracking/dutyTracker'
import { submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'

export type Recorded = {
  kind: 'admitted' | 'denied'
  subject: string
  state: 'pending' | 'syncing' | 'confirmed' | 'refused'
  /** The server's sentence when it did not accept the record. */
  refusal: string | null
}

export type GateRecordModel = {
  busy: boolean
  recorded: Recorded | null
  /** The record could not even be saved on the phone (storage) — said plainly, nothing is pretended. */
  saveError: string | null
  admitOnApproval: (approvalId: number, visitor: string, subject: string) => void
  admitOnOwnDecision: (visitor: string, subject: string) => void
  /** The gate's own verdict against its advice, with the guard's reason (5–160 characters, the server's limits). */
  admitOnOverride: (visitor: string, subject: string, reason: string) => void
  /** `basis`: the guard's own decision unless the household declined (HOUSEHOLD_DECLINED). */
  deny: (visitor: string, subject: string, basis?: string) => void
}

/** A walk-up visitor has no pass, so no pass category: the gate log's default. */
const CATEGORY = entryCategory(null)

/** The gate log's basis for a denial: whose decision it was. */
export const GUARD_DECISION = 'Guard decision'
export const HOUSEHOLD_DECLINED = 'Household declined'

export function useGateRecord(): GateRecordModel {
  const ops = useAppSelector((s) => s.outbox.ops)
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState<{ kind: Recorded['kind']; subject: string; opId: string } | null>(null)
  const [saveError, setSaveError] = useState<string | null>(null)
  // State lands a render late; two fast taps must not write two records.
  const inFlight = useRef(false)

  const run = useCallback(async (kind: Recorded['kind'], subject: string, input: { endpoint: EndpointName; body: Record<string, unknown>; label: string }) => {
    if (inFlight.current) return
    inFlight.current = true
    setBusy(true)
    setSaveError(null)
    try {
      const op = await submit({ ...input, kind: 'gate' })
      // A fix for the moment, taken by on-duty sharing when the officer shares — never part of the gate record.
      void recordMoment('gate')
      setDone({ kind, subject, opId: op.id })
      void Haptics.notificationAsync(kind === 'admitted' ? Haptics.NotificationFeedbackType.Success : Haptics.NotificationFeedbackType.Warning).catch(() => undefined)
    } catch {
      setSaveError('That could not be saved on this phone, so nothing has been recorded. Try again.')
    } finally {
      inFlight.current = false
      setBusy(false)
    }
  }, [])

  const op = done ? ops.find((o) => o.id === done.opId) : undefined

  return {
    busy,
    // An act that has left the queue was confirmed (a pending one can never be dismissed).
    recorded: done ? { kind: done.kind, subject: done.subject, state: op?.state ?? 'confirmed', refusal: op?.state === 'refused' ? (op.error?.message ?? 'The server did not accept this.') : null } : null,
    saveError,
    admitOnApproval: (approvalId, visitor, subject) => void run('admitted', subject, { endpoint: 'gate.entry', body: { category: CATEGORY, approval_id: approvalId }, label: `Entry · ${visitor}` }),
    admitOnOwnDecision: (visitor, subject) => void run('admitted', subject, { endpoint: 'gate.entry', body: { category: CATEGORY, subject }, label: `Entry · ${visitor}` }),
    admitOnOverride: (visitor, subject, reason) => void run('admitted', subject, { endpoint: 'gate.override', body: { category: CATEGORY, subject, reason: reason.trim() }, label: `Override · ${visitor}` }),
    // Whose decision it was, on the gate log: the household's "no" is recorded as theirs (M22), the policy's as the guard's.
    deny: (visitor, subject, basis = GUARD_DECISION) => void run('denied', subject, { endpoint: 'gate_events.store', body: { verdict: 'deny', category: CATEGORY, subject, basis }, label: `Denied · ${visitor}` }),
  }
}
