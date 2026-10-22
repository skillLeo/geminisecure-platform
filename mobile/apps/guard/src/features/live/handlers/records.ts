/**
 * EVERY OTHER RECORD ON THE GUARD'S PHONE, FROM THE SOCKET (M11 §2; the server's D-175).
 *
 * The server says `{entity}.changed` or `{entity}.removed` — which record, nothing about it — on the channels this
 * handset holds (its own guard channel, its estate's guards). The phone asks again for every read of that kind a
 * screen is showing, through the same endpoint and allowlist as ever:
 *
 *   incident   My reports (a report filed from the console, closed, a photo added)
 *   shift      the current shift and the roster, colleagues on duty, open shifts
 *   message    the threads with dispatch and their unread badges
 *   orders     standing orders and the dashboard's "new standing orders" banner
 *   request    leave and equipment requests, decided in the console
 *   payroll    the guard's own payslips, when a run is approved
 *   ticket     a resident's report of something happening now at the site — and when it closes
 *
 * Nothing here writes anything or touches the outbox: a queued report or message stays exactly as the outbox shows it.
 */

import type { TagType } from '@gs/core/api'
import { invalidateKind, type LiveHandler } from '@gs/core/live'

const refetch = (kind: TagType): LiveHandler => () => invalidateKind(kind)

/** entity → the reads that show it on this app. */
export const RECORD_KINDS: Record<string, TagType[]> = {
  incident: ['incidents'],
  shift: ['shifts', 'presence'],
  message: ['messages'],
  orders: ['orders'],
  request: ['requests'],
  payroll: ['payslips'],
  ticket: ['sync'],
}

export const recordHandlers: Record<string, LiveHandler[]> = Object.fromEntries(
  Object.entries(RECORD_KINDS).flatMap(([entity, kinds]) => [
    [`${entity}.changed`, kinds.map(refetch)],
    [`${entity}.removed`, kinds.map(refetch)],
  ]),
)
