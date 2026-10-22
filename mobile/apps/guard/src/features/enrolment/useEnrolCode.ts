/**
 * Enrolment by code — how a handset becomes a guard's handset (DOC_NOTES §3b).
 *
 *   1. The office issues a one-time code ("4F7K-9QXM", 24 hours, one use).
 *   2. The handset makes its own Ed25519 key pair; only the public half is sent.
 *   3. POST /devices/enrol → `enrolled` (a token, once) or `pending_approval` (this guard
 *      already has a handset: a supervisor must approve the move). While pending, the
 *      claim secret is held in the secure store and the request is polled; the token is
 *      handed over exactly once, on the first poll after approval.
 *
 * Enrolment is ONLINE ONLY by the server's contract — nothing here is queued or faked.
 * The token goes to the secure store and nowhere else; it is never in state, never logged.
 *
 * THE CODE BINDS THE PHONE, ONCE (M32 F3). After it, a guard who is signed out — the 30-minute rule, a sign-out they
 * chose — signs back in with a fingerprint or a supervisor's PIN on the unlock screen (features/unlock), never with a
 * code. A code is asked for again only when the phone genuinely needs one, and this screen says which case it is.
 *
 * AND THE SAME CODE TAKES THE SAME PHONE BACK (M46 §1). A phone whose data was wiped comes here as a stranger; its own
 * spent code, typed again on the same handset, is a recovery the server records as one (`recovered`). On any other
 * handset that code stays dead.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { Platform } from 'react-native'
import { router } from 'expo-router'
import * as Device from 'expo-device'
import type { ApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { devicePublicKey, deviceUid } from '@gs/core/crypto'
import { KEY_LOST, NOT_SET_UP, askServer } from '@/features/unlock/resume'
import { useAppDispatch, useAppSelector } from '@/store'
import { binding } from './binding'
import { handsetId } from './handset'
import { openSession } from './openSession'
import { pendingRebind, type PendingRebind } from './secure'

export const CODE_LENGTH = 8
const POLL_MS = 5000

/** "4f7k9qxm" → "4F7K-9QXM". The server hashes the code WITH its hyphen, upper-cased. */
export function formatCode(input: string): string {
  const clean = input.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, CODE_LENGTH)
  return clean.length > 4 ? `${clean.slice(0, 4)}-${clean.slice(4)}` : clean
}

export type EnrolPhase =
  | { state: 'entry' }
  | { state: 'sending' }
  | { state: 'waiting'; guardName: string; siteName: string }
  | { state: 'denied'; note: string | null }
  | { state: 'lost'; message: string }

export type EnrolCodeModel = {
  code: string
  setCode: (text: string) => void
  complete: boolean
  phase: EnrolPhase
  error: string | null
  online: boolean
  endedReason: 'revoked' | 'expired' | 'signed_out' | null
  /**
   * WHICH CASE THIS IS, in one line (M32 F3, §6.3): the server's own sentence when it has taken this phone out of
   * service ("Your supervisor removed this phone on 2 October. Ask the office for a new code."), otherwise that the
   * phone has not been set up yet — a new phone, or one whose data was cleared.
   */
  caseLine: string
  /** The line is a removal: the guard cannot get back in tonight, so the emergency numbers go under it. */
  removed: boolean
  submit: () => void
  startOver: () => void
}

const sentence = (e: ApiError): string =>
  e.status === 'network' || e.status === 'timeout' ? 'Enrolment needs a connection. Check the Wi-Fi or mobile data and try again.' : e.message

export function useEnrolCode(onEnrolled: () => void): EnrolCodeModel {
  const dispatch = useAppDispatch()
  const online = useAppSelector((s) => s.network.online)
  const endedReason = useAppSelector((s) => s.session.endedReason)

  const [code, setCodeRaw] = useState('')
  const [phase, setPhase] = useState<EnrolPhase>({ state: 'entry' })
  const [error, setError] = useState<string | null>(null)
  const [enrol] = guardApi.useDevicesEnrolMutation()
  const [collect] = guardApi.useDevicesEnrolStatusMutation()
  const pending = useRef<PendingRebind | null>(null)
  const alive = useRef(true)

  const open = useCallback(
    async (token: string, guard: PendingRebind['guard'], site: PendingRebind['site'], reviewer = false) => {
      await pendingRebind.clear()
      // The token, whose phone this is (the binding a sign-out keeps, M32 F3), the session and the queue: openSession.ts.
      await openSession(dispatch, token, { guard, site, reviewer })
      onEnrolled()
    },
    [dispatch, onEnrolled],
  )

  const poll = useCallback(async () => {
    const waiting = pending.current
    if (!waiting) return
    const answer = await collect({ rebind: waiting.rebindId, claim_secret: waiting.claimSecret, device_uid: await deviceUid() })
    if (!alive.current || pending.current !== waiting) return

    if ('error' in answer && answer.error) {
      const e = answer.error as ApiError
      // No signal is not an answer — keep waiting. A 404 is: the request is gone, or is not this handset's.
      if (e.status === 404) {
        pending.current = null
        await pendingRebind.clear()
        setPhase({ state: 'lost', message: 'This request can no longer be found. Ask the office for a new enrolment code.' })
      }
      return
    }

    const result = answer.data
    if (!result) return
    if (result.status === 'approved' && result.token) {
      pending.current = null
      await open(result.token, waiting.guard, waiting.site)
    } else if (result.status === 'denied') {
      pending.current = null
      await pendingRebind.clear()
      setPhase({ state: 'denied', note: result.decision_note || null })
    } else if (result.status === 'collected' || result.status === 'approved') {
      // Approved, but the one-time token has already been handed out and this install does not hold it.
      pending.current = null
      await pendingRebind.clear()
      setPhase({ state: 'lost', message: 'This approval has already been used. Ask the office for a new enrolment code.' })
    }
  }, [collect, open])

  // A guard who closed the app while waiting for a supervisor comes back to the same wait.
  useEffect(() => {
    alive.current = true
    void pendingRebind.get().then((saved) => {
      if (!saved || !alive.current) return
      pending.current = saved
      setPhase({ state: 'waiting', guardName: saved.guard.name, siteName: saved.site.name })
    })
    return () => {
      alive.current = false
    }
  }, [])

  /*
   * A PHONE THAT IS STILL SOMEBODY'S IS NOT ASKED FOR A CODE (M32 F3). A phone enrolled by an earlier version has no
   * binding record, and one whose session had already ended before this version was installed lands here. It still
   * holds its own key, so it asks the server — signed, with no fingerprint needed — whether it is still bound: if it
   * is, the guard is sent to sign back in with a fingerprint; if the server took it out of service, this screen says
   * so. Asked once per opening, with signal, and only when the phone holds a key and no binding — and never once
   * the phone has been told it was removed (by the server, or by the guard's own "Remove this app from this phone").
   */
  const [removal, setRemoval] = useState(binding.removal())
  const owner = binding.peek()
  useEffect(() => {
    if (!online || binding.peek() !== null || binding.removal() !== null) return
    let current = true
    void askServer(dispatch, 'check').then(async (answer) => {
      if (!current) return
      if (answer.kind === 'bound') {
        // With the server's reviewer flag: on that demonstration account alone, signing back in may use the passcode.
        await binding.set({ guardId: Number(answer.guard.id), name: answer.guard.name, reference: answer.guard.employee_number, siteName: answer.site.name, boundAt: new Date().toISOString(), reviewer: answer.reviewer })
        if (current) router.replace('/unlock' as never)
      } else if (answer.kind === 'removed') {
        await binding.markRemoved({ message: answer.message, at: answer.at })
        if (current) setRemoval(binding.removal())
      }
    })
    return () => {
      current = false
    }
  }, [online, dispatch])

  useEffect(() => {
    if (phase.state !== 'waiting') return
    void poll()
    const timer = setInterval(() => void poll(), POLL_MS)
    return () => clearInterval(timer)
  }, [phase.state, poll])

  const complete = code.replace('-', '').length === CODE_LENGTH

  const submit = useCallback(async () => {
    if (!complete || phase.state === 'sending') return
    // The connectivity flag is advisory (an office Wi-Fi with no internet still reaches the server),
    // so the request is always tried; a real failure comes back as the sentence below.
    setError(null)
    setPhase({ state: 'sending' })

    const model = Device.modelName ?? (Platform.OS === 'ios' ? 'iPhone' : 'Android phone')
    // The handset's own identity rides with the code: it is what lets the same phone take its own code again after a
    // reinstall, and nothing else can (M46 §1).
    const hardware = handsetId()
    const answer = await enrol({
      enrolment_code: code,
      device_uid: await deviceUid(),
      platform: Platform.OS === 'ios' ? 'ios' : 'android',
      public_key: await devicePublicKey(),
      label: model.slice(0, 60),
      ...(hardware ? { hardware_id: hardware } : null),
    })
    if (!alive.current) return

    if ('error' in answer && answer.error) {
      setPhase({ state: 'entry' })
      return setError(sentence(answer.error as ApiError))
    }

    const result = answer.data
    if (result?.status === 'enrolled' && result.token) return open(result.token, result.guard, result.site, result.reviewer === true)

    if (result?.status === 'pending_approval' && result.rebind_request_id !== undefined && result.claim_secret) {
      const saved: PendingRebind = { rebindId: result.rebind_request_id, claimSecret: result.claim_secret, guard: result.guard, site: result.site }
      await pendingRebind.set(saved)
      pending.current = saved
      return setPhase({ state: 'waiting', guardName: result.guard.name, siteName: result.site.name })
    }

    setPhase({ state: 'entry' })
    setError('The server gave an answer this version of the app does not understand. Ask the office for help.')
  }, [complete, phase.state, enrol, code, open])

  const startOver = useCallback(() => {
    pending.current = null
    void pendingRebind.clear()
    setCodeRaw('')
    setError(null)
    setPhase({ state: 'entry' })
  }, [])

  return {
    code,
    setCode: (text) => {
      setError(null)
      setCodeRaw(formatCode(text))
    },
    complete,
    phase,
    error,
    online,
    endedReason,
    // Reached from the unlock screen's "not my phone", the phone IS set up — for somebody else — and says whom for.
    // Reached because its own key is gone, it says the way back: the code it was set up with (M46 §1).
    caseLine: removal?.message ?? (binding.keyLost() ? KEY_LOST : owner ? `This phone is set up for ${owner.name}. A one-time code from the office sets it up for you instead.` : NOT_SET_UP),
    removed: removal !== null,
    submit: () => void submit(),
    startOver,
  }
}
