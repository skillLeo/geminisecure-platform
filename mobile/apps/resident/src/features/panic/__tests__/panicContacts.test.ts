/**
 * J-PANIC-1 — who the panic screen texts and calls, at every access level (household invites, G2).
 *
 * A Helper cannot read `me.household`, and panic is never taken from anybody: the household's emergency contacts
 * come from `panic.contacts`, which every level reads. An older server has no such endpoint, and the household's
 * own list is used, as before. Each person's phone once, as always (M01).
 */

import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { panicContactsOf } from '../panicSms'

const MARCIA = { name: 'Marcia Ahmed', relationship: 'Spouse', phone: '876-555-0148' }

describe('panicContactsOf', () => {
  it("gives a Helper the household's contacts from panic.contacts when there is no household to read", () => {
    const contacts = panicContactsOf([MARCIA], undefined)
    expect(contacts).toEqual([{ name: 'Marcia Ahmed', phone: '876-555-0148', relationship: 'Spouse' }])
    expect(contacts.length).toBeGreaterThan(0)
  })

  it('falls back to the household list when panic.contacts has never answered (an older server)', () => {
    expect(panicContactsOf(undefined, [MARCIA])).toHaveLength(1)
    expect(panicContactsOf(null, [MARCIA])).toHaveLength(1)
  })

  it('prefers panic.contacts when both are there: even an empty answer is the server\'s answer', () => {
    expect(panicContactsOf([], [MARCIA])).toEqual([])
    expect(panicContactsOf([{ name: 'Marlon', phone: '876 555 0192' }], [MARCIA]).map((c) => c.name)).toEqual(['Marlon'])
  })

  it('each phone once, and only numbers that can be dialled', () => {
    const contacts = panicContactsOf([MARCIA, { ...MARCIA, phone: '+1 876 555 0148' }, { name: 'Nobody', phone: '12' }, { name: 'None', phone: null }], undefined)
    expect(contacts.map((c) => c.name)).toEqual(['Marcia Ahmed'])
  })
})

describe('usePanic', () => {
  it("reads 'panic.contacts': the source every level has", () => {
    const source = readFileSync(join(__dirname, '..', 'usePanic.ts'), 'utf8')
    expect(source).toContain("'panic.contacts'")
    expect(source).toContain('panicContactsOf(')
  })
})
