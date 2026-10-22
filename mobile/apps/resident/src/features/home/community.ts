/**
 * Home's way in to the community's business — the next meeting, and an election — pure, so it can be tested.
 *
 * The boards give Home six quick actions and none of them is a meeting or a vote, so without these rows
 * those screens would exist and be unreachable. The rows appear only when there is something to open:
 *
 *   VOTING   shown only to a household the SERVER says may vote (eligibility is asked, online). A household
 *            that is not eligible sees NO invitation — not a disabled one with a reason to argue about.
 *            After voting the row opens the receipt; once results are published it opens the results.
 *   MEETING  the next one still ahead, with its date in the site's zone and the household's own RSVP. With none ahead
 *            but one postponed with no new date, that one: still to come, and never "see past meetings" (M98).
 */

import { dayTimeTag } from '@gs/core/lib'
import { DATE_TO_BE_SET, dateToBeSet } from '@/features/meetings/meetings'

export type ElectionItem = { id: number | string; title: string; kind: string; voting_open: boolean; has_voted: boolean; results_published: boolean; closes_at: string | null }
export type MeetingItem = { id: number | string; title: string; starts_at: string; status: string; my_rsvp: string | null; date_to_be_set?: boolean | null }
export type Row = {
  key: string
  icon: 'tab-card' | 'input'
  title: string
  detail: string
  path: string
  tone: 'navy-600' | 'slate-500'
  /** A second, smaller way on from the row: the next meeting opens its lobby, and "All meetings" sits under it. */
  more?: { label: string; path: string }
}

/** The list of every meeting — upcoming and past. */
export const ALL_MEETINGS = '/meetings'

/** The election Home should ask about: one that is open and not yet voted in. */
export const openElection = (items: ElectionItem[]): ElectionItem | null => items.find((e) => e.voting_open && !e.has_voted) ?? null

export function electionRow(items: ElectionItem[], eligible: boolean | null): Row | null {
  const open = openElection(items)
  // `eligible` is null until the server has answered — and nothing is offered on a guess.
  if (open) return eligible === true ? { key: `vote:${open.id}`, icon: 'tab-card', title: open.kind === 'resolution' ? 'A resolution is open for your vote' : 'Voting is open', detail: `${open.title}${open.closes_at ? ` · closes ${dayTimeTag(open.closes_at)}` : ''}`, path: `/elections/${open.id}/ballot-a`, tone: 'navy-600' } : null
  const voted = items.find((e) => e.voting_open && e.has_voted)
  if (voted) return { key: `voted:${voted.id}`, icon: 'tab-card', title: 'Your household has voted', detail: `${voted.title} · open your receipt`, path: `/elections/${voted.id}/receipt`, tone: 'slate-500' }
  const results = items.find((e) => e.results_published)
  if (results) return { key: `results:${results.id}`, icon: 'tab-card', title: 'Election results', detail: results.title, path: `/elections/${results.id}/results`, tone: 'slate-500' }
  return null
}

const RSVP: Record<string, string> = { attending: 'you are attending', apologies: 'you sent apologies', not_attending: 'you are not attending' }

export function meetingRow(items: MeetingItem[], at: Date): Row | null {
  const byStart = (p: MeetingItem, q: MeetingItem) => new Date(p.starts_at).getTime() - new Date(q.starts_at).getTime()
  /** A meeting still to come: it opens its lobby, with "All meetings" under it. */
  const rowOf = (m: MeetingItem, detail: string): Row => ({ key: `meeting:${m.id}`, icon: 'input', title: m.title, detail, path: `/meetings/${m.id}`, tone: 'navy-600', more: { label: 'All meetings', path: ALL_MEETINGS } })
  const next = items.filter((m) => m.status === 'scheduled' && !dateToBeSet(m) && new Date(m.starts_at).getTime() > at.getTime() - 3 * 3_600_000).sort(byStart)[0]
  // Postponed with no new date (M98, 12 Oct 2026): still to come, so Home names it rather than sending the resident to the past.
  const waiting = next ? undefined : items.filter((m) => dateToBeSet(m)).sort(byStart)[0]
  if (waiting) return rowOf(waiting, `Postponed · ${DATE_TO_BE_SET}`)
  // Nothing ahead, but the estate HAS held meetings: the row is the way in to them (and their minutes) — not nothing at all.
  if (!next) return items.length > 0 ? { key: 'meetings:all', icon: 'input', title: 'Meetings', detail: 'No meeting is scheduled. See past meetings.', path: ALL_MEETINGS, tone: 'slate-500' } : null
  const rsvp = next.my_rsvp ? RSVP[next.my_rsvp] : null
  return rowOf(next, `${dayTimeTag(next.starts_at)}${rsvp ? ` · ${rsvp}` : ' · tell the estate if you are coming'}`)
}
