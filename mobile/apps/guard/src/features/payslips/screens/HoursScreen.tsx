/**
 * guard-app-19 · My Hours. Styles are the board's own, copied from the lifted file
 * (src/lifted/GuardApp19.tsx).
 *
 * The board splits "regular" from "overtime" and tells a rest day from an unscheduled one. The server
 * sends hours worked and night hours per shift and nothing about why a day is empty — so the tiles are
 * HOURS WORKED · NIGHT HOURS · SHIFTS, and an empty day says "No shift". Hours, never pay.
 */

import { RefreshControl, ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { AppBar, EmptyState, ErrorView, Icon, Pressable, SkeletonLines, Text, a, c, useFidelityReady } from '@gs/core'
import { hoursFixture } from '@/fidelity/payslips'
import { PeriodStepper } from '../components/PeriodStepper'
import { hoursFigure } from '../payslips'
import { useHours } from '../useHours'

type Day = { key: string; weekday: string; day: string; title: string; detail: string; hours: string; today?: boolean }

function DayRowView({ row, last }: { row: Day; last: boolean }) {
  return (
    <View style={last ? s.dayRow2 : s.dayRow}>
      <View style={[s.dayBadge, row.today ? s.dayBadgeToday : null]}>
        <Text face="Inter-Bold" size={8.5} color={row.today ? 'amber-500' : 'text-3'}>{row.weekday}</Text>
        <Text face="Inter-Bold" size={12} color="white" style={{ marginTop: 1 }}>{row.day}</Text>
      </View>
      <View style={s.fill}>
        <Text face="Inter-SemiBold" size={12.5} color="white" numberOfLines={1}>{row.title}</Text>
        <Text face="Inter-Regular" size={10} color="text-3" style={{ marginTop: 1 }}>{row.detail}</Text>
      </View>
      <View style={s.shrink}><Text face="Inter-Bold" size={12.5} color="amber-500">{row.hours}</Text></View>
    </View>
  )
}

const Tile = ({ figure, label }: { figure: string; label: string }) => (
  <View style={s.wsItem}>
    <Text face="Inter-Bold" size={16} color="white" align="center">{figure}</Text>
    <Text face="Inter-SemiBold" size={9} color="text-3" align="center" style={{ marginTop: 2 }}>{label}</Text>
  </View>
)

export default function HoursScreen() {
  const m = useHours()
  useFidelityReady(m.status !== 'loading')

  return (
    <View style={s.screen}>
      <AppBar title="My hours" />
      <ScrollView contentContainerStyle={s.bodyPad} refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('amber-500')} />}>
        {m.fixture ? (
          <>
            <View style={s.periodRow}>
              <View style={s.sideBtn2}><Icon name="side-btn-3" size={14} color="white" style={s.sideBtnSvg2} /></View>
              <View style={s.shrink}><Text face="Inter-Bold" size={13} color="white">{hoursFixture.week}</Text></View>
              <View style={s.sideBtn2}><Icon name="side-btn-4" size={14} color="white" style={s.sideBtnSvg2} /></View>
            </View>
            <View style={s.weekSummary}>{hoursFixture.tiles.map((t) => <Tile key={t.label} figure={t.figure} label={t.label} />)}</View>
            <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper style={s.heading}>{'Daily breakdown'}</Text>
            <View style={s.card}>{hoursFixture.days.map((d, i) => <DayRowView key={d.key} row={d} last={i === hoursFixture.days.length - 1} />)}</View>
            <View style={s.btnOutline}>
              <Icon name="btn-outline" size={17} color="amber-500" style={s.btnOutlineSvg} />
              <Text face="Inter-SemiBold" size={13} color="white">{hoursFixture.payslipButton}</Text>
            </View>
          </>
        ) : (
          <>
            <PeriodStepper label={m.label} onOlder={m.earlier} onNewer={m.later} olderLabel="Earlier week" newerLabel="Later week" />
            {m.staleNote ? (
              <View style={s.staleNote} role="status">
                <Icon name="offline-note" size={14} color="amber-500" style={s.noteSvg} />
                <View style={s.shrink}><Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-500">{m.staleNote}</Text></View>
              </View>
            ) : null}
            {m.status === 'loading' ? <SkeletonLines tone="dark" lines={7} style={{ marginTop: 16 }} /> : null}
            {m.status === 'error' ? <ErrorView tone="dark" message={m.errorMessage ?? 'Your hours could not be loaded.'} onRetry={m.refresh} /> : null}
            {m.status === 'forbidden' ? <EmptyState tone="dark" icon="recap-item" title="Not available on this handset" body="This phone is not allowed to show your hours. Contact your supervisor." /> : null}
            {m.status === 'ready' ? (
              <>
                <View style={s.weekSummary}>
                  <Tile figure={hoursFigure(m.totals.worked)} label="HOURS WORKED" />
                  <Tile figure={hoursFigure(m.totals.night)} label="NIGHT HOURS" />
                  <Tile figure={String(m.totals.shifts)} label={m.totals.shifts === 1 ? 'SHIFT' : 'SHIFTS'} />
                </View>
                <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={s.heading}>{'Daily breakdown'}</Text>
                <View style={s.card}>{m.rows.map((d, i) => <DayRowView key={d.key} row={d} last={i === m.rows.length - 1} />)}</View>
                <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={s.footnote}>
                  {`Hours are from your clock-in to your clock-out, less breaks, as the server recorded them${m.thisWeek ? ': a shift in progress counts up to now' : ''}. Night hours are the part between 10 PM and 6 AM. If a day looks wrong, tell the control room.`}
                </Text>
              </>
            ) : null}
            <Pressable onPress={() => router.push('/(tabs)/payslips' as never)} role="button" style={({ pressed }) => [s.btnOutline, { opacity: pressed ? 0.8 : 1 }]}>
              <Icon name="btn-outline" size={17} color="amber-500" style={s.btnOutlineSvg} />
              <Text face="Inter-SemiBold" size={13} color="white">{'View my payslips'}</Text>
            </Pressable>
          </>
        )}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  heading: { marginTop: 16, marginLeft: 2, marginRight: 2 },
  sideBtnSvg2: { width: 14, height: 14, overflow: 'hidden' },
  sideBtn2: { width: 28, height: 28, backgroundColor: c('surface-700'), flexDirection: 'row', flexWrap: 'wrap', alignItems: 'flex-start' },
  periodRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 12 },
  wsItem: { flex: 1, backgroundColor: c('surface-800'), borderRadius: 12, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 6, paddingVertical: 11 },
  weekSummary: { flexDirection: 'row', gap: 8, marginTop: 16 },
  dayBadge: { width: 38, height: 38, flexGrow: 0, flexShrink: 0, flexDirection: 'column', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 11 },
  dayBadgeToday: { borderWidth: 1, borderColor: a('amber-500', 0.5) },
  dayRow: { flexDirection: 'row', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: c('border-700'), paddingVertical: 11, gap: 12 },
  dayRow2: { flexDirection: 'row', alignItems: 'center', paddingVertical: 11, gap: 12 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13, paddingVertical: 2, marginTop: 8 },
  footnote: { marginTop: 10, marginHorizontal: 2 },
  btnOutlineSvg: { width: 17, height: 17, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  btnOutline: { height: 46, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 14, borderWidth: 1, borderColor: c('border-600'), gap: 9, marginTop: 16 },
  noteSvg: { flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  staleNote: { flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 12, borderWidth: 1, borderColor: a('amber-500', 0.28), paddingHorizontal: 12, paddingVertical: 10, gap: 9, marginTop: 14 },
})
