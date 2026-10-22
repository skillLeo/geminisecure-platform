/**
 * resident-app-37 · My Bookings — board 10 Bookings Support and AutoPay. Styles are the board's own,
 * copied from the lifted file (src/lifted/ResidentApp37.tsx). All behaviour lives in useMyBookings().
 *
 * The board draws both lists under the "Upcoming" tab, and a past row that says "deposit refunded
 * Aug 18". Live, a tab shows its own list, and a row says only what the server sends:
 *   - the pill is the server's status. `pending` is "Requested" with the line "Requested — waiting for
 *     the estate to confirm" — never "Confirmed" until the estate has confirmed it;
 *   - a deposit is shown with its STATE (not received yet · held · refunded · kept). The server sends no
 *     refund date, so none is drawn. "Not received yet" is said only of a booking still to come: under a
 *     past, declined or cancelled one it would read as a debt. A request whose time passed undecided says so;
 *   - the fee and the deposit each say whether they are paid; while one can be paid it has a "Pay … by card"
 *     button — the card checkout's demonstration payment (M12 §2.14): the fee once the estate approved, the
 *     deposit while it is awaited;
 *   - "Cancel booking" appears only while now < the server's `cancellable_until`, asks first — in a
 *     ConfirmSheet over the list that names WHICH booking — and is sent ONLINE ONLY. With no connection it is
 *     replaced by a sentence — nothing is saved for later. A refusal (409) is shown in the estate's own words,
 *     in the sheet, and stays on the row if the sheet is closed. There is no edit and no delete.
 */

import { useRef, type RefObject } from 'react'
import { FlatList, RefreshControl, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { ConfirmSheet, EmptyState, ErrorView, Pressable, ResidentAppBar, ResidentButton, SkeletonLines, Text, a, c, sh, useFidelityReady, type ColorToken } from '@gs/core'
import { PRESSED } from '@/design/parts'
import { radius } from '@/design/tokens'
import { cancelBookingSheet, type Tone } from '../amenities'
import { AmenityTile, Note, gated } from '../components/AmenityParts'
import { useMyBookings, type BookingRow, type BookingsModel, type BookingsTab } from '../useAmenities'

const PILL: Record<Tone, { box: ColorToken; ink: ColorToken }> = {
  navy: { box: 'navy-100', ink: 'navy-700' },
  amber: { box: 'amber-100', ink: 'amber-700' },
  slate: { box: 'navy-100', ink: 'slate-600' },
}

type Entry = { kind: 'label'; key: string; text: string; first: boolean } | { kind: 'row'; key: string; row: BookingRow; afterLabel: boolean; last: boolean }

const TABS: [BookingsTab, string][] = [['upcoming', 'Upcoming'], ['past', 'Past']]

function Tabs({ m }: { m: BookingsModel }) {
  return (
    <View style={s.seg2} role="tablist">
      {TABS.map(([key, label]) => {
        const on = m.tab === key
        return (
          <Pressable key={key} onPress={() => m.setTab(key)} role="tab" aria-selected={on} aria-label={`${label} bookings`} hitSlop={12} style={s.seg2Item}>
            <Text face="Inter-Bold" size={13} color={on ? 'navy-700' : 'slate-500'}>{label}</Text>
            {on ? <View style={s.seg2Underline} /> : null}
          </Pressable>
        )
      })}
    </View>
  )
}

function RowTop({ row }: { row: BookingRow }) {
  const pill = PILL[row.pill.tone]
  return (
    <View style={s.bookRow}>
      <AmenityTile icon={row.icon} size={44} />
      <View style={s.fill}>
        <Text face="Inter-Bold" size={13} color="navy-900">{row.name}</Text>
        <Text face="Inter-Regular" size={10.5} color="slate-500" style={{ marginTop: 2 }}>{row.sub}</Text>
      </View>
      <View style={[s.bookStatus, { backgroundColor: c(pill.box) }]}>
        <Text face="Inter-Bold" size={9.5} color={pill.ink} numberOfLines={1}>{row.pill.word}</Text>
      </View>
    </View>
  )
}

function CancelArea({ row, m, trigger }: { row: BookingRow; m: BookingsModel; trigger: RefObject<View | null> }) {
  const note = m.cancelNote?.id === row.id ? m.cancelNote.text : null
  const state = row.cancel
  if (!state && !note) return null

  return (
    <View style={s.cancelArea}>
      {state && !state.can && state.reason ? <Text face="Inter-Regular" size={11} lh={1.5} color="slate-500">{state.reason}</Text> : null}

      {state?.can ? (
        m.online ? (
          <View style={s.cancelLine}>
            <View style={s.fill}><Text face="Inter-Regular" size={11} lh={1.5} color="slate-500">{state.until}</Text></View>
            {/* The question is a ConfirmSheet over the list (below), naming this booking in full. */}
            <Pressable ref={m.asking?.id === row.id ? trigger : undefined} onPress={() => m.askCancel(row.id)} role="button" aria-label={`Cancel the booking of ${row.which}`} hitSlop={14} style={s.cancelLink}>
              <Text face="Inter-Bold" size={12} color="red-700">{'Cancel booking'}</Text>
            </Pressable>
          </View>
        ) : (
          <Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-700" role="status">{m.offlineCancelLine}</Text>
        )
      ) : null}

      {note ? <Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-700" style={{ marginTop: 6 }} role="alert">{note}</Text> : null}
    </View>
  )
}

/** Before a booking is cancelled: a ConfirmSheet naming WHICH booking, sent once and online only. */
function CancelBookingSheet({ m, trigger }: { m: BookingsModel; trigger: RefObject<View | null> }) {
  const words = m.asking ? cancelBookingSheet(m.asking.which) : null
  return (
    <ConfirmSheet
      open={!!words}
      title={words?.title ?? ''}
      lines={words?.lines ?? []}
      confirmLabel={words?.confirmLabel ?? ''}
      cancelLabel={words?.cancelLabel}
      working={m.cancelWorking}
      problem={m.cancelProblem}
      blocked={m.online ? null : m.offlineCancelLine}
      onConfirm={m.confirmCancel}
      onCancel={m.keep}
      returnFocusTo={trigger}
    />
  )
}

function Card({ entry, m, trigger }: { entry: Extract<Entry, { kind: 'row' }>; m: BookingsModel; trigger: RefObject<View | null> }) {
  const { row } = entry
  const box = [s.card, entry.afterLabel ? null : s.cardNext, entry.last ? s.cardLast : null]
  // The board's drawing: no booking stands behind it, so it is not a control.
  if (m.board) return <View style={box}><RowTop row={row} /></View>

  return (
    <View style={box}>
      {/* Pressed, the row darkens as one piece (M50 §6.7a) — never a fade drawn through the card. */}
      <Pressable onPress={() => router.push(`/amenities/confirmed?booking=${encodeURIComponent(row.id)}` as never)} role="button" aria-label={`${row.label}. Open it.`} style={({ pressed }) => (pressed ? s.pressed : null)}>
        <RowTop row={row} />
      </Pressable>
      {row.extra.map((line) => (
        <Text key={line} face="Inter-Regular" size={11} lh={1.5} color="slate-600" style={s.extra}>{line}</Text>
      ))}
      {/* The fee or the deposit, paid now with a test card — a demonstration payment (M12 §2.14). */}
      {row.pay.length > 0 ? (
        <View style={s.payArea}>
          {row.pay.map((part) => (
            <ResidentButton
              key={part.part}
              variant="outline"
              label={part.label}
              onPress={() => router.push({ pathname: '/dues/test-pay', params: { kind: part.part, booking: row.id, amount: part.amount, charge: part.what, ...(part.note ? { note: part.note } : {}) } } as never)}
            />
          ))}
          {row.pay.map((part) => (part.note ? <Text key={`${part.part}-note`} face="Inter-Regular" size={10.5} lh={1.5} color="slate-600">{part.note}</Text> : null))}
          <Text face="Inter-Regular" size={10.5} lh={1.5} color="slate-600">{'A demonstration payment with a test card: you get a receipt, and no money moves. Or pay the estate the same way as your dues.'}</Text>
        </View>
      ) : null}
      <CancelArea row={row} m={m} trigger={trigger} />
    </View>
  )
}

function Loading() {
  return (
    <View role="progressbar" aria-label="Loading your bookings">
      {[0, 1].map((i) => (
        <View key={i} style={[s.card, i === 0 ? { marginTop: 14 } : s.cardNext]}><SkeletonLines tone="light" lines={2} /></View>
      ))}
    </View>
  )
}

function entriesOf(m: BookingsModel): Entry[] {
  const section = (key: BookingsTab, text: string, rows: BookingRow[], first: boolean, closing: boolean): Entry[] =>
    rows.length === 0 ? [] : [{ kind: 'label', key: `label-${key}`, text, first }, ...rows.map((row, i): Entry => ({ kind: 'row', key: `${key}-${row.id}`, row, afterLabel: i === 0, last: closing && i === rows.length - 1 }))]
  // The board draws both lists at once; a phone shows the list its tab names.
  if (m.board) return [...section('upcoming', 'Upcoming', m.upcoming, true, false), ...section('past', 'Past', m.past, m.upcoming.length === 0, true)]
  return m.tab === 'upcoming' ? section('upcoming', 'Upcoming', m.upcoming, true, true) : section('past', 'Past', m.past, true, true)
}

function MyBookingsScreen() {
  const m = useMyBookings()
  // The "Cancel booking" that opened the question gets focus back when it closes.
  const trigger = useRef<View>(null)
  useFidelityReady(m.status === 'ready')

  const header = (
    <>
      <Tabs m={m} />
      {m.stale ? <Note tone="amber" icon="offline-note" text={`${m.staleLead}: these are the bookings saved on this phone. The estate may have decided on a request since.`} style={s.gapTop} /> : null}
    </>
  )

  const empty =
    m.status === 'loading' ? <Loading />
    : m.status === 'error' ? <ErrorView tone="light" message={m.errorMessage ?? 'Your bookings could not be loaded.'} onRetry={m.refresh} />
    : m.status === 'denied' ? <EmptyState tone="light" icon="amenity-icon" title="Bookings aren't open to your account" body={m.errorMessage ?? 'Your account cannot see bookings.'} />
    : m.tab === 'upcoming' ? <EmptyState tone="light" icon="amenity-icon" title="Nothing booked" body="Ask for the club house, the gazebo or another space, and your request appears here while the estate decides." action={{ label: 'Book an amenity', onPress: () => router.push('/amenities' as never) }} />
    : <EmptyState tone="light" icon="amenity-icon" title="No past bookings" body="Bookings that have taken place, were declined or were cancelled appear here." />

  return (
    <View style={s.screen}>
      <ResidentAppBar title="My bookings" fallback="/amenities" />
      <FlatList
        style={s.fill}
        contentContainerStyle={s.bodyPad}
        data={m.status === 'ready' ? entriesOf(m) : []}
        keyExtractor={(entry) => entry.key}
        renderItem={({ item }) =>
          item.kind === 'label' ? (
            <Text face="Inter-Bold" size={11.5} ls={0.2} color="slate-500" style={item.first ? s.labelFirst : s.labelNext}>{item.text}</Text>
          ) : (
            <Card entry={item} m={m} trigger={trigger} />
          )
        }
        extraData={m}
        ListHeaderComponent={header}
        ListEmptyComponent={empty}
        keyboardShouldPersistTaps="handled"
        refreshControl={m.board ? undefined : <RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('navy-600')} />}
      />
      <CancelBookingSheet m={m} trigger={trigger} />
    </View>
  )
}

export default gated(MyBookingsScreen)

const s = StyleSheet.create({
  screen: { backgroundColor: c('white'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  gapTop: { marginTop: 12 },
  // Pressed: the row's fill one step darker, as one piece (M50 §6.7a) — never a fade drawn through the card
  pressed: { ...PRESSED, borderRadius: radius.iconTile },
  seg2Item: { paddingBottom: 10, flexShrink: 1 },
  // The board's underline is a pseudo-element laid over the row's hairline: it takes no space.
  seg2Underline: { position: 'absolute', right: 0, bottom: -1.5, left: 0, height: 2.5, borderRadius: 2, backgroundColor: c('navy-600') },
  seg2: { flexDirection: 'row', borderBottomWidth: 1, borderBottomColor: c('navy-100'), gap: 22 },
  labelFirst: { marginTop: 14, marginLeft: 2, marginRight: 2 },
  labelNext: { marginTop: 11, marginLeft: 2, marginRight: 2 },
  bookStatus: { flexShrink: 1, borderRadius: 20, paddingHorizontal: 9, paddingVertical: 4 },
  bookRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  card: { backgroundColor: c('white'), borderRadius: 16, borderWidth: 1, borderColor: c('navy-100'), padding: 13, boxShadow: sh([{ x: 0, y: 8, blur: 20, spread: 0, token: 'navy-800', opacity: 0.06 }]), marginTop: 7 },
  cardNext: { marginTop: 11 },
  cardLast: { marginBottom: 11 },
  extra: { marginTop: 8 },
  payArea: { gap: 8, marginTop: 10 },
  cancelArea: { borderTopWidth: 1, borderTopColor: a('navy-200', 0.6), paddingTop: 10, marginTop: 10 },
  cancelLine: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  // A destructive act: a red OUTLINE (M98, 12 Oct 2026), never red words alone and never a red fill.
  cancelLink: { minHeight: 30, justifyContent: 'center', paddingHorizontal: 12, borderRadius: 10, borderWidth: 1, borderColor: c('rd-red-700'), backgroundColor: c('rd-white') },
  bodyPad: { paddingTop: 16, paddingBottom: 32, paddingHorizontal: 20 },
})
