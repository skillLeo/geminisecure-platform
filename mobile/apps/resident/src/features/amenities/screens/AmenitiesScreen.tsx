/**
 * resident-app-21 · Book an amenity — rebuilt from the board (M42 round 2), every value from the design tokens and parts.
 *
 * The board, top to bottom: the slim app bar with its back chevron · the filter row — "All", "Available today",
 * "My bookings" · one card per place: its picture tile, its name, "Up to 120 guests · …", its price line, and the gold
 * "Available" pill. Nothing else.
 *
 * LIVE, ALL OF IT IS REAL (useAmenityList):
 *   - "All" and "Available today" filter the list; "Available today" keeps the places with an hour still free today,
 *     read from the diary the server sends (M21 §1.5). "My bookings" opens My bookings.
 *   - the line under a name is the place's capacity — the server sends no description ("kitchen & AV included");
 *   - the price line is the server's ONE fee per booking and its deposit ("$3,000 fee · $5,000 deposit") — the board's
 *     "$4,500/hr" is an hourly rate the estate does not charge;
 *   - "Available" is on a place this household can book. While the estate has paused the household's bookings no card
 *     says it, and a card pressed says so in one sentence.
 *
 * THE PAGE DOES NOT SCROLL: the filter row stays put, and only a list longer than the board's four scrolls, in its own
 * area under it. Pull it down to ask the estate again.
 */

import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, Pressable, aboveGestureBar, useFidelityReady } from '@gs/core'
import { AppBar, Chip, ChipRow, NoteSheet, useNote } from '@/design/parts'
import { fill, space } from '@/design/tokens'
import { AmenityCard } from '../components/AmenityCard'
import { gated } from '../components/AmenityParts'
import { useAmenityList, type AmenityCard as Card, type AmenityFilter, type AmenityListModel } from '../useAmenities'

/** The board's filter row, word for word. */
const FILTERS: { key: AmenityFilter; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'today', label: 'Available today' },
]
const MY_BOOKINGS = 'My bookings'

/** `.filter-row`: the two filters choose; "My bookings" goes to the household's bookings. */
function Filters({ m }: { m: AmenityListModel }) {
  return (
    <View style={s.filterRow}>
      <View role="radiogroup" aria-label="Show amenities">
        <ChipRow>
          {FILTERS.map((f) => (
            <Chip key={f.key} label={f.label} small on={m.filter === f.key} onPress={() => m.setFilter(f.key)} />
          ))}
        </ChipRow>
      </View>
      <Pressable onPress={() => router.push('/amenities/bookings' as never)} role="link" aria-label={MY_BOOKINGS} hitSlop={space[4]}>
        <Chip label={MY_BOOKINGS} small />
      </Pressable>
    </View>
  )
}

function AmenitiesScreen() {
  const m = useAmenityList()
  const insets = useSafeAreaInsets()
  useFidelityReady(m.status === 'ready')

  // Paused: the card is still the place, and says in one sentence why it cannot be asked for now — in the app's own
  // sheet, never the phone's grey dialog (M50 §4.3).
  const note = useNote()
  const open = (card: Card) => (m.mayBook ? router.push(`/amenities/${card.id}` as never) : note.show({ title: card.name, lines: [`${m.pausedLine}.`] }))

  const empty =
    m.status === 'loading' ? <View style={s.loading}><ActivityIndicator color={fill.primary} /></View>
    : m.status === 'error' ? <ErrorView tone="light" message={m.errorMessage ?? "The estate's amenities could not be loaded."} onRetry={m.refresh} />
    : m.status === 'denied' ? <EmptyState tone="light" icon="amenity-icon" title="Bookings aren't open to your account" body={m.errorMessage ?? 'Your account cannot book amenities.'} />
    : null

  return (
    <View style={s.screen}>
      <AppBar title="Book an amenity" fallback="/(tabs)" />
      <Filters m={m} />
      <FlatList
        style={s.fill}
        contentContainerStyle={[s.list, { paddingBottom: aboveGestureBar(space[20], insets.bottom) }]}
        data={m.status === 'ready' ? m.cards : []}
        keyExtractor={(card) => card.id}
        renderItem={({ item }) => <AmenityCard card={item} onPress={() => open(item)} />}
        ListEmptyComponent={empty}
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={fill.primary} colors={[fill.primary]} />}
      />
      <NoteSheet note={note.note} onClose={note.close} />
    </View>
  )
}

export default gated(AmenitiesScreen)

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface },
  fill: { flex: 1 },
  // .body-pad's top and sides, and .filter-row's 16 below it
  filterRow: { flexDirection: 'row', gap: space[8], paddingTop: space[18], paddingHorizontal: space[20], marginBottom: space[16] },
  // .body-pad's sides; each .card brings its own 12 below
  list: { paddingHorizontal: space[20] },
  loading: { paddingTop: space[24], alignItems: 'center' },
})
