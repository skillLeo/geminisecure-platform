/**
 * resident-app-08 · the Visitors tab's model: On site now, Recent activity and History (tracking.ts), from the
 * household's passes as useVisitorList holds them — cached, so they draw with no connection, and worked out again
 * every minute, so the badges keep counting.
 *
 * FIDELITY MODE (the gallery, the measuring harness) hands the screen the design's own sample instead — the
 * trackingFixture, word for word — here and nowhere else: the screen draws whatever it is given, so the screen that
 * is measured is the screen a phone shows. The board's badges are its own ("45 min" after 2:14 PM, "3h 12m" after
 * 10:02 AM — no one clock gives both), so they are taken as written, not worked out from the frozen clock.
 */

import { useMemo } from 'react'
import { isFidelity, now } from '@gs/core'
import { trackingFixture } from '@/fidelity/visitors'
import { trackingOf, type Tracking } from './tracking'
import { useVisitorList, type VisitorListModel } from './useVisitors'

export type TrackingModel = Tracking & Pick<VisitorListModel, 'status' | 'errorMessage' | 'refreshing' | 'refresh'> & {
  /** "My e-Pass" heads "On site now" (M50 §6.1) — on a phone; the board does not draw it, so its picture has none. */
  epass: boolean
}

/** The board's own two visitors and four gate events. The board draws no History. */
const BOARD: Tracking = {
  onSite: trackingFixture.onSite.map((v) => ({ key: v.id, passId: null, initials: v.initials, name: v.name, line: v.detail, badge: v.chip })),
  activity: trackingFixture.activity.map((row) => ({ key: row.id, passId: null, entered: row.entered, name: row.name, line: row.detail, time: row.time })),
  history: [],
}

export function useVisitorTracking(): TrackingModel {
  const list = useVisitorList()
  // `groups` is worked out again each minute (useVisitorList), so this is too: "45 min" becomes "46 min".
  const live = useMemo(() => trackingOf(list.groups, now()), [list.groups])
  if (isFidelity) return { ...BOARD, status: 'ready', errorMessage: null, refreshing: false, refresh: list.refresh, epass: false }
  // Every resident's own pass, whatever their level (M50 §6.1) — a Dependent or a Helper is refused the visitors, not
  // their e-Pass — and none for an account with no home on the register, which has none.
  return { ...live, status: list.status, errorMessage: list.errorMessage, refreshing: list.refreshing, refresh: list.refresh, epass: list.status !== 'denied' || list.levelRefused }
}
