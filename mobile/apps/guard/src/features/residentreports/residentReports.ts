/**
 * WHAT A RESIDENT SAYS IS HAPPENING NOW, ON THE GUARD'S HANDSET.
 *
 * The report arrives inside `sync.pull` — the same pull that raises the banner — and is the same record
 * the server pushes to a CLOSED app as `resident_report`. Both say "Open the app for what they said", so
 * what they said is kept HERE, on the phone, and the tap opens it. Without this the notification led to
 * "Unmatched Route": the one thing the client met on a real phone.
 *
 * READ ONLY, IN EVERY SENSE. "Notify, but do not reassign" — the report belongs to community staff with
 * `source = resident`. Nothing here writes, claims, answers or closes one; there is no endpoint that
 * would, and no control on the screen that pretends there is.
 *
 * WHAT IS KEPT. The last 20, and only while the SERVER still calls one current: 12 hours, the same
 * window `SyncController::HAPPENING_NOW_HOURS` uses, so a handset never shows a "happening now" the
 * gatehouse has already stopped being told about. The server sends a place and never a household or a
 * name, so none is kept. It goes with the rest of the key-value store at sign-out (`clearEverything`).
 */

import { getStorage, jsonStore } from '@gs/core/lib'

/** As long as the server itself calls a "happening now" current (SyncController::HAPPENING_NOW_HOURS). */
export const KEEP_HOURS = 12

export const KEEP_MOST_RECENT = 20

export type ResidentReport = {
  id: string
  number: string
  title: string
  detail: string | null
  location: string
  latitude: number | null
  longitude: number | null
  accuracyM: number | null
  reportedAt: string
}

/** One row of `sync.pull`'s `resident_reports`, as loosely as it may arrive. */
export type PulledReport = {
  report_id?: unknown
  number?: unknown
  title?: unknown
  detail?: unknown
  location?: unknown
  latitude?: unknown
  longitude?: unknown
  accuracy_m?: unknown
  reported_at?: unknown
}

const ID = /^[A-Za-z0-9_-]{1,40}$/

const text = (value: unknown, max: number): string => (typeof value === 'string' || typeof value === 'number' ? String(value).replace(/\s+/g, ' ').trim().slice(0, max) : '')

const decimal = (value: unknown): number | null => {
  const n = typeof value === 'number' ? value : typeof value === 'string' && value.trim() !== '' ? Number(value) : NaN
  return Number.isFinite(n) ? n : null
}

const whole = (value: unknown): number | null => {
  const n = decimal(value)
  return n === null ? null : Math.max(0, Math.round(n))
}

/** A row the phone can use, or null — a report with no id, no title or no time is not one. */
export function toReport(raw: PulledReport | null | undefined): ResidentReport | null {
  if (!raw) return null
  const id = text(raw.report_id, 40)
  const title = text(raw.title, 140)
  const reportedAt = typeof raw.reported_at === 'string' ? raw.reported_at : ''
  if (!ID.test(id) || title === '' || reportedAt === '' || Number.isNaN(Date.parse(reportedAt))) return null

  const latitude = decimal(raw.latitude)
  const longitude = decimal(raw.longitude)
  const both = latitude !== null && longitude !== null && Math.abs(latitude) <= 90 && Math.abs(longitude) <= 180

  return {
    id,
    number: text(raw.number, 20),
    title,
    detail: text(raw.detail, 600) || null,
    location: text(raw.location, 120),
    latitude: both ? latitude : null,
    longitude: both ? longitude : null,
    accuracyM: both ? whole(raw.accuracy_m) : null,
    reportedAt,
  }
}

/** Still current, newest first, at most `KEEP_MOST_RECENT` — one row per report however often it was pulled. */
export function mergeReports(kept: readonly ResidentReport[], incoming: readonly ResidentReport[], at: Date): ResidentReport[] {
  const oldest = at.getTime() - KEEP_HOURS * 3_600_000
  const byId = new Map<string, ResidentReport>()
  for (const report of [...kept, ...incoming]) {
    if (Date.parse(report.reportedAt) >= oldest) byId.set(report.id, report)
  }
  return [...byId.values()].sort((a, b) => Date.parse(b.reportedAt) - Date.parse(a.reportedAt)).slice(0, KEEP_MOST_RECENT)
}

/* ---- what is on this phone ---------------------------------------------------------------------- */

const box = () => jsonStore<ResidentReport[]>(getStorage(), 'notify.resident_reports', 'v1')

const read = (): ResidentReport[] => {
  const stored = box().get()
  return Array.isArray(stored) ? stored : []
}

/** Called by the poller with whatever `sync.pull` returned. Never throws: a banner is worth more than a store. */
export function rememberReports(raw: readonly PulledReport[] | null | undefined, at: Date = new Date()): ResidentReport[] {
  try {
    const incoming = (raw ?? []).map(toReport).filter((report): report is ResidentReport => report !== null)
    const next = mergeReports(read(), incoming, at)
    if (incoming.length > 0 || next.length !== read().length) box().set(next)
    return next
  } catch {
    return []
  }
}

/** The one the notification names, or null — it may have aged out, or belong to a shift that has ended. */
export function reportById(id: string, at: Date = new Date()): ResidentReport | null {
  try {
    return mergeReports(read(), [], at).find((report) => report.id === id) ?? null
  } catch {
    return null
  }
}
