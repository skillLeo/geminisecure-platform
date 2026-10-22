import { chosen, demoEstate, estateLabel, estateNote, estateOptions, homeEstates, initialEstate, pickerMode } from '../estates'

const phoenix = { id: 'phoenixpark', name: 'Phoenix Park Village 1', parish: 'St. Andrew', demo: false }
const cedar = { id: 'cedargrove', name: 'Cedar Grove', parish: null, demo: false }
const demo = { id: 'demoestate', name: 'Seaview Demo Estate (demonstration data)', parish: 'St. Ann', demo: true }

describe('the estate pick-list', () => {
  it('cleans what the server sent: nameless rows go, the rest sort by name', () => {
    expect(estateOptions([phoenix, { id: '', name: 'No id' }, { id: 'x', name: ' ' }, cedar])).toEqual([cedar, phoenix])
    expect(estateOptions(null)).toEqual([])
    expect(estateOptions(undefined)).toEqual([])
  })

  it('reads `demo` as the server sends it, and treats anything else as not a demonstration', () => {
    expect(estateOptions([{ id: 'd', name: 'Demo', demo: true }])[0]?.demo).toBe(true)
    // A missing field, a string, a 1 — none of them makes a real estate a demonstration one.
    expect(estateOptions([{ id: 'a', name: 'A' }])[0]?.demo).toBe(false)
    expect(estateOptions([{ id: 'b', name: 'B', demo: 'true' }])[0]?.demo).toBe(false)
    expect(estateOptions([{ id: 'c', name: 'C', demo: 1 }])[0]?.demo).toBe(false)
  })

  it('labels an estate with its parish, so two of one name can be told apart', () => {
    expect(estateLabel(phoenix)).toBe('Phoenix Park Village 1 · St. Andrew')
    expect(estateLabel(cedar)).toBe('Cedar Grove')
  })

  it('with ONE active estate, the pilot, it is chosen already', () => {
    expect(initialEstate([phoenix], null)).toBe('phoenixpark')
    expect(initialEstate([phoenix, cedar], null)).toBe('')
  })

  it('keeps the estate this phone remembered, if it is still listed', () => {
    expect(initialEstate([phoenix, cedar], 'CedarGrove ')).toBe('cedargrove')
    expect(initialEstate([phoenix, cedar], 'closedestate')).toBe('')
  })

  it('shows the list when there is one, and the typed code only as the fallback', () => {
    expect(pickerMode([phoenix], '', false)).toBe('list')
    expect(pickerMode([phoenix], 'phoenixpark', false)).toBe('list')
    expect(pickerMode([], '', false)).toBe('code') // offline, failed, or empty
    expect(pickerMode([phoenix], '', true)).toBe('code') // "my estate isn't listed"
    expect(pickerMode([phoenix], 'somewhereelse', false)).toBe('code') // a code already typed that the list does not hold is never thrown away
  })

  it('finds the chosen estate whatever the case it was typed in', () => {
    expect(chosen([phoenix, cedar], ' PhoenixPark')).toEqual(phoenix)
    expect(chosen([phoenix], 'nowhere')).toBeNull()
  })
})

describe('the demonstration community', () => {
  it('finds the one the server flagged, and says so plainly under its name', () => {
    expect(demoEstate([phoenix, demo, cedar])).toEqual(demo)
    expect(demoEstate([phoenix, cedar])).toBeNull()
    expect(estateNote(demo)).toBe('Demonstration community: invented data, nobody lives here')
    expect(estateNote(phoenix)).toBe('St. Andrew')
    expect(estateNote(cedar)).toBeNull()
  })

  it('NEVER lands a resident in it: with one real estate and the demonstration, the real one is chosen', () => {
    // Without this, the pilot platform — one real estate plus the demonstration — is two
    // options, nothing is chosen for anybody, and every resident is made to pick.
    expect(initialEstate([phoenix, demo], null)).toBe('phoenixpark')
    // And a platform that is ONLY the demonstration chooses nothing at all.
    expect(initialEstate([demo], null)).toBe('')
    expect(homeEstates([phoenix, demo, cedar])).toEqual([phoenix, cedar])
  })

  it('is still pickable by hand: a reviewer signs in to it with a real account', () => {
    // It stays in the list `pickerMode` and `chosen` see, so the static credentials in
    // STORE_REVIEW_NOTES.md work without anybody typing a community code.
    expect(pickerMode([phoenix, demo], 'demoestate', false)).toBe('list')
    expect(chosen([phoenix, demo], 'DemoEstate ')).toEqual(demo)
  })
})
