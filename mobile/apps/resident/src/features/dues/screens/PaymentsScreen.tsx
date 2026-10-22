/**
 * resident-app-40 · Payments & AutoPay — rebuilt from the board, every value from the design tokens and parts.
 *
 * The bar: the back chevron, "Payments & AutoPay", the spacer. Then the board in its order: "Saved payment methods" and
 * its card — the saved card's row (its dark tile, its two lines, the "Default" chip), the rule, "Add a card";
 * "AutoPay" and the dark-blue block — the dot and the heading, the four lines, "Edit limit" and "Turn off" side by side;
 * "Payment history" and its card, "View all receipts".
 *
 * What each part says and does is the hook's (useDues.ts usePaymentsAndAutopay) — the design's own sample in fidelity
 * mode; this file reads no mode. Live, nothing is drawn that is not true (M50 §4.2 #3):
 *   SAVED PAYMENT METHODS  the resident's saved TEST cards, the default first with its chip; each opens Make default
 *                          and Remove card in the app's own sheet. "Add a card" closes the card.
 *   AUTOPAY                the household's own arrangement: "AUTOPAY IS ON", its dot gold, with its limit, its day,
 *                          its card and the next day it pays — or OFF (its dot grey) with the terms it would start on.
 *                          What its last run did is one line under the terms. "Edit limit" opens the limits in the
 *                          app's own chooser; the second button turns it off — or on.
 *   PAYMENT HISTORY        "View all receipts" opens, in its own card, every payment the estate recorded; each opens
 *                          its receipt. Only those rows ever scroll, inside the card.
 * THE PAGE DOES NOT SCROLL.
 */

import { Fragment } from 'react'
import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Gradient, Icon, Pressable, Text, aboveGestureBar, useFidelityReady } from '@gs/core'
import { AppBar, Card, IconTile, NoteSheet, SectionLabel } from '@/design/parts'
import { corner, fill, font, gradient, icon, ink, inkAlpha, line, radius, role, size, space } from '@/design/tokens'
import { ChooserSheet } from '@/features/visitors/components/ChooserSheet'
import type { PaymentRow } from '../duesLogic'
import { usePaymentsAndAutopay, type PaymentsModel, type SavedCardRow } from '../useDues'

/** "Edit limit" and "Turn off" are drawn 32 tall; each touch area grows to 44, up and down, without moving a pixel. */
const BUTTON_REACH = { top: space[6], bottom: space[6] }

/** `.pm-row2` — the dark tile, the card's two lines, the "Default" chip; tapped, its options (Make default, Remove card). */
function MethodRow({ row }: { row: SavedCardRow }) {
  const body = (
    <>
      <View style={s.cardTile}>
        <Icon name="pm-icon" size={icon[18]} color={ink.onDark} />
      </View>
      <View style={s.rowText}>
        <Text {...font.inter700_12_5} color={ink.heading}>{row.title}</Text>
        <View style={s.methodSub}>
          <Text {...font.inter400_10_5} color={ink.secondary}>{row.sub}</Text>
        </View>
      </View>
      {row.chip ? (
        <View style={s.chip}>
          <Text {...font.inter700_9} color={ink.onTint}>{row.chip}</Text>
        </View>
      ) : null}
    </>
  )
  return row.onPress ? (
    <Pressable onPress={row.onPress} role="button" aria-label={`${row.title}. ${row.sub}${row.chip ? `. ${row.chip}` : ''}. Options`} style={({ pressed }) => [s.methodRow, pressed ? s.pressed : null]}>{body}</Pressable>
  ) : (
    <View style={s.methodRow}>{body}</View>
  )
}

/** `.card` with the board's own padding (3 × 13) and its 11 below, holding `.pm-row2`s, `.set-div` and `.add-card-row`. */
function SavedMethods({ m }: { m: PaymentsModel }) {
  return (
    <Card pad={13} style={s.card}>
      {m.methods.map((row) => (
        <Fragment key={row.key}>
          <MethodRow row={row} />
          <View style={s.rule} />
        </Fragment>
      ))}
      <Pressable onPress={m.addCard} role="button" aria-label="Add a card" style={({ pressed }) => [s.addRow, pressed ? s.pressed : null]}>
        <View style={s.plus}>
          <Icon name="qty-btn-2" size={icon[13]} color={ink.action} />
        </View>
        <View style={s.rowText}>
          <Text {...font.inter700_12} color={ink.action}>Add a card</Text>
        </View>
      </Pressable>
    </Card>
  )
}

/** `.autopay-status` — the dot and heading, the four lines, "Edit limit" and "Turn off". */
function AutopayBlock({ m }: { m: PaymentsModel }) {
  const autopay = m.autopay
  return (
    <Gradient spec={gradient.hero} style={s.autopay}>
      <View style={s.asTop}>
        <View style={autopay.on ? s.dotOn : s.dotOff} />
        <View style={s.rowText}>
          <Text {...font.inter700_12} color={ink.onDark}>{autopay.heading}</Text>
        </View>
      </View>
      {autopay.terms.map((term) => (
        <View key={term.label} style={s.term}>
          <View style={s.termPart}>
            <Text {...font.inter400_11} style={s.termLabel}>{term.label}</Text>
          </View>
          {term.value ? (
            <View style={s.termPart}>
              <Text {...font.inter600_11_5} color={ink.onDark}>{term.value}</Text>
            </View>
          ) : null}
        </View>
      ))}
      {autopay.lastRun ? (
        <View style={s.lastRun}>
          <Text {...font.inter400_10_5_lh1_4} style={s.termLabel}>{autopay.lastRun}</Text>
        </View>
      ) : null}
      <View style={s.buttons}>
        <Pressable onPress={autopay.editLimit} role="button" aria-label="Edit limit" hitSlop={BUTTON_REACH} style={({ pressed }) => [s.editButton, pressed ? s.pressed : null]}>
          <Text {...font.inter700_11_5} color={ink.heading} align="center">Edit limit</Text>
        </Pressable>
        <Pressable onPress={autopay.busy ? undefined : autopay.second.onPress} role="button" aria-label={`${autopay.second.label} AutoPay`} aria-busy={autopay.busy} hitSlop={BUTTON_REACH} style={({ pressed }) => [s.offButton, pressed ? s.pressed : null]}>
          <Text {...font.inter700_11_5} color={ink.onDark} align="center">{autopay.second.label}</Text>
        </Pressable>
      </View>
    </Gradient>
  )
}

/** One payment, in the board's `.help-topic` row: the rule above it, the receipt tile, what and when, the chevron. */
function ReceiptLine({ row, onPress }: { row: PaymentRow; onPress: () => void }) {
  return (
    <>
      <View style={s.rule} />
      <Pressable onPress={onPress} role="button" aria-label={`${row.title}, ${row.sub}${row.reversed ? ', reversed' : ''}. Open the receipt`} style={({ pressed }) => [s.topic, pressed ? s.pressed : null]}>
        <IconTile name="help-icon-3" />
        <View style={s.rowText}>
          <Text {...role.rowTitleSmall} color={ink.heading}>{row.title}</Text>
          <View style={s.methodSub}>
            <Text {...font.inter400_10_5} color={ink.secondary}>{row.sub}</Text>
          </View>
        </View>
        {/* Amber, not red: red is for life-safety only. */}
        {row.reversed ? (
          <View style={s.reversed}>
            <Text {...font.inter700_9} color={ink.onGoldTint}>Reversed</Text>
          </View>
        ) : null}
        <Icon name="chevron-right" size={icon[16]} color={ink.faint} />
      </Pressable>
    </>
  )
}

/** What "View all receipts" opens: the payments, or — not on the board, and kept to one line — why there are none. */
function Receipts({ m }: { m: PaymentsModel }) {
  if (m.history.length === 0) {
    if (m.historyState === 'loading') {
      return (
        <View style={s.state}>
          <ActivityIndicator color={fill.primary} />
        </View>
      )
    }
    const why =
      m.historyState === 'error'
        ? (m.historyError ?? 'Your payments could not be loaded.')
        : m.historyState === 'denied'
          ? "Your account is not linked to a home on the estate's register yet."
          : 'No payments recorded yet.'
    return (
      <>
        <View style={s.rule} />
        <View style={s.state}>
          <Text {...font.inter400_12_lh1_5} color={ink.secondary}>{why}</Text>
        </View>
      </>
    )
  }
  return (
    <FlatList
      style={s.list}
      data={m.history}
      keyExtractor={(row) => row.id}
      renderItem={({ item }) => <ReceiptLine row={item} onPress={() => m.openReceipt(item.id)} />}
      refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={fill.primary} />}
    />
  )
}

export default function PaymentsScreen() {
  const m = usePaymentsAndAutopay()
  const insets = useSafeAreaInsets()
  useFidelityReady(true)

  return (
    <View style={s.screen}>
      <AppBar title="Payments & AutoPay" fallback="/(tabs)/dues" />
      {/* The board's 20 below, or the home indicator's strip where that is deeper — not both. */}
      <View style={[s.body, { paddingBottom: aboveGestureBar(space[20], insets.bottom) }]}>
        <SectionLabel first>Saved payment methods</SectionLabel>
        <SavedMethods m={m} />

        <SectionLabel>AutoPay</SectionLabel>
        <AutopayBlock m={m} />

        <View style={s.historyLabel}>
          <SectionLabel>Payment history</SectionLabel>
        </View>
        <Card pad={13} style={[s.card, s.history]}>
          <Pressable onPress={m.receipts.toggle} role="button" aria-label="View all receipts" aria-expanded={m.receipts.open} style={({ pressed }) => [s.topic, pressed ? s.pressed : null]}>
            <IconTile name="help-icon-3" />
            <View style={s.rowText}>
              <Text {...role.rowTitleSmall} color={ink.heading}>View all receipts</Text>
            </View>
            <Icon name="chevron-right" size={icon[16]} color={ink.faint} />
          </Pressable>
          {m.receipts.open ? <Receipts m={m} /> : null}
        </Card>
      </View>
      {/* "Edit limit"'s choices, and a card's options or what stopped a switch — the app's own sheets. */}
      <ChooserSheet open={m.limits.open} title="AutoPay limit" choices={m.limits.choices} onClose={m.limits.close} />
      <NoteSheet note={m.note} onClose={m.clearNote} />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  // .body-pad, laid out fixed (its bottom is set by the screen)
  body: { flex: 1, paddingTop: space[16], paddingHorizontal: space[20] },
  pressed: { opacity: 0.7 },
  rowText: { flex: 1, minWidth: 0 },

  // .card — the board's inline padding 3 × 13, and its 11 below
  card: { paddingVertical: space[3], marginBottom: space[11] },
  // .pm-row2 · .pm-icon2 · .pm-txt2 .t2 · .default-chip
  methodRow: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[11] },
  cardTile: { width: size[38], height: size[26], borderRadius: corner[6], backgroundColor: fill.deep, alignItems: 'center', justifyContent: 'center', flexShrink: 0 },
  methodSub: { marginTop: space[1] },
  chip: { backgroundColor: fill.tint, borderRadius: radius.chip, paddingVertical: space[4], paddingHorizontal: space[8] },
  // .set-div
  rule: { height: size[1], backgroundColor: line.hairline, marginLeft: space[46] },
  // .add-card-row · .plus — the row is 44 tall, a full touch target
  addRow: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[9] },
  plus: { width: size[26], height: size[26], borderRadius: corner.round(size[26]), backgroundColor: fill.tint, alignItems: 'center', justifyContent: 'center' },

  // .autopay-status · .as-top · .as-dot (gold only for an arrangement that exists)
  autopay: { borderRadius: radius.card, padding: space[15] },
  asTop: { flexDirection: 'row', alignItems: 'center', gap: space[8], marginBottom: space[10] },
  dotOn: { width: size[8], height: size[8], borderRadius: corner.round(size[8]), backgroundColor: fill.gold, flexShrink: 0 },
  dotOff: { width: size[8], height: size[8], borderRadius: corner.round(size[8]), backgroundColor: fill.switchOff, flexShrink: 0 },
  // .as-line (span · b)
  term: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: space[5] },
  termPart: { flexShrink: 1 },
  termLabel: { color: inkAlpha.onDarkDetail },
  // What the last run did — not on the board, one line under the terms, in their own light ink
  lastRun: { marginTop: space[4] },
  // .as-btns · .as-btn.edit · .as-btn.off
  buttons: { flexDirection: 'row', gap: space[8], marginTop: space[12] },
  editButton: { flex: 1, alignItems: 'center', backgroundColor: fill.gold, borderRadius: radius.buttonSmall, paddingVertical: space[9], paddingHorizontal: space[4] },
  offButton: { flex: 1, alignItems: 'center', backgroundColor: fill.onDarkTrack, borderRadius: radius.buttonSmall, paddingVertical: space[9], paddingHorizontal: space[4] },

  // "Payment history", 14 under the AutoPay block
  historyLabel: { marginTop: space[14] },
  // Its card is as tall as what is in it, and never taller than the room left: then the receipts scroll inside it.
  history: { flexShrink: 1 },
  list: { flexGrow: 0 },
  // .help-topic — the receipts' rows are drawn in it too, each with its line under the title
  topic: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[11] },
  reversed: { backgroundColor: fill.goldTint, borderRadius: radius.chip, paddingVertical: space[4], paddingHorizontal: space[8] },
  state: { paddingVertical: space[11] },
})
