/**
 * The notices' logic, held to the rules that matter on it:
 *   - "today" and "yesterday" are days in JAMAICA, whatever zone the phone thinks it is in;
 *   - only the SERVER's answer is ever "read by the server" — a read queued on this phone is pending,
 *     and a refusal is shown and never queued again;
 *   - a chip is drawn only for something the server's list really contains.
 */
import { applyFilter, attachmentDetail, attachmentFolder, attachmentPath, attachmentsOf, bylineOf, dayKey, daysAgo, deniedSentence, filtersFor, findNotice, initialsOf, isUnread, kindOf, listStatusOf, mootRefusals, newestFirst, openSentence, openStateOfFailure, paragraphs, postedCard, postedDetail, postedFull, postedShort, previewOf, readStateOf, seenLine, seenOf, shouldQueueRead, sizeLabel, staleSentence, stalenessOf, tagLine, tagsOf, type ListFailure, type Notice, type ReadOp } from '../notices'

const AT = new Date('2026-09-04T09:41:00-05:00')

const notice = (over: Partial<Notice>): Notice => ({
  id: 1, kind: 'general', title: 'Water interruption', body: 'NWC work from 9am.', author_name: 'Delroy Samuels', posted_as_role: null, audience: 'Whole estate', published_at: '2026-09-04T08:15:00-05:00', read_at: null, ...over,
})

const op = (over: Partial<ReadOp>): ReadOp => ({ id: 'op-1', endpoint: 'notices.read', params: { notice: 1 }, state: 'pending', error: null, result: null, ...over })

describe('when a notice was posted', () => {
  // M19A §4.1: a recent notice in words that belong to no clock; past the estate's midnight, "yesterday" with the time;
  // older, the date in the one form (M98, the consoles' "9 Oct 2026"), never a tag after a time.
  it('the list line: "An hour ago", "Yesterday at 3:40 PM", "2 days ago", "21 Aug 2026"', () => {
    expect(postedShort('2026-09-04T08:15:00-05:00', AT)).toBe('An hour ago')
    expect(postedShort('2026-09-04T09:30:00-05:00', AT)).toBe('11 minutes ago')
    expect(postedShort('2026-09-03T15:40:00-05:00', AT)).toBe('Yesterday at 3:40 PM')
    expect(postedShort('2026-09-02T10:00:00-05:00', AT)).toBe('2 days ago')
    expect(postedShort('2026-08-21T10:00:00-05:00', AT)).toBe('21 Aug 2026')
    expect(postedShort('2025-12-24T10:00:00-05:00', AT)).toBe('24 Dec 2025')
  })

  it('the detail line: "An hour ago" · "Yesterday at 3:40 PM" · "21 Aug 2026, 7:05 AM"', () => {
    expect(postedFull('2026-09-04T08:15:00-05:00', AT)).toBe('An hour ago')
    expect(postedFull('2026-09-03T15:40:00-05:00', AT)).toBe('Yesterday at 3:40 PM')
    expect(postedFull('2026-08-21T07:05:00-05:00', AT)).toBe('21 Aug 2026, 7:05 AM')
  })

  it('the card and the notice: a day word or the date, a comma, the time (M98: one way to write a date and a time)', () => {
    expect(postedCard('2026-09-04T08:15:00-05:00', AT)).toBe('Today, 8:15 AM')
    expect(postedCard('2026-09-03T15:40:00-05:00', AT)).toBe('Yesterday, 3:40 PM')
    expect(postedCard('2026-09-02T10:00:00-05:00', AT)).toBe('2 days ago')
    expect(postedCard('2026-08-21T10:00:00-05:00', AT)).toBe('21 Aug 2026')
    expect(postedDetail('2026-09-04T08:15:00-05:00', AT)).toBe('Today, 8:15 AM')
    expect(postedDetail('2026-08-21T07:05:00-05:00', AT)).toBe('21 Aug 2026, 7:05 AM')
  })

  it('counts days on the KINGSTON calendar: 11:30 PM last night in UTC terms is still "today" there', () => {
    // 03:30 UTC on the 4th is 10:30 PM on the 3rd in Jamaica.
    expect(daysAgo('2026-09-04T03:30:00Z', AT)).toBe(1)
    expect(daysAgo('2026-09-04T05:00:00Z', AT)).toBe(0)
    // A time the phone's clock has not reached yet never reads as "-1 days ago": it is its own day and time.
    expect(postedShort('2026-09-05T00:10:00-05:00', AT)).toBe('5 Sep 2026, 12:10 AM')
  })

  it('sorts newest first without touching the server\'s array', () => {
    const items = [notice({ id: 1, published_at: '2026-09-01T09:00:00-05:00' }), notice({ id: 2, published_at: '2026-09-04T09:00:00-05:00' })]
    expect(newestFirst(items).map((n) => n.id)).toEqual([2, 1])
    expect(items.map((n) => n.id)).toEqual([1, 2])
  })

  it('breaks a tie on the SERVER\'s rule, same second, higher id first, so pages join up', () => {
    /*
     * The list pages now (`before` / `next_before`), and the server orders `published_at DESC, id DESC`.
     * Sorted by time alone, two notices posted in the same second could swap places depending on which
     * page each arrived in, and the join between two pages would look like rows moving about.
     */
    const sameSecond = '2026-09-04T09:00:00-05:00'
    const items = [
      notice({ id: 7, published_at: sameSecond }),
      notice({ id: 9, published_at: sameSecond }),
      notice({ id: 8, published_at: sameSecond }),
      notice({ id: 3, published_at: '2026-09-01T09:00:00-05:00' }),
    ]
    expect(newestFirst(items).map((n) => n.id)).toEqual([9, 8, 7, 3])
    // Whichever order the two pages hand them over in, the list settles the same way.
    expect(newestFirst([...items].reverse()).map((n) => n.id)).toEqual([9, 8, 7, 3])
  })
})

describe('who posted it, and what it says', () => {
  it('the role where one was recorded, the person otherwise, never both', () => {
    expect(bylineOf(notice({ posted_as_role: 'Property Manager' }))).toBe('Property Manager')
    expect(bylineOf(notice({ posted_as_role: null }))).toBe('Delroy Samuels')
    expect(bylineOf(notice({ posted_as_role: '  ' }))).toBe('Delroy Samuels')
    expect(initialsOf('Property Manager')).toBe('PM')
    expect(initialsOf('andrea  fletcher-brown smith')).toBe('AF')
  })

  it('pills are the kind and the audience: no topic is invented', () => {
    expect(tagsOf(notice({ kind: 'urgent' }))).toEqual([{ label: 'Urgent', urgent: true }, { label: 'Whole estate', urgent: false }])
    expect(tagsOf(notice({ audience: 'Phase 2' }))).toEqual([{ label: 'Phase 2', urgent: false }])
    expect(tagLine(notice({ kind: 'urgent', audience: 'Phase 2' }))).toBe('Urgent · Phase 2')
  })

  it('a blank line starts a paragraph; the card gets one run of text', () => {
    expect(paragraphs('One.\r\n\r\nTwo,\nstill two.\n\n\n  Three.  ')).toEqual(['One.', 'Two,\nstill two.', 'Three.'])
    expect(paragraphs('   ')).toEqual([])
    expect(previewOf('One.\n\nTwo,\n still two.')).toBe('One. Two, still two.')
  })
})

describe('read and unread', () => {
  it('the server\'s read_at is the server\'s word', () => {
    expect(readStateOf(notice({ read_at: '2026-09-04T09:00:00-05:00' }), [])).toEqual({ kind: 'server', at: '2026-09-04T09:00:00-05:00', seen: null })
    expect(isUnread(notice({ read_at: '2026-09-04T09:00:00-05:00' }), [])).toBe(false)
  })

  it('a read queued on this phone is PENDING, never "read by the server", though the dot leaves the card', () => {
    expect(readStateOf(notice({}), [op({})])).toEqual({ kind: 'pending' })
    expect(readStateOf(notice({}), [op({ state: 'syncing' })])).toEqual({ kind: 'syncing' })
    expect(isUnread(notice({}), [op({})])).toBe(false)
  })

  it('once the server answers the queued read, that answer is the server\'s word', () => {
    expect(readStateOf(notice({}), [op({ state: 'confirmed', result: { notice_id: 1, read_at: '2026-09-04T09:41:10-05:00' } })])).toEqual({ kind: 'server', at: '2026-09-04T09:41:10-05:00', seen: null })
    expect(readStateOf(notice({}), [op({ state: 'confirmed', result: null })])).toEqual({ kind: 'server', at: null, seen: null })
  })

  it('a refusal is shown with the server\'s sentence, keeps the dot, and is never queued again', () => {
    const refused = op({ state: 'refused', error: { message: 'No notice for your household with that id.' } })
    expect(readStateOf(notice({}), [refused])).toEqual({ kind: 'refused', message: 'No notice for your household with that id.' })
    expect(isUnread(notice({}), [refused])).toBe(true)
    expect(shouldQueueRead(notice({}), [refused])).toBe(false)
  })

  it('a read is queued once per notice, and never for one the server already has', () => {
    expect(shouldQueueRead(notice({}), [])).toBe(true)
    expect(shouldQueueRead(notice({}), [op({})])).toBe(false)
    expect(shouldQueueRead(notice({}), [op({ params: { notice: '1' } })])).toBe(false)
    expect(shouldQueueRead(notice({ id: 2 }), [op({})])).toBe(true)
    expect(shouldQueueRead(notice({ read_at: '2026-09-04T09:00:00-05:00' }), [])).toBe(false)
    // Another kind of act about "1" is not a notice read.
    expect(shouldQueueRead(notice({}), [op({ endpoint: 'meetings.rsvp', params: { notice: 1 } })])).toBe(true)
  })
})

describe('the chips', () => {
  it('only what the list really contains', () => {
    expect(filtersFor([], []).map((f) => f.key)).toEqual(['all'])
    expect(filtersFor([notice({ read_at: 'x' })], []).map((f) => f.key)).toEqual(['all'])
    expect(filtersFor([notice({}), notice({ id: 2, kind: 'urgent', read_at: 'x' })], []).map((f) => f.label)).toEqual(['All', 'Unread', 'Urgent'])
  })

  it('audiences become chips only when the household sees more than one', () => {
    const items = [notice({ read_at: 'x' }), notice({ id: 2, read_at: 'x', audience: 'Phase 2' })]
    expect(filtersFor(items, []).map((f) => f.label)).toEqual(['All', 'Whole estate', 'Phase 2'])
    expect(applyFilter(items, 'audience:Phase 2', []).map((n) => n.id)).toEqual([2])
  })

  it('"Unread" follows this phone\'s mirror: a notice opened here leaves it', () => {
    const items = [notice({}), notice({ id: 2 })]
    expect(applyFilter(items, 'unread', [op({})]).map((n) => n.id)).toEqual([2])
    expect(applyFilter(items, 'urgent', [])).toEqual([])
    expect(applyFilter(items, 'all', []).length).toBe(2)
    expect(applyFilter(items, 'board:Security', []).length).toBe(2)
  })

  it('finds a notice by the id in the URL, which is always a string', () => {
    expect(findNotice('2', [notice({}), notice({ id: 2 })])?.id).toBe(2)
    expect(findNotice('9', [notice({})])).toBeNull()
    expect(findNotice(undefined, [notice({})])).toBeNull()
  })
})

describe('what the phone is showing, and why', () => {
  const failure = (status: NonNullable<ListFailure>['status'], code = 'x'): ListFailure => ({ status, code, message: 'The server\'s sentence.' })

  it('a 403 is the server\'s latest word: it outranks a list saved on the phone', () => {
    expect(listStatusOf({ hasList: true, failed: true, failure: failure(403, 'account_pending') })).toBe('denied')
    expect(listStatusOf({ hasList: false, failed: true, failure: failure(403) })).toBe('denied')
  })

  it('any other failure leaves a saved list on screen; with nothing saved it is an error, and before any answer it is loading', () => {
    expect(listStatusOf({ hasList: true, failed: true, failure: failure(500) })).toBe('ready')
    expect(listStatusOf({ hasList: true, failed: true, failure: failure('network') })).toBe('ready')
    expect(listStatusOf({ hasList: false, failed: true, failure: failure(500) })).toBe('error')
    expect(listStatusOf({ hasList: false, failed: true, failure: null })).toBe('error')
    expect(listStatusOf({ hasList: false, failed: false, failure: null })).toBe('loading')
    expect(listStatusOf({ hasList: true, failed: false, failure: null })).toBe('ready')
  })

  it('explains only the 403 it knows the cause of', () => {
    expect(deniedSentence('account_pending')).toMatch(/not linked to a home/)
    expect(deniedSentence('forbidden')).toMatch(/Ask your estate office/)
    expect(deniedSentence('forbidden')).not.toMatch(/claim/)
  })

  it('"No connection" is said only when that is the reason: a server that answered with a failure is not "no connection"', () => {
    expect(stalenessOf({ hasList: true, online: false, failed: false, failure: null })).toBe('offline')
    expect(stalenessOf({ hasList: true, online: true, failed: true, failure: failure('network') })).toBe('offline')
    expect(stalenessOf({ hasList: true, online: true, failed: true, failure: failure('timeout') })).toBe('offline')
    expect(stalenessOf({ hasList: true, online: true, failed: true, failure: failure(500) })).toBe('unrefreshed')
    expect(stalenessOf({ hasList: true, online: true, failed: true, failure: failure(429) })).toBe('unrefreshed')
    expect(stalenessOf({ hasList: true, online: true, failed: false, failure: null })).toBeNull()
    expect(stalenessOf({ hasList: false, online: false, failed: true, failure: failure('network') })).toBeNull()

    expect(staleSentence('offline', 'list')).toMatch(/^No connection/)
    expect(staleSentence('offline', 'notice')).toMatch(/^No connection/)
    expect(staleSentence('unrefreshed', 'list')).not.toMatch(/No connection/)
    expect(staleSentence('unrefreshed', 'notice')).not.toMatch(/No connection/)
    expect(staleSentence(null, 'list')).toBeNull()
  })

  it('the day key is the KINGSTON day: it turns at midnight there, not at midnight UTC', () => {
    expect(dayKey(new Date('2026-09-04T23:59:00-05:00'))).toBe(dayKey(new Date('2026-09-04T00:01:00-05:00')))
    expect(dayKey(new Date('2026-09-05T00:01:00-05:00'))).not.toBe(dayKey(new Date('2026-09-04T23:59:00-05:00')))
    // 03:30 UTC on the 5th is still the 4th in Jamaica.
    expect(dayKey(new Date('2026-09-05T03:30:00Z'))).toBe(dayKey(new Date('2026-09-04T12:00:00-05:00')))
  })

  it('a refused read leaves the queue only once the server\'s fresh list makes it moot', () => {
    const refused = op({ id: 'r-1', state: 'refused', error: { message: 'No notice for your household with that id.' } })
    // Still listed and still unread: the refusal has something to say, and stays.
    expect(mootRefusals([notice({})], [refused])).toEqual([])
    // Withdrawn — no longer in the list.
    expect(mootRefusals([notice({ id: 2 })], [refused])).toEqual(['r-1'])
    // The server has it as read after all.
    expect(mootRefusals([notice({ read_at: '2026-09-04T09:00:00-05:00' })], [refused])).toEqual(['r-1'])
    // A read that is waiting, being sent or confirmed is never touched; nor is another kind of act.
    expect(mootRefusals([], [op({ id: 'p' }), op({ id: 's', state: 'syncing' }), op({ id: 'c', state: 'confirmed' }), op({ id: 'o', state: 'refused', endpoint: 'meetings.rsvp' })])).toEqual([])
  })
})

describe('"seen by 212 of 450 households": the server\'s count, never the phone\'s', () => {
  it('prints the board\'s words from the server\'s two figures', () => {
    expect(seenLine(seenOf({ seen_households: 212, audience_households: 450 })!)).toBe('seen by 212 of 450 households')
    expect(seenLine({ seen: 1, audience: 1 })).toBe('seen by 1 of 1 household')
    expect(seenLine({ seen: 0, audience: 1240 })).toBe('seen by 0 of 1,240 households')
  })

  it('prints nothing it cannot stand behind: no count sent, nobody to see it, or figures that cannot both be true', () => {
    expect(seenOf({})).toBeNull() // a list saved by a build from before the server sent a count
    expect(seenOf({ seen_households: 3 })).toBeNull()
    expect(seenOf({ seen_households: 0, audience_households: 0 })).toBeNull()
    expect(seenOf({ seen_households: 9, audience_households: 4 })).toBeNull()
    expect(seenOf({ seen_households: -1, audience_households: 4 })).toBeNull()
    expect(seenOf({ seen_households: 1.5, audience_households: 4 })).toBeNull()
    expect(seenOf({ seen_households: '212', audience_households: '450' })).toEqual({ seen: 212, audience: 450 })
  })

  it('a read the server has just confirmed carries ITS count, this household included, over the older list\'s', () => {
    const listed = notice({ seen_households: 211, audience_households: 450 })
    const confirmed = op({ state: 'confirmed', result: { notice_id: 1, read_at: '2026-09-04T09:41:10-05:00', seen_households: 212, audience_households: 450 } })
    expect(readStateOf(listed, [confirmed])).toEqual({ kind: 'server', at: '2026-09-04T09:41:10-05:00', seen: { seen: 212, audience: 450 } })
    // An answer from before the server counted falls back to the list's figure; the server's own `read_at` uses the list's.
    expect(readStateOf(listed, [op({ state: 'confirmed', result: { notice_id: 1, read_at: '2026-09-04T09:41:10-05:00' } })])).toMatchObject({ seen: { seen: 211, audience: 450 } })
    expect(readStateOf(notice({ read_at: '2026-09-04T09:00:00-05:00', seen_households: 212, audience_households: 450 }), [])).toMatchObject({ kind: 'server', seen: { seen: 212, audience: 450 } })
    // A read still on this phone is never dressed up with a count.
    expect(readStateOf(listed, [op({ state: 'pending' })])).toEqual({ kind: 'pending' })
  })
})

describe('the files the office put with a notice', () => {
  const pdf = { id: 9, name: 'Gate2_Contractor_Notice.pdf', content_type: 'application/pdf', bytes: 245_760 }

  it('lists only what is really a file, and nothing for a list saved before the server sent any', () => {
    expect(attachmentsOf(notice({}))).toEqual([])
    expect(attachmentsOf(notice({ attachments: [pdf] }))).toEqual([pdf])
    expect(attachmentsOf(notice({ attachments: [pdf, { id: 3, name: '  ', content_type: 'application/pdf', bytes: 1 }, null as never] }))).toEqual([pdf])
    expect(attachmentsOf({ attachments: 'nope' as never })).toEqual([])
  })

  it('says what it is and how big, in a homeowner\'s words', () => {
    expect(attachmentDetail(pdf)).toBe('PDF · 240 KB')
    expect(kindOf({ content_type: 'image/jpeg', name: 'map.jpg' })).toBe('Picture')
    expect(kindOf({ content_type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', name: 'letter.docx' })).toBe('Word document')
    expect(kindOf({ content_type: 'application/octet-stream', name: 'letter.PDF' })).toBe('PDF')
    expect(kindOf({ content_type: '', name: 'thing' })).toBe('File')
    expect(sizeLabel(512)).toBe('512 bytes')
    expect(sizeLabel(1_572_864)).toBe('1.5 MB')
    expect(sizeLabel(10_485_760)).toBe('10 MB')
    expect(sizeLabel(0)).toBeNull()
    expect(sizeLabel(undefined)).toBeNull()
    expect(attachmentDetail({ ...pdf, bytes: 0 })).toBe('PDF')
  })

  it('is fetched from the API by id, never from an address in the list, and kept one folder per file', () => {
    expect(attachmentPath(4, pdf)).toBe('/notices/4/attachments/9')
    expect(attachmentPath('4/../5', { id: '9?x=1' })).toBe('/notices/4%2F..%2F5/attachments/9%3Fx%3D1')
    expect(attachmentFolder(4, pdf)).toBe('notice-4-9')
  })

  it('every way opening can fail has its own plain sentence, and "opened" claims nothing', () => {
    expect(openStateOfFailure({ status: 'network', message: 'x' })).toEqual({ kind: 'offline' })
    expect(openStateOfFailure({ status: 'timeout', message: 'x' })).toEqual({ kind: 'offline' })
    expect(openStateOfFailure({ status: 404, message: 'No attachment for your household with that id.' })).toEqual({ kind: 'refused', message: 'No attachment for your household with that id.' })
    expect(openStateOfFailure({ status: 500, message: 'x' })).toEqual({ kind: 'failed' })

    expect(openSentence({ kind: 'idle' })).toBeNull()
    expect(openSentence({ kind: 'opened' })).toBeNull()
    expect(openSentence({ kind: 'offline' })?.text).toMatch(/^No connection/)
    expect(openSentence({ kind: 'refused', message: 'No attachment for your household with that id.' })?.text).toBe('Not available · No attachment for your household with that id.')
    expect(openSentence({ kind: 'unsupported' })?.text).toMatch(/on your phone/)
    expect(openSentence({ kind: 'noViewer' })?.text).toMatch(/no app that can open or share it/)
    // Every one of them is an ordinary problem: amber, never a panic's red (M98).
    expect(openSentence({ kind: 'failed' })?.tone).toBe('amber-700')
    expect(openSentence({ kind: 'refused', message: 'x' })?.tone).toBe('amber-700')
  })
})
