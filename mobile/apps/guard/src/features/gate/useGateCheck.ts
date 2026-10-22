/**
 * Checking a pass and acting on the verdict — shared by the scanner (guard-app-04), the
 * override sheet (-28) and manual code entry (-32).
 *
 *   CHECK     with signal: POST /gate/verify — signature, window, cancellation, use, restriction.
 *             without, or when the request fails: the handset verifies the signature itself
 *             (Ed25519, public keys only) — a QR token only; a typed short code needs the server.
 *   ADMIT     captured in the outbox FIRST (gate.entry, `verified_offline` when it was), then sent.
 *             The server reconciles an offline entry and says so in the sync queue.
 *   DENY      a denial is a record too (the legacy gate-events form is the only endpoint that takes one).
 *   OVERRIDE  admit against the advice with a written reason, 5–160 characters (gate.override).
 *
 * Nothing here blocks on the network: the gate keeps moving with no signal.
 * A household's standing arrives as one boolean and is shown as one fixed sentence (verdict.ts).
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import * as Haptics from 'expo-haptics'
import { now, useOfflineCache } from '@gs/core'
import { guardApi } from '@gs/core/api/guard'
import { verifyPassOffline } from '@gs/core/crypto'
import { onboarding } from '@/features/enrolment/onboardingState'
import { recordMoment } from '@/features/tracking/dutyTracker'
import { submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { gateKnowledge, useGateKnowledge } from './knowledge'
import { entryCategory, entrySubject, fromHandset, fromServer, needsSignal, type GateVerdict } from './verdict'

export const REASON_MIN = 5
export const REASON_MAX = 160

export type GateOutcome = { kind: 'admitted' | 'denied' | 'overridden'; subject: string; pending: boolean }

export type GateCheckModel = {
  checking: boolean
  verdict: GateVerdict | null
  outcome: GateOutcome | null
  busy: boolean
  online: boolean
  /** How old the handset's keys and cancelled list are — shown while offline. */
  knowledgeAge: string | null
  hasKeys: boolean
  check: (input: string) => void
  admit: () => void
  deny: () => void
  override: (reason: string) => void
  reset: () => void
}

const looksLikeToken = (input: string) => input.includes('.')

export function useGateCheck(fixture?: { verdict: GateVerdict | null } | null): GateCheckModel {
  const online = useAppSelector((s) => s.network.online)
  const knowledge = useGateKnowledge()
  const shift = useOfflineCache<{ shift: { site: { id: number | string } } | null }>('shift.current', undefined)
  const siteId = shift?.shift?.site.id ?? onboarding.get().site?.id ?? knowledge.siteId

  const [verify] = guardApi.useGateVerifyMutation()
  const [checking, setChecking] = useState(false)
  const [verdict, setVerdict] = useState<GateVerdict | null>(fixture?.verdict ?? null)
  const [outcome, setOutcome] = useState<GateOutcome | null>(null)
  const [busy, setBusy] = useState(false)
  const running = useRef(false)

  // Keys and the cancelled list are refreshed whenever a gate screen opens with signal.
  useEffect(() => {
    if (!fixture && siteId !== null && siteId !== undefined) void gateKnowledge.refresh(siteId)
  }, [fixture, siteId, online])

  const onHandset = useCallback(
    (token: string): GateVerdict => {
      if (!looksLikeToken(token)) return needsSignal()
      const k = gateKnowledge.get()
      return fromHandset(verifyPassOffline({ token, siteId: siteId ?? '', keys: k.keys, revoked: k.revoked, now: now() }))
    },
    [siteId],
  )

  const check = useCallback(
    async (raw: string) => {
      const input = raw.trim()
      if (fixture || running.current || input.length === 0) return
      running.current = true
      setChecking(true)
      setOutcome(null)
      try {
        let result: GateVerdict | null = null
        if (online) {
          const answer = await verify({ pass: input })
          if ('data' in answer && answer.data) result = fromServer(answer.data)
          // A refusal (4xx) is not a verdict on the pass, and a failure is no answer at all: the handset decides.
        }
        result ??= onHandset(input)
        setVerdict(result)
        void Haptics.notificationAsync(result.tone === 'green' ? Haptics.NotificationFeedbackType.Success : result.tone === 'amber' ? Haptics.NotificationFeedbackType.Warning : Haptics.NotificationFeedbackType.Error).catch(() => undefined)
      } finally {
        running.current = false
        setChecking(false)
      }
    },
    [fixture, online, verify, onHandset],
  )

  const record = useCallback(
    async (kind: GateOutcome['kind'], reason?: string) => {
      if (fixture || !verdict || busy) return
      setBusy(true)
      try {
        const subject = entrySubject(verdict)
        const category = entryCategory(verdict.pass)
        const passId = verdict.pass?.id
        if (kind === 'admitted') {
          await submit({ endpoint: 'gate.entry', body: { category, ...(passId ? { pass_id: passId } : { subject }), verified_offline: verdict.source === 'offline' }, label: `Entry · ${subject}`, kind: 'gate' })
          if (passId && verdict.pass?.singleUse) gateKnowledge.markUsed(passId)
        } else if (kind === 'overridden') {
          await submit({ endpoint: 'gate.override', body: { category, subject, reason: (reason ?? '').trim(), ...(passId ? { pass_id: passId } : null) }, label: `Override · ${subject}`, kind: 'gate' })
        } else {
          await submit({ endpoint: 'gate_events.store', body: { verdict: 'deny', category, subject, basis: 'QR pass' }, label: `Denied · ${subject}`, kind: 'gate' })
        }
        // A fix for the moment, taken by on-duty sharing when the officer shares — never part of the gate record.
        void recordMoment('gate')
        setOutcome({ kind, subject, pending: !online })
        setVerdict(null)
      } finally {
        setBusy(false)
      }
    },
    [fixture, verdict, busy, online],
  )

  const reset = useCallback(() => {
    setVerdict(null)
    setOutcome(null)
  }, [])

  return {
    checking,
    verdict,
    outcome,
    busy,
    online,
    knowledgeAge: knowledge.refreshedAt,
    hasKeys: knowledge.keys.length > 0,
    check: (input) => void check(input),
    admit: () => void (verdict?.admitAllowed ? record('admitted') : undefined),
    deny: () => void record('denied'),
    override: (reason) => {
      const text = reason.trim()
      if (!verdict?.overrideAllowed || text.length < REASON_MIN || text.length > REASON_MAX) return
      void record('overridden', text)
    },
    reset,
  }
}
