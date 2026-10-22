/**
 * The outbox as the UI sees it: a mirror of what is on disk, refreshed on every
 * change, so every record can show its own state — Pending sync · Syncing ·
 * Confirmed · Refused — and the offline banner can show a live count.
 *
 * ONE KIND OF ACT NEVER REACHES THIS MIRROR: a SILENT duress alert. A guard raising one
 * is being watched; a "1 queued" badge, or a row reading "Duress alert" in the sync queue,
 * is a confirmation the person watching can read. It is held and sent exactly like any
 * other act — it is only never SHOWN. Its status lives on the Duress screen, which the
 * guard opens when it is safe to.
 */

import { createSlice, type PayloadAction } from '@reduxjs/toolkit'
import type { OutboxOp } from './outbox'

/** The `kind` of an act that must leave no visible trace while it is unsent. */
export const SILENT_KIND = 'silent'

export type OutboxView = Pick<OutboxOp, 'id' | 'endpoint' | 'label' | 'kind' | 'state' | 'attempts' | 'capturedAt' | 'lastTriedAt' | 'error'>

export type OutboxUiState = { ops: OutboxView[]; waiting: number; refused: number }

const initialState: OutboxUiState = { ops: [], waiting: 0, refused: 0 }

const slice = createSlice({
  name: 'outbox',
  initialState,
  reducers: {
    outboxChanged(state, action: PayloadAction<OutboxOp[]>) {
      state.ops = action.payload
        .filter((op) => op.kind !== SILENT_KIND)
        .map(({ id, endpoint, label, kind, state: s, attempts, capturedAt, lastTriedAt, error }) => ({ id, endpoint, label, kind, state: s, attempts, capturedAt, lastTriedAt, error }))
      state.waiting = state.ops.filter((o) => o.state === 'pending' || o.state === 'syncing').length
      state.refused = state.ops.filter((o) => o.state === 'refused').length
    },
  },
})

export const { outboxChanged } = slice.actions
export const outboxReducer = slice.reducer

/** The words the boards use, per record. */
export const OUTBOX_LABEL: Record<OutboxOp['state'], string> = {
  pending: 'Pending sync',
  syncing: 'Syncing',
  confirmed: 'Confirmed',
  refused: 'Not accepted',
}
