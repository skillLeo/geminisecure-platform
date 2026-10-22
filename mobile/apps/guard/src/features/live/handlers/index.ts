/**
 * Every live handler the Guard App registers, one file per group (DESIGN §12.2): each wiring unit edits only
 * its own file. Metro has no glob import, so each group is listed here once.
 */

import type { LiveHandler } from '@gs/core/live'
import { alertHandlers } from './alerts'
import { gateHandlers } from './gate'
import { recordHandlers } from './records'

const groups: Record<string, LiveHandler[]>[] = [alertHandlers, gateHandlers, recordHandlers]

export function liveHandlers(): Record<string, LiveHandler[]> {
  const all: Record<string, LiveHandler[]> = {}
  for (const group of groups) {
    for (const [type, handlers] of Object.entries(group)) all[type] = [...(all[type] ?? []), ...handlers]
  }
  return all
}
