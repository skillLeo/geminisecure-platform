/**
 * The household's security reports — board resident-app-27 (one report's status), and the short
 * "Your reports" list under the report form.
 *
 *   READ     `tickets.index`, cached (`useOfflineCache`): the contract says "Serve the cache", so a
 *            report's status opens with no connection. The list carries
 *            every ticket the household has; ONLY one this feature filed — told by the marker its
 *            description ends with (incidents.ts, isIncidentReport), NOT by category — is a report here.
 *   REFUSED  a report the server said no to was never filed. Once read it may be cleared from the list
 *            (photos first); that removes no record.
 *   ONE      there is no `tickets.show`: a report is found BY ID in that list — or, between "sent" and
 *            "listed", in the server's own answer to the report this phone queued.
 *   APPEND-ONLY. The server gives a resident no way to edit, delete, comment on or close a report, and none is
 *            drawn. Something to add is a NEW report, or a word with the office. Once the office marks it done,
 *            the household says whether it is (26 Sep, R8: `fixedAnswer`, shared with maintenance).
 */

import { useCallback, useMemo, useState } from 'react'
import { fidelityScreenId, isFidelity, useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { mergeById, olderFor, type OlderPages } from '@gs/core/lib'
import { statusFixture } from '@/fidelity/incidents'
import { drain, outbox } from '@/offline/runtime'
import { fixedAnswer, type TicketModel } from '@/features/maintenance/useTickets'
import { useAppSelector } from '@/store'
import { INCIDENT_KIND, boardStamp, boardSteps, boardUpdates, findIncident, incidentHeading, incidentTitle, readDescription, recentRows, refusalToClear, reportsOnly, type BoardStep, type Incident, type QueuedOp, type RecentRow } from './incidents'

const DENIED = 'Your account cannot see reports sent to the estate office. Ask your estate office to check your access.'

/* ---- the list the phone holds ------------------------------------------------------------------- */

type ListState = {
  status: 'loading' | 'ready' | 'error' | 'denied'
  errorMessage: string | null
  items: readonly Incident[]
  ops: readonly QueuedOp[]
  online: boolean
  stale: boolean
  refreshing: boolean
  refresh: () => void
}

function useIncidentList(skip: boolean): ListState {
  const online = useAppSelector((s) => s.network.online)
  const view = useAppSelector((s) => s.outbox.ops)
  const query = residentApi.useTicketsIndexQuery(undefined, { skip })
  // The same answer the maintenance screens keep: one endpoint, one copy on the phone.
  const cached = useOfflineCache('tickets.all', query.data)
  const [refreshing, setRefreshing] = useState(false)

  // The store mirrors the queue without bodies or answers; the queue itself has both.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const ops = useMemo<readonly QueuedOp[]>(() => (skip ? [] : outbox.all().filter((op) => op.kind === INCIDENT_KIND)), [view, skip])
  // …and whatever "All requests" has fetched BEYOND that first page, so an old report opened from that list is
  // found here by id. The chain counts only while it belongs to today's first page (core: olderPages.ts).
  const savedOlder = useOfflineCache<OlderPages<Incident>>('tickets.older', undefined)
  // The WHOLE household list: which of these are reports is decided by the marker, in incidents.ts.
  const items = useMemo<readonly Incident[]>(() => {
    const first = cached?.items ?? []
    const older = olderFor(savedOlder, cached?.next_before ?? null)
    return older ? mergeById(first, older.items) : first
  }, [cached, savedOlder])

  const refetch = query.refetch
  const refresh = useCallback(() => {
    if (skip) return
    setRefreshing(true)
    void drain()
    void refetch().finally(() => setRefreshing(false))
  }, [refetch, skip])

  const denied = isApiError(query.error) && query.error.status === 403
  return {
    status: cached ? 'ready' : denied ? 'denied' : query.error ? 'error' : 'loading',
    errorMessage: denied ? DENIED : isApiError(query.error) ? query.error.message : null,
    items,
    ops,
    online,
    stale: !!cached && (!online || !!query.error),
    refreshing,
    refresh,
  }
}

/* ---- "Your reports", under the form --------------------------------------------------------------- */

/**
 * A refusal the resident has read leaves the queue — its photos first, then the report (incidents.ts says
 * why the order matters, and why nothing goes while a photo is still waiting). The server refused it, so
 * nothing was ever filed: this removes no record. Nothing else ever removes a refused report: left alone
 * it heads "Your reports" for good.
 */
export async function clearRefusal(opId: string): Promise<void> {
  const ids = refusalToClear(opId, outbox.all().filter((op) => op.kind === INCIDENT_KIND))
  if (!ids) return
  for (const id of ids) await outbox.dismiss(id)
}

export type RecentModel = {
  status: ListState['status']
  errorMessage: string | null
  rows: RecentRow[]
  more: number
  stale: boolean
  refreshing: boolean
  refresh: () => void
  /** True for a refused row that can leave the list NOW — the control is drawn only then, so it never does nothing. */
  canDismiss: (row: RecentRow) => boolean
  dismiss: (row: RecentRow) => void
}

export function useRecentReports(): RecentModel {
  const list = useIncidentList(isFidelity)
  const rows = useMemo(() => recentRows({ items: list.items, ops: list.ops }), [list.items, list.ops])
  // "N older reports": of the ones the LIST carries — a row drawn from the server's answer is not yet among them.
  const listed = useMemo(() => new Set(reportsOnly(list.items).map((item) => String(item.id))), [list.items])
  const shown = rows.filter((row) => row.incidentId !== null && listed.has(row.incidentId)).length
  const ops = list.ops
  const canDismiss = useCallback((row: RecentRow) => row.queue === 'refused' && row.opId !== null && refusalToClear(row.opId, ops) !== null, [ops])
  const dismiss = useCallback((row: RecentRow) => {
    if (row.opId) void clearRefusal(row.opId)
  }, [])
  return { status: list.status, errorMessage: list.errorMessage, rows, more: Math.max(0, listed.size - shown), stale: list.stale, refreshing: list.refreshing, refresh: list.refresh, canDismiss, dismiss }
}

/* ---- one report ------------------------------------------------------------------------------------ */

/** Board resident-app-27, as this report fills it. */
export type IncidentModel = {
  status: 'loading' | 'ready' | 'missing' | 'error' | 'denied'
  /** With `missing`: the id IS one of the household's tickets, but not a report sent from here — it is a maintenance request. */
  elsewhere: boolean
  errorMessage: string | null
  /** The app bar: "Incident #1042". */
  heading: string
  incident: Incident | null
  /** The board's tag: the type the resident chose, read back from the description. Null for a report that names none. */
  tag: string | null
  title: string
  /** "Reported Sep 4, 9:12 AM" — the server's clock, when it reached the office. */
  reportedLine: string
  /** "Status": the four stages, each line in the board's format. */
  steps: BoardStep[]
  /** "Updates": every entry that fills no stage, in the office's words, with its time. */
  updates: { key: string; text: string; when: string }[]
  /** Marked done by the office: "Is it fixed?" (M12 §2.15) — null until then, and on the board. */
  fixed: TicketModel['fixed']
  refreshing: boolean
  refresh: () => void
}

export function useIncident(id: string | undefined): IncidentModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-27' ? statusFixture : null
  const list = useIncidentList(isFidelity)

  const incident: Incident | null = fixture ? fixture.ticket : findIncident(id, list.items, list.ops.map((op) => op.result))

  const steps = useMemo(() => (incident ? boardSteps(incident) : []), [incident])
  const updates = useMemo(() => (incident ? boardUpdates(incident) : []), [incident])
  const tag = useMemo(() => readDescription(incident?.description).type, [incident])

  // The household HAS a ticket with this id, but this feature did not file it: it is followed under Maintenance.
  const elsewhere = !fixture && !incident && !!id && list.items.some((item) => String(item.id) === id)

  return {
    status: incident ? 'ready' : list.status === 'ready' ? 'missing' : list.status,
    elsewhere,
    errorMessage: list.errorMessage,
    heading: fixture ? fixture.board.heading : incident ? incidentHeading(incident) : 'Incident',
    incident,
    tag,
    // The chip is the category and the title is the incident (M47 §12.4) — never the type a second time.
    title: incident ? incidentTitle(incident) : '',
    reportedLine: incident ? `Reported ${boardStamp(incident.reported_at)}` : '',
    steps,
    updates,
    // Done by the office: the household says whether it is (R8, 26 Sep) — a report is a ticket like a request.
    fixed: fixedAnswer(incident, fixture !== null, list.ops, 'Security report', INCIDENT_KIND),
    refreshing: list.refreshing,
    refresh: list.refresh,
  }
}
