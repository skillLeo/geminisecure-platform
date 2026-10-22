/**
 * The incident feature's rules. The ones that matter most: what is filed is exactly what the server
 * takes (its values, its limits, nothing else); a report never vanishes or appears twice between
 * "saved on the phone" and "listed by the server"; and a refusal is shown, never hidden.
 */
import {
  CATEGORIES,
  DETAIL_MAX,
  DETAIL_MIN,
  LOCATION_MAX,
  PHOTO_MAX,
  PHOTO_TILE,
  SEVERITIES,
  STATUS_CHIP,
  appendTranscript,
  detailCount,
  iconForKind,
  incidentSchema,
  isThisWeek,
  kindOf,
  mediaBody,
  mergeReports,
  photoTileWidth,
  reference,
  reportBody,
  whenLabel,
  type IncidentForm,
  type QueuedOp,
  type ReportItem,
} from '../incidents'

const NOW = new Date('2026-09-04T09:41:00-05:00')
const at = (day: string, time: string) => `${day}T${time}:00-05:00`

const good: IncidentForm = { category: 'suspicious', severity: 'med', detail: 'Grey pickup, no plates, parked by the playground.', location: 'Main Gate' }

const item = (id: number, kind: string, status: ReportItem['status'], occurredAt: string, extra: Partial<ReportItem> = {}): ReportItem => ({
  id, kind, severity: 'low', status, detail: `${kind}: what happened, in the guard's words.`, location: null, shift_id: null, occurred_at: occurredAt, resolution: '', closed_at: null, media_count: 0, ...extra,
})

let n = 0
const op = (endpoint: string, state: QueuedOp['state'], capturedAt: string, extra: Partial<QueuedOp> = {}): QueuedOp => ({
  id: `op-${++n}`, endpoint, state, capturedAt, after: null, body: null, error: null, result: null, ...extra,
})

describe('the photo strip is one row of five on every phone', () => {
  /*
   * Board guard-app-08 draws five tiles in a row. Five 58s and four 9-point gaps need 366 points and
   * the board leaves 357, so the fifth tile wrapped onto a line of its own — reading, on the "+",
   * as though no more photos could be added. Worse on a 360-point phone, where 320 is left.
   */
  it.each([
    ['a small Android', 320],
    ['the commonest Android width', 360],
    ['iPhone 12–15', 390],
    ['the board’s own frame', 397],
    ['Pixel 6/7/8', 412],
  ])('%s (%i pt): five tiles and four gaps fit one row', (_name, width) => {
    const tile = photoTileWidth(width)
    expect(PHOTO_MAX * tile + (PHOTO_MAX - 1) * 9).toBeLessThanOrEqual(width - 40)
  })

  it('never larger than the board’s tile, and never too small to be a photograph', () => {
    for (const width of [412, 430, 600, 1024]) expect(photoTileWidth(width)).toBe(PHOTO_TILE)
    for (const width of [240, 280, 300]) expect(photoTileWidth(width)).toBeGreaterThanOrEqual(44)
  })

  it('a width nobody could measure leaves the board’s tile alone', () => {
    for (const bad of [0, -1, Number.NaN, Number.POSITIVE_INFINITY]) expect(photoTileWidth(bad)).toBe(PHOTO_TILE)
  })
})

describe('categories and severity', () => {
  it("draws the board's four chips first, in the board's order", () => {
    expect(CATEGORIES.slice(0, 4).map((cat) => cat.label)).toEqual(['Suspicious activity', 'Break-in', 'Fire', 'Equipment fault'])
  })

  it("files the chip's own words as the server's free-text kind, within its 160 characters", () => {
    for (const cat of CATEGORIES) {
      expect(kindOf(cat.id)).toBe(cat.label)
      expect(cat.label.length).toBeLessThanOrEqual(160)
    }
    expect(kindOf('no-such-chip')).toBe('Other')
  })

  it("offers exactly the server's three severities, with the console's words", () => {
    expect(SEVERITIES).toEqual([{ value: 'low', label: 'Low' }, { value: 'med', label: 'Medium' }, { value: 'high', label: 'High' }])
  })

  it('gives a kind it did not file (the console logs its own) the warning sign', () => {
    expect(iconForKind('Equipment fault')).toBe('rp-icon')
    expect(iconForKind('  equipment   FAULT ')).toBe('rp-icon')
    expect(iconForKind('Attempted unauthorized access')).toBe('qa-tile-3')
  })
})

describe('incidentSchema: the server\'s limits', () => {
  it('accepts a complete report', () => {
    expect(incidentSchema.safeParse(good).success).toBe(true)
  })

  it('requires a kind and a severity: nothing is pre-chosen or suggested for the guard', () => {
    const result = incidentSchema.safeParse({ ...good, category: '', severity: '' })
    expect(result.success).toBe(false)
    const fields = result.success ? [] : result.error.issues.map((issue) => issue.path[0])
    expect(fields).toEqual(expect.arrayContaining(['category', 'severity']))
  })

  it('refuses a severity the server does not have', () => {
    expect(incidentSchema.safeParse({ ...good, severity: 'critical' }).success).toBe(false)
    expect(incidentSchema.safeParse({ ...good, severity: 'medium' }).success).toBe(false)
  })

  it('counts the 20 characters AFTER trimming, as the server does', () => {
    expect(incidentSchema.safeParse({ ...good, detail: 'x'.repeat(DETAIL_MIN - 1) }).success).toBe(false)
    expect(incidentSchema.safeParse({ ...good, detail: 'x'.repeat(DETAIL_MIN) }).success).toBe(true)
    expect(incidentSchema.safeParse({ ...good, detail: `${'x'.repeat(DETAIL_MIN - 1)}          ` }).success).toBe(false)
    const short = incidentSchema.safeParse({ ...good, detail: 'too short' })
    expect(short.success ? '' : short.error.issues[0]?.message).toBe('Describe what happened in at least 20 characters.')
  })

  it('holds the account to 5000 and the location to 160; the location is required (M18C)', () => {
    expect(incidentSchema.safeParse({ ...good, detail: 'x'.repeat(DETAIL_MAX + 1) }).success).toBe(false)
    expect(incidentSchema.safeParse({ ...good, location: 'x'.repeat(LOCATION_MAX + 1) }).success).toBe(false)
    expect(incidentSchema.safeParse({ ...good, location: '' }).success).toBe(false)
    expect(incidentSchema.safeParse({ ...good, location: 'Back wall by Lot 63' }).success).toBe(true)
  })
})

describe('detailCount', () => {
  it('says how many more are needed, then simply counts', () => {
    expect(detailCount('')).toEqual({ count: 0, ok: false, label: '0 of 20 characters minimum: 20 more' })
    expect(detailCount('   Grey pickup   ')).toEqual({ count: 11, ok: false, label: '11 of 20 characters minimum: 9 more' })
    expect(detailCount('x'.repeat(20))).toEqual({ count: 20, ok: true, label: '20 characters' })
    expect(detailCount('x'.repeat(DETAIL_MAX + 1)).ok).toBe(false)
  })
})

describe('appendTranscript', () => {
  it('adds what was heard to what is written, never replacing it', () => {
    expect(appendTranscript('', '  grey pickup  ')).toBe('grey pickup')
    expect(appendTranscript('Grey pickup. ', 'two men   inside')).toBe('Grey pickup. two men inside')
    expect(appendTranscript('Grey pickup.', '   ')).toBe('Grey pickup.')
  })

  it("never grows past the server's limit", () => {
    expect(appendTranscript('x'.repeat(DETAIL_MAX - 2), 'more words')).toHaveLength(DETAIL_MAX)
  })
})

describe('reportBody: exactly what incidents.store takes', () => {
  const occurredAt = '2026-09-04T09:12:00-05:00'

  it('sends the server\'s fields and only those, dated by the moment of capture', () => {
    const body = reportBody({ values: { ...good, detail: `  ${good.detail}  ` }, fix: { latitude: 17.99, longitude: -76.79, accuracyM: 6 }, occurredAt })
    expect(body).toEqual({ kind: 'Suspicious activity', severity: 'med', detail: good.detail, location: 'Main Gate', latitude: 17.99, longitude: -76.79, occurred_at: occurredAt })
    expect(Object.keys(body).every((key) => ['kind', 'severity', 'detail', 'location', 'latitude', 'longitude', 'occurred_at'].includes(key))).toBe(true)
  })

  it('leaves out what is not known, no invented position, and always carries where it happened', () => {
    const body = reportBody({ values: { ...good, location: '  Main Gate  ' }, fix: null, occurredAt })
    expect(body).toEqual({ kind: 'Suspicious activity', severity: 'med', detail: good.detail, location: 'Main Gate', occurred_at: occurredAt })
  })

  it('drops a position that is not a position', () => {
    expect(reportBody({ values: good, fix: { latitude: 191, longitude: -76.79, accuracyM: null }, occurredAt })).not.toHaveProperty('latitude')
    expect(reportBody({ values: good, fix: { latitude: Number.NaN, longitude: 0, accuracyM: null }, occurredAt })).not.toHaveProperty('longitude')
  })

  it('will not build a report with no severity', () => {
    expect(() => reportBody({ values: { ...good, severity: '' }, fix: null, occurredAt })).toThrow()
  })
})

describe('mediaBody: multipart, one field: file', () => {
  it('names the part as the generated endpoint picks it', () => {
    expect(mediaBody({ uri: 'file:///cache/IMG_1.jpg', mime: 'image/jpeg', fileName: 'IMG_1.jpg' }, 0)).toEqual({ file: { uri: 'file:///cache/IMG_1.jpg', name: 'incident-photo-1.jpg', type: 'image/jpeg' } })
  })

  it('works the type out from the file when the picker does not say', () => {
    expect(mediaBody({ uri: 'file:///cache/shot.PNG', mime: null, fileName: null }, 1).file).toEqual({ uri: 'file:///cache/shot.PNG', name: 'incident-photo-2.png', type: 'image/png' })
    expect(mediaBody({ uri: 'file:///cache/shot.heic', mime: null, fileName: null }, 2).file.type).toBe('image/heic')
    expect(mediaBody({ uri: 'content://media/1234', mime: null, fileName: null }, 0).file).toEqual({ uri: 'content://media/1234', name: 'incident-photo-1.jpg', type: 'image/jpeg' })
  })

  it('never claims a type the server does not take', () => {
    expect(mediaBody({ uri: 'file:///cache/anim.gif', mime: 'image/gif', fileName: null }, 0).file.type).toBe('image/jpeg')
  })

  it('caps a report at five photos', () => {
    expect(PHOTO_MAX).toBe(5)
  })
})

describe('when and where a row says it happened', () => {
  it('writes the day in the site’s zone', () => {
    expect(whenLabel(at('2026-09-04', '09:12'), NOW)).toBe('Today, 9:12 AM')
    expect(whenLabel(at('2026-09-03', '18:40'), NOW)).toBe('Yesterday, 6:40 PM')
    expect(whenLabel(at('2026-09-02', '18:40'), NOW)).toBe('2 Sep 2026, 6:40 PM')
    // 02:30 UTC on the 5th is still 9:30 PM on the 4th in Kingston.
    expect(whenLabel('2026-09-05T02:30:00Z', new Date('2026-09-05T03:00:00Z'))).toBe('Today, 9:30 PM')
  })

  it("numbers a report as the board does, from the server's id", () => {
    expect(reference(337)).toBe('#IR-0337')
    expect(reference('12')).toBe('#IR-0012')
    expect(reference(104233)).toBe('#IR-104233')
  })

  it('"this week" is today and the six days before it', () => {
    expect(isThisWeek(at('2026-09-04', '00:05'), NOW)).toBe(true)
    expect(isThisWeek(at('2026-08-29', '23:50'), NOW)).toBe(true)
    expect(isThisWeek(at('2026-08-28', '23:50'), NOW)).toBe(false)
  })
})

describe('mergeReports', () => {
  const board = [
    item(337, 'Suspicious activity', 'open', at('2026-09-04', '09:12')),
    item(331, 'Equipment fault', 'resolved', at('2026-09-02', '18:40')),
    item(298, 'Noise complaint', 'resolved', at('2026-08-26', '23:40')),
    item(271, 'Trespass', 'resolved', at('2026-08-19', '22:05')),
  ]

  it("draws board 23: two sections, each a card, the server's statuses in the board's chips", () => {
    const rows = mergeReports({ items: board, ops: [], nowDate: NOW })
    expect(rows.map((r) => [r.heading, r.title, r.chip.label, r.meta, r.first, r.last])).toEqual([
      ['This week', 'Suspicious activity', 'Open', 'Today, 9:12 AM · #IR-0337', true, false],
      [null, 'Equipment fault', 'Resolved', '2 Sep 2026, 6:40 PM · #IR-0331', false, true],
      ['Earlier', 'Noise complaint', 'Resolved', '26 Aug 2026, 11:40 PM · #IR-0298', true, false],
      [null, 'Trespass', 'Resolved', '19 Aug 2026, 10:05 PM · #IR-0271', false, true],
    ])
    expect(rows.map((r) => r.icon)).toEqual(['qa-tile-3', 'rp-icon', 'qa-tile-3', 'qa-tile-3'])
    expect(rows.every((r) => !r.queued && r.note === null)).toBe(true)
    expect(STATUS_CHIP.open.tone).toBe('amber-500')
    expect(STATUS_CHIP.resolved.tone).toBe('green-400')
  })

  it('sorts newest first whatever order the list arrives in', () => {
    const rows = mergeReports({ items: [...board].reverse(), ops: [], nowDate: NOW })
    expect(rows.map((r) => r.key)).toEqual(['i:337', 'i:331', 'i:298', 'i:271'])
  })

  it("puts this phone's unsent reports on top: amber, marked Pending sync, opening the sync queue", () => {
    const waiting = op('incidents.store', 'pending', at('2026-09-04', '09:30'), { body: { kind: 'Fire', severity: 'high', detail: 'Smoke from the generator room, brigade called.', occurred_at: at('2026-09-04', '09:30') } })
    const sending = op('incidents.store', 'syncing', at('2026-09-04', '09:35'), { body: { kind: 'Break-in', detail: 'Rear window of Lot 12 forced open.' } })
    const rows = mergeReports({ items: board, ops: [waiting, sending], nowDate: NOW })
    expect(rows.slice(0, 2).map((r) => [r.heading, r.title, r.chip, r.queued, r.first, r.last])).toEqual([
      ['On this phone', 'Break-in', { label: 'Syncing', tone: 'amber-500' }, true, true, false],
      [null, 'Fire', { label: 'Pending sync', tone: 'amber-500' }, true, false, true],
    ])
    expect(rows[1]!.meta).toBe('Today, 9:30 AM · saved on this phone')
    expect(rows[1]!.detail).toBe('Smoke from the generator room, brigade called.')
    expect(rows[2]!.heading).toBe('This week')
    expect(rows).toHaveLength(6)
  })

  it("shows a refusal as Not accepted, with the server's own sentence", () => {
    const refused = op('incidents.store', 'refused', at('2026-09-04', '09:20'), { body: { kind: 'Trespass', detail: 'Man climbing the east wall near Lot 40.' }, error: { message: "An incident is reported after it happens. That time is in the future: check the handset's clock." } })
    const [row] = mergeReports({ items: [], ops: [refused], nowDate: NOW })
    expect(row!.chip).toEqual({ label: 'Not accepted', tone: 'red-500' })
    expect(row!.note).toEqual({ text: "An incident is reported after it happens. That time is in the future: check the handset's clock.", tone: 'red-500' })
    expect(row!.meta).toBe('Today, 9:20 AM · not filed')
    expect(row!.queued).toBe(true)
  })

  it('never draws a confirmed report twice once the list carries it', () => {
    const sent = op('incidents.store', 'confirmed', at('2026-09-04', '09:12'), { body: { kind: 'Suspicious activity' }, result: board[0] })
    const rows = mergeReports({ items: board, ops: [sent], nowDate: NOW })
    expect(rows.filter((r) => r.key === 'i:337')).toHaveLength(1)
    expect(rows).toHaveLength(4)
  })

  it("never lets a report vanish between sent and listed: the server's own answer stands in", () => {
    const answer = item(340, 'Fire', 'open', at('2026-09-04', '09:38'))
    const sent = op('incidents.store', 'confirmed', at('2026-09-04', '09:38'), { body: { kind: 'Fire' }, result: { ...answer, server_time: 'x', device_time: 'y', clock_skewed: false } })
    const rows = mergeReports({ items: board, ops: [sent], nowDate: NOW })
    expect(rows[0]).toMatchObject({ key: 'i:340', heading: 'This week', title: 'Fire', chip: { label: 'Open' }, meta: 'Today, 9:38 AM · #IR-0340', queued: false })
    expect(rows).toHaveLength(5)
  })

  it('ignores a confirmed act whose answer is not an incident', () => {
    const odd = op('incidents.store', 'confirmed', at('2026-09-04', '09:38'), { result: { ok: true } })
    expect(mergeReports({ items: [], ops: [odd], nowDate: NOW })).toEqual([])
  })

  it('says when photos are still waiting behind a report', () => {
    const waiting = op('incidents.store', 'pending', at('2026-09-04', '09:30'), { body: { kind: 'Fire', detail: 'Smoke from the generator room.' } })
    const sent = op('incidents.store', 'confirmed', at('2026-09-04', '09:12'), { result: board[0] })
    const ops = [
      waiting,
      op('incidents.media', 'pending', at('2026-09-04', '09:30'), { after: waiting.id }),
      op('incidents.media', 'pending', at('2026-09-04', '09:30'), { after: waiting.id }),
      sent,
      op('incidents.media', 'syncing', at('2026-09-04', '09:12'), { after: sent.id }),
      op('incidents.media', 'confirmed', at('2026-09-04', '09:12'), { after: sent.id }),
    ]
    const rows = mergeReports({ items: board, ops, nowDate: NOW })
    expect(rows[0]!.note).toEqual({ text: '2 photos will upload after the report.', tone: 'amber-500' })
    expect(rows.find((r) => r.key === 'i:337')!.note).toEqual({ text: '1 photo still waiting to upload.', tone: 'amber-500' })
  })

  it("shows the office's outcome once a report is resolved, and never for an open one", () => {
    const rows = mergeReports({
      items: [
        item(331, 'Equipment fault', 'resolved', at('2026-09-02', '18:40'), { resolution: ' Contractor replaced the sensor. ' }),
        item(337, 'Suspicious activity', 'open', at('2026-09-04', '09:12'), { resolution: 'stale text' }),
      ],
      ops: [],
      nowDate: NOW,
    })
    expect(rows.map((r) => r.outcome)).toEqual([null, 'Contractor replaced the sensor.'])
  })

  it('leaves other acts in the outbox alone', () => {
    expect(mergeReports({ items: [], ops: [op('shifts.clock_in', 'pending', at('2026-09-04', '07:00')), op('gate.entry', 'refused', at('2026-09-04', '08:00'))], nowDate: NOW })).toEqual([])
  })

  it('tolerates a null resolution from the server', () => {
    const rows = mergeReports({ items: [item(1, 'Fire', 'resolved', at('2026-09-04', '08:00'), { resolution: null as unknown as string })], ops: [], nowDate: NOW })
    expect(rows[0]!.outcome).toBeNull()
  })
})
