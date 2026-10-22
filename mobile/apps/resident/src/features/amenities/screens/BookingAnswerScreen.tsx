/**
 * resident-app-31 · Booking confirmed — rebuilt from the board (M42 round 2), every value from the design tokens and
 * the resident boards' success pattern (design/parts GoldTick, InfoCard, ButtonRow).
 *
 * The board, under its status bar and no app bar: the gold tick in its two faint rings · "Booking confirmed" · "You're
 * all set for the Gazebo" · the navy-100 booking card — the place, the day, the hours, then "CONFIRMATION CODE" and the
 * code under a rule · the gate QR in its white box · "Show this at the gate on the day of your event" · the deposit note
 * · "Add to calendar" and "Done", side by side.
 *
 * LIVE IT IS THE SERVER'S ANSWER, IN THE BOARD'S LAYOUT (useBookingAnswer):
 *   - paid for in the app (M47 §10), a booking is confirmed when it arrives here: the tick, "Booking confirmed", "You're
 *     all set for the Gazebo", "CONFIRMATION CODE" and the server's short code ("GZ-2609-A4");
 *   - the QR is the guests' signed pass for the booking's hours, which the gate accepts then and only then;
 *     "Show this at the gate on the day of your event" goes with it, and neither is drawn without it;
 *   - the deposit note is the board's, with the booking's own deposit and the card it goes back to;
 *   - "Add to calendar" adds it — the phone's own calendar, filled in — and "Done" goes home;
 *   - a REQUEST (the demonstration card switched off) is what it is: "Booking requested", a clock, "BOOKING
 *     REFERENCE", until the estate says yes. A declined or cancelled booking says so (amber, grey).
 *
 * THE PAGE DOES NOT SCROLL at 390 × 844. Pull it down to ask the estate again.
 */

import { useState } from 'react'
import { ActivityIndicator, RefreshControl, ScrollView, StyleSheet, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, Text, aboveGestureBar, now, useFidelityReady } from '@gs/core'
import { ButtonRow, GoldTick, InfoCard, PrimaryButton, SecondaryButton } from '@/design/parts'
import { fill, font, ink, space } from '@/design/tokens'
import { addToCalendar } from '../calendar'
import { gated } from '../components/AmenityParts'
import { BookingCard } from '../components/BookingCard'
import { BookingQr } from '../components/BookingQr'
import { StatusMark } from '../components/StatusMark'
import { useBookingAnswer } from '../useAmenities'

/** The board's words, as written. */
const AT_THE_GATE = 'Show this at the gate on the day of your event'
const ADD_TO_CALENDAR = 'Add to calendar'
const DONE = 'Done'
/** When the phone has no calendar to add it to, said under the buttons — never a system dialog (M47 U7). */
const NO_CALENDAR = 'This phone has no calendar app to add it to. The booking is in My bookings.'

const leave = () => router.replace('/(tabs)' as never)
const back = () => (router.canGoBack() ? router.back() : router.replace('/amenities' as never))

function BookingAnswerScreen() {
  const insets = useSafeAreaInsets()
  const { booking } = useLocalSearchParams<{ booking?: string }>()
  const m = useBookingAnswer(typeof booking === 'string' && booking !== '' ? booking : undefined)
  const [calendarNote, setCalendarNote] = useState<string | null>(null)
  useFidelityReady(m.status === 'ready')

  const calendar = m.calendar
  const add = async () => {
    if (!calendar) return
    setCalendarNote(null)
    if ((await addToCalendar(calendar, now())) === 'failed') setCalendarNote(NO_CALENDAR)
  }

  return (
    <View style={s.screen}>
      <StatusBar style="dark" />
      <ScrollView
        contentContainerStyle={[s.wrap, { paddingTop: insets.top + space[30], paddingBottom: aboveGestureBar(space[20], insets.bottom) }]}
        overScrollMode="never"
        showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={fill.primary} colors={[fill.primary]} />}
      >
        {m.status === 'ready' ? (
          <>
            {m.mark === 'tick' ? <GoldTick /> : <StatusMark mark={m.mark} />}
            <Text {...font.poppins600_20} color={ink.heading} align="center" role="heading">{m.title}</Text>
            <View style={s.line}><Text {...font.inter400_12_lh1_5} color={ink.secondary} align="center">{m.line}</Text></View>
            <BookingCard rows={m.rows} codeLabel={m.codeLabel} code={m.code} />
            {m.qr ? (
              <>
                <BookingQr value={m.qr} label={`QR code of booking ${m.code}`} />
                <Text {...font.inter400_12_lh1_5} color={ink.secondary} align="center">{AT_THE_GATE}</Text>
              </>
            ) : null}
            {m.depositNote ? <View style={s.note}><InfoCard icon="confirm-note" text={m.depositNote} /></View> : null}
            {/* The board's two buttons; "Add to calendar" only for a confirmed booking still to come — one that adds. */}
            <ButtonRow style={s.buttons}>
              {calendar ? <SecondaryButton label={ADD_TO_CALENDAR} icon="qa-tile-5" onPress={() => void add()} /> : null}
              <PrimaryButton label={DONE} onPress={leave} />
            </ButtonRow>
            {calendarNote ? <View style={s.calendarNote} role="status"><Text {...font.inter500_11_5} color={ink.onGoldTint} align="center">{calendarNote}</Text></View> : null}
          </>
        ) : null}

        {m.status === 'loading' ? <View style={s.loading}><ActivityIndicator color={fill.primary} /></View> : null}
        {m.status === 'error' ? <ErrorView tone="light" message={m.errorMessage ?? 'This booking could not be loaded.'} onRetry={m.refresh} /> : null}
        {m.status === 'denied' ? <EmptyState tone="light" icon="amenity-icon" title="Bookings aren't open to your account" body={m.errorMessage ?? 'Your account cannot see bookings.'} /> : null}
        {m.status === 'missing' ? (
          <EmptyState tone="light" icon="help-icon-3" title="No such booking" body="The estate has no booking of your household's with that number." action={{ label: 'Open My bookings', onPress: () => router.replace('/amenities/bookings' as never) }} />
        ) : null}
        {m.status !== 'ready' && m.status !== 'loading' ? (
          <View style={s.back}><SecondaryButton label="Back" onPress={back} /></View>
        ) : null}
      </ScrollView>
    </View>
  )
}

export default gated(BookingAnswerScreen)

const s = StyleSheet.create({
  // .screen — white
  screen: { flex: 1, backgroundColor: fill.surface },
  // .receipt-wrap: a centred column, 24 in at the sides, 30 below the status bar
  wrap: { alignItems: 'center', paddingHorizontal: space[24] },
  // .rs under the title
  line: { marginTop: space[5] },
  // .confirm-note's own 16 above (InfoCard brings 2 of it)
  note: { marginTop: space[14] },
  // .btn-row's 16 above
  buttons: { marginTop: space[16] },
  // Under the buttons, only when the phone had no calendar to add to (no board draws it)
  calendarNote: { marginTop: space[10] },
  loading: { paddingTop: space[24], alignItems: 'center' },
  back: { width: '100%', marginTop: space[8] },
})
