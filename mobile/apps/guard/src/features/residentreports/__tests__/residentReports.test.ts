/**
 * What a resident says is happening now, kept on the handset — the pure half.
 *
 * The screen it feeds is where a `resident_report` notification LEADS, local or pushed, so a
 * row that cannot be trusted must never become one: the id is what the payload's id is checked against.
 */

import { KEEP_HOURS, KEEP_MOST_RECENT, mergeReports, toReport, type ResidentReport } from '../residentReports'

const AT = new Date('2026-09-20T18:00:00.000Z')

const raw = (over: Record<string, unknown> = {}) => ({
  report_id: 41,
  number: 1042,
  title: '  Gate   light  out  ',
  detail: 'Two men by the transformer box.',
  location: 'Lot 982 · front verge',
  latitude: '17.98765',
  longitude: '-76.79321',
  accuracy_m: '12.4',
  reported_at: '2026-09-20T17:40:00.000Z',
  ...over,
})

const made = (id: string, iso: string): ResidentReport => ({
  id, number: '', title: `Report ${id}`, detail: null, location: '', latitude: null, longitude: null, accuracyM: null, reportedAt: iso,
})

describe('one row of sync.pull', () => {
  it('keeps what the server sent, tidied, and the place, never a household', () => {
    const report = toReport(raw())!
    expect(report).toEqual({
      id: '41',
      number: '1042',
      title: 'Gate light out',
      detail: 'Two men by the transformer box.',
      location: 'Lot 982 · front verge',
      latitude: 17.98765,
      longitude: -76.79321,
      accuracyM: 12,
      reportedAt: '2026-09-20T17:40:00.000Z',
    })
  })

  it('refuses a row it could not show, or could not be found again by its id', () => {
    expect(toReport(null)).toBeNull()
    expect(toReport(raw({ report_id: '../../etc/passwd' }))).toBeNull()
    expect(toReport(raw({ report_id: '' }))).toBeNull()
    expect(toReport(raw({ title: '   ' }))).toBeNull()
    expect(toReport(raw({ reported_at: 'the other day' }))).toBeNull()
    expect(toReport(raw({ reported_at: undefined }))).toBeNull()
  })

  it('drops half a pin, and a pin off the planet, rather than draw a wrong one', () => {
    expect(toReport(raw({ latitude: null }))!.longitude).toBeNull()
    expect(toReport(raw({ longitude: 'somewhere' }))!.latitude).toBeNull()
    expect(toReport(raw({ latitude: 917 }))!.latitude).toBeNull()
    // No pin, no accuracy: "to about 12 m" from nowhere would be a lie.
    expect(toReport(raw({ latitude: null }))!.accuracyM).toBeNull()
  })

  it('has no room for a wall of text on a lock-screen tap', () => {
    expect(toReport(raw({ detail: 'x'.repeat(900) }))!.detail).toHaveLength(600)
    expect(toReport(raw({ detail: '' }))!.detail).toBeNull()
  })
})

describe('what stays on the phone', () => {
  it('keeps one row per report, newest first', () => {
    const kept = mergeReports(
      [made('1', '2026-09-20T17:00:00.000Z'), made('2', '2026-09-20T17:30:00.000Z')],
      [{ ...made('1', '2026-09-20T17:00:00.000Z'), title: 'Report 1, pulled again' }],
      AT,
    )
    expect(kept.map((r) => r.id)).toEqual(['2', '1'])
    expect(kept[1]!.title).toBe('Report 1, pulled again')
  })

  it('forgets one the gatehouse is no longer told about', () => {
    const old = new Date(AT.getTime() - (KEEP_HOURS * 3_600_000 + 60_000)).toISOString()
    const fresh = new Date(AT.getTime() - 60_000).toISOString()
    expect(mergeReports([made('old', old), made('new', fresh)], [], AT).map((r) => r.id)).toEqual(['new'])
    // …and never brings one back on the next pull, either.
    expect(mergeReports([], [made('old', old)], AT)).toEqual([])
  })

  it('cannot grow without limit, however long a shift is', () => {
    const many = Array.from({ length: KEEP_MOST_RECENT + 15 }, (_, i) => made(String(i), new Date(AT.getTime() - i * 60_000).toISOString()))
    const kept = mergeReports(many, [], AT)
    expect(kept).toHaveLength(KEEP_MOST_RECENT)
    expect(kept[0]!.id).toBe('0')
  })
})
