/**
 * Standing orders arrive as ONE plain-text `body` per order set. The board draws headed
 * sections of bulleted lines, so the text is read for the structure it already has —
 * nothing is added, dropped or reworded:
 *
 *   "# Heading" / "Heading:" / a short line standing directly over a list   → a section heading
 *   "- text" · "• text"                                                     → a bullet
 *   "1. text" · "2) text"                                                   → a numbered line (the number is kept:
 *                                                                              orders are cited by number)
 *   "FIRE     Raise the alarm…"  (capitals, then a run of spaces)           → a labelled line
 *   an indented or unbroken following line                                  → the rest of the line above
 *   anything else, split on blank lines                                     → paragraphs
 *
 * Pure functions, no React — so the rules can be read (and tested) on their own.
 */

import { dateShort, isFidelity, siteNoon, siteParts, now, time12 } from '@gs/core'

export type OrderEntry =
  | { kind: 'bullet'; text: string }
  | { kind: 'numbered'; number: string; text: string }
  | { kind: 'labelled'; label: string; text: string }
  | { kind: 'para'; text: string }

export type OrderSection = { heading: string | null; entries: OrderEntry[] }

const MD_HEADING = /^\s*#{1,6}\s+(.*?)\s*#*\s*$/
const BULLET = /^\s*[-*•·‣▪]\s+(\S.*)$/
const NUMBERED = /^\s*(\d{1,3}[a-z]?)[.)]\s+(\S.*)$/
const LABELLED = /^([A-Z][A-Z0-9/&-]*(?: [A-Z0-9/&-]+){0,3})(?:\s{2,}|\t+)(\S.*)$/

const tidy = (text: string) => text.replace(/\s+/g, ' ').trim()
const isListLine = (line: string | undefined) => line !== undefined && (BULLET.test(line) || NUMBERED.test(line))

export function parseOrdersBody(body: string): OrderSection[] {
  const lines = body.replace(/\r\n?/g, '\n').split('\n')
  const sections: OrderSection[] = []
  let last: OrderEntry | null = null
  let blankBefore = true

  const section = (): OrderSection => {
    const current = sections[sections.length - 1]
    if (current) return current
    const first: OrderSection = { heading: null, entries: [] }
    sections.push(first)
    return first
  }
  const add = (entry: OrderEntry) => {
    section().entries.push(entry)
    last = entry
  }
  const open = (heading: string) => {
    sections.push({ heading: tidy(heading), entries: [] })
    last = null
  }

  lines.forEach((line, i) => {
    if (line.trim() === '') {
      blankBefore = true
      last = null
      return
    }
    const next = lines.slice(i + 1).find((l) => l.trim() !== '')
    const nextIsBlankOrEnd = lines[i + 1] === undefined || lines[i + 1]!.trim() === ''
    const text = tidy(line)

    const md = MD_HEADING.exec(line)
    const bullet = BULLET.exec(line)
    const numbered = NUMBERED.exec(line)
    const labelled = LABELLED.exec(line)

    if (md) open(md[1]!)
    else if (numbered) add({ kind: 'numbered', number: numbered[1]!, text: tidy(numbered[2]!) })
    else if (bullet) add({ kind: 'bullet', text: tidy(bullet[1]!) })
    else if (!/^\s/.test(line) && text.endsWith(':') && text.length <= 60 && isListLine(next)) open(text.slice(0, -1))
    else if (!/^\s/.test(line) && blankBefore && text.length <= 60 && !/[.;,]$/.test(text) && !labelled && isListLine(lines[i + 1])) open(text)
    else if (!/^\s/.test(line) && blankBefore && nextIsBlankOrEnd && text.length <= 60 && /[A-Z]/.test(text) && !/[a-z]/.test(text) && next !== undefined) open(text)
    else if (last && !blankBefore && (/^\s/.test(line) || !labelled)) last.text = `${last.text} ${text}`
    else if (labelled) add({ kind: 'labelled', label: labelled[1]!, text: tidy(labelled[2]!) })
    else add({ kind: 'para', text })

    blankBefore = false
  })

  return sections.filter((s) => s.heading !== null || s.entries.length > 0)
}

/** "Phoenix Park Village 1 — Main Gate" reads "Main Gate" on a handset that is already at Phoenix Park Village 1. */
export function displayTitle(title: string, siteName: string | null): string {
  if (!siteName) return title
  const rest = title.startsWith(siteName) ? title.slice(siteName.length).replace(/^\s*[—–-]\s*/, '') : title
  return rest.length > 0 && rest !== title ? rest : title
}

/**
 * `effective_on` is a DATE (YYYY-MM-DD) — read at noon AT THE SITE so no zone can move it a day. "1 Sep 2026", the one
 * form `dateShort` now writes with its year (M98); the fidelity harness keeps the board's "Sep 1, 2026".
 */
export function effectiveLabel(effectiveOn: string): string {
  const at = siteNoon(effectiveOn.slice(0, 10))
  if (at === '' || Number.isNaN(new Date(at).getTime())) return effectiveOn
  return isFidelity ? `${dateShort(at)}, ${siteParts(at).year}` : dateShort(at)
}

/** "today, 7:26 AM" · "1 Aug 2026, 6:58 AM" (M98's one form, its year always) — on the site’s clock. */
export function acknowledgedLabel(iso: string): string {
  if (Number.isNaN(new Date(iso).getTime())) return iso
  const then = siteParts(iso)
  const today = siteParts(now())
  if (then.year === today.year && then.month === today.month && then.day === today.day) return `today, ${time12(iso)}`
  if (!isFidelity) return `${dateShort(iso)}, ${time12(iso)}`
  return then.year === today.year ? `${dateShort(iso)}, ${time12(iso)}` : `${dateShort(iso)}, ${then.year}, ${time12(iso)}`
}
