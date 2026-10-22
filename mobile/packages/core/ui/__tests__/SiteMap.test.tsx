/**
 * A MAP THAT SAYS WHAT IT CAN AND CANNOT SHOW (M11 §6), DRAWN FROM OPENSTREETMAP (M18A): every state designed, none of
 * them a grey square, the place always given in words when there is no map — and on an Android phone with no Google
 * key, a map at last.
 */

import { act, fireEvent, render, screen } from '@testing-library/react-native'
import { Platform } from 'react-native'
import { CLUSTER_ABOVE, MAP_READY_TIMEOUT_MS, OSM_ATTRIBUTION, SiteMap, clusterPins, coordinatesText, mapsAvailable, metresPerPixel, project, regionFor, tilesFor, zoomFor, type MapPin } from '../SiteMap'

const lot47: MapPin = { id: 'a', latitude: 18.017945, longitude: -76.809941, title: 'Lot 47', tone: 'danger' }

const setOS = (os: 'ios' | 'android') => Object.defineProperty(Platform, 'OS', { configurable: true, get: () => os })

beforeEach(() => {
  setOS('ios')
  jest.useFakeTimers()
})

afterEach(() => jest.useRealTimers())

describe('the place in words', () => {
  it('gives five places of coordinates: about a metre, more than any phone fix is worth', () => {
    expect(coordinatesText(lot47)).toBe('18.01795, -76.80994')
  })

  it('offline: the map is not drawn, and it says the location is recorded, with the lot and its coordinates', async () => {
    await render(<SiteMap tone="light" pins={[lot47]} label="Where your alert was raised" offline />)
    expect(screen.queryAllByTestId('osm-tile')).toHaveLength(0)
    expect(screen.getByText('Map unavailable offline: location recorded.')).toBeOnTheScreen()
    expect(screen.getByText('Lot 47 · 18.01795, -76.80994')).toBeOnTheScreen()
  })

  it('a map of places the office set (a tour) never says a location was "recorded": nobody recorded one', async () => {
    await render(<SiteMap tone="dark" kind="plan" pins={[lot47]} label="The tour's checkpoints on the map" offline />)
    expect(screen.getByText('Map unavailable offline.')).toBeOnTheScreen()
    expect(screen.getByText('Lot 47 · 18.01795, -76.80994')).toBeOnTheScreen()
    expect(screen.queryByText(/recorded/)).toBeNull()
  })

  it('no position at all is said as such, not drawn as a map of nowhere', async () => {
    await render(<SiteMap tone="light" pins={[]} label="Where your alert was raised" />)
    expect(screen.queryAllByTestId('osm-tile')).toHaveLength(0)
    expect(screen.getByText('No position was recorded for this.')).toBeOnTheScreen()
  })
})

describe('the map, from OpenStreetMap: no key, on either phone (M18A)', () => {
  it('an Android phone with no Google key draws a map', async () => {
    setOS('android')
    expect(mapsAvailable()).toBe(true)
    await render(<SiteMap tone="dark" pins={[lot47]} label="Incident location" />)
    expect(screen.getAllByTestId('osm-tile').length).toBeGreaterThan(0)
  })

  // M22: the tiles are expo-image's, whose loader sends the headers (React Native's Image on Android sent `okhttp/…`,
  // and OpenStreetMap answered every tile with its "Access blocked" picture). expo-image hands its view a list of sources.
  it('asks OpenStreetMap\'s own tile server, saying who is asking, and credits it on the map', async () => {
    await render(<SiteMap tone="light" pins={[lot47]} label="Where your alert was raised" />)
    const [tile] = screen.getAllByTestId('osm-tile')
    const [source] = [tile!.props.source].flat() as { uri: string; headers: Record<string, string> }[]
    expect(source!.uri).toMatch(/^https:\/\/tile\.openstreetmap\.org\/\d+\/\d+\/\d+\.png$/)
    expect(source!.headers['User-Agent']).toMatch(/^GeminiSecure-mobile\//)
    expect(screen.getByText(OSM_ATTRIBUTION)).toBeOnTheScreen()
  })

  it('says it is loading until a tile has drawn, then shows the pin', async () => {
    await render(<SiteMap tone="light" pins={[lot47]} label="Where your alert was raised" />)
    expect(screen.getByTestId('marker:Lot 47')).toBeOnTheScreen()
    expect(screen.getByText('Loading map…')).toBeOnTheScreen()
    const loaded = { nativeEvent: { cacheType: 'none', source: { url: 'https://tile.openstreetmap.org/15/0/0.png', width: 256, height: 256, mediaType: 'image/png' } } }
    await act(async () => fireEvent(screen.getAllByTestId('osm-tile')[0]!, 'load', loaded))
    expect(screen.queryByText('Loading map…')).toBeNull()
  })

  it('a map that never comes up says so after ten seconds, with the place in words, never a grey square', async () => {
    await render(<SiteMap tone="light" pins={[lot47]} label="Where your alert was raised" />)
    await act(async () => jest.advanceTimersByTime(MAP_READY_TIMEOUT_MS + 1))
    expect(screen.queryAllByTestId('osm-tile')).toHaveLength(0)
    expect(screen.getByText('The map could not be drawn: the location is recorded.')).toBeOnTheScreen()
    expect(screen.getByText('Lot 47 · 18.01795, -76.80994')).toBeOnTheScreen()
  })

  it('draws the geofence when there is one', async () => {
    await render(<SiteMap tone="dark" pins={[lot47]} fence={{ latitude: 18.018, longitude: -76.81, radiusM: 60 }} label="Checkpoints" />)
    expect(screen.getByTestId('fence')).toBeOnTheScreen()
  })
})

describe('the tiles', () => {
  it('projects the way every Web Mercator map does: the prime meridian and the equator are the middle of the world', () => {
    expect(project(0, 0, 1)).toEqual({ x: 256, y: 256 })
    const kingston = project(18.017945, -76.809941, 16)
    // Tile 18785 / 29432 at zoom 16 holds Lot 47 — the published slippy-map formula, worked by hand, gives the same.
    expect(Math.floor(kingston.x / 256)).toBe(18785)
    expect(Math.floor(kingston.y / 256)).toBe(29432)
  })

  it('a single place is shown close up, with the streets around it; a spread-out estate further out, so all of it fits', () => {
    // regionFor gives one place ~450 m of margin: zoom 15 on a phone-wide frame, the lot and the roads to it.
    expect(zoomFor(regionFor([lot47])!, 360, 180)).toBe(15)
    const wide = regionFor([lot47, { ...lot47, id: 'b', latitude: 18.05, longitude: -76.75 }])!
    expect(zoomFor(wide, 360, 180)).toBeLessThan(15)
  })

  it('covers the whole frame, and nothing but it', () => {
    const tiles = tilesFor(lot47, 16, 360, 180)
    expect(tiles.length).toBeGreaterThanOrEqual(2)
    expect(tiles.length).toBeLessThanOrEqual(6)
    expect(Math.min(...tiles.map((t) => t.left))).toBeLessThanOrEqual(0)
    expect(Math.max(...tiles.map((t) => t.left + 256))).toBeGreaterThanOrEqual(360)
    expect(new Set(tiles.map((t) => t.key)).size).toBe(tiles.length)
  })

  it('draws a geofence the size it is on the ground', () => {
    // At Kingston's latitude, zoom 17, a pixel is about 1.14 m: a 60 m fence is about 53 pixels across the radius.
    expect(60 / metresPerPixel(18.018, 17)).toBeCloseTo(52.8, 0)
  })
})

describe('clusters', () => {
  const many = (n: number): MapPin[] => Array.from({ length: n }, (_, i) => ({ id: `p${i}`, latitude: 18.01 + (i % 5) * 0.00001, longitude: -76.8 + (i % 5) * 0.00001, title: `Checkpoint ${i}` }))

  it('every pin is its own up to the threshold', () => {
    const pins = many(CLUSTER_ABOVE)
    expect(clusterPins(pins, regionFor(pins))).toHaveLength(CLUSTER_ABOVE)
  })

  it('above it, pins close together are one counted marker, and every pin is in exactly one', () => {
    const pins = [...many(CLUSTER_ABOVE), { ...lot47, latitude: 18.01, longitude: -76.8 }]
    const clusters = clusterPins(pins, { latitude: 18.01, longitude: -76.8, latitudeDelta: 0.05, longitudeDelta: 0.05 })
    expect(clusters.length).toBeLessThan(pins.length)
    expect(clusters.reduce((n, c) => n + c.pins.length, 0)).toBe(pins.length)
    expect(clusters.find((c) => c.pins.some((p) => p.id === 'a'))?.pins.length).toBeGreaterThan(1)
  })

  it('a region holds every pin and the fence, and one pin gets a street-level view', () => {
    expect(regionFor([lot47])?.latitudeDelta).toBeCloseTo(0.004)
    expect(regionFor([lot47], { latitude: 18.03, longitude: -76.81, radiusM: 500 })!.latitude).toBeGreaterThan(lot47.latitude)
  })
})
