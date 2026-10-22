/**
 * resident-app-22 · an amenity, to book (the board's "Gazebo") — rebuilt from the board, every value from the design
 * tokens and parts (M47 §9: "minimum text" — six blocks, nothing else).
 *
 * The board, top to bottom: the slim app bar with the place's name · the hero (the name, "Up to 30 guests · garden
 * setting · Phase 2") · the day, with "Change" · "Available time slots" and the blocks · the house-rules box · the
 * summary card (the rate, the refundable damage deposit, "Total due today", the deposit's refund line) · the button.
 *
 * LIVE, ALL OF IT IS REAL (useBookingRequest):
 *   - the blocks are the estate's own — two hours for the board's places — struck out once begun, booked or closed;
 *   - the house rules are the estate's: the gold link opens them, and the box starts ticked, as drawn; a place with no
 *     rules has no box;
 *   - every figure in the summary is the server's quote for the hours chosen ("2 hrs × $2,000 … $4,000", "Total due
 *     today $9,000"); nothing is added up on the phone;
 *   - "Confirm & pay $9,000" opens the card checkout, which books, pays and confirms in one act (M47 §10). With the
 *     demonstration card switched off the booking is the request it was: "Send booking request", $0 due today.
 *
 * Pressed with something missing — a time, the box, a connection — the button says what, UNDER itself, in the screen's
 * own words (M47 U7: no system dialog anywhere). THE PAGE DOES NOT SCROLL at 390 × 844.
 */

import { useState } from 'react'
import { ActivityIndicator, RefreshControl, ScrollView, StyleSheet, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { ConfirmSheet, EmptyState, ErrorView, Icon, Pressable, Text, aboveGestureBar, useFidelityReady } from '@gs/core'
import { AppBar, PrimaryButton, SectionLabel } from '@/design/parts'
import { corner, fill, font, icon, ink, line, size, space, stroke } from '@/design/tokens'
import { ChooserSheet } from '@/features/visitors/components/ChooserSheet'
import { gated } from '../components/AmenityParts'
import { DetailHero } from '../components/DetailHero'
import { SlotGrid } from '../components/SlotGrid'
import { SummaryCard } from '../components/SummaryCard'
import { useBookingRequest, type PayFor, type RequestModel } from '../useAmenities'

/** The board's words, as written. */
const CHANGE = 'Change'
const SLOTS_TITLE = 'Available time slots'
/** Under the heading when the place has no readable opening hours, so no blocks to choose (M57 §8: never an empty box). */
const NO_SLOTS = 'No times can be booked here yet. The estate office sets them.'
const RULES_LEAD = "I've read and accept the "

/** The 19-point box's touch area, grown to 43. */
const BOX_REACH = space[12]

/** `.date-row`: the day chosen, and "Change", which opens the days the estate takes bookings for. */
function DayRow({ m, onChange }: { m: RequestModel; onChange: () => void }) {
  return (
    <Pressable onPress={onChange} role="button" aria-label={`Day: ${m.dayLabel}. ${CHANGE}`} style={({ pressed }) => [s.dateRow, pressed ? s.pressed : null]}>
      <Icon name="input" size={icon[18]} color={ink.action} />
      <View style={s.dateText}><Text {...font.inter600_13} color={ink.heading}>{m.dayLabel}</Text></View>
      <Text {...font.inter700_11} color={ink.link}>{CHANGE}</Text>
    </Pressable>
  )
}

/** `.rules-row`: the box, and the sentence with the rules' gold link — which opens the rules themselves. */
function RulesRow({ m, onRead }: { m: RequestModel; onRead: () => void }) {
  if (!m.rules) return null
  const rules = m.rules.title
  return (
    <View style={s.rulesRow}>
      <Pressable onPress={m.toggleRules} role="checkbox" aria-checked={m.rulesAccepted} aria-label={`${RULES_LEAD}${rules}`} hitSlop={BOX_REACH} style={m.rulesAccepted ? s.box : s.boxEmpty}>
        {m.rulesAccepted ? <Icon name="check" size={icon[12]} color={ink.onDark} /> : null}
      </Pressable>
      <View style={s.rulesText}>
        <Text {...font.inter400_11_5_lh1_55} color={ink.label}>
          {RULES_LEAD}
          <Text face={font.inter600_11_5_lh1_55.face} color={ink.link} role="link" onPress={onRead}>{rules}</Text>
          {m.rules.tail}
        </Text>
      </View>
    </View>
  )
}

function BookingRequestScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const pay = (p: PayFor) =>
    router.push({ pathname: '/dues/test-pay', params: { kind: 'book', amenity: p.amenity, starts: p.startsAt, ends: p.endsAt, amount: p.total, charge: p.what, rules: p.rules ? '1' : '0' } } as never)
  const m = useBookingRequest(typeof id === 'string' ? id : undefined, (bookingId) => router.replace(`/amenities/confirmed?booking=${encodeURIComponent(bookingId)}` as never), pay)
  const insets = useSafeAreaInsets()
  const [choosingDay, setChoosingDay] = useState(false)
  const [readingRules, setReadingRules] = useState(false)
  useFidelityReady(m.status === 'ready')

  const days = m.days.map((d) => ({ key: d.iso, label: d.long, on: d.iso === m.day, pick: () => { m.setDay(d.iso); setChoosingDay(false) } }))

  return (
    <View style={s.screen}>
      <AppBar title={m.name} fallback="/amenities" />
      <ScrollView
        style={s.fill}
        contentContainerStyle={[s.body, { paddingBottom: aboveGestureBar(space[20], insets.bottom) }]}
        overScrollMode="never"
        showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={fill.primary} colors={[fill.primary]} />}
      >
        {m.status === 'loading' ? <View style={s.loading}><ActivityIndicator color={fill.primary} /></View> : null}
        {m.status === 'error' ? <ErrorView tone="light" message={m.errorMessage ?? 'This amenity could not be loaded.'} onRetry={m.refresh} /> : null}
        {m.status === 'denied' ? <EmptyState tone="light" icon="amenity-icon" title="Bookings aren't open to your account" body={m.errorMessage ?? 'Your account cannot book amenities.'} /> : null}
        {m.status === 'missing' ? (
          <EmptyState tone="light" icon="amenity-icon" title="Not open for booking" body="The estate does not offer this amenity for booking any more. Choose another from the list." action={{ label: 'See the amenities', onPress: () => router.replace('/amenities' as never) }} />
        ) : null}

        {m.status === 'ready' ? (
          <>
            <DetailHero name={m.name} meta={m.meta} picture={m.icon} />
            <DayRow m={m} onChange={() => setChoosingDay(true)} />
            <SectionLabel gap="wide">{SLOTS_TITLE}</SectionLabel>
            <View style={s.slots}>
              {m.slots.length > 0 ? (
                <SlotGrid slots={m.slots} onPick={m.pick} label={SLOTS_TITLE} resetKey={m.day} />
              ) : (
                <Text {...font.inter400_12_lh1_5} color={ink.body} role="status">{NO_SLOTS}</Text>
              )}
            </View>
            <RulesRow m={m} onRead={() => setReadingRules(true)} />
            <SummaryCard rows={m.rows} total={m.total} note={m.note} />
            <PrimaryButton label={m.button} onPress={() => void m.send()} loading={m.busy} />
            {m.problem ? (
              <View style={s.problem} role="alert">
                <Text {...font.inter500_11_5} color={ink.onGoldTint} align="center">{m.problem.text}</Text>
                {m.problem.openBookings ? (
                  <Pressable onPress={() => router.push('/amenities/bookings' as never)} role="link" aria-label="Open My bookings" hitSlop={space[12]} style={s.problemLink}>
                    <Text {...font.inter700_11} color={ink.link} align="center">{'Open My bookings'}</Text>
                  </Pressable>
                ) : null}
              </View>
            ) : null}
          </>
        ) : null}
      </ScrollView>
      <ChooserSheet open={choosingDay} title="Day" choices={days} onClose={() => setChoosingDay(false)} />
      <ConfirmSheet
        open={readingRules && m.rules !== null}
        title={m.rules?.title ?? ''}
        lines={m.rules?.lines ?? []}
        confirmLabel="I accept the house rules"
        cancelLabel="Close"
        destructive={false}
        working={false}
        onConfirm={() => {
          if (!m.rulesAccepted) m.toggleRules()
          setReadingRules(false)
        }}
        onCancel={() => setReadingRules(false)}
      />
    </View>
  )
}

export default gated(BookingRequestScreen)

const box = { width: size[19], height: size[19], borderRadius: corner[6], flexShrink: 0, alignItems: 'center', justifyContent: 'center', marginTop: space[1] } as const

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface },
  fill: { flex: 1 },
  pressed: { opacity: 0.85 },
  // .body-pad
  body: { paddingTop: space[18], paddingHorizontal: space[20] },
  loading: { paddingTop: space[24], alignItems: 'center' },
  // .date-row
  dateRow: { flexDirection: 'row', alignItems: 'center', gap: space[11], backgroundColor: fill.tint, borderRadius: corner[14], paddingVertical: space[12], paddingHorizontal: space[14], marginBottom: space[16] },
  dateText: { flex: 1, minWidth: 0 },
  // .slot-grid's 18 below
  slots: { marginBottom: space[18] },
  // .rules-row · .cbox (ticked, as the board draws it; empty, in the board's unchosen edge)
  rulesRow: { flexDirection: 'row', alignItems: 'flex-start', gap: space[10], marginBottom: space[18] },
  box: { ...box, backgroundColor: fill.primary },
  boxEmpty: { ...box, backgroundColor: fill.surface, borderWidth: stroke[2], borderColor: line.muted },
  rulesText: { flex: 1, minWidth: 0 },
  // Under the button: why it could not go on (no board draws it; it replaces the system dialog, M47 U7)
  problem: { marginTop: space[10], paddingHorizontal: space[4] },
  problemLink: { marginTop: space[6], alignSelf: 'center' },
})
