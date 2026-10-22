/**
 * Leave and equipment requests — boards guard-app-10 and guard-app-09.
 *
 *   THE SERVER'S LIST   requests.index (kept on the phone — "Serve the cache"), with the leave entitlement.
 *   ASKING              requests.store — "Queue": captured on the phone first with its Idempotency-Key and
 *                       device time, then sent. Until the server has it the row says "Pending sync", never
 *                       "Pending" (which is the server's word for "with your supervisor").
 */

import { useCallback, useMemo, useState } from 'react'
import { fidelityScreenId, isFidelity, now, useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { drain, drainNow, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { addDays, equipmentBody, siteDate, leaveBody, requestRows, validateEquipment, validateLeave, type EquipmentErrors, type EquipmentInput, type EquipmentItem, type LeaveErrors, type LeaveInput, type LeaveType, QTY_MAX, QTY_MIN } from './requests'

function useRequestList(kind: 'leave' | 'equipment', skip: boolean) {
  const online = useAppSelector((s) => s.network.online)
  const mirrored = useAppSelector((s) => s.outbox.ops)
  const query = guardApi.useRequestsIndexQuery(undefined, { skip })
  const cached = useOfflineCache('requests.index', query.data)

  // `mirrored` is the signal that the outbox changed; the bodies themselves are read from the outbox.
  const queued = useMemo(() => (skip ? [] : outbox.all().filter((op) => op.endpoint === 'requests.store')), [skip, mirrored])
  const rows = useMemo(() => requestRows(cached?.items ?? [], queued, kind), [cached, queued, kind])

  const [refreshing, setRefreshing] = useState(false)
  const refetch = query.refetch
  const refresh = useCallback(async () => {
    if (skip) return
    setRefreshing(true)
    try { await drainNow(); await refetch() } finally { setRefreshing(false) }
  }, [skip, refetch])

  const error = query.error
  const forbidden = isApiError(error) && error.status === 403
  const fresh = !!query.data && !query.isError
  const listStatus: 'loading' | 'error' | 'forbidden' | 'ready' = skip || cached || rows.length > 0 ? 'ready' : query.isLoading ? 'loading' : forbidden ? 'forbidden' : error ? 'error' : 'ready'
  const staleNote = skip || fresh || query.isLoading ? null
    : forbidden ? 'This handset is not allowed to read your requests. A request you make is still saved and sent.'
    : `${online ? 'Your requests could not be refreshed' : 'No signal'}: ${cached ? 'showing what this phone last received' : 'earlier requests are not on this phone yet'}. A request you make now is saved and sent when the connection is back.`

  return { online, leave: cached?.leave ?? null, rows, listStatus, listError: isApiError(error) ? error.message : null, staleNote, refreshing, refresh: () => void refresh() }
}

export function useLeave() {
  const fixture = isFidelity && ['guard-app-10', 'guard-app-21'].includes(fidelityScreenId() ?? '')
  const list = useRequestList('leave', fixture)
  const today = siteDate(now())
  const [input, setInput] = useState<LeaveInput>({ type: 'vacation', from: addDays(today, 7), to: addDays(today, 7), reason: '', certificate: false })
  const [errors, setErrors] = useState<LeaveErrors>({})
  const [sending, setSending] = useState(false)
  const [saved, setSaved] = useState<'sent' | 'queued' | null>(null)
  const [problem, setProblem] = useState<string | null>(null)

  const set = <K extends keyof LeaveInput>(key: K, value: LeaveInput[K]) => {
    setSaved(null)
    setInput((prev) => {
      const next = { ...prev, [key]: value }
      // Moving the first day past the last drags the last day with it — never an impossible range.
      if (key === 'from' && next.to < next.from) next.to = next.from
      return next
    })
    setErrors((prev) => ({ ...prev, [key]: undefined }))
  }

  const send = useCallback(async () => {
    if (fixture || sending) return
    const found = validateLeave(input, now())
    setErrors(found)
    setProblem(null)
    if (Object.values(found).some(Boolean)) return
    setSending(true)
    try {
      const type = input.type
      const op = await submit({ endpoint: 'requests.store', body: leaveBody(input), label: `Leave request: ${type}, ${input.from} to ${input.to}`, kind: 'request' })
      // submit() returns once the act is safely on the phone; what happened to it next is in the outbox.
      await drain()
      const after = outbox.all().find((o) => o.id === op.id)
      if (after?.state === 'refused') setProblem(after.error?.message ?? 'The server did not accept this request.')
      else { setSaved(after?.state === 'confirmed' ? 'sent' : 'queued'); setInput((prev) => ({ ...prev, reason: '', certificate: false })) }
    } catch {
      setProblem('This request could not be saved on the phone. Check the phone has free storage and try again.')
    } finally {
      setSending(false)
    }
  }, [fixture, sending, input])

  return { ...list, fixture, input, set, setType: (type: LeaveType) => set('type', type), errors, sending, saved, problem, send: () => void send() }
}

export function useEquipment() {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-09'
  const list = useRequestList('equipment', fixture)
  const [input, setInput] = useState<EquipmentInput>({ item: null, other: '', quantity: 1, reason: '' })
  const [errors, setErrors] = useState<EquipmentErrors>({})
  const [sending, setSending] = useState(false)
  const [saved, setSaved] = useState<'sent' | 'queued' | null>(null)
  const [problem, setProblem] = useState<string | null>(null)

  const patch = (next: Partial<EquipmentInput>) => { setSaved(null); setInput((prev) => ({ ...prev, ...next })); setErrors((prev) => ({ ...prev, ...Object.fromEntries(Object.keys(next).map((k) => [k, undefined])) })) }

  const send = useCallback(async () => {
    if (fixture || sending) return
    const found = validateEquipment(input)
    setErrors(found)
    setProblem(null)
    if (Object.values(found).some(Boolean)) return
    setSending(true)
    try {
      const body = equipmentBody(input)
      const op = await submit({ endpoint: 'requests.store', body, label: `Equipment request: ${body.subject} ×${body.quantity}`, kind: 'request' })
      await drain()
      const after = outbox.all().find((o) => o.id === op.id)
      if (after?.state === 'refused') setProblem(after.error?.message ?? 'The server did not accept this request.')
      else { setSaved(after?.state === 'confirmed' ? 'sent' : 'queued'); setInput({ item: null, other: '', quantity: 1, reason: '' }) }
    } catch {
      setProblem('This request could not be saved on the phone. Check the phone has free storage and try again.')
    } finally {
      setSending(false)
    }
  }, [fixture, sending, input])

  return {
    ...list, fixture, input, errors, sending, saved, problem,
    pick: (item: EquipmentItem) => patch({ item }),
    setOther: (other: string) => patch({ other }),
    setReason: (reason: string) => patch({ reason }),
    fewer: input.quantity > QTY_MIN ? () => patch({ quantity: input.quantity - 1 }) : null,
    more: input.quantity < QTY_MAX ? () => patch({ quantity: input.quantity + 1 }) : null,
    send: () => void send(),
  }
}
