/**
 * Choosing the estate before signing in: a LIST of the active estates, with the typed
 * community code kept as the fallback. A typed code is the easiest thing in the product for a
 * homeowner to get wrong; a list cannot be mistyped.
 *
 *   list  the server's `estates.index` answered — pick from it. With exactly ONE active estate (the
 *         pilot) it is chosen already: there is nothing to decide.
 *   code  no list (offline, the server failed, or it is empty) — or the resident said their estate is
 *         not on it — so the typed field is shown, exactly as before.
 *
 * The value either way is the estate's id, which is what the sign-in endpoints take.
 */

export type EstateOption = { id: string; name: string; parish: string | null; demo: boolean }

/** "Phoenix Park Village 1 · St. Andrew" — the parish tells two estates of one name apart. */
export const estateLabel = (estate: EstateOption): string => (estate.parish ? `${estate.name} · ${estate.parish}` : estate.name)

/** What the server sent, cleaned: rows without an id or a name are dropped, the rest sorted by name. */
export function estateOptions(items: readonly { id?: unknown; name?: unknown; parish?: unknown; demo?: unknown }[] | null | undefined): EstateOption[] {
  return (items ?? [])
    .map((row) => ({
      id: String(row.id ?? '').trim(),
      name: String(row.name ?? '').trim(),
      parish: typeof row.parish === 'string' && row.parish.trim() ? row.parish.trim() : null,
      demo: row.demo === true,
    }))
    .filter((row) => row.id.length > 0 && row.name.length > 0)
    .sort((a, b) => a.name.localeCompare(b.name))
}

/**
 * The DEMONSTRATION community, if this platform has one.
 *
 * "Explore a demo community" is offered only when this answers — a control that could only
 * ever fail is a dead control, and on a platform with no demonstration estate `demo.enter`
 * answers 404 every time.
 */
export const demoEstate = (options: readonly EstateOption[]): EstateOption | null => options.find((o) => o.demo) ?? null

/**
 * It stays IN the pick-list, labelled.
 *
 * An App Store reviewer signs in to it with a real account and the static code the review
 * notes quote, so hiding it would leave them typing a community code. What it must never be
 * is mistaken for somewhere people live — hence the line under the name.
 */
export const estateNote = (estate: EstateOption): string | null => (estate.demo ? 'Demonstration community: invented data, nobody lives here' : estate.parish)

/**
 * Never chosen FOR anybody.
 *
 * `initialEstate` picks the only estate there is when a platform has one, and remembers the
 * last one used. Neither may land a real resident in the demonstration estate by itself: a
 * demonstration is something you ask for.
 */
export const homeEstates = (options: readonly EstateOption[]): EstateOption[] => options.filter((o) => !o.demo)

export type PickerMode = 'list' | 'code'

/** The typed code is shown when there is no list to pick from, when the resident asked for it, or when what they already hold is not on the list. */
export function pickerMode(options: readonly EstateOption[], current: string, wantsCode: boolean): PickerMode {
  if (options.length === 0 || wantsCode) return 'code'
  if (current.trim().length > 0 && !options.some((o) => o.id === current.trim().toLowerCase())) return 'code'
  return 'list'
}

/**
 * The estate chosen before the resident touches anything: what this phone remembered if it is
 * still listed, else the only one there is, else nothing. The demonstration community is never
 * "the only one there is" — it is always asked for, never landed in.
 */
export function initialEstate(options: readonly EstateOption[], remembered: string | null | undefined): string {
  const kept = (remembered ?? '').trim().toLowerCase()
  if (kept && options.some((o) => o.id === kept)) return kept
  const homes = homeEstates(options)
  return homes.length === 1 ? (homes[0]?.id ?? '') : ''
}

export const chosen = (options: readonly EstateOption[], id: string): EstateOption | null => options.find((o) => o.id === id.trim().toLowerCase()) ?? null
