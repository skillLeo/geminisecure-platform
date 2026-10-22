import { EMPTY_SNAPSHOT, residentDigest, type Feeds, type Snapshot } from '../residentDigest'

const AT = new Date('2026-09-04T14:41:00Z')
const approval = (id: number, by = '2026-09-04T14:43:00Z') => ({ id, visitor_name: 'Devon Clarke', post_name: 'Main Gate', respond_by: by })
const ALL = ['approvals', 'bookings', 'notices', 'passes', 'payments', 'tickets']

describe('the first look on a phone', () => {
  const feeds: Feeds = {
    approvals: [approval(7)],
    notices: [{ id: 1, title: 'Water off Tuesday', read_at: null }],
    tickets: [{ id: 5, number: 1042, status: 'in_progress' }],
    payments: [{ id: 9, status: 'recorded' }],
  }
  const { notes, next } = residentDigest(null, feeds, AT)

  it('announces nothing old: except someone at the gate right now', () => {
    expect(notes.map((n) => n.key)).toEqual(['approval:7'])
    expect(notes[0]).toMatchObject({ urgent: true, data: { type: 'approval_request', id: '7' } })
  })
  it('remembers ids and statuses only, never a name, a title or an amount', () => {
    expect(next).toEqual({ seen: ['approvals', 'notices', 'payments', 'tickets'], approvals: ['7'], notices: ['1'], tickets: { 5: 'in_progress' }, payments: ['9'], passes: {}, bookings: {} })
    expect(JSON.stringify(next)).not.toMatch(/Devon|Water|Main Gate/)
  })
})

describe('lists arrive one at a time', () => {
  it('a list seen for the FIRST time after the others announces nothing old', () => {
    // The household answered first; the notices arrive a second later. Six months of notices must stay silent.
    const afterHousehold = residentDigest(null, { approvals: [] }, AT).next
    const second = residentDigest(afterHousehold, { notices: [{ id: 1, title: 'old', read_at: null }, { id: 2, title: 'older', read_at: null }] }, AT)
    expect(second.notes).toEqual([])
    expect(second.next.seen).toEqual(['approvals', 'notices'])
    // …and from then on a NEW one is announced.
    const third = residentDigest(second.next, { notices: [{ id: 1, title: 'old', read_at: null }, { id: 2, title: 'older', read_at: null }, { id: 3, title: 'Gate closed Friday', read_at: null }] }, AT)
    expect(third.notes.map((n) => n.key)).toEqual(['notice:3'])
  })

  it('a snapshot written by an older version of the app (no `seen`) is treated as never seen: silent, not noisy', () => {
    const old = { approvals: [], notices: [], tickets: {}, payments: [], passes: {}, bookings: {} } as unknown as Snapshot
    expect(residentDigest(old, { notices: [{ id: 1, title: 'x', read_at: null }] }, AT).notes).toEqual([])
  })
})

describe('what changed since last time', () => {
  const before: Snapshot = { ...EMPTY_SNAPSHOT, seen: ALL, approvals: ['7'], notices: ['1'], tickets: { 5: 'submitted' }, payments: ['9'], passes: { 3: 'active', 4: 'active' }, bookings: { 2: 'pending' } }

  it('an approval already announced is not announced again; one whose time has run out is dropped', () => {
    expect(residentDigest(before, { approvals: [approval(7)] }, AT).notes).toEqual([])
    const late = residentDigest(before, { approvals: [approval(8, '2026-09-04T14:40:00Z')] }, AT)
    expect(late.notes).toEqual([])
    expect(late.next.approvals).toEqual([])
  })

  it('a new unread notice is its title; one already read elsewhere is silent', () => {
    const { notes } = residentDigest(before, { notices: [{ id: 1, title: 'old', read_at: null }, { id: 2, title: 'Gate maintenance\nSaturday', read_at: null }, { id: 3, title: 'seen', read_at: '2026-09-04T10:00:00Z' }] }, AT)
    expect(notes).toHaveLength(1)
    expect(notes[0]).toMatchObject({ key: 'notice:2', body: 'Gate maintenance Saturday', data: { id: '2' } })
  })

  it('a ticket that moved says its number and new status; a NEW ticket (their own) is silent', () => {
    const { notes } = residentDigest(before, { tickets: [{ id: 5, number: 1042, status: 'assigned' }, { id: 6, number: 1043, status: 'submitted' }] }, AT)
    expect(notes).toEqual([expect.objectContaining({ key: 'ticket:5:assigned', title: 'Request #1042', body: 'Assigned to a technician.' })])
  })

  it('a request says the consoles\' words (M98): "Under review", never Acknowledged; "Closed", never Verified', () => {
    const said = (status: string) => residentDigest(before, { tickets: [{ id: 5, number: 1042, status }] }, AT).notes[0]?.body
    expect(said('acknowledged')).toBe('Under review.')
    expect(said('verified')).toBe('Closed.')
  })

  it('a payment is "recorded", never an amount; a reversal is not announced as a payment', () => {
    const { notes } = residentDigest(before, { payments: [{ id: 9, status: 'recorded' }, { id: 10, status: 'recorded' }, { id: 11, status: 'reversed' }] }, AT)
    expect(notes.map((n) => n.key)).toEqual(['payment:10'])
    expect(JSON.stringify(notes)).not.toMatch(/\$|\d{3,}\.\d\d|JMD/)
  })

  it('a visitor let in is announced; the resident\'s own e-pass is not', () => {
    const { notes } = residentDigest(before, { passes: [{ id: 3, visitor_name: 'Aunt May', status: 'used', category: 'single' }, { id: 4, visitor_name: 'Andrea Fletcher', status: 'used', category: 'resident' }] }, AT)
    expect(notes.map((n) => n.key)).toEqual(['pass:3:used'])
  })

  it('a booking decision is said plainly', () => {
    const { notes } = residentDigest(before, { bookings: [{ id: 2, status: 'declined', amenity: { name: 'Gazebo' } }] }, AT)
    expect(notes[0]).toMatchObject({ body: 'Gazebo: not approved.', type: 'booking_decided' })
  })

  it('a feed that was not fetched this time keeps what was remembered', () => {
    const { next } = residentDigest(before, { notices: [] }, AT)
    expect(next.tickets).toEqual(before.tickets)
    expect(next.payments).toEqual(before.payments)
    expect(next.notices).toEqual([])
  })

  it('every payload is a type and an id, never a URL', () => {
    const { notes } = residentDigest(before, { approvals: [approval(12)], notices: [{ id: 2, title: 'x', read_at: null }] }, AT)
    for (const n of notes) { expect(Object.keys(n.data).sort()).toEqual(['id', 'type']); expect(n.data.type).toBe(n.type) }
  })
})
