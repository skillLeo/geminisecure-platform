/**
 * Finding a unit or a household at the gate — boards guard-app-05 (a result) and -40 (none).
 *
 * WHAT THE SERVER GIVES: the unit reference, the household's name, its primary resident,
 * today's expected visitors, ONE boolean about the household's standing, and `notice` —
 * a standing note this officer has to read. It gives no member list and no vehicles, so
 * the live card does not draw them.
 *
 * `notice` IS THE CARD'S EXISTING STANDING-NOTE SLOT AND NOT A NEW ONE (Q-M44). Today the
 * server sends exactly one: somebody whose Resident App access the estate office ended is
 * no longer a resident of that lot and goes to the Visitor Entrance. Their name STAYS on
 * the estate's register — a revocation is not a deletion — so without the note the card
 * would go on naming a former owner as the primary resident and an officer would admit
 * them on it.
 *
 * INVARIANT 3: `access_restricted` is shown as the one fixed sentence and nothing else —
 * no figure, no bucket, no wording implying money.
 *
 * ONLINE ONLY by the server's contract (a cached register would be a stale register):
 * with no signal the screen says so and points to what does work offline.
 */

import { useEffect, useMemo, useState } from 'react'
import { fidelityScreenId, isFidelity, time12 } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { searchFixtures } from '@/fidelity/search'
import { useAppSelector } from '@/store'

export type UnitResult = {
  key: string
  unit: string
  household: string
  /** The badge's one or two short lines, e.g. ["P2", "47"]. */
  badge: string[]
  phase: number | null
  people: { name: string; note: string }[]
  vehicle: string | null
  instruction: string | null
  restricted: boolean
}

export type UnitSearchModel = {
  query: string
  setQuery: (text: string) => void
  clear: () => void
  /** The phase NAME the officer has filtered to, exactly as the estate spells it, or null for all. */
  phase: string | null
  setPhase: (phase: string | null) => void
  /** THE ESTATE'S OWN phases, from `guards.me` — five where there are five, two where there are two. */
  phases: string[]
  status: 'idle' | 'too_short' | 'offline' | 'loading' | 'error' | 'denied' | 'empty' | 'results'
  errorMessage: string | null
  results: UnitResult[]
  /** How many units matched IN THE ESTATE, which can be more than `results` carries. */
  total: number
  /** How many `results` can carry at most, so the screen can say it is showing the first of many. */
  limit: number
  siteName: string
  retry: () => void
}

const DEBOUNCE_MS = 350

const phaseOf = (reference: string): number | null => {
  const m = /phase\s*(\d+)/i.exec(reference) ?? /\bP(\d+)\b/i.exec(reference)
  return m ? Number(m[1]) : null
}

/** "Phase 2 · Lot 47" → ["P2", "47"]; "Lot 47" → ["47"]; anything else → its first four characters. */
const badgeOf = (reference: string): string[] => {
  const phase = phaseOf(reference)
  const lot = /(?:lot|unit|apt|#)\s*([A-Za-z]?\d+[A-Za-z]?)/i.exec(reference)?.[1] ?? /(\d+[A-Za-z]?)\s*$/.exec(reference)?.[1] ?? null
  if (phase !== null && lot) return [`P${phase}`, lot]
  return [lot ?? reference.replace(/\s+/g, '').slice(0, 4).toUpperCase()]
}

export function useUnitSearch(): UnitSearchModel {
  const fixture = isFidelity ? (searchFixtures[fidelityScreenId() ?? ''] ?? null) : null
  const online = useAppSelector((s) => s.network.online)
  const siteName = useAppSelector((s) => s.session.principal?.siteName ?? 'This estate')

  const [query, setQuery] = useState(fixture ? fixture.query : '')
  const [term, setTerm] = useState('')
  const [phase, setPhase] = useState<string | null>(null)

  /*
   * THE PHASES ARE THE ESTATE'S, NOT THE SCREEN'S (23 September 2026).
   *
   * This was `[1, 2, 3]` written into the screen — three chips, because the board drew three.
   * Phoenix Park Village 1 has five phases, so two of them could not be filtered at all, and any
   * estate that is not three phases was wrong from its first day. `guards.me` carries the estate's
   * own list; it is cached by RTK Query, so the chips are there before the officer types.
   */
  const profile = guardApi.useGuardsMeQuery(undefined, { skip: isFidelity })
  const phases = useMemo(
    () => (fixture ? fixture.phases : (profile.data?.site.phases ?? []).map((p) => p.name)),
    [fixture, profile.data],
  )

  useEffect(() => {
    if (fixture) return
    const timer = setTimeout(() => setTerm(query.trim()), DEBOUNCE_MS)
    return () => clearTimeout(timer)
  }, [fixture, query])

  // A number in it is a unit; otherwise it is a name, and the server wants two letters of one.
  const byUnit = /\d/.test(term)
  const tooShort = term.length > 0 && !byUnit && term.length < 2
  const ask = !fixture && term.length > 0 && !tooShort && online
  const search = guardApi.useGateSearchQuery(
    {
      ...(byUnit ? { unit: term.slice(0, 32) } : { name: term.slice(0, 80) }),
      // The phase narrows the QUERY. Filtering the page the server sent would search one phase of
      // twenty rows rather than one phase of the estate.
      ...(phase === null ? null : { phase }),
    },
    { skip: !ask },
  )

  const all = useMemo<UnitResult[]>(() => {
    if (fixture) return fixture.results
    if (!ask || !search.data) return []
    return search.data.items.map((item, i) => ({
      key: `${item.unit}:${i}`,
      unit: item.unit,
      household: item.household,
      badge: badgeOf(item.unit),
      phase: phaseOf(item.unit),
      people: [
        ...(item.primary_resident ? [{ name: item.primary_resident, note: 'Primary' }] : []),
        ...item.expected_visitors.map((v) => ({ name: v.visitor_name, note: `Expected today · pass valid until ${time12(v.valid_to)}` })),
      ],
      vehicle: null,
      instruction: item.notice ?? null,
      restricted: item.access_restricted === true,
    }))
  }, [fixture, ask, search.data])

  const results = all

  const error = ask ? search.error : undefined
  const status: UnitSearchModel['status'] = fixture
    ? fixture.results.length > 0 ? 'results' : 'empty'
    : term.length === 0 ? 'idle'
    : tooShort ? 'too_short'
    : !online ? 'offline'
    : isApiError(error) && error.status === 403 ? 'denied'
    : error ? 'error'
    : search.isFetching || search.data === undefined ? 'loading'
    : results.length > 0 ? 'results' : 'empty'

  return {
    query,
    setQuery,
    clear: () => {
      setQuery('')
      setTerm('')
      setPhase(null)
    },
    phase,
    setPhase,
    phases,
    status,
    errorMessage: isApiError(error) ? error.message : null,
    results,
    total: fixture ? results.length : (search.data?.total ?? results.length),
    limit: search.data?.limit ?? results.length,
    siteName: fixture ? fixture.siteName : siteName,
    retry: () => void search.refetch(),
  }
}
