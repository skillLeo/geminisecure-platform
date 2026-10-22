/**
 * A MAP THAT SAYS WHAT IT CAN AND CANNOT SHOW (M11 §6) — DRAWN FROM OPENSTREETMAP (M18A, DECISIONS D-M18A-OSM).
 *
 * WHY OPENSTREETMAP. The map used to be the phone's own through react-native-maps: Apple Maps on iOS, the Google Maps SDK
 * on Android — and the Google SDK draws nothing at all without an API key, which is a console account and a billing
 * decision, not something the app can supply. So on every Android phone the map was "not set up". OpenStreetMap's tiles
 * need no key and no account: the map is a small grid of 256-pixel tiles laid out here, in plain views, with the pins
 * and the geofence drawn over them. It is a picture of a position the app already holds — it pans and zooms not at all,
 * which is all a panic, a report or a tour needs — and it carries the attribution OpenStreetMap's licence requires.
 *
 * Every state is designed, and none of them is a grey square:
 *
 *   loading     a skeleton, until the first tile has drawn
 *   drawn       the pins (clustered when dense, a count on each cluster) and, where there is one, the geofence
 *   offline     "Map unavailable offline — location recorded", and the place in words (tiles need a connection)
 *   failed      no tile came within ten seconds (the tile server refused, or a captive portal): said so, in words
 *
 * THE PLACE IN WORDS IS ALWAYS THERE when the map is not: each pin's title (the lot, the post, the checkpoint) and its
 * coordinates, so a guard or a resident is never left with nothing. The map never asks for a position and never
 * records one.
 *
 * THE TILES ARE DRAWN WITH EXPO-IMAGE, NOT REACT NATIVE'S IMAGE (M22, found on the emulator). React Native's Image on
 * Android did not send the `User-Agent` it was given, so every tile was asked for as `okhttp/…`, which OpenStreetMap
 * refuses — and it refuses with a picture: a 403 whose body is a tile reading "Access blocked — App is not following
 * the tile usage policy". That picture loaded like any tile, so every map on every Android phone showed it under the
 * pin, and the screen called it a map. expo-image's loader sends the headers it is given, and it treats a 403 as a
 * failure, so a refused tile now ends in "The map could not be drawn" with the place in words, not a warning sign.
 */

import { Image } from 'expo-image'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Platform, StyleSheet, View } from 'react-native'
import { palette, alpha, type ColorToken } from '../theme/colors'
import type { Tone } from './States'
import { Text } from './Text'

export type MapPinTone = 'danger' | 'ok' | 'neutral' | 'stale'

export type MapPin = {
  id: string
  latitude: number
  longitude: number
  /** The place in words: "Lot 47", "Main Gate", "Checkpoint 3 · Pool". */
  title: string
  detail?: string
  tone?: MapPinTone
}

export type MapFence = { latitude: number; longitude: number; radiusM: number }

/** How long the first tile has to draw before the screen says it could not draw a map. */
export const MAP_READY_TIMEOUT_MS = 10_000

/** Above this many pins they are drawn as clusters. */
export const CLUSTER_ABOVE = 25

/** OpenStreetMap's own tile server — no key, no account (its usage policy asks for an identifying User-Agent). */
export const OSM_TILES = 'https://tile.openstreetmap.org'
/** The licence's attribution, drawn on every map. */
export const OSM_ATTRIBUTION = '© OpenStreetMap contributors'
const TILE = 256
const MAX_ZOOM = 18
/** A single pin, or pins a few metres apart: a street-level view. */
const STREET_ZOOM = 17

const TONE: Record<MapPinTone, ColorToken> = { danger: 'red-500', ok: 'success', neutral: 'navy-500', stale: 'slate-500' }

/** Whether this build can draw a map at all: any phone — the tiles need a connection, and nothing else. */
export function mapsAvailable(): boolean {
  return Platform.OS === 'ios' || Platform.OS === 'android' || Platform.OS === 'web'
}

const valid = (p: { latitude: number; longitude: number }): boolean =>
  Number.isFinite(p.latitude) && Number.isFinite(p.longitude) && Math.abs(p.latitude) <= 85 && Math.abs(p.longitude) <= 180

/** "18.01794, −76.80994": five places is about a metre, which is more than any phone's fix is worth. */
export function coordinatesText(p: { latitude: number; longitude: number }): string {
  return `${p.latitude.toFixed(5)}, ${p.longitude.toFixed(5)}`
}

export type Region = { latitude: number; longitude: number; latitudeDelta: number; longitudeDelta: number }

/** A region that holds every pin (and the fence), with a margin; a single pin gets a street-level view. */
export function regionFor(pins: readonly MapPin[], fence?: MapFence | null): Region | null {
  const points = pins.filter(valid).map((p) => ({ latitude: p.latitude, longitude: p.longitude }))
  if (fence && valid(fence)) {
    const dLat = fence.radiusM / 111_320
    points.push({ latitude: fence.latitude + dLat, longitude: fence.longitude }, { latitude: fence.latitude - dLat, longitude: fence.longitude })
  }
  if (points.length === 0) return null
  const lats = points.map((p) => p.latitude)
  const lngs = points.map((p) => p.longitude)
  const minLat = Math.min(...lats)
  const maxLat = Math.max(...lats)
  const minLng = Math.min(...lngs)
  const maxLng = Math.max(...lngs)
  return {
    latitude: (minLat + maxLat) / 2,
    longitude: (minLng + maxLng) / 2,
    latitudeDelta: Math.max((maxLat - minLat) * 1.4, 0.004),
    longitudeDelta: Math.max((maxLng - minLng) * 1.4, 0.004),
  }
}

/* ---- Web Mercator: where a place falls on the world's tiles ------------------------------------------------ */

/** A place in world pixels at zoom `z` (the world is 256 · 2^z pixels across). */
export function project(latitude: number, longitude: number, z: number): { x: number; y: number } {
  const world = TILE * 2 ** z
  const lat = (Math.max(-85.05112878, Math.min(85.05112878, latitude)) * Math.PI) / 180
  return {
    x: ((longitude + 180) / 360) * world,
    y: ((1 - Math.log(Math.tan(lat) + 1 / Math.cos(lat)) / Math.PI) / 2) * world,
  }
}

/** The closest zoom at which the whole region fits the frame, with a margin; a single place gets a street-level view. */
export function zoomFor(region: Region, width: number, height: number): number {
  for (let z = MAX_ZOOM; z >= 1; z--) {
    const a = project(region.latitude + region.latitudeDelta / 2, region.longitude - region.longitudeDelta / 2, z)
    const b = project(region.latitude - region.latitudeDelta / 2, region.longitude + region.longitudeDelta / 2, z)
    if (Math.abs(b.x - a.x) <= width * 0.9 && Math.abs(b.y - a.y) <= height * 0.9) return Math.min(z, STREET_ZOOM)
  }
  return 1
}

export type Tile = { key: string; uri: string; left: number; top: number }

/** The tiles that cover a frame `width` × `height` centred on `center` at zoom `z`, each placed in the frame. */
export function tilesFor(center: { latitude: number; longitude: number }, z: number, width: number, height: number): Tile[] {
  const c = project(center.latitude, center.longitude, z)
  const originX = c.x - width / 2
  const originY = c.y - height / 2
  const count = 2 ** z
  const tiles: Tile[] = []
  for (let ty = Math.floor(originY / TILE); ty <= Math.floor((originY + height) / TILE); ty++) {
    if (ty < 0 || ty >= count) continue
    for (let tx = Math.floor(originX / TILE); tx <= Math.floor((originX + width) / TILE); tx++) {
      const wrapped = ((tx % count) + count) % count
      tiles.push({ key: `${z}/${tx}/${ty}`, uri: `${OSM_TILES}/${z}/${wrapped}/${ty}.png`, left: tx * TILE - originX, top: ty * TILE - originY })
    }
  }
  return tiles
}

/** Metres a pixel covers at this latitude and zoom — how big a geofence of `radiusM` is drawn. */
export const metresPerPixel = (latitude: number, z: number): number => (156_543.03392 * Math.cos((latitude * Math.PI) / 180)) / 2 ** z

/* ---- clusters ------------------------------------------------------------------------------------------------- */

export type Cluster = { id: string; latitude: number; longitude: number; pins: MapPin[] }

/**
 * Pins grouped by a grid an eighth of the view wide, so a dense estate reads as a few counted markers rather
 * than a smear. Below CLUSTER_ABOVE pins every pin is its own. The most urgent tone in a cluster is its tone.
 */
export function clusterPins(pins: readonly MapPin[], region: Region | null): Cluster[] {
  const drawable = pins.filter(valid)
  if (drawable.length <= CLUSTER_ABOVE || region === null) return drawable.map((p) => ({ id: p.id, latitude: p.latitude, longitude: p.longitude, pins: [p] }))
  const cellLat = region.latitudeDelta / 8
  const cellLng = region.longitudeDelta / 8
  const cells = new Map<string, MapPin[]>()
  for (const p of drawable) {
    const key = `${Math.floor(p.latitude / cellLat)}:${Math.floor(p.longitude / cellLng)}`
    cells.set(key, [...(cells.get(key) ?? []), p])
  }
  return [...cells.entries()].map(([key, members]) => ({
    id: members.length === 1 ? members[0]!.id : `cluster:${key}`,
    latitude: members.reduce((sum, p) => sum + p.latitude, 0) / members.length,
    longitude: members.reduce((sum, p) => sum + p.longitude, 0) / members.length,
    pins: members,
  }))
}

const RANK: Record<MapPinTone, number> = { danger: 3, ok: 1, neutral: 0, stale: 2 }
const toneOf = (pins: readonly MapPin[]): MapPinTone => pins.reduce<MapPinTone>((worst, p) => (RANK[p.tone ?? 'neutral'] > RANK[worst] ? p.tone ?? 'neutral' : worst), 'neutral')

export type SiteMapProps = {
  tone: Tone
  pins: readonly MapPin[]
  fence?: MapFence | null
  /** What the map is of, for a screen reader: "Where your alert was raised". */
  label: string
  /** The phone has no signal: the map is not drawn, and the place is given in words. */
  offline?: boolean
  height?: number
  /**
   * `record` (the default): a position this app recorded — an alert, a report — so the words say it is recorded.
   * `plan`: places the office set (a tour's checkpoints), where "recorded" would claim something nobody did.
   */
  kind?: 'record' | 'plan'
}

type Stage = 'loading' | 'ready' | 'failed'

/** OpenStreetMap's tile policy asks every app to say who it is. */
const TILE_HEADERS = { 'User-Agent': 'GeminiSecure-mobile/1.0 (community security app)' }

/** Until the frame has been measured: the width of a common phone, so the first draw is already about right. */
const FIRST_WIDTH = 360

export function SiteMap({ tone, pins, fence = null, label, offline = false, height = 180, kind = 'record' }: SiteMapProps) {
  const drawable = mapsAvailable() && !offline
  const initial = useMemo(() => regionFor(pins, fence), [pins, fence])
  const [width, setWidth] = useState(FIRST_WIDTH)
  const [stage, setStage] = useState<Stage>('loading')
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    if (!drawable || initial === null) return
    setStage('loading')
    timer.current = setTimeout(() => setStage((s) => (s === 'loading' ? 'failed' : s)), MAP_READY_TIMEOUT_MS)
    return () => {
      if (timer.current) clearTimeout(timer.current)
    }
  }, [drawable, initial])

  const onTile = useCallback(() => {
    if (timer.current) clearTimeout(timer.current)
    setStage((s) => (s === 'failed' ? s : 'ready'))
  }, [])

  const words = pins.filter(valid)
  const ink = tone === 'dark' ? { strip: 'surface-800', words: 'text-2', muted: 'text-3' } : { strip: 'navy-100', words: 'navy-900', muted: 'slate-600' }

  const plan = kind === 'plan'
  const why = offline
    ? plan ? 'Map unavailable offline.' : 'Map unavailable offline: location recorded.'
    : !drawable
      ? plan ? 'The map is not set up on this phone.' : 'The map is not set up on this phone: the location is recorded.'
      : stage === 'failed'
        ? plan ? 'The map could not be drawn.' : 'The map could not be drawn: the location is recorded.'
        : initial === null
          ? plan ? 'Nothing here has been placed on the map yet.' : 'No position was recorded for this.'
          : null

  const inWords = (
    <View style={[s.words, { backgroundColor: palette[ink.strip as ColorToken] }]} role="group" aria-label={`${label}. ${why ?? ''}`}>
      {why ? (
        <Text face="Inter-SemiBold" size={12} lh={1.4} color={ink.muted as ColorToken}>
          {why}
        </Text>
      ) : null}
      {words.map((p) => (
        <Text key={p.id} face="Inter-Medium" size={12.5} lh={1.45} color={ink.words as ColorToken}>
          {`${p.title} · ${coordinatesText(p)}`}
        </Text>
      ))}
    </View>
  )

  if (!drawable || initial === null || stage === 'failed') return inWords

  const z = zoomFor(initial, width, height)
  const tiles = tilesFor(initial, z, width, height)
  const origin = (() => {
    const c = project(initial.latitude, initial.longitude, z)
    return { x: c.x - width / 2, y: c.y - height / 2 }
  })()
  const at = (p: { latitude: number; longitude: number }) => {
    const q = project(p.latitude, p.longitude, z)
    return { left: q.x - origin.x, top: q.y - origin.y }
  }
  const clusters = clusterPins(pins, initial)
  const fenceRadius = fence && valid(fence) ? fence.radiusM / metresPerPixel(fence.latitude, z) : 0

  return (
    <View style={[s.frame, { height, backgroundColor: palette[ink.strip as ColorToken] }]} role="img" aria-label={`${label}. ${words.map((p) => p.title).join(', ')}`} onLayout={(e) => setWidth(Math.max(1, Math.round(e.nativeEvent.layout.width)))}>
      {tiles.map((t) => (
        <Image key={t.key} testID="osm-tile" source={{ uri: t.uri, headers: TILE_HEADERS }} cachePolicy="disk" onLoad={onTile} style={[s.tile, { left: t.left, top: t.top }]} accessible={false} />
      ))}
      {fence && fenceRadius > 0 ? (
        <View testID="fence" pointerEvents="none" style={[s.fence, { left: at(fence).left - fenceRadius, top: at(fence).top - fenceRadius, width: fenceRadius * 2, height: fenceRadius * 2, borderRadius: fenceRadius }]} />
      ) : null}
      {clusters.map((c) => {
        const place = at(c)
        const one = c.pins.length === 1
        const colour = palette[TONE[one ? c.pins[0]!.tone ?? 'neutral' : toneOf(c.pins)]]
        return one ? (
          <View key={c.id} testID={`marker:${c.pins[0]!.title}`} pointerEvents="none" style={[s.pin, { left: place.left - 9, top: place.top - 9, backgroundColor: colour }]} />
        ) : (
          <View key={c.id} testID={`marker:${c.pins.length} here`} pointerEvents="none" style={[s.cluster, { left: place.left - 15, top: place.top - 15, backgroundColor: colour }]}>
            <Text face="Inter-Bold" size={12} color="white">{String(c.pins.length)}</Text>
          </View>
        )
      })}
      <View style={s.attribution} pointerEvents="none">
        <Text face="Inter-Medium" size={9.5} color="navy-900">{OSM_ATTRIBUTION}</Text>
      </View>
      {stage === 'loading' ? (
        <View style={[StyleSheet.absoluteFill, s.loading, { backgroundColor: palette[ink.strip as ColorToken] }]} pointerEvents="none" role="progressbar" aria-label="Loading map">
          <Text face="Inter-Medium" size={12} color={ink.muted as ColorToken}>
            {'Loading map…'}
          </Text>
        </View>
      ) : null}
    </View>
  )
}

const s = StyleSheet.create({
  frame: { width: '100%', overflow: 'hidden', borderRadius: 12 },
  tile: { position: 'absolute', width: TILE, height: TILE },
  words: { gap: 4, paddingHorizontal: 14, paddingVertical: 12, borderRadius: 12 },
  loading: { alignItems: 'center', justifyContent: 'center' },
  pin: { position: 'absolute', width: 18, height: 18, borderRadius: 9, borderWidth: 3, borderColor: palette.white },
  cluster: { position: 'absolute', minWidth: 30, height: 30, borderRadius: 15, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 6, borderWidth: 2, borderColor: palette.white },
  fence: { position: 'absolute', borderWidth: 2, borderColor: palette['navy-500'], backgroundColor: alpha('navy-500', 0.12) },
  attribution: { position: 'absolute', right: 0, bottom: 0, backgroundColor: alpha('white', 0.85), paddingHorizontal: 5, paddingVertical: 1, borderTopLeftRadius: 6 },
})
