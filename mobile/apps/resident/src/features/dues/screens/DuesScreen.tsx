/**
 * resident-app-09 · Dues Statement — rebuilt from the board (M42 §5 #8), every value from the design tokens and parts.
 *
 * A TAB screen: the navigator draws the tab bar. The bar: the back chevron, "Dues & payments", the spacer. Then the
 * dark-blue balance card — "Total outstanding", the total in large white, the gold line ("2 months overdue · next due
 * Sep 28"), the FOUR AGEING BOXES in one row (CURRENT · 30 DAYS · 60 DAYS · 90+ DAYS) and the gold "Pay now" inside the
 * card; the Statement / AutoPay switch; and the statement card, one row per ledger line: its tile, its title, "Charged
 * <date> · <state>", the amount on the right and the running balance beneath it. A payment is the gold "+" tile and a
 * gold amount.
 *
 * THE PAGE DOES NOT SCROLL. The statement card is as tall as its rows, and never taller than the room left: with a
 * ledger longer than the screen holds, only its rows scroll, inside the card. Where the ageing figures come from — and
 * why the row of boxes is left out when they would not add up to the total — is statement.ts.
 */

import { useCallback } from 'react'
import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, View } from 'react-native'
import { router, useFocusEffect } from 'expo-router'
import { EmptyState, ErrorView, Gradient, Pressable, Text, c, useFidelityReady } from '@gs/core'
import { AppBar, Card, IconTile } from '@/design/parts'
import { corner, fill, font, gradient, ink, inkAlpha, line, radius, shadow, size, space, stroke } from '@/design/tokens'
import type { AgeBox, BalanceView, StatementRow } from '../statement'
import { useDuesStatement, type DuesModel } from '../useDues'

const go = (path: string) => router.push(path as never)

/** The AutoPay tab is drawn about 33 tall; its touch area grows to 44, up and down, without moving a pixel. */
const TAB_REACH = { top: space[6], bottom: space[6] }

function AgeingRow({ boxes }: { boxes: AgeBox[] }) {
  return (
    <View style={s.ageRow}>
      {boxes.map((box) => (
        <View key={box.label} style={s.ageChip}>
          <Text {...font.inter700_9} align="center" style={s.ageLabel}>{box.label}</Text>
          <View style={s.ageAmount}>
            <Text {...font.inter700_12} color={ink.onDark} align="center">{box.value}</Text>
          </View>
        </View>
      ))}
    </View>
  )
}

function BalanceCard({ view }: { view: BalanceView }) {
  return (
    <Gradient spec={gradient.header} style={s.hero}>
      <Text {...font.inter600_11_5} style={s.heroLabel}>Total outstanding</Text>
      <View style={s.heroAmount}>
        <Text {...font.poppins700_28} color={ink.onDark}>{view.amount}</Text>
      </View>
      {view.due ? (
        <View style={s.heroDue}>
          <Text {...font.inter600_11_5} color={ink.accent}>{view.due}</Text>
        </View>
      ) : null}
      {view.ageing ? <AgeingRow boxes={view.ageing} /> : null}
      {view.canPay ? (
        <Pressable onPress={() => go('/dues/pay')} role="button" aria-label="Pay now" style={({ pressed }) => [s.payButton, pressed ? s.pressed : null]}>
          <Text {...font.poppins700_13_5} color={ink.heading}>Pay now</Text>
        </Pressable>
      ) : null}
    </Gradient>
  )
}

function StatementSwitch() {
  return (
    <View style={s.seg} role="tablist">
      <View style={s.segOn} role="tab" aria-selected>
        <Text {...font.inter700_12} color={ink.onDark} align="center">Statement</Text>
      </View>
      <Pressable onPress={() => go('/dues/autopay')} role="tab" aria-selected={false} aria-label="AutoPay" hitSlop={TAB_REACH} style={s.segItem}>
        <Text {...font.inter700_12} color={ink.secondary} align="center">AutoPay</Text>
      </Pressable>
    </View>
  )
}

function LedgerLine({ row, last }: { row: StatementRow; last: boolean }) {
  const payment = row.kind === 'payment'
  return (
    <View style={last ? s.row : [s.row, s.rowRule]} aria-label={`${row.title}, ${row.sub}, ${row.amount}, ${row.balance}`}>
      <IconTile name={payment ? 's-icon' : 'input'} tint={payment ? fill.goldTint : fill.tint} color={payment ? ink.onGoldTint : ink.action} />
      <View style={s.rowText}>
        <Text {...font.inter600_12_5} color={ink.heading}>{row.title}</Text>
        <View style={s.rowSub}>
          <Text {...font.inter400_10_5} color={ink.secondary}>{row.sub}</Text>
        </View>
      </View>
      <View style={s.rowAmount}>
        <Text {...font.inter700_13} color={payment ? ink.link : ink.heading} align="right">{row.amount}</Text>
        <View style={s.rowBalance}>
          <Text {...font.inter600_9_5} color={ink.faint} align="right">{row.balance}</Text>
        </View>
      </View>
    </View>
  )
}

function Ledger({ m }: { m: DuesModel }) {
  if (m.lines.length === 0) {
    if (m.linesState === 'loading') return <View style={s.loading}><ActivityIndicator color={c(ink.action)} /></View>
    if (m.linesState === 'error') return <ErrorView tone="light" message="Your statement could not be loaded." onRetry={m.refresh} />
    return null
  }
  return (
    <Card style={s.ledger}>
      <FlatList
        style={s.ledgerList}
        data={m.lines}
        keyExtractor={(row) => row.key}
        renderItem={({ item, index }) => <LedgerLine row={item} last={index === m.lines.length - 1} />}
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c(ink.action)} />}
      />
    </Card>
  )
}

export default function DuesScreen() {
  const m = useDuesStatement()
  useFidelityReady(m.status === 'ready')
  // Money is re-read every time the tab comes back into view.
  useFocusEffect(useCallback(() => m.reread(), [m.reread]))

  return (
    <View style={s.screen}>
      <AppBar title="Dues & payments" />
      <View style={s.body}>
        {m.status === 'loading' ? <View style={s.loading}><ActivityIndicator color={c(ink.action)} /></View> : null}
        {m.status === 'error' ? <ErrorView tone="light" message={m.errorMessage ?? 'Your dues could not be loaded.'} onRetry={m.refresh} /> : null}
        {m.status === 'denied' ? (
          // The server's own sentence — this person's access and who can change it, or that the account has no home yet.
          <EmptyState tone="light" icon="tab-card" title="Dues aren't open to your account" body={m.errorMessage ?? "Your account is not linked to a home on the estate's register yet. Once the estate approves your claim, your balance and statement appear here."} />
        ) : null}
        {m.status === 'ready' && m.balance ? (
          <>
            <BalanceCard view={m.balance} />
            <StatementSwitch />
            <Ledger m={m} />
          </>
        ) : null}
      </View>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface },
  // .content-scroll > .body-pad, laid out fixed: only the statement's rows ever scroll. 14 below, not the board's 20:
  // at 360 x 800 the design overflows by its own empty padding (Stage 0 §4.2), and 6 of it lets the rows fit there.
  body: { flex: 1, paddingTop: space[16], paddingHorizontal: space[20], paddingBottom: space[14] },
  loading: { paddingTop: space[24], alignItems: 'center' },
  pressed: { opacity: 0.85 },

  // .balance-hero · .lbl · .amt (margin 3 0 2) · .due (margin-bottom 14)
  hero: { borderRadius: radius.heroCard, padding: space[18], marginBottom: space[16], overflow: 'hidden' },
  heroLabel: { color: inkAlpha.onDarkSubtitle },
  heroAmount: { marginTop: space[3] },
  heroDue: { marginTop: space[2] },
  // .age-row (gap 7, margin-bottom 14) · .age-chip · .a-lbl · .a-amt
  ageRow: { flexDirection: 'row', gap: space[7], marginTop: space[14] },
  ageChip: { flex: 1, backgroundColor: fill.onDarkChip, borderRadius: corner[10], paddingVertical: space[8], paddingHorizontal: space[6] },
  ageLabel: { color: inkAlpha.onDarkLabel },
  ageAmount: { marginTop: space[2] },
  // .balance-hero .pay-btn
  payButton: { height: size[44], borderRadius: corner[13], backgroundColor: fill.gold, alignItems: 'center', justifyContent: 'center', marginTop: space[14] },

  // .seg · .seg-item · .seg-item.active — this board's own (12 · 700, 18 below), not Invite visitor's
  seg: { flexDirection: 'row', backgroundColor: fill.tint, borderRadius: radius.segment, padding: space[4], marginBottom: space[18] },
  segItem: { flex: 1, borderRadius: radius.segmentItem, paddingVertical: space[9], paddingHorizontal: space[4] },
  segOn: { flex: 1, borderRadius: radius.segmentItem, paddingVertical: space[9], paddingHorizontal: space[4], backgroundColor: fill.primary, ...shadow.segmentActive },

  // .card: as tall as its rows, never taller than the room left — then its rows scroll inside it
  ledger: { flexShrink: 1 },
  ledgerList: { flexGrow: 0 },
  // .stmt-row · .s-info (.n, .d) · .s-amt (.v, .bal)
  row: { flexDirection: 'row', alignItems: 'center', gap: space[11], paddingVertical: space[10] },
  rowRule: { borderBottomWidth: stroke[1], borderBottomColor: line.hairline },
  rowText: { flex: 1, minWidth: 0 },
  rowSub: { marginTop: space[1] },
  rowAmount: { alignItems: 'flex-end' },
  rowBalance: { marginTop: space[1] },
})
