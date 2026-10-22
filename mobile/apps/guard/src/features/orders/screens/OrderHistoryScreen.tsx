/**
 * Order history — board guard-app-16: the guard's proof of what they were instructed and
 * when. "All versions" is the current post's order set; "Other posts you cover" is every
 * other set this guard has acknowledged. Plus loading, empty, error, 403 and offline.
 *
 * The server keeps the TEXT of the version in force only (`orders.current`), so the
 * "Current" line opens the orders, and an earlier line — drawn with the board's chevron —
 * opens a plain sentence saying what the line proves and where its text can be had.
 * Nothing is invented, and no line is dead. History is append-only: no edit, no delete.
 *
 * Layout values are the board's own, lifted (src/lifted/GuardApp16.tsx).
 * All behaviour lives in useOrderHistory(); this file only draws it.
 */

import { useState } from 'react'
import { FlatList, RefreshControl, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { AppBar, EmptyState, ErrorView, Icon, Pressable, SkeletonLines, Text, a, c, useFidelityReady } from '@gs/core'
import { useOrderHistory, type HistoryGroup, type HistoryRow } from '../useStandingOrders'

const openOrders = () => router.dismissTo('/orders' as never)

function Row({ row, last, open, onToggle }: { row: HistoryRow; last: boolean; open: boolean; onToggle: () => void }) {
  return (
    <View style={last ? null : s.divider}>
      <Pressable
        onPress={row.current ? openOrders : onToggle}
        role="button"
        aria-label={`${row.title}. ${row.detail}.${row.current ? ' Current version. Opens the orders' : ''}`}
        aria-expanded={row.current ? undefined : open}
        style={({ pressed }) => [s.ohRow, pressed ? { opacity: 0.7 } : null]}
      >
        <View style={row.current ? s.ohIcon : s.ohIcon2}>
          <Icon name="order-banner" size={17} color={row.current ? 'amber-500' : 'text-2'} />
        </View>
        <View style={s.grow}>
          <Text face="Inter-Bold" size={13} color="white">{row.title}</Text>
          <Text face="Inter-Regular" size={10.5} color="text-3" style={{ marginTop: 2 }}>{row.detail}</Text>
        </View>
        {row.current ? (
          <View style={s.ohCurrentTag}>
            <Text face="Inter-Bold" size={9} color="g-navy-900">{'Current'}</Text>
          </View>
        ) : (
          <Icon name="chevron-right" size={16} color="text-3" style={[s.chevSvg, open ? { transform: [{ rotate: '90deg' }] } : null]} />
        )}
      </Pressable>
      {open && !row.current ? (
        <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-2" style={s.explain}>{row.explain}</Text>
      ) : null}
    </View>
  )
}

export default function OrderHistoryScreen() {
  const m = useOrderHistory()
  useFidelityReady(m.status !== 'loading')
  const insets = useSafeAreaInsets()
  const [openKey, setOpenKey] = useState<string | null>(null)

  const renderGroup = ({ item, index }: { item: HistoryGroup; index: number }) => (
    <View>
      <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={{ marginTop: index === 0 ? 2 : 18, marginLeft: 2, marginRight: 2 }}>{item.label}</Text>
      <View style={[s.card, index === m.groups.length - 1 ? { marginBottom: 12 } : null]}>
        {item.rows.map((row, i) => (
          <Row key={row.key} row={row} last={i === item.rows.length - 1} open={openKey === row.key} onToggle={() => setOpenKey((k) => (k === row.key ? null : row.key))} />
        ))}
      </View>
    </View>
  )

  return (
    <View style={s.screen}>
      <AppBar title={m.title} />
      <FlatList
        data={m.status === 'ready' ? m.groups : []}
        keyExtractor={(group) => group.key}
        renderItem={renderGroup}
        extraData={openKey}
        // Opaque, in the screen's own colour: a scroller is its own layer, and text on a see-through layer is smoothed differently.
        style={s.scroll}
        contentContainerStyle={[s.bodyPad, { paddingBottom: 20 + insets.bottom }]}
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('amber-500')} />}
        ListHeaderComponent={
          m.status === 'ready' && m.stale ? (
            <View style={s.staleNote}>
              <Icon name="offline-note" size={14} color="text-3" style={{ marginTop: 1 }} />
              <View style={s.shrink}>
                <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3">{m.stale}</Text>
              </View>
            </View>
          ) : null
        }
        ListEmptyComponent={
          m.status === 'loading' ? (
            <SkeletonLines tone="dark" lines={6} style={{ marginTop: 12 }} />
          ) : m.status === 'error' ? (
            <ErrorView tone="dark" message={m.errorMessage ?? 'Your order history could not be loaded.'} onRetry={m.refresh} />
          ) : m.status === 'denied' ? (
            <EmptyState tone="dark" icon="order-banner" title="Not available on this handset" body={m.errorMessage ?? 'A handset reads the orders for its own site. If your posting has changed, ask your supervisor to check this handset.'} />
          ) : m.status === 'no_shift' ? (
            <EmptyState tone="dark" icon="order-banner" title="No post, no history to show" body="Your acknowledgements are listed against the post you are rostered to. When a shift is rostered to you, they appear here." />
          ) : (
            <EmptyState tone="dark" icon="order-banner" title="Nothing acknowledged yet" body="When you acknowledge a version of your post's standing orders it is listed here with the time: your proof of what you were instructed, and when." action={{ label: 'Read the standing orders', onPress: openOrders }} />
          )
        }
      />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: c('surface-900') },
  scroll: { flex: 1, backgroundColor: c('surface-900') },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  grow: { flex: 1 },
  shrink: { flexShrink: 1 },
  staleNote: { flexDirection: 'row', gap: 9, marginBottom: 12 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13, paddingVertical: 2, marginTop: 8 },
  divider: { borderBottomWidth: 1, borderBottomColor: c('border-700') },
  ohRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 13, gap: 12 },
  ohIcon: { width: 38, height: 38, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: a('amber-500', 0.14), borderRadius: 11 },
  ohIcon2: { width: 38, height: 38, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 11 },
  ohCurrentTag: { backgroundColor: c('amber-500'), flexShrink: 1, borderRadius: 20, paddingHorizontal: 9, paddingVertical: 4 },
  chevSvg: { width: 16, height: 16, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  explain: { paddingLeft: 50, paddingBottom: 13, marginTop: -4 },
})
