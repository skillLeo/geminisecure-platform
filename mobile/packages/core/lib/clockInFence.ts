/**
 * WHETHER A GUARD IS AT THE POST THEY ARE ROSTERED TO — the server's rule (app/Services/Dispatch/ClockInFence.php),
 * the same sums, for the ONE moment the phone must decide on its own: a clock-in with no signal, judged against the
 * fence cached with the shift at the last sync (M12 §1.4). Online, the server's own answer (`shifts.preflight`) is
 * what the screen shows; this is never used to overrule it.
 *
 *   the fence   the rostered post's own, else the estate's, else none (`source`), as the server sent it
 *   the fix     a circle: the margin is `margin_per_accuracy` × the accuracy the phone reports (its 68% radius,
 *               widened to about 95%), never more than `max_margin_m`; past `poor_accuracy_m` the position is not
 *               established; no fix at all is `no_position`
 *   no fence    `no_geofence`: allowed, and the server marks the shift for the office
 */

export type Fence = {
  source: 'post' | 'estate' | 'none'
  name: string
  latitude: number | null
  longitude: number | null
  radius_m: number | null
  good_accuracy_m: number
  poor_accuracy_m: number
  max_margin_m: number
  margin_per_accuracy: number
}

export type ClockInFix = { latitude: number; longitude: number; accuracyM: number | null }

export type FenceOutcome = 'inside' | 'inside_margin' | 'outside' | 'poor_fix' | 'no_position' | 'no_geofence'

export type FenceVerdict = {
  outcome: FenceOutcome
  allowed: boolean
  source: Fence['source']
  fenceName: string | null
  radiusM: number | null
  distanceM: number | null
  beyondM: number | null
  accuracyM: number | null
  marginM: number | null
  bearingDeg: number | null
  direction: string | null
  /** What a supervisor's PIN would be recorded as, when it may start this shift. */
  overrideReason: 'outside_geofence' | 'position_unconfirmed' | null
}

const EARTH_RADIUS_M = 6_371_008.8
const COMPASS = ['north', 'north-east', 'east', 'south-east', 'south', 'south-west', 'west', 'north-west'] as const
const rad = (deg: number) => (deg * Math.PI) / 180

/** Haversine on a spherical Earth — the server's `Geo::metresBetween`. */
export function metresBetween(lat1: number, lng1: number, lat2: number, lng2: number): number {
  const a = Math.sin(rad(lat2 - lat1) / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(rad(lng2 - lng1) / 2) ** 2
  return EARTH_RADIUS_M * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))
}

/** Initial bearing from one point to another, whole degrees clockwise from north. */
function bearing(fromLat: number, fromLng: number, toLat: number, toLng: number): number {
  const y = Math.sin(rad(toLng - fromLng)) * Math.cos(rad(toLat))
  const x = Math.cos(rad(fromLat)) * Math.sin(rad(toLat)) - Math.sin(rad(fromLat)) * Math.cos(rad(toLat)) * Math.cos(rad(toLng - fromLng))
  return (Math.round((Math.atan2(y, x) * 180) / Math.PI) + 360) % 360
}

// PHP's round() and JS's Math.round() differ at .5 below zero; every value rounded here is a distance or a bearing
// already made positive, where they agree.
export function judgeFence(fence: Fence | null, fix: ClockInFix | null): FenceVerdict {
  const placed = fence && fence.source !== 'none' && fence.latitude !== null && fence.longitude !== null && fence.radius_m !== null
  const base: FenceVerdict = {
    outcome: 'no_geofence',
    allowed: true,
    source: placed ? fence.source : 'none',
    fenceName: placed ? fence.name : null,
    radiusM: placed ? fence.radius_m : null,
    distanceM: null,
    beyondM: null,
    accuracyM: fix?.accuracyM ?? null,
    marginM: null,
    bearingDeg: null,
    direction: null,
    overrideReason: null,
  }
  if (!placed) return base
  if (!fix) return { ...base, outcome: 'no_position', allowed: false, overrideReason: 'position_unconfirmed' }

  const radius = Math.max(1, fence.radius_m!)
  const distance = Math.round(metresBetween(fence.latitude!, fence.longitude!, fix.latitude, fix.longitude))
  const b = bearing(fix.latitude, fix.longitude, fence.latitude!, fence.longitude!)
  const at: FenceVerdict = { ...base, radiusM: radius, distanceM: distance, beyondM: Math.max(0, distance - radius), bearingDeg: b, direction: COMPASS[Math.round(b / 45) % 8]! }

  if (fix.accuracyM !== null && fix.accuracyM > fence.poor_accuracy_m) return { ...at, outcome: 'poor_fix', allowed: false, overrideReason: 'position_unconfirmed' }
  if (distance <= radius) return { ...at, outcome: 'inside', allowed: true, marginM: 0 }

  const margin = Math.min(fence.margin_per_accuracy * Math.max(0, fix.accuracyM ?? 0), fence.max_margin_m)
  if (distance <= radius + margin) return { ...at, outcome: 'inside_margin', allowed: true, marginM: margin }
  return { ...at, outcome: 'outside', allowed: false, marginM: margin, overrideReason: 'outside_geofence' }
}
