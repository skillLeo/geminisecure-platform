/**
 * Every election and resolution the estate has published to this household, as rows (the user, 8 Oct 2026: a resident
 * who missed the "Voting is open" notification had no way to find the vote). Pure, so it is proven without a phone.
 *
 * A row opens what the household can do with it now: the paper while voting is open and it has not voted, the receipt
 * once it has, the results once they are published. Anything earlier or later says where it stands and opens nothing.
 * Open votes first, then the household's receipts, then results, then the rest, each newest first.
 */

import { dayTimeTag } from '@gs/core/lib'

export type ElectionListItem = {
  id: number | string
  title: string
  kind: string
  stage?: string
  stage_label?: string
  voting_open: boolean
  has_voted: boolean
  results_published: boolean
  closes_at: string | null
}

export type ElectionTone = 'navy' | 'green' | 'slate'

export type ElectionRow = {
  id: string
  title: string
  kind: string
  chip: { label: string; tone: ElectionTone }
  detail: string
  /** Where a tap goes, or null when there is nothing to open yet. */
  path: string | null
  /** What a screen reader says for the whole row. */
  label: string
}

const rank = (e: ElectionListItem): number => (e.voting_open && !e.has_voted ? 0 : e.voting_open ? 1 : e.results_published ? 2 : 3)

export function electionRows(items: ElectionListItem[]): ElectionRow[] {
  return [...items]
    .sort((p, q) => rank(p) - rank(q) || Number(q.id) - Number(p.id))
    .map((e) => {
      const id = String(e.id)
      const kind = e.kind === 'resolution' ? 'Resolution' : 'Election'
      const row = ((): Pick<ElectionRow, 'chip' | 'detail' | 'path'> => {
        if (e.voting_open && !e.has_voted) return { chip: { label: 'Voting open', tone: 'navy' }, detail: e.closes_at ? `Closes ${dayTimeTag(e.closes_at)}` : 'Open now', path: `/elections/${id}/ballot-a` }
        if (e.voting_open) return { chip: { label: 'You voted', tone: 'green' }, detail: 'Open your receipt', path: `/elections/${id}/receipt` }
        if (e.results_published) return { chip: { label: 'Results', tone: 'slate' }, detail: 'See the results', path: `/elections/${id}/results` }
        // Cancelled by the estate (M56 §3): it will not happen, and any vote cast in it counts for nothing.
        if (e.stage === 'cancelled') return { chip: { label: 'Cancelled', tone: 'slate' }, detail: 'The estate office cancelled this election.', path: null }
        const closed = e.stage === 'voting_closed' || e.stage === 'tally' || e.stage === 'certified'
        return { chip: { label: e.stage_label || (closed ? 'Closed' : 'Not open yet'), tone: 'slate' }, detail: closed ? 'Voting has closed. Results come here once published.' : 'Voting has not opened yet.', path: null }
      })()
      return { id, title: e.title, kind, ...row, label: `${kind}: ${e.title}. ${row.chip.label}. ${row.detail}` }
    })
}
