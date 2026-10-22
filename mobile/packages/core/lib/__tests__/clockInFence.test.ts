/**
 * The phone's copy of the clock-in rule gives the server's answers — the same cases as
 * tests/Feature/ClockInGeofenceTest.php ("judges a fix as a circle, not a point").
 */
import { judgeFence, metresBetween, type Fence } from '../clockInFence'

const GATE = { lat: 18.0179, lng: -76.8099 }
const M_PER_DEG = 111_195.08
const fence: Fence = { source: 'post', name: 'Main Gate', latitude: GATE.lat, longitude: GATE.lng, radius_m: 50, good_accuracy_m: 50, poor_accuracy_m: 200, max_margin_m: 50, margin_per_accuracy: 2 }
const north = (m: number, accuracyM: number | null) => ({ latitude: GATE.lat + m / M_PER_DEG, longitude: GATE.lng, accuracyM })

describe('the clock-in fence, on the phone (M12 §1.4)', () => {
  it('measures as the server does', () => {
    expect(Math.round(metresBetween(GATE.lat, GATE.lng, GATE.lat + 500 / M_PER_DEG, GATE.lng))).toBe(500)
  })

  it('judges a fix as a circle: M12\'s example is inside within the margin of error', () => {
    expect(judgeFence(fence, north(20, 10)).outcome).toBe('inside')
    expect(judgeFence(fence, north(85, 30))).toMatchObject({ outcome: 'inside_margin', allowed: true, marginM: 50 })
    expect(judgeFence(fence, north(70, 5)).outcome).toBe('outside')
    expect(judgeFence(fence, north(150, 150)).outcome).toBe('outside')
    expect(judgeFence(fence, north(20, 250))).toMatchObject({ outcome: 'poor_fix', allowed: false, overrideReason: 'position_unconfirmed' })
    expect(judgeFence(fence, null)).toMatchObject({ outcome: 'no_position', allowed: false, overrideReason: 'position_unconfirmed' })
  })

  it('names how far and which way', () => {
    expect(judgeFence(fence, north(500, 6))).toMatchObject({ outcome: 'outside', distanceM: 500, beyondM: 450, direction: 'south', bearingDeg: 180, overrideReason: 'outside_geofence' })
  })

  it('with no fence configured, allows the start and says so, never invents one', () => {
    const none: Fence = { ...fence, source: 'none', latitude: null, longitude: null, radius_m: null }
    expect(judgeFence(none, north(5, 5))).toMatchObject({ outcome: 'no_geofence', allowed: true, source: 'none', radiusM: null })
    expect(judgeFence(null, null)).toMatchObject({ outcome: 'no_geofence', allowed: true })
  })

  it('judges against the estate\'s fence when that is what the server sent', () => {
    const estate: Fence = { ...fence, source: 'estate', name: 'Phoenix Park Village', radius_m: 300 }
    expect(judgeFence(estate, north(200, 5))).toMatchObject({ outcome: 'inside', source: 'estate', fenceName: 'Phoenix Park Village' })
  })
})
