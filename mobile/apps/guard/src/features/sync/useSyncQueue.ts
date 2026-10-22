/**
 * The sync queue — board guard-app-34. Everything the guard did that has not yet reached
 * the server, in the order it happened, each with its own honest state:
 *
 *   Queued        saved on this phone, waiting for signal (or for the act before it)
 *   Syncing       being sent now
 *   Not accepted  the server ANSWERED, and the answer was no — its own sentence is shown.
 *                 A refusal is never resent and never hidden.
 *   Confirmed     received; kept for a day as proof the post was covered
 *
 * When an upload has been TRIED and failed, the row says so and says why — a guard must
 * never be left guessing whether "queued" means "no signal" or "something is wrong".
 * Opening the screen, and pulling it down, both try the upload again; nothing is ever
 * sent twice (every act keeps the key it was captured with).
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { isFidelity, time12, type IconName } from '@gs/core'
import type { OutboxView } from '@gs/core/offline'
import { syncQueueFixture } from '@/fidelity/sync'
import { drain, drainNow, outbox } from '@/offline/runtime'
import { useAppSelector } from '@/store'

export type QueueRow = {
  id: string
  icon: IconName
  title: string
  detail: string
  state: OutboxView['state']
  /** Why an upload that was tried did not go through (a failure), or the server's refusal. */
  trouble: string | null
}

export type SyncQueueModel = {
  waiting: QueueRow[]
  refused: QueueRow[]
  confirmed: QueueRow[]
  online: boolean
  refreshing: boolean
  warning: string | null
  refresh: () => void
  dismiss: (id: string) => void
}

const ICONS: Record<string, IconName> = { clock: 'bio-btn', patrol: 'tab-patrol', incident: 'qa-tile-3', gate: 'privacy-note', orders: 'check', message: 'bubble-meta' }

function toRow(op: OutboxView): QueueRow {
  // Labels are written "What · where" by the screen that captured the act.
  const [title, ...rest] = op.label.split(' · ')
  const where = rest.join(' · ')
  const trouble =
    op.state === 'refused'
      ? (op.error?.message ?? 'The server did not accept this.')
      : op.state === 'pending' && op.attempts > 0 && op.error
        ? `Tried ${op.attempts} time${op.attempts === 1 ? '' : 's'}: ${op.error.message}${typeof op.error.status === 'number' ? ` (${op.error.status} ${op.error.code})` : ''}`
        : null
  return { id: op.id, icon: ICONS[op.kind] ?? 'bubble-meta', title: title ?? op.label, detail: `${where ? `${where} · ` : ''}${time12(op.capturedAt)}`, state: op.state, trouble }
}

export function useSyncQueue(): SyncQueueModel {
  const ops = useAppSelector((s) => s.outbox.ops)
  const online = useAppSelector((s) => s.network.online)
  const [refreshing, setRefreshing] = useState(false)

  const rows = useMemo(() => ops.map(toRow), [ops])

  const refresh = useCallback(() => {
    if (isFidelity) return
    setRefreshing(true)
    void drainNow().finally(() => setRefreshing(false))
  }, [])

  // Opening the queue is itself a reason to try.
  useEffect(() => {
    if (!isFidelity) void drain()
  }, [])

  const limits = isFidelity ? null : outbox.warnings()
  const warning = !limits
    ? null
    : limits.tooOld
      ? `Some items have been waiting ${Math.floor(limits.oldestHours)} hours. Move to where there is signal, or tell your supervisor: they are safe on this phone but the office cannot see them yet.`
      : limits.tooMany
        ? 'A lot is waiting to upload. Move to where there is signal when you can.'
        : null

  return {
    waiting: isFidelity ? syncQueueFixture.waiting : rows.filter((r) => r.state === 'pending' || r.state === 'syncing'),
    refused: isFidelity ? [] : rows.filter((r) => r.state === 'refused'),
    confirmed: isFidelity ? [] : rows.filter((r) => r.state === 'confirmed').reverse(),
    online,
    refreshing,
    warning,
    refresh,
    dismiss: (id) => void outbox.dismiss(id),
  }
}
