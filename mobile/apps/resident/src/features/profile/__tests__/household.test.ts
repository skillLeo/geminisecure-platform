/**
 * The household's logic, held to the rules that matter on it:
 *   - the forms refuse exactly what the server would (a queued write's refusal carries no field errors);
 *   - a body carries the contract's fields and nothing else — no access level, no "" optionals, and the
 *     invite flag only with an email to send it to;
 *   - me.update sends only what CHANGED, and only the fields that resident is offered;
 *   - a change still on this phone is never drawn as if the server had it, and never drawn twice.
 */
import {
  contactBody, contactLine, contactRows, contactSchema, deniedSentence, detailsBody, detailsChange, detailsModeOf, detailsNameError, EMPTY_MEMBER, initialsOf, isPrimary, isSelf, memberBody, memberRows, memberSchema, memberTag,
  normalisePlate, plateAlreadyListed, relationshipLabel, RELATIONSHIPS, roleTag, sameDetails, sectionStatusOf, staleSentence, stalenessOf, unitLine, unsentSummary, vehicleBody, vehicleLine, vehicleRows, vehicleSchema, waitingCount,
  type HouseholdOp, type Member,
} from '../household'

const member = (over: Partial<Member>): Member => ({ id: 1, full_name: 'Andrea Fletcher', relationship: 'owner', is_primary: true, status: 'verified', phone: null, email: null, alerts_to_primary: false, ...over })
const op = (over: Partial<HouseholdOp>): HouseholdOp => ({ id: 'op-1', endpoint: 'members.store', body: { full_name: 'Marlon Fletcher', relationship: 'relative' }, state: 'pending', error: null, result: null, ...over })

describe('words', () => {
  it('draws the board: initials, the unit line, the vehicle and the contact', () => {
    expect(initialsOf('Andrea Fletcher')).toBe('AF')
    expect(initialsOf('  mary  jane  watson ')).toBe('MW')
    expect(initialsOf('Milo')).toBe('M')
    expect(initialsOf('')).toBe('?')
    expect(unitLine('Phoenix Park Village 1', { phase: 'Phase 2', reference: 'Lot 47' })).toBe('Phoenix Park Village 1 · Phase 2 · Lot 47')
    expect(unitLine('Phoenix Park Village 1', null)).toBe('Phoenix Park Village 1')
    // The session may remember no unit (`principal.reference` is null for a resident who never made a claim): never "null", never a dangling dot.
    expect(unitLine('Phoenix Park Village 1', { reference: null })).toBe('Phoenix Park Village 1')
    expect(unitLine('Phoenix Park Village 1', { phase: null, reference: 'Lot 47' })).toBe('Phoenix Park Village 1 · Lot 47')
    expect(unitLine(null, { reference: null })).toBe('')
    expect(unitLine(undefined, undefined)).toBe('')
    expect(vehicleLine({ plate: 'PC 4471', make: 'Honda', model: 'CR-V', colour: 'Grey' })).toBe('Grey Honda CR-V · PC 4471')
    expect(vehicleLine({ plate: 'PC 4471', make: null, model: null, colour: null })).toBe('PC 4471')
    // One phone format everywhere (M50 §6.11): the board's "876 555 0192" is drawn "+1 876 555 0192"; a number from
    // elsewhere written without its + is shown as written.
    expect(contactLine({ name: 'Marlon Fletcher', relationship: 'Brother', phone: '876 555 0192' })).toBe('Marlon Fletcher · Brother · +1 876 555 0192')
    expect(contactLine({ name: 'Bhai', relationship: 'Bro', phone: '876-555-0148' })).toBe('Bhai · Bro · +1 876 555 0148')
    expect(contactLine({ name: 'Bhai', relationship: 'Bro', phone: '03110000571' })).toBe('Bhai · Bro · 03110000571')
    expect(contactLine({ name: 'Marlon Fletcher', relationship: null, phone: '876 555 0192' })).toBe('Marlon Fletcher · +1 876 555 0192')
  })

  it('reads the server\'s boolean whatever shape it arrives in', () => {
    expect(isPrimary({ is_primary: true })).toBe(true)
    expect(isPrimary({ is_primary: 1 })).toBe(true)
    expect(isPrimary({ is_primary: '1' })).toBe(true)
    expect(isPrimary({ is_primary: false })).toBe(false)
    expect(isPrimary({ is_primary: '0' })).toBe(false)
    expect(isPrimary({ is_primary: '' })).toBe(false)
  })

  it('knows the row that is the person holding the phone, so it never offers to invite them (M19 §10.3)', () => {
    expect(isSelf(member({ id: 7 }), 7)).toBe(true)
    expect(isSelf(member({ id: 7 }), '7')).toBe(true)
    expect(isSelf(member({ id: 8 }), 7)).toBe(false)
    // No signed-in id (an older saved session): nobody is "you", so every other rule still applies.
    expect(isSelf(member({ id: 7 }), null)).toBe(false)
    expect(isSelf(member({ id: 7 }), undefined)).toBe(false)
    expect(isSelf(member({ id: 7 }), '')).toBe(false)
  })

  it('a member\'s pill is "Primary" or their access level, never their relationship (M47 §14.1)', () => {
    expect(memberTag(member({}))).toBe('Primary')
    expect(memberTag(member({ is_primary: false, relationship: 'spouse' }))).toBe('Member')
    expect(memberTag({ ...member({ is_primary: false, relationship: 'domestic_staff' }), access_level: 'helper' })).toBe('Helper')
    expect(roleTag(member({}))).toBe('Primary resident')
    expect(roleTag(member({ is_primary: false, relationship: 'tenant' }))).toBe('Tenant')
    expect(roleTag(null)).toBeNull()
    // A relationship this app does not know (the console may record its own) is shown as written.
    expect(relationshipLabel('house_sitter')).toBe('House sitter')
  })

  it('offers exactly the seven relationships members.store takes', () => {
    expect(RELATIONSHIPS.map((r) => r.value)).toEqual(['spouse', 'child', 'parent', 'relative', 'tenant', 'domestic_staff', 'other'])
  })
})

describe('add a household member', () => {
  // Nothing is optional (M18C, and the client, 27 Sep: "email and phone both are required when adding the household").
  const ok = { full_name: 'Marlon Fletcher', relationship: 'relative', phone: '876 555 0192', email: 'marlon@email.com' }

  it('refuses what the server would: a one-letter name, no relationship, a bad email', () => {
    expect(memberSchema.safeParse(ok).success).toBe(true)
    expect(memberSchema.safeParse({ ...ok, full_name: 'M' }).success).toBe(false)
    expect(memberSchema.safeParse({ ...ok, full_name: 'x'.repeat(161) }).success).toBe(false)
    expect(memberSchema.safeParse({ ...ok, relationship: '' }).success).toBe(false)
    expect(memberSchema.safeParse({ ...ok, relationship: 'owner' }).success).toBe(false) // the estate's to set, not the resident's
    expect(memberSchema.safeParse({ ...ok, email: 'marlon@' }).success).toBe(false)
    expect(memberSchema.safeParse({ ...ok, phone: '1'.repeat(41) }).success).toBe(false)
  })

  it('needs a phone and an email: both required, no tick that says there is none (M47 §15e, §15l, §15m)', () => {
    expect(memberSchema.safeParse({ ...ok, phone: '' }).success).toBe(false)
    expect(memberSchema.safeParse({ ...ok, email: '' }).success).toBe(false)
    expect(memberSchema.safeParse({ ...ok, email: '', no_email: true }).success).toBe(false)
    const refusal = memberSchema.safeParse({ ...ok, email: '' })
    expect(refusal.success).toBe(false)
    if (!refusal.success) expect(refusal.error.issues[0]?.message).toBe('Enter their email address: their invite to the app goes to it.')
    // A number from abroad is taken with its + — the client's own test number, typed the Pakistani way, is not Jamaican.
    expect(memberSchema.safeParse({ ...ok, phone: '+92 301 111 6259' }).success).toBe(true)
    expect(memberSchema.safeParse({ ...ok, phone: '03011116259' }).success).toBe(false)
  })

  it('sends the contract\'s fields and nothing else, the number in its one written form', () => {
    expect(memberBody({ ...ok, full_name: '  Marlon   Fletcher ' })).toEqual({ full_name: 'Marlon Fletcher', relationship: 'relative', phone: '+1 876 555 0192', email: 'marlon@email.com', invite: true })
    expect(memberBody({ ...ok, phone: ' 876-555-0192 ', email: ' Marlon@Email.com ' })).toEqual({ full_name: 'Marlon Fletcher', relationship: 'relative', phone: '+1 876 555 0192', email: 'marlon@email.com', invite: true })
    expect(Object.keys(memberBody(ok)).every((key) => ['full_name', 'relationship', 'phone', 'email', 'invite'].includes(key))).toBe(true)
    expect(() => memberBody({ ...ok, relationship: 'Brother' })).toThrow()
  })

  it('the invite goes to the email, says which, and never goes with a save made offline (D-158)', () => {
    expect(memberBody({ ...ok, invite: false })).toEqual({ full_name: 'Marlon Fletcher', relationship: 'relative', phone: '+1 876 555 0192', email: 'marlon@email.com', invite: false })
    expect(memberBody(ok).invite).toBe(true) // switched on, it goes
    expect(EMPTY_MEMBER.invite).toBe(false) // OFF until the resident turns it on (M52 §6) — the board draws it on
    expect(memberBody({ ...ok, ...EMPTY_MEMBER, full_name: 'Marlon Fletcher', relationship: 'relative', phone: '876 555 0192', email: 'marlon@email.com' }).invite).toBe(false)
    expect(memberBody({ ...ok, invite: true }, { online: false })).toEqual({ full_name: 'Marlon Fletcher', relationship: 'relative', phone: '+1 876 555 0192', email: 'marlon@email.com', invite: false })
  })
})

describe('add a vehicle', () => {
  const car = { plate: 'PC 4471', make: 'Honda', model: 'CR-V', colour: 'Grey' }

  it('a plate is letters, digits, spaces and hyphens, 16 at most', () => {
    expect(vehicleSchema.safeParse(car).success).toBe(true)
    expect(vehicleSchema.safeParse({ ...car, plate: 'pc-4471' }).success).toBe(true)
    expect(vehicleSchema.safeParse({ ...car, plate: '' }).success).toBe(false)
    expect(vehicleSchema.safeParse({ ...car, plate: 'PC*4471' }).success).toBe(false)
    expect(vehicleSchema.safeParse({ ...car, plate: 'A'.repeat(17) }).success).toBe(false)
    expect(vehicleSchema.safeParse({ ...car, colour: 'x'.repeat(25) }).success).toBe(false)
  })

  /** The client's instruction of 23 September 2026: a plate alone does not identify a car at a gate. */
  it('refuses a vehicle with no make, no model or no colour', () => {
    expect(vehicleSchema.safeParse({ ...car, make: '' }).success).toBe(false)
    expect(vehicleSchema.safeParse({ ...car, model: '' }).success).toBe(false)
    expect(vehicleSchema.safeParse({ ...car, colour: '' }).success).toBe(false)
    expect(vehicleSchema.safeParse({ ...car, make: '   ' }).success).toBe(false)

    const refusal = vehicleSchema.safeParse({ ...car, make: '' })
    expect(refusal.success).toBe(false)
    if (!refusal.success) expect(refusal.error.issues[0]?.message).toContain('make')
  })

  it('normalises the plate the way the server does, and knows one the household already has', () => {
    expect(normalisePlate('  pc   4471 ')).toBe('PC 4471')
    expect(vehicleBody({ plate: ' pc  4471', make: ' Honda ', model: 'CR-V', colour: ' Grey ' })).toEqual({ plate: 'PC 4471', make: 'Honda', model: 'CR-V', colour: 'Grey' })
    expect(plateAlreadyListed('pc 4471', [{ plate: 'PC 4471' }])).toBe(true)
    expect(plateAlreadyListed('pc 4472', [{ plate: 'PC 4471' }])).toBe(false)
    expect(plateAlreadyListed('', [{ plate: '' }])).toBe(false)
  })
})

describe('add an emergency contact', () => {
  it('needs a name, a relationship, a phone the server would take and an email', () => {
    expect(contactSchema.safeParse({ name: 'Marlon Fletcher', relationship: 'Brother', phone: '876 555 0192', email: 'marlon@example.com' }).success).toBe(true)
    expect(contactSchema.safeParse({ name: 'Marlon Fletcher', relationship: 'Brother', phone: '+1 (876) 555-0192', email: 'marlon@example.com' }).success).toBe(true)
    expect(contactSchema.safeParse({ name: 'Marlon Fletcher', relationship: 'Brother', phone: '876 555 0192' }).success).toBe(false)
    expect(contactSchema.safeParse({ name: 'Marlon Fletcher', relationship: '', phone: '' }).success).toBe(false)
    expect(contactSchema.safeParse({ name: 'Marlon Fletcher', relationship: '', phone: '55501' }).success).toBe(false)
    expect(contactSchema.safeParse({ name: 'Marlon Fletcher', relationship: 'Brother', phone: 'call me', email: 'marlon@example.com' }).success).toBe(false)
    expect(contactSchema.safeParse({ name: 'M', relationship: 'Brother', phone: '876 555 0192', email: 'marlon@example.com' }).success).toBe(false)
    // Nothing is optional (M18C): a relationship and an email as well as the number.
    expect(contactSchema.safeParse({ name: 'Marlon Fletcher', relationship: '', phone: '876 555 0192', email: 'marlon@example.com' }).success).toBe(false)
    expect(contactSchema.safeParse({ name: 'Marlon Fletcher', relationship: 'Brother', phone: '876 555 0192', email: '' }).success).toBe(false)
    expect(contactSchema.safeParse({ name: 'Marlon Fletcher', relationship: 'Brother', phone: '876 555 0192', email: 'marlon@example.com' }).success).toBe(true)
    // Stored in its one form, however it was typed.
    expect(contactBody({ name: ' Marlon  Fletcher ', relationship: ' Brother ', phone: ' 876-555-0192 ', email: ' marlon@example.com ' })).toEqual({ name: 'Marlon Fletcher', relationship: 'Brother', phone: '+1 876 555 0192', email: 'marlon@example.com' })
  })
})

describe('my own details (me.update)', () => {
  const current = { full_name: 'Andrea Fletcher', phone: '876 555 0148', email: 'andrea.fletcher@email.com' }

  it('a linked resident is offered phone and email; an unlinked account its own name', () => {
    expect(detailsModeOf({ resident: { id: 1 } })).toBe('register')
    expect(detailsModeOf({ resident: null })).toBe('account')
  })

  it('sends only what changed, and nothing at all when nothing did', () => {
    expect(detailsBody(current, current, 'register')).toBeNull()
    expect(detailsBody({ ...current, phone: '876 555 0199' }, current, 'register')).toEqual({ phone: '876 555 0199' })
    expect(detailsBody({ ...current, email: ' Andrea@New.com ' }, current, 'register')).toEqual({ email: 'andrea@new.com' })
    // Emptied: sent as "" — the server reads that as "none".
    expect(detailsBody({ ...current, phone: '' }, current, 'register')).toEqual({ phone: '' })
  })

  it('never sends a field that resident is not offered', () => {
    // The register's name is the estate's: a linked resident cannot send one, whatever the form holds.
    expect(detailsBody({ ...current, full_name: 'Andi' }, current, 'register')).toBeNull()
    // An unlinked account has nowhere to keep a phone or an email: the server would silently drop them.
    expect(detailsBody({ full_name: 'Andi Fletcher', phone: '876 555 0199', email: 'x@y.com' }, current, 'account')).toEqual({ full_name: 'Andi Fletcher' })
    expect(detailsNameError({ ...current, full_name: 'A' }, 'account')).toBe('Enter your full name.')
    expect(detailsNameError({ ...current, full_name: '' }, 'register')).toBeNull()
  })
})

describe('what a read can honestly say', () => {
  const denied = { status: 403 as const, code: 'account_pending' }
  const down = { status: 'network' as const, code: 'network_unreachable' }
  const slow = { status: 'timeout' as const, code: 'timeout' }
  const broken = { status: 500 as const, code: 'server_error' }

  it('a 403 outranks a saved copy: the server said "not for this account", not "no connection"', () => {
    expect(sectionStatusOf({ has: true, failed: true, failure: denied })).toBe('denied')
    expect(sectionStatusOf({ has: false, failed: true, failure: denied })).toBe('denied')
    // Any other failure keeps what this phone saved.
    expect(sectionStatusOf({ has: true, failed: true, failure: broken })).toBe('ready')
    expect(sectionStatusOf({ has: true, failed: true, failure: down })).toBe('ready')
    expect(sectionStatusOf({ has: false, failed: true, failure: broken })).toBe('error')
    expect(sectionStatusOf({ has: false, failed: true, failure: null })).toBe('error')
    expect(sectionStatusOf({ has: false, failed: false, failure: null })).toBe('loading')
  })

  it('"No connection" is said only when that is the reason', () => {
    expect(stalenessOf({ has: true, online: false, failures: [] })).toBe('offline')
    expect(stalenessOf({ has: true, online: true, failures: [down] })).toBe('offline')
    expect(stalenessOf({ has: true, online: true, failures: [slow, broken] })).toBe('offline')
    // The server was reached and failed: that is not "no connection".
    expect(stalenessOf({ has: true, online: true, failures: [broken] })).toBe('unrefreshed')
    expect(stalenessOf({ has: true, online: true, failures: [null] })).toBe('unrefreshed')
    // A 403 is an answer, not staleness; nothing saved means nothing stale; no failure means fresh.
    expect(stalenessOf({ has: true, online: true, failures: [denied] })).toBeNull()
    expect(stalenessOf({ has: false, online: false, failures: [down] })).toBeNull()
    expect(stalenessOf({ has: true, online: true, failures: [] })).toBeNull()

    expect(staleSentence('offline', 'household')).toMatch(/^No connection/)
    expect(staleSentence('offline', 'details')).toMatch(/^No connection/)
    expect(staleSentence('unrefreshed', 'household')).not.toMatch(/No connection/)
    expect(staleSentence('unrefreshed', 'details')).not.toMatch(/No connection/)
    expect(staleSentence(null, 'household')).toBeNull()
  })

  it('explains the one 403 it knows, and does not guess at any other', () => {
    expect(deniedSentence('account_pending')).toMatch(/not linked to a home/)
    expect(deniedSentence('forbidden')).not.toMatch(/not linked/)
    expect(deniedSentence(null)).toMatch(/estate office/)
  })
})

describe('signing out: what would be lost is named, not only counted', () => {
  it('the first few as they were saved, then how many more', () => {
    expect(unsentSummary([])).toEqual({ shown: [], more: 0 })
    expect(unsentSummary(['Vehicle · 1234 AB', '  Emergency contact ·  Pat  '])).toEqual({ shown: ['Vehicle · 1234 AB', 'Emergency contact · Pat'], more: 0 })
    expect(unsentSummary(['a', 'b', 'c', 'd', 'e'])).toEqual({ shown: ['a', 'b', 'c'], more: 2 })
    expect(unsentSummary(['a', '', '  '])).toEqual({ shown: ['a'], more: 0 })
  })
})

describe('my details are seeded from what the resident was shown', () => {
  const seed = { full_name: 'Andrea Fletcher', phone: '876 555 0148', email: 'andrea.fletcher@email.com' }

  it('knows when the server\'s answer really differs from the seed', () => {
    expect(sameDetails(seed, { ...seed })).toBe(true)
    expect(sameDetails(seed, { ...seed, phone: '876 555 0199' })).toBe(false)
    expect(sameDetails(null, seed)).toBe(false)
    expect(sameDetails(null, null)).toBe(true)
  })

  it('a field the resident never touched is never sent: even when the server has moved on since', () => {
    // The office corrected the phone after this phone saved its copy; the resident changes only the email.
    const typed = { ...seed, email: 'andrea@new.com' }
    expect(detailsBody(typed, seed, 'register')).toEqual({ email: 'andrea@new.com' })
  })
})

describe('what is still on this phone', () => {
  const server = [member({}), member({ id: 2, full_name: 'Jason Fletcher', relationship: 'spouse', is_primary: false })]

  it('a pending member is drawn from what was typed, marked as not sent: after the server\'s rows', () => {
    const rows = memberRows(server, [op({})])
    expect(rows.map((r) => [r.item.full_name, r.queue])).toEqual([['Andrea Fletcher', null], ['Jason Fletcher', null], ['Marlon Fletcher', 'pending']])
    expect(rows[2]?.opId).toBe('op-1')
    expect(rows[2]?.item.status).toBe('pending')
  })

  it('a refusal carries the server\'s sentence', () => {
    const rows = memberRows(server, [op({ state: 'refused', error: { message: 'Only the household holder can add members.' } })])
    expect(rows[2]?.queue).toBe('refused')
    expect(rows[2]?.refusal).toBe('Only the household holder can add members.')
  })

  it('a confirmed one is the server\'s answer: shown until the list catches up, and never twice', () => {
    const made = member({ id: 9, full_name: 'Marlon Fletcher', relationship: 'relative', is_primary: false, status: 'pending' })
    const before = memberRows(server, [op({ state: 'confirmed', result: made })])
    expect(before.map((r) => r.item.full_name)).toEqual(['Andrea Fletcher', 'Jason Fletcher', 'Marlon Fletcher'])
    expect(before[2]?.queue).toBeNull()
    const after = memberRows([...server, made], [op({ state: 'confirmed', result: made })])
    expect(after).toHaveLength(3)
    // An answer this app cannot read adds nothing.
    expect(memberRows(server, [op({ state: 'confirmed', result: null })])).toHaveLength(2)
  })

  it('each list takes only its own endpoint', () => {
    const ops = [op({}), op({ id: 'op-2', endpoint: 'vehicles.store', body: { plate: 'pc 4471', colour: 'Grey' } }), op({ id: 'op-3', endpoint: 'contacts.store', body: { name: 'Marlon Fletcher', phone: '876 555 0192' } })]
    expect(memberRows([], ops)).toHaveLength(1)
    expect(vehicleRows([], ops).map((r) => vehicleLine(r.item))).toEqual(['Grey · PC 4471'])
    // One phone format everywhere (M50 §6.11), however it was typed.
    expect(contactRows([], ops).map((r) => contactLine(r.item))).toEqual(['Marlon Fletcher · +1 876 555 0192'])
    expect(waitingCount(ops)).toBe(3)
    expect(waitingCount([op({ state: 'refused' }), op({ state: 'confirmed' }), op({ endpoint: 'notices.read' })])).toBe(0)
  })

  it('the newest unanswered change to my details is the one shown', () => {
    expect(detailsChange([])).toBeNull()
    expect(detailsChange([op({ endpoint: 'me.update', state: 'confirmed', body: { phone: '1' } })])).toBeNull()
    const found = detailsChange([op({ id: 'a', endpoint: 'me.update', body: { phone: '876 555 0199' } }), op({ id: 'b', endpoint: 'me.update', body: { email: 'new@email.com', access_level: 'admin' } })])
    expect(found).toEqual({ opId: 'b', state: 'pending', body: { email: 'new@email.com' }, refusal: null })
  })
})
