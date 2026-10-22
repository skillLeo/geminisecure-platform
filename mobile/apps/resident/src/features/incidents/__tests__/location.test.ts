/**
 * The pin on a resident's security report (board resident-app-14, "Pinned from current location"): read once when
 * the screen opens; the phone's dialog only after the Permissions screen's disclosure; the board's words only for a
 * pin that is there; a name for it in words, never degrees (M47 §13.3); and a refusal that never blocks the report.
 */
jest.mock('expo-location', () => ({
  getForegroundPermissionsAsync: jest.fn(),
  requestForegroundPermissionsAsync: jest.fn(),
  getCurrentPositionAsync: jest.fn(),
  reverseGeocodeAsync: jest.fn(),
  Accuracy: { Balanced: 3 },
}))

import * as Location from 'expo-location'
import { HERE, PINNED, namePin, pinLine, pinLocation, placeOf } from '../location'

const look = Location.getForegroundPermissionsAsync as unknown as jest.Mock
const ask = Location.requestForegroundPermissionsAsync as unknown as jest.Mock
const read = Location.getCurrentPositionAsync as unknown as jest.Mock
const geocode = Location.reverseGeocodeAsync as unknown as jest.Mock

const fix = (over: Record<string, unknown> = {}) => ({ coords: { latitude: 18.0179123, longitude: -76.8099456, accuracy: 12.4, ...over } })
const permission = (granted: boolean, canAskAgain = true) => ({ granted, canAskAgain, status: granted ? 'granted' : 'undetermined' })
const PIN = { latitude: 18.0179, longitude: -76.8099, accuracyM: 12 }

beforeEach(() => {
  look.mockReset()
  ask.mockReset()
  read.mockReset()
  geocode.mockReset()
})

describe('one reading, and no watcher', () => {
  it('offers one read and no way to start another', () => {
    expect(Object.keys(require('../location')).sort()).toEqual(['FIX_TIMEOUT_MS', 'HERE', 'NAME_TIMEOUT_MS', 'PINNED', 'namePin', 'pinLine', 'pinLocation', 'placeOf'])
  })

  it('with the permission held, reads once and pins the place and how wide the circle is: no dialog', async () => {
    look.mockResolvedValue(permission(true))
    read.mockResolvedValue(fix())

    const state = await pinLocation({ mayAsk: true })

    expect(state).toEqual({ state: 'attached', fix: { latitude: 18.0179123, longitude: -76.8099456, accuracyM: 12 } })
    expect(read).toHaveBeenCalledTimes(1)
    expect(ask).not.toHaveBeenCalled()
    expect(pinLine(state)).toBe('Pinned from current location')
  })

  it('a phone that gives no accuracy still gives a place', async () => {
    look.mockResolvedValue(permission(true))
    read.mockResolvedValue(fix({ accuracy: null }))
    expect(await pinLocation({ mayAsk: false })).toEqual({ state: 'attached', fix: { latitude: 18.0179123, longitude: -76.8099456, accuracyM: null } })
  })
})

describe('the phone\'s dialog only after the disclosure', () => {
  it('after the Permissions screen, a permission not yet given is asked for, then read once', async () => {
    look.mockResolvedValue(permission(false))
    ask.mockResolvedValue(permission(true))
    read.mockResolvedValue(fix())

    expect((await pinLocation({ mayAsk: true })).state).toBe('attached')
    expect(ask).toHaveBeenCalledTimes(1)
    expect(read).toHaveBeenCalledTimes(1)
  })

  it('before the Permissions screen, nothing is asked: the permission the phone holds is only looked at', async () => {
    look.mockResolvedValue(permission(false))

    const state = await pinLocation({ mayAsk: false })

    expect(state).toEqual({ state: 'refused', why: 'permission' })
    expect(ask).not.toHaveBeenCalled()
    expect(read).not.toHaveBeenCalled()
  })

  it('a permission the phone will not ask for again is not asked for', async () => {
    look.mockResolvedValue({ granted: false, canAskAgain: false, status: 'denied' })
    expect(await pinLocation({ mayAsk: true })).toEqual({ state: 'refused', why: 'permission' })
    expect(ask).not.toHaveBeenCalled()
  })
})

describe('the row says only what is true', () => {
  it('uses the board\'s words for a pin that is there, and never for one that is not', () => {
    expect(PINNED).toBe('Pinned from current location')
    expect(pinLine({ state: 'finding' })).toBe('Finding where you are…')
    for (const why of ['permission', 'no_fix', 'unavailable'] as const) expect(pinLine({ state: 'refused', why })).not.toBe(PINNED)
    expect(pinLine({ state: 'refused', why: 'permission' })).toBe('Location is off for this app')
    expect(pinLine({ state: 'idle' })).not.toBe(PINNED)
  })

})

describe('the pin has a name, never degrees (M47 §13.3)', () => {
  it('a place’s own name, else the street and its number, then the district or town', () => {
    expect(placeOf({ name: 'Hope Gardens', street: 'Old Hope Road', streetNumber: null, district: 'Liguanea', city: 'Kingston' })).toBe('Hope Gardens, Liguanea')
    expect(placeOf({ name: '12', street: 'Palm Drive', streetNumber: '12', district: null, subregion: null, city: 'Portmore' })).toBe('12 Palm Drive, Portmore')
    // Where Hassam tests from: no estate place to match, and still a place a person reads.
    expect(placeOf({ name: '65 Main Boulevard Gulberg', street: 'Main Boulevard Gulberg', streetNumber: '65', district: 'Gulberg III', city: 'Lahore' })).toBe('65 Main Boulevard Gulberg, Gulberg III')
    expect(placeOf({ name: null, street: null, district: null, city: 'Lahore', region: 'Punjab' })).toBe('Lahore')
  })

  it('nothing in it that is a word: no name', () => {
    expect(placeOf({ name: '54000', postalCode: '54000' })).toBeNull()
    expect(placeOf({})).toBeNull()
    expect(placeOf(null)).toBeNull()
  })

  it('asks the phone’s own geocoder once, for the pin already taken, and reads no position', async () => {
    geocode.mockResolvedValue([{ name: null, street: 'Palm Drive', streetNumber: '12', city: 'Portmore' }])
    expect(await namePin(PIN)).toBe('12 Palm Drive, Portmore')
    expect(geocode).toHaveBeenCalledTimes(1)
    expect(geocode).toHaveBeenCalledWith({ latitude: 18.0179, longitude: -76.8099 })
    expect(read).not.toHaveBeenCalled()
  })

  it('no answer, an empty one, or a failure: no name, and the row says so in words', async () => {
    geocode.mockResolvedValue([])
    expect(await namePin(PIN)).toBeNull()
    geocode.mockResolvedValue([{ name: '54000', postalCode: '54000' }])
    expect(await namePin(PIN)).toBeNull()
    geocode.mockRejectedValue(new Error('no network'))
    expect(await namePin(PIN)).toBeNull()
    expect(HERE).toBe('Your current location')
    expect(HERE).not.toMatch(/°|\d/)
  })
})

describe('refusing never blocks the report', () => {
  it('a permission the resident said no to is said plainly, and nothing is read', async () => {
    look.mockResolvedValue(permission(false))
    ask.mockResolvedValue({ granted: false, canAskAgain: true, status: 'denied' })

    expect(await pinLocation({ mayAsk: true })).toEqual({ state: 'refused', why: 'permission' })
    expect(read).not.toHaveBeenCalled()
  })

  it('a phone that cannot find itself, or throws, is the same answer, and never an error', async () => {
    look.mockResolvedValue(permission(true))
    read.mockRejectedValue(new Error('location services are off'))
    expect(await pinLocation({ mayAsk: true })).toEqual({ state: 'refused', why: 'no_fix' })

    read.mockResolvedValue(null)
    expect(await pinLocation({ mayAsk: true })).toEqual({ state: 'refused', why: 'no_fix' })

    look.mockRejectedValue(new Error('no such module'))
    expect(await pinLocation({ mayAsk: true })).toEqual({ state: 'refused', why: 'no_fix' })
  })
})
