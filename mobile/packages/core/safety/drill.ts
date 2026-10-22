/**
 * A DRILL SAYS SO FIRST (M10 §1.3).
 *
 * `panic:walk` raises a real alert to prove who a panic reaches — real officers' phones, real consoles —
 * and marks it `is_drill`. The server puts "DRILL — " in front of the push title, the queue row, the pop-up
 * and the mail subject; this is the phones' half. Wherever either app draws an alert, a drill is labelled
 * DRILL before anything else about it, so nobody reads the rest as a person in danger.
 *
 * ONLY A POSITIVE `true` IS A DRILL. A missing flag (an answer saved before the field existed, or an
 * endpoint that does not carry it — `alerts.show` does not) is NOT read as "real" either: the label is
 * simply not drawn, and nothing claims the opposite. `is_drill` is false on every alert a person raises.
 *
 * Pure: no imports.
 */

export const DRILL_LABEL = 'DRILL'

/** The one sentence under the label: what a drill is, and nothing it cannot know. */
export const DRILL_NOTE = 'This alert is a drill: a test of who an alert reaches. Nobody pressed it in an emergency.'

export const isDrill = (value: unknown): boolean => value === true

/** "DRILL — Panic alert": the server's own prefix (`DuressAlert::drillPrefix`), so a lock screen reads the same whichever way it arrived. */
export const drillTitle = (title: string, drill: unknown): string => (isDrill(drill) ? `${DRILL_LABEL}: ${title}` : title)
