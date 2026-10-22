/**
 * guard-app-24 · Gate Activity — the guard's own log for today. Styles are the board's own, copied
 * from the lifted file (src/lifted/GuardApp24.tsx). The board's one card of rows is drawn by a
 * FlatList: the first row carries the card's top edge, the last its bottom.
 *
 * Append-only: a row opens nothing to edit. Only this phone's UNSENT acts are tappable — they open the sync queue.
 */

import { useState } from 'react'
import { FlatList, RefreshControl, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { AppBar, EmptyState, ErrorView, Icon, Pressable, SkeletonLines, Text, a, c, useFidelityReady, type ColorToken, type IconName } from '@gs/core'
import { useGateActivity } from '../useGateActivity'
import type { ActivityResult, ActivityRow, OnSiteRow } from '../walkup'

const RESULT: Record<ActivityResult, { icon: IconName; tone: ColorToken }> = {
  admit: { icon: 'check', tone: 'green-400' },
  deny: { icon: 'act-result', tone: 'red-500' },
  override: { icon: 'qa-tile-3', tone: 'amber-500' },
  exit: { icon: 'chevron-right', tone: 'text-2' },
  other: { icon: 'bubble-meta', tone: 'text-2' },
  pending: { icon: 'bubble-meta', tone: 'amber-500' },
  syncing: { icon: 'bubble-meta', tone: 'amber-500' },
  refused: { icon: 'qa-tile-3', tone: 'red-500' },
}

function Row({ row, first, last }: { row: ActivityRow; first: boolean; last: boolean }) {
  const r = RESULT[row.result]
  const body = (
    <View style={last ? s.actRow2 : s.actRow}>
      <View style={s.actAvatar}>
        <Text face="Inter-Bold" size={10} color="white">{row.initials}</Text>
      </View>
      <View style={s.fill}>
        <Text face="Inter-SemiBold" size={12} color="white">{row.title}</Text>
        <Text face="Inter-Regular" size={10} color={row.queued ? r.tone : 'text-3'} style={{ marginTop: 1 }}>{row.sub}</Text>
        {row.note ? <Text face="Inter-Medium" size={10} lh={1.45} color="red-500" style={{ marginTop: 3 }}>{row.note}</Text> : null}
      </View>
      <View style={s.actTime}>
        <Text face="Inter-SemiBold" size={10.5} color="text-3">{row.time}</Text>
      </View>
      <View style={[s.actResult, { backgroundColor: a(r.tone, 0.14) }]} role="img" aria-label={row.resultLabel}>
        <Icon name={r.icon} size={13} color={r.tone} style={s.actResultSvg} />
      </View>
    </View>
  )
  const edge = [s.cardMid, first ? s.cardFirst : null, last ? s.cardLast : null]
  return row.queued ? (
    <Pressable onPress={() => router.push('/sync-queue' as never)} role="button" aria-label={`${row.title}, ${row.resultLabel}. Open the sync queue`} style={({ pressed }) => [edge, { opacity: pressed ? 0.8 : 1 }]}>
      {body}
    </Pressable>
  ) : (
    <View style={edge}>{body}</View>
  )
}

/**
 * "On site now" (M54 §2.4): one visitor still inside, and the press that records them leaving. Two presses, the second on
 * "Confirm exit", because the gate log is append-only: a slip of the thumb would be a departure nobody can take back.
 */
function OnSite({ row, confirming, onAsk, onConfirm, onCancel, last }: { row: OnSiteRow; confirming: boolean; onAsk: () => void; onConfirm: () => void; onCancel: () => void; last: boolean }) {
  return (
    <View style={[s.siteRow, last ? null : s.siteRowLine]}>
      <View style={s.actAvatar}><Text face="Inter-Bold" size={10} color="white">{row.initials}</Text></View>
      <View style={s.fill}>
        <Text face="Inter-SemiBold" size={12} color="white">{row.title}</Text>
        <Text face="Inter-Regular" size={10} color="text-3" style={{ marginTop: 1 }}>{row.sub}</Text>
      </View>
      {confirming ? (
        <View style={s.confirmRow}>
          <Pressable onPress={onCancel} role="button" aria-label={`Keep ${row.title} on site`} hitSlop={10} style={s.keepBtn}>
            <Text face="Inter-Bold" size={11} color="text-2">{'Keep'}</Text>
          </Pressable>
          <Pressable onPress={onConfirm} role="button" aria-label={`Confirm ${row.title} has left`} hitSlop={10} style={s.exitBtnConfirm}>
            <Text face="Inter-Bold" size={11} color="surface-900">{'Confirm exit'}</Text>
          </Pressable>
        </View>
      ) : (
        <Pressable onPress={onAsk} role="button" aria-label={`Mark ${row.title} as exited`} hitSlop={10} style={s.exitBtn}>
          <Text face="Inter-Bold" size={11} color="amber-500">{'Mark exited'}</Text>
        </Pressable>
      )}
    </View>
  )
}

export default function GateActivityScreen() {
  const m = useGateActivity()
  useFidelityReady(m.status !== 'loading')
  const ready = m.status === 'ready'
  const [confirming, setConfirming] = useState<string | null>(null)

  const header = (
    <>
      {m.status === 'loading' ? <SkeletonLines tone="dark" lines={6} style={{ marginTop: 12 }} /> : null}
      {m.status === 'error' ? <ErrorView tone="dark" message={m.errorMessage ?? "Today's gate log could not be loaded."} onRetry={m.refresh} /> : null}
      {m.status === 'forbidden' ? (
        <EmptyState tone="dark" icon="side-btn-7" title="Not available on this handset" body="This phone is not allowed to read the gate log. Contact your supervisor. What you record at the gate is still saved and sent." />
      ) : null}
      {ready ? (
        <>
          {m.staleNote ? (
            <View style={s.staleNote} role="status">
              <Icon name="offline-note" size={14} color="amber-500" style={s.noteSvg} />
              <View style={s.shrink}><Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-500">{m.staleNote}</Text></View>
            </View>
          ) : null}
          <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper style={{ marginTop: 2, marginLeft: 2, marginRight: 2 }}>{m.countsLabel}</Text>
          <View style={s.statRow}>
            {m.tiles.map((tile) => (
              <View key={tile.key} style={s.statItem}>
                <Text face="Inter-Bold" size={17} color={tile.tone} align="center">{tile.value}</Text>
                <Text face="Inter-SemiBold" size={9} color="text-3" align="center" numberOfLines={1} style={{ marginTop: 2 }}>{tile.label}</Text>
              </View>
            ))}
          </View>
          {/* Who is still inside, estate-wide, and the way to record them leaving (M54 §2.4). Not on the board. */}
          {m.onSite.length > 0 ? (
            <>
              <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper style={{ marginTop: 16, marginLeft: 2, marginRight: 2 }}>{`On site now (${m.onSite.length})`}</Text>
              <View style={s.siteCard}>
                {m.onSite.map((row, i) => (
                  <OnSite
                    key={row.key}
                    row={row}
                    last={i === m.onSite.length - 1}
                    confirming={confirming === row.key}
                    onAsk={() => setConfirming(row.key)}
                    onCancel={() => setConfirming(null)}
                    onConfirm={() => {
                      setConfirming(null)
                      m.markExited(row)
                    }}
                  />
                ))}
              </View>
              {m.exitError ? <Text face="Inter-Medium" size={11} lh={1.45} color="red-500" role="alert" style={{ marginTop: 6 }}>{m.exitError}</Text> : null}
            </>
          ) : null}
          <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper style={{ marginTop: 16, marginLeft: 2, marginRight: 2 }}>{m.listLabel}</Text>
        </>
      ) : null}
    </>
  )

  const footer = ready ? (
    <>
      {m.rows.length === 0 ? (
        <EmptyState
          tone="dark"
          icon="tab-visitors"
          title={m.scope === 'mine' ? 'Nothing recorded by you yet today' : 'Nothing recorded at the gate yet today'}
          body="Every entry, denial and override you record, scanned, typed or walk-up, appears here, newest first. Nothing in this log can be edited or removed."
        />
      ) : null}
      {m.canSwitchScope ? (
        <Pressable onPress={m.toggleScope} role="button" aria-label={m.scope === 'mine' ? "Show everyone's entries at this estate" : 'Show only my entries'} hitSlop={14} style={s.scopeLink}>
          <Text face="Inter-Bold" size={12} color="text-2">
            {m.scope === 'mine' ? `Show everyone's entries at this estate (${m.othersCount} more)` : 'Show only my entries'}
          </Text>
        </Pressable>
      ) : null}
    </>
  ) : null

  return (
    <View style={s.screen}>
      <AppBar title="Today's activity" />
      <FlatList
        data={ready ? m.rows : []}
        keyExtractor={(row) => row.key}
        renderItem={({ item, index }) => <Row row={item} first={index === 0} last={index === m.rows.length - 1} />}
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
  statItem: { flex: 1, backgroundColor: c('surface-800'), borderRadius: 12, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 6, paddingVertical: 11 },
  statRow: { flexDirection: 'row', gap: 8, marginTop: 8 },
  actAvatar: { width: 32, height: 32, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 16 },
  actTime: { flexShrink: 1 },
  actResultSvg: { width: 13, height: 13, overflow: 'hidden' },
  actResult: { width: 26, height: 26, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 13 },
  actRow: { flexDirection: 'row', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: c('border-700'), paddingVertical: 9, gap: 11 },
  actRow2: { flexDirection: 'row', alignItems: 'center', paddingVertical: 9, gap: 11 },
  // The board's .card (radius 16 · 1px border-700 · padding 2/13 · margin 8/12), split across the list's rows.
  cardMid: { backgroundColor: c('surface-800'), borderLeftWidth: 1, borderRightWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13 },
  cardFirst: { borderTopWidth: 1, borderTopLeftRadius: 16, borderTopRightRadius: 16, paddingTop: 2, marginTop: 8 },
  cardLast: { borderBottomWidth: 1, borderBottomLeftRadius: 16, borderBottomRightRadius: 16, paddingBottom: 2, marginBottom: 12 },
  noteSvg: { flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  staleNote: { flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 12, borderWidth: 1, borderColor: a('amber-500', 0.28), paddingHorizontal: 12, paddingVertical: 10, gap: 9, marginBottom: 14 },
  scopeLink: { alignSelf: 'center', marginTop: 8, minHeight: 20, justifyContent: 'center' },
  // "On site now": the board's card and row, with the exit press at the right.
  siteCard: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13, paddingVertical: 2, marginTop: 8 },
  siteRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 9, gap: 11 },
  siteRowLine: { borderBottomWidth: 1, borderBottomColor: c('border-700') },
  exitBtn: { flexShrink: 0, borderRadius: 10, borderWidth: 1, borderColor: a('amber-500', 0.5), paddingHorizontal: 10, paddingVertical: 7 },
  exitBtnConfirm: { flexShrink: 0, borderRadius: 10, backgroundColor: c('amber-500'), paddingHorizontal: 10, paddingVertical: 7 },
  keepBtn: { flexShrink: 0, borderRadius: 10, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 10, paddingVertical: 7 },
  confirmRow: { flexDirection: 'row', gap: 6, flexShrink: 0 },
})
