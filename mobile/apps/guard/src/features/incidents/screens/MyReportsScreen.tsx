/**
 * guard-app-23 · My Reports — the guard's own incident reports. Styles are the board's own, copied from
 * the lifted file (src/lifted/GuardApp23.tsx). The board's cards are drawn by ONE FlatList and ONE row
 * component: a section's first row carries its heading and the card's top edge, its last the bottom.
 *
 * Append-only: a row opens nothing to edit. Only this phone's UNSENT reports are tappable — they open
 * the sync queue.
 */

import { FlatList, RefreshControl, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { AppBar, EmptyState, ErrorView, Icon, Pressable, SkeletonLines, Text, a, c, useFidelityReady } from '@gs/core'
import type { ReportRow } from '../incidents'
import { useMyReports } from '../useMyReports'

function Row({ row, top }: { row: ReportRow; top: boolean }) {
  const body = (
    <View style={row.last ? s.reportRowLast : s.reportRow}>
      <View style={s.rpIcon}>
        <Icon name={row.icon} size={17} color="text-2" style={s.rpIconSvg} />
      </View>
      <View style={s.fill}>
        <View style={s.rpTop}>
          <View style={s.rpTitle}>
            <Text face="Inter-Bold" size={12.5} color="white">{row.title}</Text>
          </View>
          <View style={[s.statusChip, { backgroundColor: a(row.chip.tone, row.chip.tone === 'amber-500' ? 0.15 : 0.14) }]}>
            <Text face="Inter-Bold" size={9} color={row.chip.tone} numberOfLines={1}>{row.chip.label}</Text>
          </View>
        </View>
        {row.detail ? <Text face="Inter-Regular" size={11} lh={1.45} color="text-2" numberOfLines={3} style={{ marginTop: 4 }}>{row.detail}</Text> : null}
        {row.outcome ? <Text face="Inter-Medium" size={10.5} lh={1.45} color="green-400" numberOfLines={3} style={{ marginTop: 4 }}>{`Outcome: ${row.outcome}`}</Text> : null}
        {row.note ? <Text face="Inter-Medium" size={10.5} lh={1.45} color={row.note.tone} style={{ marginTop: 4 }} role={row.note.tone === 'red-500' ? 'alert' : undefined}>{row.note.text}</Text> : null}
        <Text face="Inter-SemiBold" size={10} color="text-3" style={{ marginTop: 5 }}>{row.meta}</Text>
      </View>
    </View>
  )
  const edge = [s.cardMid, row.first ? s.cardFirst : null, row.last ? s.cardLast : null]
  return (
    <>
      {row.heading ? (
        <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper style={{ marginTop: top ? 2 : 16, marginLeft: 2, marginRight: 2 }} role="heading">{row.heading}</Text>
      ) : null}
      {row.queued ? (
        <Pressable onPress={() => router.push('/sync-queue' as never)} role="button" aria-label={`${row.title}, ${row.chip.label}. Open the sync queue`} style={({ pressed }) => [edge, { opacity: pressed ? 0.8 : 1 }]}>
          {body}
        </Pressable>
      ) : (
        <View style={edge}>{body}</View>
      )}
    </>
  )
}

export default function MyReportsScreen() {
  const m = useMyReports()
  useFidelityReady(m.status !== 'loading')
  const ready = m.status === 'ready'

  const header = (
    <>
      {m.status === 'loading' ? <SkeletonLines tone="dark" lines={6} style={{ marginTop: 12 }} /> : null}
      {m.status === 'error' ? <ErrorView tone="dark" message={m.errorMessage ?? 'Your reports could not be loaded.'} onRetry={m.refresh} /> : null}
      {m.status === 'forbidden' ? (
        <EmptyState tone="dark" icon="side-btn-7" title="Not available on this handset" body="This phone is not allowed to read your reports. Contact your supervisor. A report you file is still saved and sent." action={{ label: 'File a report', onPress: () => router.push('/incident/new' as never) }} />
      ) : null}
      {ready && m.staleNote ? (
        <View style={s.staleNote} role="status">
          <Icon name="offline-note" size={14} color="amber-500" style={s.noteSvg} />
          <View style={s.shrink}><Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-500">{m.staleNote}</Text></View>
        </View>
      ) : null}
    </>
  )

  const footer = ready && m.rows.length === 0 ? (
    <EmptyState
      tone="dark"
      icon="qa-tile-3"
      title="No reports yet"
      body="Anything you report, a suspicious vehicle, a break-in, a fault, is listed here with what the office did about it. Reports from the last 90 days are kept. Nothing here can be edited or removed."
      action={{ label: 'File a report', onPress: () => router.push('/incident/new' as never) }}
    />
  ) : null

  return (
    <View style={s.screen}>
      <AppBar title="My reports" />
      <FlatList
        data={ready ? m.rows : []}
        keyExtractor={(row) => row.key}
        renderItem={({ item, index }) => <Row row={item} top={index === 0} />}
        ListHeaderComponent={header}
        ListFooterComponent={footer}
        contentContainerStyle={s.bodyPad}
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('amber-500')} />}
      />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  rpIconSvg: { width: 17, height: 17, overflow: 'hidden' },
  rpIcon: { width: 38, height: 38, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 11 },
  rpTitle: { flexShrink: 1 },
  statusChip: { flexShrink: 1, borderRadius: 20, paddingHorizontal: 9, paddingVertical: 4 },
  rpTop: { flexDirection: 'row', justifyContent: 'space-between', gap: 8 },
  reportRow: { flexDirection: 'row', borderBottomWidth: 1, borderBottomColor: c('border-700'), paddingVertical: 12, gap: 12 },
  reportRowLast: { flexDirection: 'row', paddingVertical: 12, gap: 12 },
  // The board's .card (radius 16 · 1px border-700 · padding 2/13 · 8 above), split across a section's rows.
  cardMid: { backgroundColor: c('surface-800'), borderLeftWidth: 1, borderRightWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13 },
  cardFirst: { borderTopWidth: 1, borderTopLeftRadius: 16, borderTopRightRadius: 16, paddingTop: 2, marginTop: 8 },
  cardLast: { borderBottomWidth: 1, borderBottomLeftRadius: 16, borderBottomRightRadius: 16, paddingBottom: 2 },
  noteSvg: { flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  staleNote: { flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 12, borderWidth: 1, borderColor: a('amber-500', 0.28), paddingHorizontal: 12, paddingVertical: 10, gap: 9, marginBottom: 14 },
})
