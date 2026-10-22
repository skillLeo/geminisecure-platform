/**
 * Where the phone is, pinned to a security report — board resident-app-14's "Pinned from current location".
 *
 * THE DESIGN PINS IT BY ITSELF (M42 §5 #15; the user, 7 October). The card that asked "Send where you are?"
 * is gone: when Report incident opens, the phone's position is read ONCE and shown on the location row, and it
 * goes with the report the resident sends from that screen.
 *
 * THE PHONE'S OWN PERMISSION STILL DECIDES. Its dialog may appear only after the resident has passed the
 * Permissions screen ("Before we get started"), which says what location is for and that it is never tracked
 * in the background — the app's location disclosure (features/auth/screens/PermissionsScreen.tsx). Before that,
 * the permission the phone already holds is only LOOKED at, so no dialog can appear behind the disclosure's back.
 * The same rule the Guard App's three location files follow with its own setup step.
 *
 * ONE READING, IN THE FOREGROUND, AND NOTHING KEPT OF ITS OWN. `getCurrentPositionAsync` is called once, while
 * the resident is looking at the screen; there is no watcher, no background task, no history. The fix lives in
 * the form's own state until the report is captured, and then goes with that ONE act: it is never written under
 * a key of this feature's own and never read again. A report filed with no connection is captured on the phone
 * first (`submit()`), so its body — the two degrees included — waits in the outbox until the queue drains, and
 * leaves when the report does. Nothing is sent if no report is.
 *
 * A REPORT IS NEVER BLOCKED FOR WANT OF IT. No permission, no fix, no location services, a phone that takes too
 * long, the web — each is said on the row in a few words, and the report goes without a pin (the server files
 * it at the resident's unit unless they say where).
 *
 * THE PIN HAS A NAME, NEVER DEGREES (M47 §13.3). The row reads a place a person knows: the phone's OWN geocoder
 * (Google's on Android, Apple's on an iPhone — the platform's service, no key and no account of ours) names the
 * pinned point once, right after it is pinned — "12 Palm Drive, Portmore". The estate's own places are not known to
 * a resident's app with a position (a guard's posts and checkpoints are the guard side's), so a nearest-place match
 * was not possible without telling residents where the patrol goes. No name — no connection, a phone with no
 * geocoder, the web, a lookup that takes too long — and the row says HERE, in words. Nothing new is read: the
 * geocoder is given the one point already pinned, and its answer lives in the form like the pin does.
 */

import { Platform } from 'react-native'
import * as Location from 'expo-location'
import { LOCATION_MAX } from './incidents'

/**
 * A deadline on ONE reading — never a repeat of it. The same helper the Guard App's incident form uses:
 * the single call below is raced against the clock so the row cannot hang, and when the clock wins
 * the answer is "no fix", not another attempt. Nothing here schedules a second read.
 */
const timeout = <T,>(work: Promise<T>, ms: number): Promise<T | null> => Promise.race([work, new Promise<null>((resolve) => setTimeout(() => resolve(null), ms))])

/** What is sent: two degrees and how wide the circle is. Nothing else about the phone. */
export type Fix = { latitude: number; longitude: number; accuracyM: number | null }

export type LocationState =
  | { state: 'idle' }
  | { state: 'finding' }
  | { state: 'attached'; fix: Fix }
  | { state: 'refused'; why: 'permission' | 'no_fix' | 'unavailable' }

/** The phone is given this long before the report is offered without a pin. A resident is not kept waiting. */
export const FIX_TIMEOUT_MS = 8000

/** The board's own words, for a pin that is really there. */
export const PINNED = 'Pinned from current location'

/** The location row's second line: the board's words when there is a pin, otherwise why there is none — never a pin that is not there. */
export function pinLine(state: LocationState): string {
  switch (state.state) {
    case 'attached':
      return PINNED
    case 'finding':
      return 'Finding where you are…'
    case 'refused':
      return state.why === 'permission' ? 'Location is off for this app' : state.why === 'no_fix' ? 'Your location could not be found' : "Location isn't available here"
    default:
      return 'No location'
  }
}

/** A pin the geocoder has not named (yet, or at all): what the row says, in words a person reads. */
export const HERE = 'Your current location'

/** The geocoder is given this long to name the pin; the row reads HERE meanwhile, and after if it cannot. */
export const NAME_TIMEOUT_MS = 6000

type Address = Partial<Record<'name' | 'street' | 'streetNumber' | 'district' | 'subregion' | 'city' | 'region' | 'postalCode', string | null>>

/**
 * A geocoded address as one short line: the place's own name ("Hope Gardens") where it has one, else the street and
 * its number, then the district or town — "12 Palm Drive, Portmore". A bare number, a postcode, the street twice:
 * left out. Null when nothing in it is a word.
 */
export function placeOf(address: Address | null | undefined): string | null {
  if (!address) return null
  const clean = (value: string | null | undefined): string => (value ?? '').trim().replace(/\s+/g, ' ')
  const street = clean(address.street)
  const road = [clean(address.streetNumber), street].filter(Boolean).join(' ')
  const name = clean(address.name)
  const own = /[A-Za-z]/.test(name) && name !== clean(address.postalCode) && !(street && name.includes(street)) && !(road && road.includes(name)) ? name : ''
  const first = own || road
  const area = [address.district, address.subregion, address.city, address.region].map(clean).find((part) => part !== '' && !first.includes(part)) ?? ''
  const text = [first, area].filter(Boolean).join(', ')
  return /[A-Za-z]/.test(text) ? text.slice(0, LOCATION_MAX).trimEnd() : null
}

/**
 * The pinned point's name, asked of the phone's own geocoder ONCE — null when it has none to give. Never throws, never
 * asks twice, never reads a position: it is handed the one already pinned.
 */
export async function namePin(fix: Fix): Promise<string | null> {
  if (Platform.OS === 'web' || typeof Location.reverseGeocodeAsync !== 'function') return null
  try {
    const found = await timeout(Location.reverseGeocodeAsync({ latitude: fix.latitude, longitude: fix.longitude }), NAME_TIMEOUT_MS)
    for (const address of found ?? []) {
      const name = placeOf(address)
      if (name) return name
    }
    return null
  } catch {
    return null
  }
}

/**
 * Read where the phone is, once. `mayAsk`: the resident has passed the Permissions screen — only then may the
 * phone's own dialog appear; otherwise only the permission it already holds is used. The answer is the new
 * state, whatever happens: nothing here throws, and nothing here is remembered.
 */
export async function pinLocation({ mayAsk }: { mayAsk: boolean }): Promise<LocationState> {
  if (Platform.OS === 'web' || typeof Location.getForegroundPermissionsAsync !== 'function') return { state: 'refused', why: 'unavailable' }
  try {
    let permission = await Location.getForegroundPermissionsAsync()
    if (!permission.granted && mayAsk && permission.canAskAgain) permission = await Location.requestForegroundPermissionsAsync()
    if (!permission.granted) return { state: 'refused', why: 'permission' }
    const position = await timeout(Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced }), FIX_TIMEOUT_MS)
    if (!position) return { state: 'refused', why: 'no_fix' }
    const accuracy = position.coords.accuracy
    return {
      state: 'attached',
      fix: {
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        // The server's column is a whole number of metres, and a circle 2 km wide is not a doorstep.
        accuracyM: accuracy === null || accuracy === undefined || !Number.isFinite(accuracy) ? null : Math.min(65535, Math.max(0, Math.round(accuracy))),
      },
    }
  } catch {
    return { state: 'refused', why: 'no_fix' }
  }
}
