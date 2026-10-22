/**
 * The one report a notification named — read from THIS PHONE first (residentReports.ts).
 *
 * `sync.pull` normally brought it already: the same pull that raised the banner. But the point of the
 * push is that the app was CLOSED, and then this phone has never seen the report the guard
 * has just tapped. So: look on the phone; if it is not there, pull once — the pull is how this handset
 * learns anything — and look again.
 *
 * WITH NO SIGNAL IT SAYS SO and shows nothing else. It never invents a report, never retries in a loop,
 * and never writes: the report belongs to the estate office.
 */

import { useCallback, useRef, useState } from 'react'
import { useFocusEffect } from 'expo-router'
import { pullDeltas } from '@/features/notify/poller'
import { useAppSelector } from '@/store'
import { reportById, type ResidentReport } from './residentReports'

export type ReportState =
  | { status: 'looking' }
  /** Pulled, and it is not there: older than the twelve hours the gatehouse is told about, or off duty when it came in. */
  | { status: 'gone' }
  /** Not on this phone, and no signal to go and look. */
  | { status: 'no_signal' }
  | { status: 'ready'; report: ResidentReport }

export function useResidentReport(id: string | undefined): ReportState {
  const online = useAppSelector((s) => s.network.online)
  const [state, setState] = useState<ReportState>({ status: 'looking' })
  const pulled = useRef(false)

  useFocusEffect(
    useCallback(() => {
      let watching = true

      const settle = (next: ReportState): void => {
        if (watching) setState(next)
      }

      const look = (): ResidentReport | null => (id ? reportById(id) : null)

      const here = look()
      if (here !== null) {
        settle({ status: 'ready', report: here })
        return () => { watching = false }
      }

      if (!id || !online) {
        settle({ status: id && !online ? 'no_signal' : 'gone' })
        return () => { watching = false }
      }

      if (pulled.current) {
        settle({ status: 'gone' })
        return () => { watching = false }
      }

      settle({ status: 'looking' })
      pulled.current = true
      void pullDeltas()
        .catch(() => null)
        .then(() => {
          const after = look()
          settle(after === null ? { status: 'gone' } : { status: 'ready', report: after })
        })

      return () => { watching = false }
    }, [id, online]),
  )

  return state
}
