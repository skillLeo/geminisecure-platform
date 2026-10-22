/**
 * resident-app-10 · Pay maintenance fee — rebuilt from the board (M42 §5 #9), every value from the design tokens and
 * parts.
 *
 * The bar: the back chevron, "Pay maintenance fee", the spacer. Then the board in its order: "Amount to pay" and its
 * two radio cards — the full balance with the line under it, and "Pay another amount"; "Payment method" and its rows,
 * each an icon tile, two lines and a radio, then "Add new card", which has no radio; the cream AutoPay card with its
 * switch; "Pay $12,400.00"; the padlock line. No card form, no reference to copy, no instructions card: the client
 * deleted them.
 *
 * ONE ELEMENT IS ADDED (the M42 order for this screen): while "Pay another amount" is chosen, ONE amount field sits
 * directly under it, in the design's own Field look. Nothing else is drawn that the board does not draw.
 *
 * What Pay does (a card — saved, or the demonstration card — records the server's demonstration payment and opens
 * Payment successful; Bank transfer and Cash agent show the estate's own instructions), where the amounts come from,
 * the line under the full balance, the methods and AutoPay (which turns on, stays on, and off) are the hook's
 * (useDues.ts `usePay`) and pay.ts's — this file only draws. What the hook has to say is in the app's own sheet.
 * With the board's content the page fits a 390 × 844 phone and nothing moves; on a smaller phone, or with the amount
 * field and its keyboard open, it scrolls rather than cut anything off, and the padlock line keeps the board's 20 below
 * it — or the home-indicator strip where that is deeper (M47 §13.2: "check every screen").
 */

import { ActivityIndicator, Platform, ScrollView, StyleSheet, View, type KeyboardTypeOptions, type StyleProp, type ViewStyle } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, Icon, Pressable, Text, aboveGestureBar, c, useFidelityReady, type IconName } from '@gs/core'
import { AppBar, Field, NoteSheet, PrimaryButton, Toggle } from '@/design/parts'
import { corner, fill, font, icon, ink, line, role, size, space, stroke } from '@/design/tokens'
import type { MethodKey, PayMethod } from '../pay'
import { usePay, type PayModel } from '../useDues'

/** Each row's own tile glyph: a card (on the dark tile) — the demonstration card or a saved one — the bank, the dollar. */
const glyphOf = (key: MethodKey): IconName => (key === 'bank' ? 'pm-icon-2' : key === 'cash' ? 'pm-icon-3' : 'pm-icon')

/** A phone's amount keys: iOS's decimal pad has no key to put it away, so iOS gets the one that does. */
const AMOUNT_KEYS: KeyboardTypeOptions = Platform.OS === 'ios' ? 'numbers-and-punctuation' : 'decimal-pad'

/** `.field label` over a group of choices (12 · 600 · slate-600) — no part draws the label on its own. */
function GroupLabel({ text, style }: { text: string; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={style}>
      <Text {...role.label} color={ink.label}>{text}</Text>
    </View>
  )
}

/** `.radio` · `.radio.on` · `.radio.on i` */
function Radio({ on }: { on: boolean }) {
  return <View style={on ? s.radioOn : s.radio}>{on ? <View style={s.radioDot} /> : null}</View>
}

function AmountOption({ title, sub, on, first, onPress }: { title: string; sub: string; on: boolean; first?: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} role="radio" aria-checked={on} aria-label={`${title}. ${sub}`} style={({ pressed }) => [on ? s.optionOn : s.option, first ? s.first : s.next, pressed ? s.pressed : null]}>
      <Radio on={on} />
      <View style={s.rowText}>
        <Text {...font.inter700_13} color={ink.heading}>{title}</Text>
        <View style={s.sub}><Text {...font.inter400_10_5} color={ink.secondary}>{sub}</Text></View>
      </View>
    </Pressable>
  )
}

function MethodRow({ method, on, first, onPress }: { method: PayMethod; on: boolean; first: boolean; onPress: () => void }) {
  const card = method.key.startsWith('card')
  return (
    <Pressable onPress={onPress} role="radio" aria-checked={on} aria-label={`${method.title}. ${method.sub}`} style={({ pressed }) => [on ? s.methodOn : s.method, first ? s.first : s.next, pressed ? s.pressed : null]}>
      <View style={card ? s.tileCard : s.tile}><Icon name={glyphOf(method.key)} size={icon[18]} color={card ? ink.onDark : ink.action} /></View>
      <View style={s.rowText}>
        <Text {...font.inter700_12_5} color={ink.heading}>{method.title}</Text>
        <View style={s.sub}><Text {...font.inter400_10_5} color={ink.secondary}>{method.sub}</Text></View>
      </View>
      <Radio on={on} />
    </Pressable>
  )
}

function Checkout({ m }: { m: PayModel }) {
  const part = m.choice === 'part'
  return (
    <>
      <GroupLabel text="Amount to pay" />
      <View role="radiogroup" aria-label="Amount to pay">
        <AmountOption title={m.full.title} sub={m.full.sub} on={!part} first onPress={() => m.choose('full')} />
        <AmountOption title="Pay another amount" sub="Enter a part-payment" on={part} onPress={() => m.choose('part')} />
      </View>
      {part ? (
        <View style={s.next}>
          <Field icon="pm-icon-3" value={m.part} onChangeText={m.setPart} keyboardType={AMOUNT_KEYS} returnKeyType="done" autoFocus maxLength={12} aria-label="Part-payment amount" last />
        </View>
      ) : null}

      <GroupLabel text="Payment method" style={s.methodLabel} />
      <View role="radiogroup" aria-label="Payment method">
        {m.methods.map((method, i) => (
          <MethodRow key={method.key} method={method} on={method.key === m.method} first={i === 0} onPress={() => m.chooseMethod(method.key)} />
        ))}
      </View>
      <Pressable onPress={m.addCard} role="button" aria-label="Add new card. Save a card for next time" style={({ pressed }) => [s.method, s.next, pressed ? s.pressed : null]}>
        <View style={s.tile}><Icon name="s-icon" size={icon[18]} color={ink.action} /></View>
        <View style={s.rowText}>
          <Text {...font.inter700_12_5} color={ink.heading}>Add new card</Text>
          <View style={s.sub}><Text {...font.inter400_10_5} color={ink.secondary}>Save a card for next time</Text></View>
        </View>
      </Pressable>

      <View style={s.autopay}>
        <Icon name="autopay-card" size={icon[22]} color={ink.onGoldTint} />
        <View style={s.rowText}>
          <Text {...font.inter700_12_5} color={ink.heading}>Turn on AutoPay</Text>
          {m.autopay.line ? <View style={s.autopayLine}><Text {...font.inter400_10_5_lh1_4} color={ink.label}>{m.autopay.line}</Text></View> : null}
        </View>
        <Toggle on={m.autopay.on} onChange={m.autopay.busy ? () => undefined : m.autopay.toggle} label="Turn on AutoPay" />
      </View>

      <PrimaryButton label={m.payLabel} onPress={m.pay} loading={m.paying} style={s.pay} />

      <View style={s.note}>
        <Icon name="privacy-note" size={icon[15]} color={ink.secondary} style={s.noteIcon} />
        <View style={s.rowText}>
          <Text {...font.inter400_10_5_lh1_5} color={ink.secondary}>Payments are processed securely by our payment gateway. GeminiSecure never stores your full card number.</Text>
        </View>
      </View>
    </>
  )
}

export default function PayScreen() {
  const m = usePay()
  const insets = useSafeAreaInsets()
  useFidelityReady(m.status === 'ready')
  // .body-pad's 20 below — or the home-indicator strip, where that is deeper.
  const bottom = { paddingBottom: aboveGestureBar(space[20], insets.bottom) }

  return (
    <View style={s.screen}>
      <AppBar title="Pay maintenance fee" fallback="/(tabs)/dues" />
      <ScrollView testID="pay-page" style={s.fill} contentContainerStyle={[s.body, bottom]} keyboardShouldPersistTaps="handled" alwaysBounceVertical={false} showsVerticalScrollIndicator={false}>
        {m.status === 'loading' ? <View style={s.loading}><ActivityIndicator color={c(ink.action)} /></View> : null}
        {m.status === 'error' ? <ErrorView tone="light" message={m.errorMessage ?? 'Your balance could not be loaded.'} onRetry={m.refresh} /> : null}
        {m.status === 'denied' ? <EmptyState tone="light" icon="tab-card" title="Dues aren't open to your account" body={m.errorMessage ?? "Your account is not linked to a home on the estate's register yet."} /> : null}
        {m.status === 'settled' ? <EmptyState tone="light" icon="tab-card" title="Nothing to pay" body="Your household owes nothing just now." /> : null}
        {m.status === 'ready' ? <Checkout m={m} /> : null}
      </ScrollView>
      {/* How to pay by hand, or what stopped a payment or AutoPay — the app's own sheet, never a grey dialog. */}
      <NoteSheet note={m.note} onClose={m.clearNote} />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  fill: { flex: 1 },
  // .body-pad — its bottom is set by the screen: the board's 20, or the home-indicator strip
  body: { flexGrow: 1, paddingTop: space[16], paddingHorizontal: space[20] },
  loading: { paddingTop: space[24], alignItems: 'center' },
  pressed: { opacity: 0.7 },

  // The board's gaps, as they fall once its margins collapse: 15 under a label, 10 between rows, 18 above the second label
  first: { marginTop: space[15] },
  next: { marginTop: space[10] },
  methodLabel: { marginTop: space[18] },
  rowText: { flex: 1, minWidth: 0 },
  sub: { marginTop: space[1] },

  // .amt-option · .amt-option.active
  option: { flexDirection: 'row', alignItems: 'center', gap: space[12], padding: space[13], borderWidth: stroke['1.5'], borderColor: line.hairline, borderRadius: corner[14] },
  optionOn: { flexDirection: 'row', alignItems: 'center', gap: space[12], padding: space[13], borderWidth: stroke['1.5'], borderColor: line.active, borderRadius: corner[14], backgroundColor: fill.tint },
  // .radio · .radio.on · .radio.on i
  radio: { width: size[19], height: size[19], borderRadius: corner.round(size[19]), borderWidth: stroke[2], borderColor: line.muted, alignItems: 'center', justifyContent: 'center', flexShrink: 0 },
  radioOn: { width: size[19], height: size[19], borderRadius: corner.round(size[19]), borderWidth: stroke[2], borderColor: line.active, alignItems: 'center', justifyContent: 'center', flexShrink: 0 },
  radioDot: { width: size[10], height: size[10], borderRadius: corner.round(size[10]), backgroundColor: fill.primary },

  // .pm-row · .pm-row.active · .pm-icon (the card's, dark) · .pm-icon.outline
  method: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[12], paddingHorizontal: space[13], borderWidth: stroke['1.5'], borderColor: line.hairline, borderRadius: corner[14] },
  methodOn: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[12], paddingHorizontal: space[13], borderWidth: stroke['1.5'], borderColor: line.active, borderRadius: corner[14] },
  tile: { width: size[38], height: size[26], borderRadius: corner[6], backgroundColor: fill.tint, alignItems: 'center', justifyContent: 'center', flexShrink: 0 },
  tileCard: { width: size[38], height: size[26], borderRadius: corner[6], backgroundColor: fill.deep, alignItems: 'center', justifyContent: 'center', flexShrink: 0 },

  // .autopay-card (16 under the last row) · .a-txt .t2
  autopay: { flexDirection: 'row', alignItems: 'center', gap: space[12], padding: space[13], borderRadius: corner[14], backgroundColor: fill.goldTint, marginTop: space[16] },
  autopayLine: { marginTop: space[2] },
  // .btn-primary, 16 under the AutoPay card
  pay: { marginTop: space[16] },
  // .secure-note
  note: { flexDirection: 'row', gap: space[9], paddingHorizontal: space[2], marginTop: space[16] },
  noteIcon: { marginTop: space[1] },
})
