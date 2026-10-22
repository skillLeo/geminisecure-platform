/**
 * The community rows on Home (see community.ts). Lists come from the cache when there is no signal; the
 * VOTING row needs the server's live answer on eligibility, so with no signal it is simply not offered —
 * voting is online only anyway.
 *
 * Meetings and voting are the household's Members' (`access.may`): for a Dependent or a Helper neither is read
 * nor offered, since the server would refuse both.
 */

import { useMemo } from 'react'
import { isFidelity, now, useOfflineCache } from '@gs/core'
import { residentApi } from '@gs/core/api/resident'
import { useAccess } from '@/features/access/useAccess'
import { useAppSelector } from '@/store'
import { electionRow, meetingRow, openElection, type ElectionItem, type MeetingItem, type Row } from './community'

export function useCommunity(): Row[] {
  const online = useAppSelector((s) => s.network.online)
  const { may } = useAccess()
  const votes = may('elections')
  const meets = may('meetings')
  const elections = residentApi.useElectionsIndexQuery(undefined, { skip: isFidelity || !votes })
  const meetings = residentApi.useMeetingsIndexQuery(undefined, { skip: isFidelity || !meets })
  const cachedElections = useOfflineCache('elections.index', elections.data)
  const cachedMeetings = useOfflineCache('meetings.index', meetings.data)

  const items = (cachedElections?.items ?? []) as ElectionItem[]
  const open = openElection(items)
  // Asked only when there is something to be eligible FOR, and only live: a cached "eligible" is not an invitation.
  const eligibility = residentApi.useElectionsEligibilityQuery({ ballot: open?.id ?? '' }, { skip: isFidelity || !votes || !open || !online })
  const eligible = open && online && eligibility.data && !eligibility.isError ? eligibility.data.eligible === true && eligibility.data.voting_open === true : null

  return useMemo(() => {
    if (isFidelity) return []
    return [votes ? electionRow(items, eligible) : null, meets ? meetingRow((cachedMeetings?.items ?? []) as MeetingItem[], now()) : null].filter((row): row is Row => row !== null)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cachedElections, cachedMeetings, eligible, votes, meets])
}
