/**
 * resident-app-35 · Add a card — rebuilt from the board, every value from the design tokens and parts.
 *
 * The bar: the back chevron, "Add a card", the spacer. Then the board in its order: the card — its chip, the brand,
 * "•••• •••• •••• 0148" and the holder's name; Card number (drawn focused on the board); Expiry date and Security code
 * side by side; Cardholder name; "Set as default payment method" and its switch, on; "Save card"; the padlock line.
 *
 * "SAVE CARD" WORKS (M50 §4.2 #3) — FOR STRIPE'S TEST CARDS, BY WHAT NAMES THEM. The fields are real fields, and the
 * card at the top shows what is typed, as the board's does. "Save card" checks it on the phone against the test list
 * and saves the brand, last four, expiry and name — never the number, never the security code — and everything typed
 * is emptied when the screen closes. That is the hook's (useDues.ts useAddCard, addCard.ts); this file only draws,
 * and reads no mode. An empty box shows the board's own placeholder — and
 * Cardholder name the board's own name — in the design's placeholder grey (fidelity/dues.ts, never typed again here).
 *
 * The chip is drawn as the board renders it: a white pill with a pale-blue edge (the board's page styles every `.chip`,
 * its gold chip `svg.chip` included). The page does not scroll at 390 × 844; it can while the keyboard covers its lower
 * fields, so no field and no button is ever out of reach.
 */

import { useRef } from 'react'
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, View, type KeyboardTypeOptions, type TextInput } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Gradient, Icon, Text, aboveGestureBar, useFidelityReady } from '@gs/core'
import { AppBar, Field, NoteSheet, PrimaryButton, Toggle } from '@/design/parts'
import { addCardFixture } from '@/fidelity/dues'
import { corner, fill, font, gradient, icon, ink, inkAlpha, line, radius, size, space, stroke } from '@/design/tokens'
import { CARD_FIELD_MAX, type CardPreview } from '../addCard'
import { useAddCard } from '../useDues'

const EXAMPLE = addCardFixture
const AS_DEFAULT = 'Set as default payment method'

/** A phone's digit keys: iOS's number pad has no key to go on or to put it away, so iOS gets the one that does (as Pay). */
const DIGIT_KEYS: KeyboardTypeOptions = Platform.OS === 'ios' ? 'numbers-and-punctuation' : 'number-pad'

/** No phone's autofill fills the card in, and none offers to keep it. */
const PRIVATE = { autoComplete: 'off', importantForAutofill: 'no', autoCorrect: false } as const

/**
 * `.cv-brand` is Poppins 700 italic, and no italic Poppins is bundled — the board's browser slants the upright face, and
 * so does this: by the 14° a browser gives a face it has to slant.
 */
const SLANT = { transform: [{ skewX: '-14deg' }] }

/** `.card-visual` — the chip, then what the card says: each part once there is something to say. */
function CardFace({ card }: { card: CardPreview }) {
  return (
    <Gradient spec={gradient.hero} style={s.card}>
      <View style={s.chip} />
      {card.brand ? (
        <View style={[s.brand, SLANT]}>
          <Text {...font.poppins700_13_italic} color={ink.onDark}>{card.brand}</Text>
        </View>
      ) : null}
      {card.number ? (
        <View style={s.number}>
          <Text {...font.inter400_15_ls2} color={ink.onDark}>{card.number}</Text>
        </View>
      ) : null}
      {card.holder ? (
        <View style={s.holder}>
          <Text {...font.inter600_10_ls0_5} style={s.holderInk}>{card.holder}</Text>
        </View>
      ) : null}
    </Gradient>
  )
}

export default function AddCardScreen() {
  const m = useAddCard()
  const insets = useSafeAreaInsets()
  const expiry = useRef<TextInput>(null)
  const cvv = useRef<TextInput>(null)
  const holder = useRef<TextInput>(null)
  useFidelityReady(true)

  return (
    <View style={s.screen}>
      <AppBar title="Add a card" fallback="/(tabs)/dues" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
        {/* The board's 20 below the padlock line, or the home indicator's strip where that is deeper — not both. */}
        <ScrollView style={s.fill} contentContainerStyle={[s.body, { paddingBottom: aboveGestureBar(space[20], insets.bottom) }]} keyboardShouldPersistTaps="handled" alwaysBounceVertical={false} showsVerticalScrollIndicator={false}>
          <CardFace card={m.preview} />

          <Field
            label="Card number"
            icon="pm-icon"
            value={m.fields.number}
            placeholder={EXAMPLE.numberPlaceholder}
            onChangeText={(text) => m.set('number', text)}
            open={m.numberLit}
            keyboardType={DIGIT_KEYS}
            maxLength={CARD_FIELD_MAX.number}
            returnKeyType="next"
            submitBehavior="submit"
            onSubmitEditing={() => expiry.current?.focus()}
            {...PRIVATE}
          />
          <View style={s.twoCol}>
            <View style={s.half}>
              <Field
                ref={expiry}
                label="Expiry date"
                icon="input"
                value={m.fields.expiry}
                placeholder={EXAMPLE.expiryPlaceholder}
                onChangeText={(text) => m.set('expiry', text)}
                keyboardType={DIGIT_KEYS}
                maxLength={CARD_FIELD_MAX.expiry}
                returnKeyType="next"
                submitBehavior="submit"
                onSubmitEditing={() => cvv.current?.focus()}
                {...PRIVATE}
              />
            </View>
            <View style={s.half}>
              <Field
                ref={cvv}
                label="Security code"
                icon="privacy-note"
                value={m.fields.cvv}
                placeholder={EXAMPLE.cvvPlaceholder}
                onChangeText={(text) => m.set('cvv', text)}
                keyboardType={DIGIT_KEYS}
                maxLength={CARD_FIELD_MAX.cvv}
                returnKeyType="next"
                submitBehavior="submit"
                onSubmitEditing={() => holder.current?.focus()}
                {...PRIVATE}
              />
            </View>
          </View>
          <Field
            ref={holder}
            label="Cardholder name"
            icon="cc-row"
            value={m.fields.holder}
            placeholder={EXAMPLE.holder}
            onChangeText={(text) => m.set('holder', text)}
            autoCapitalize="words"
            maxLength={CARD_FIELD_MAX.holder}
            returnKeyType="done"
            {...PRIVATE}
          />

          <View style={s.defaultRow}>
            <View style={s.rowText}>
              <Text {...font.inter600_12_5} color={ink.heading}>{AS_DEFAULT}</Text>
            </View>
            <Toggle on={m.asDefault} onChange={m.setAsDefault} label={AS_DEFAULT} />
          </View>

          <PrimaryButton label="Save card" onPress={m.save} loading={m.saving} />

          <View style={s.note}>
            <Icon name="privacy-note" size={icon[15]} color={ink.secondary} style={s.noteIcon} />
            <View style={s.rowText}>
              <Text {...font.inter400_10_5_lh1_5} color={ink.secondary}>{"Entered directly into our payment gateway's secure form. GeminiSecure never sees or stores your full card number."}</Text>
            </View>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
      {/* Why the card was not saved — the app's own sheet, never a grey dialog. */}
      <NoteSheet note={m.note} onClose={m.clearNote} />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface },
  fill: { flex: 1 },
  // .body-pad (its bottom is set by the screen)
  body: { paddingTop: space[18], paddingHorizontal: space[20] },
  rowText: { flex: 1, minWidth: 0 },

  // .card-visual — 18 above Card number
  card: { height: size[120], borderRadius: radius.card, padding: space[16], marginBottom: space[18], overflow: 'hidden' },
  // svg.chip as the board renders it: its page's `.chip` — white, a 1.5 navy-200 edge, round ends, 16 in at each side —
  // on a box 22 tall, 16 in from the top and the left
  chip: { position: 'absolute', top: space[16], left: space[16], height: size[22], paddingHorizontal: space[16], borderRadius: corner.round(size[22]), borderWidth: stroke['1.5'], borderColor: line.outline, backgroundColor: fill.surface },
  // .cv-brand · .cv-num · .cv-name
  brand: { position: 'absolute', top: space[16], right: space[16] },
  number: { position: 'absolute', bottom: space[38], left: space[16] },
  holder: { position: 'absolute', bottom: space[14], left: space[16] },
  holderInk: { color: inkAlpha.onDarkSubtitle },

  // .two-col: 10 apart, each .field in it an equal share (and its own 15 below)
  twoCol: { flexDirection: 'row', gap: space[10] },
  half: { flex: 1, minWidth: 0 },

  // .default-row: the field above already leaves its 15 (the board's 2 collapses into it); 18 to the button
  defaultRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: fill.tint, borderRadius: corner[14], paddingVertical: space[13], paddingHorizontal: space[14], marginBottom: space[18] },
  // .secure-note
  note: { flexDirection: 'row', gap: space[9], marginTop: space[14], paddingHorizontal: space[2] },
  noteIcon: { marginTop: space[1] },
})
