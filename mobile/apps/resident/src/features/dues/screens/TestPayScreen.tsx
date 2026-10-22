/**
 * PAY BY CARD — the demonstration checkout (M12 §2.6, the client's instruction overriding D-077; the server's
 * D-179). No board draws it; it is built from board 35's card and fields and board 10's summary and button, in the
 * Stripe checkout's own shape: the number, the expiry, the security code, the name, then Pay → processing →
 * success → receipt, and the balance goes down. For dues (`kind=dues`), an amenity booking's fee or deposit — and
 * (`kind=book`, M47 §10) an amenity's hours booked and paid for in one act, which goes straight on to board 31's
 * "Booking confirmed".
 *
 * A DEMONSTRATION, AND NOTHING ABOUT IT CAN BE MISTAKEN FOR MONEY MOVING (testCheckout.ts has the rules):
 *   - DEMONSTRATION is said at the top, on the card, on the button, on the result and on the receipt;
 *   - only Stripe's published test numbers are taken. A real card number is wiped the moment it is recognised, and
 *     the fields tell the phone NOT to offer saved cards (no autofill of a real card into this page);
 *   - the card never leaves this phone: only which test card it was is sent (useCardCheckout). Online only — with
 *     no connection nothing is sent and nothing is charged — and never saved to send later;
 *   - a decline is Stripe's own sentence and what to do next, and the form stays to try another card.
 */

import { useEffect, useRef, useState } from 'react'
import { ActivityIndicator, KeyboardAvoidingView, Platform, ScrollView, StyleSheet, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import * as Haptics from 'expo-haptics'
import { EmptyState, Gradient, Icon, Pressable, ResidentButton, ResidentField, Text, a, c, dayTimeTag, flags, money, siteParts, useFidelityReady, type GradientSpec } from '@gs/core'
import type { Booking } from '@/features/amenities/amenities'
import { rememberBooking } from '@/features/amenities/useAmenities'
import { useAppSelector } from '@/store'
import { DuesBar, DuesNote } from '../components/DuesParts'
import {
  BRAND_NAME, DEMONSTRATION_PAID, EMPTY_CARD, NOT_A_TEST_CARD, TEST_CARDS, TEST_MODE_NOTE,
  brandOf, cardErrors, cvcLengthFor, digitsOf, formatCardNumber, formatExpiry, isRealCard, last4Of, lastFour,
  type CardField, type CardForm, type TestCard,
} from '../testCheckout'
import { useCardCheckout, type CheckoutTarget } from '../useCardCheckout'

const CARD: GradientSpec = { angle: 135, stops: [{ token: 'navy-600', at: 0 }, { token: 'navy-800', at: 1 }] }

/** Long enough to read "Processing" even when the server answers at once, short enough not to drag. */
const PROCESSING_MS = 1200

/** Nothing on this page may be offered a saved card, or remember what was typed. */
const NO_AUTOFILL = { autoComplete: 'off', importantForAutofill: 'no', textContentType: 'none', autoCorrect: false, spellCheck: false } as const

type Result = { receiptId: string | null; receiptNo: string | null; at: number; card: string; amount: string; what: string; balance: string | null; deposit: boolean }

function DemoBadge({ onDark = false }: { onDark?: boolean }) {
  return (
    <View style={[s.badge, onDark ? s.badgeDark : null]}>
      <Text face="Inter-Bold" size={9.5} ls={0.6} color={onDark ? 'navy-900' : 'amber-700'}>{'DEMONSTRATION'}</Text>
    </View>
  )
}

/** Board 35's card, filled in as the resident types. */
function CardPicture({ form }: { form: CardForm }) {
  const brand = brandOf(form.number)
  const digits = digitsOf(form.number)
  const shown = digits ? formatCardNumber(form.number) : '•••• •••• •••• ••••'
  return (
    <Gradient spec={CARD} style={s.cardVisual}>
      <View style={s.cvTop}>
        <DemoBadge onDark />
        <Text face="Poppins-Bold" size={13} color="white">{BRAND_NAME[brand]}</Text>
      </View>
      <Text face="Inter-Regular" size={16} ls={2} color="white" style={s.cvNum}>{shown}</Text>
      <View style={s.cvBottom}>
        <Text face="Inter-SemiBold" size={10} ls={0.5} numberOfLines={1} style={[s.shrink, { color: c('white') }]}>{form.name.trim() ? form.name.trim().toUpperCase() : 'NAME ON CARD'}</Text>
        <Text face="Inter-SemiBold" size={10} ls={0.5} style={{ color: c('white') }}>{form.expiry ? form.expiry.replace(/\s/g, '') : 'MM/YY'}</Text>
      </View>
    </Gradient>
  )
}

function TestCardList({ onPick }: { onPick: (card: TestCard) => void }) {
  return (
    <View style={s.testList}>
      <Text face="Inter-Bold" size={12} color="navy-900">{'Test cards you can use'}</Text>
      <Text face="Inter-Regular" size={10.5} lh={1.45} color="slate-600" style={{ marginTop: 2 }}>{'Tap one to fill the form. Any future expiry date and any security code work.'}</Text>
      {TEST_CARDS.map((card) => (
        <Pressable key={card.number} onPress={() => onPick(card)} role="button" aria-label={`Use the test card ${card.label}, ${formatCardNumber(card.number)}`} style={s.testRow}>
          <Text face="Inter-SemiBold" size={12} ls={0.5} color="navy-800" style={s.testNumber}>{formatCardNumber(card.number)}</Text>
          <Text face="Inter-Medium" size={10.5} color={card.outcome === 'succeeded' ? 'green-700' : 'amber-700'} style={s.shrink}>{card.label}</Text>
        </Pressable>
      ))}
    </View>
  )
}

function Succeeded({ result }: { result: Result }) {
  const done = () => (router.canGoBack() ? router.back() : router.replace('/(tabs)/dues' as never))
  return (
    <View style={s.done} role="status">
      <View style={s.doneBadge}><Icon name="check" size={30} color="green-700" /></View>
      <DemoBadge />
      <Text face="Poppins-SemiBold" size={18} color="navy-900" align="center" role="heading" style={{ marginTop: 10 }}>{result.deposit ? 'Deposit paid' : 'Payment received'}</Text>
      <Text face="Poppins-Bold" size={26} color="navy-900" align="center" style={{ marginTop: 6 }}>{result.amount}</Text>
      <View style={s.doneCard}>
        {[
          ['For', result.what],
          ['Card', result.card],
          ...(result.receiptNo ? [['Receipt', result.receiptNo]] : []),
          ['When', dayTimeTag(result.at)],
          ...(result.balance !== null ? [['You owe now', money(result.balance)]] : []),
        ].map(([label, value], i) => (
          <View key={label} style={[s.doneRow, i > 0 ? s.doneRowDivided : null]}>
            <Text face="Inter-Medium" size={12} color="slate-600">{label}</Text>
            <Text face="Inter-SemiBold" size={12.5} color="navy-900" style={s.doneValue} selectable>{value}</Text>
          </View>
        ))}
      </View>
      <DuesNote tone="amber" icon="privacy-note" text={result.deposit ? 'This was a demonstration payment. No money moved. The estate holds the deposit on your booking as it would any deposit, and returns it the same way.' : DEMONSTRATION_PAID} style={{ marginTop: 16, alignSelf: 'stretch' }} />
      {result.receiptId ? <ResidentButton label="View the receipt" onPress={() => router.replace({ pathname: '/dues/success', params: { payment: result.receiptId! } } as never)} style={s.full} /> : null}
      <ResidentButton variant={result.receiptId ? 'outline' : 'primary'} label="Done" onPress={done} style={result.receiptId ? s.fullTight : s.full} />
    </View>
  )
}

export default function TestPayScreen() {
  const params = useLocalSearchParams<{ kind?: string; amount?: string; charge?: string; booking?: string; note?: string; amenity?: string; starts?: string; ends?: string; rules?: string }>()
  const kind = params.kind === 'fee' || params.kind === 'deposit' || params.kind === 'book' ? params.kind : 'dues'
  const raw = typeof params.amount === 'string' ? params.amount.trim() : ''
  const amountDue = /^\d+(\.\d{1,2})?$/.test(raw) ? raw : ''
  const what = typeof params.charge === 'string' && params.charge.trim() !== '' ? params.charge : kind === 'deposit' ? 'Booking deposit' : kind === 'fee' ? 'Booking fee' : 'Your dues'
  // Why a fee takes more than itself (payParts' FEE_WITH_OLDER), said here before the card is taken.
  const note = typeof params.note === 'string' && params.note.trim() !== '' ? params.note : null
  const text = (value: string | undefined): string => (typeof value === 'string' ? value : '')
  const target: CheckoutTarget | null = kind === 'dues'
    ? (amountDue ? { kind: 'dues', amount: amountDue } : null)
    : kind === 'book'
      ? (amountDue && text(params.amenity) && text(params.starts) && text(params.ends) ? { kind: 'book', amenity: text(params.amenity), startsAt: text(params.starts), endsAt: text(params.ends), total: amountDue, rules: params.rules === '1' } : null)
      : (typeof params.booking === 'string' && params.booking !== '' ? { kind, booking: params.booking, ...(amountDue ? { amount: amountDue } : {}) } : null)
  const amount = amountDue ? money(amountDue) : ''
  const account = useAppSelector((s) => s.session.principal?.id ?? null)
  useFidelityReady(true)

  const checkout = useCardCheckout(target)
  const [form, setForm] = useState<CardForm>(EMPTY_CARD)
  const [touched, setTouched] = useState<Partial<Record<CardField, boolean>>>({})
  const [tried, setTried] = useState(false)
  const [wiped, setWiped] = useState(false)
  const [processing, setProcessing] = useState(false)
  const [declined, setDeclined] = useState<string | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  const [result, setResult] = useState<Result | null>(null)
  const alive = useRef(true)
  useEffect(() => () => {
    alive.current = false
  }, [])

  if (!flags.testCardCheckout || !target) {
    return (
      <View style={s.screen}>
        <DuesBar title="Pay by card" />
        <EmptyState
          tone="light"
          icon="pm-icon"
          title={flags.testCardCheckout ? 'There is nothing to pay here' : 'Card payments are not available'}
          body={flags.testCardCheckout ? 'Open this from How to pay, or from a booking, so it knows what you are paying.' : "Dues here are paid to the estate directly. See How to pay for the amount, the reference to quote and the estate's instructions."}
          action={{ label: 'See how to pay', onPress: () => router.replace('/dues/pay' as never) }}
        />
      </View>
    )
  }

  const p = siteParts(Date.now())
  const errors = cardErrors(form, { year: p.year, month: p.month + 1 })
  const show = (field: CardField): string | null => (tried || touched[field] ? (errors[field] ?? null) : null)
  const brand = brandOf(form.number)

  const set = (field: CardField, value: string) => {
    setDeclined(null)
    setProblem(null)
    setForm((was) => ({ ...was, [field]: value }))
  }
  const onNumber = (text: string) => {
    const next = formatCardNumber(text)
    // A real card is not held here for even one render longer than it takes to recognise it.
    if (isRealCard(next)) {
      setWiped(true)
      set('number', '')
      return
    }
    setWiped(false)
    set('number', next)
  }
  const pick = (card: TestCard) => {
    setWiped(false)
    setDeclined(null)
    setProblem(null)
    setTouched({})
    setTried(false)
    setForm((was) => ({ number: formatCardNumber(card.number), expiry: '12 / 34', cvc: card.brand === 'amex' ? '1234' : '123', name: was.name }))
  }

  const pay = async () => {
    if (processing || checkout.sending) return
    setTried(true)
    setDeclined(null)
    setProblem(null)
    if (Object.keys(errors).length > 0 || brand === 'unknown') return
    setProcessing(true)
    const started = Date.now()
    // Only WHICH test card it is leaves the phone — never the number.
    const outcome = await checkout.pay({ brand, last4: last4Of(form.number) })
    const wait = PROCESSING_MS - (Date.now() - started)
    if (wait > 0) await new Promise((resolve) => setTimeout(resolve, wait))
    if (!alive.current) return
    setProcessing(false)
    if (!outcome.ok) {
      if (outcome.kind === 'declined') setDeclined(outcome.message)
      else setProblem(outcome.message)
      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error).catch(() => undefined)
      return
    }
    // Booked, paid and confirmed (M47 §10): board 31 says so, with its code and the gate QR — not this screen's receipt.
    if (kind === 'book' && outcome.booking && outcome.booking.id !== undefined) {
      setForm(EMPTY_CARD)
      rememberBooking(account, outcome.booking as unknown as Booking)
      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined)
      router.replace(`/amenities/confirmed?booking=${encodeURIComponent(String(outcome.booking.id))}` as never)
      return
    }
    setResult({
      receiptId: outcome.receiptId,
      receiptNo: outcome.receiptNo,
      at: Date.now(),
      card: `${BRAND_NAME[brand]} ${lastFour(form.number)}`,
      amount: outcome.amount ? money(outcome.amount) : amount || what,
      what,
      balance: outcome.balance,
      deposit: kind === 'deposit',
    })
    // The card leaves this screen's memory with the result: only its brand and last four are kept, to show.
    setForm(EMPTY_CARD)
    setTouched({})
    setTried(false)
    void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined)
  }

  const payLabel = amount ? `Pay ${amount}` : kind === 'deposit' ? 'Pay the deposit' : 'Pay the fee'

  return (
    <View style={s.screen}>
      <DuesBar title="Pay by card" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
        <ScrollView style={s.fill} contentContainerStyle={s.bodyPad} keyboardShouldPersistTaps="handled">
          {result ? (
            <Succeeded result={result} />
          ) : (
            <>
              <View style={s.banner} role="note">
                <DemoBadge />
                <Text face="Inter-Medium" size={11.5} lh={1.5} color="navy-900" style={{ marginTop: 6 }}>{TEST_MODE_NOTE}</Text>
              </View>

              <View style={s.summary}>
                <View style={s.shrink}>
                  <Text face="Inter-Medium" size={11.5} color="slate-600">{'Paying'}</Text>
                  <Text face="Inter-SemiBold" size={13} color="navy-900" numberOfLines={2}>{what}</Text>
                </View>
                {amount ? <Text face="Poppins-Bold" size={18} color="navy-900">{amount}</Text> : null}
              </View>
              {note ? <DuesNote tone="amber" icon="privacy-note" text={note} style={{ marginTop: 12 }} /> : null}

              <CardPicture form={form} />

              {checkout.online ? null : <DuesNote tone="amber" icon="offline-note" text="No connection. A card payment needs the estate's server, and it is never saved to send later: connect, then pay." style={{ marginTop: 16 }} />}
              {/* A declined card is a payment that FAILED: the red outline. A problem, or a real card's number wiped, is amber (M98). */}
              {declined ? <DuesNote tone="failed" icon="qa-tile-3" alert text={declined} style={{ marginTop: 16 }} /> : null}
              {problem ? <DuesNote tone="amber" icon="qa-tile-3" alert text={problem} style={{ marginTop: 16 }} /> : null}
              {wiped ? <DuesNote tone="amber" icon="privacy-note" alert text={NOT_A_TEST_CARD} style={{ marginTop: 16 }} /> : null}

              <ResidentField
                label="Card number"
                icon="pm-icon"
                value={form.number}
                onChangeText={onNumber}
                onBlur={() => setTouched((t) => ({ ...t, number: true }))}
                error={show('number')}
                placeholder="e.g. 4242 4242 4242 4242"
                keyboardType="number-pad"
                maxLength={19}
                editable={!processing}
                {...NO_AUTOFILL}
                marginTop={18}
              />
              <View style={s.twoCol}>
                <View style={s.col}>
                  <ResidentField
                    label="Expiry date"
                    icon="input"
                    value={form.expiry}
                    onChangeText={(text) => set('expiry', formatExpiry(text, form.expiry))}
                    onBlur={() => setTouched((t) => ({ ...t, expiry: true }))}
                    error={show('expiry')}
                    placeholder="MM / YY, e.g. 12 / 34"
                    keyboardType="number-pad"
                    maxLength={7}
                    editable={!processing}
                    {...NO_AUTOFILL}
                  />
                </View>
                <View style={s.col}>
                  <ResidentField
                    label="Security code"
                    icon="privacy-note"
                    value={form.cvc}
                    onChangeText={(text) => set('cvc', digitsOf(text).slice(0, cvcLengthFor(brand)))}
                    onBlur={() => setTouched((t) => ({ ...t, cvc: true }))}
                    error={show('cvc')}
                    placeholder={brand === 'amex' ? 'e.g. 1234' : 'e.g. 123'}
                    keyboardType="number-pad"
                    maxLength={4}
                    editable={!processing}
                    {...NO_AUTOFILL}
                  />
                </View>
              </View>
              <ResidentField
                label="Name on card"
                icon="cc-row"
                value={form.name}
                onChangeText={(text) => set('name', text)}
                onBlur={() => setTouched((t) => ({ ...t, name: true }))}
                error={show('name')}
                placeholder="As printed on the card, e.g. A. Fletcher"
                autoCapitalize="words"
                maxLength={80}
                editable={!processing}
                {...NO_AUTOFILL}
              />

              <ResidentButton label={processing ? 'Processing…' : `${payLabel} (demonstration)`} onPress={() => void pay()} loading={processing} style={s.pay} />
              {processing ? (
                <View style={s.processing} role="status">
                  <ActivityIndicator color={c('navy-600')} />
                  <Text face="Inter-Medium" size={11.5} color="slate-600">{'Processing the demonstration payment…'}</Text>
                </View>
              ) : null}

              <View style={s.secureNote}>
                <Icon name="privacy-note" size={15} color="slate-600" style={s.secureIcon} />
                <View style={s.shrink}>
                  <Text face="Inter-Regular" size={10.5} lh={1.5} color="slate-600">
                    {'Your card number never leaves this phone and nothing is saved: only which test card you used is sent to the estate. When real card payments are switched on, a card will go straight to the payment provider, never to the estate or to this app.'}
                  </Text>
                </View>
              </View>

              <TestCardList onPick={pick} />

              {kind === 'dues' ? (
                <Pressable onPress={() => router.replace('/dues/pay' as never)} role="link" aria-label="Pay by bank transfer instead" style={s.bankLink}>
                  <Text face="Inter-SemiBold" size={12} color="navy-600" align="center">{'Or pay by bank transfer, cash or cheque'}</Text>
                </Pressable>
              ) : null}
            </>
          )}
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('white'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 32, paddingHorizontal: 20 },
  badge: { alignSelf: 'flex-start', backgroundColor: c('amber-100'), borderRadius: 20, paddingHorizontal: 9, paddingVertical: 3 },
  badgeDark: { backgroundColor: c('amber-500') },
  banner: { backgroundColor: c('amber-100'), borderRadius: 14, padding: 13 },
  summary: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, borderRadius: 14, borderWidth: 1, borderColor: c('navy-100'), padding: 13, marginTop: 14 },
  cardVisual: { height: 150, overflow: 'hidden', borderRadius: 16, padding: 16, marginTop: 16, justifyContent: 'space-between' },
  cvTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  cvNum: { marginTop: 4 },
  cvBottom: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 },
  twoCol: { flexDirection: 'row', gap: 10 },
  col: { flex: 1 },
  pay: { marginTop: 20 },
  processing: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 10, marginTop: 12 },
  secureIcon: { width: 15, height: 15, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  secureNote: { flexDirection: 'row', paddingHorizontal: 2, gap: 9, marginTop: 14 },
  testList: { backgroundColor: c('navy-100'), borderRadius: 14, padding: 13, marginTop: 18 },
  testRow: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 44, borderTopWidth: 1, borderTopColor: c('white'), marginTop: 6 },
  testNumber: { width: 168 },
  bankLink: { minHeight: 44, justifyContent: 'center', marginTop: 12 },
  done: { alignItems: 'center', paddingTop: 20 },
  doneBadge: { width: 74, height: 74, borderRadius: 37, alignItems: 'center', justifyContent: 'center', backgroundColor: c('success-100'), marginBottom: 14 },
  doneCard: { alignSelf: 'stretch', borderRadius: 14, borderWidth: 1, borderColor: c('navy-100'), paddingHorizontal: 14, marginTop: 18 },
  doneRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, paddingVertical: 11 },
  doneRowDivided: { borderTopWidth: 1, borderTopColor: c('navy-100') },
  doneValue: { flexShrink: 1, textAlign: 'right' },
  full: { alignSelf: 'stretch', marginTop: 20 },
  fullTight: { alignSelf: 'stretch', marginTop: 10 },
})
