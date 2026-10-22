/**
 * Elections — every election and resolution the estate has published to this household, the open vote first
 * (electionList.ts). Reached from Settings → Governance and from the card on Notices (the user, 8 Oct 2026), because a
 * resident who missed the "Voting is open" notification had no other way in.
 *
 * NO BOARD DRAWS THIS SCREEN. It wears All meetings' parts, value for value: board 12's card and icon tile, board 26's
 * status pill. A Member's: a Dependent or a Helper is told it is not part of their access, as every gated tab tells them.
 */

import { FlatList, RefreshControl, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { EmptyState, ErrorView, Icon, Pressable, ResidentAppBar, SkeletonLines, Text, a, c, isFidelity, sh, type ColorToken } from '@gs/core'
import { residentApi } from '@gs/core/api/resident'
import { notForLevelSentence } from '@/features/access/access'
import { useAccess } from '@/features/access/useAccess'
import { electionRows, type ElectionListItem, type ElectionRow, type ElectionTone } from '../electionList'

const TONE: Record<ElectionTone, { ink: ColorToken; wash: ColorToken }> = {
  navy: { ink: 'navy-700', wash: 'navy-100' },
  green: { ink: 'green-700', wash: 'success-100' },
  slate: { ink: 'slate-600', wash: 'navy-100' },
}

function Row({ row }: { row: ElectionRow }) {
  const tone = TONE[row.chip.tone]
  const body = (
    <>
      <View style={s.tile}><Icon name="tab-card" size={18} color="navy-600" /></View>
      <View style={s.fill}>
        <View style={s.top}>
          <View style={s.shrink}><Text face="Inter-Bold" size={9.5} ls={0.2} color="slate-600" numberOfLines={1}>{row.kind.toUpperCase()}</Text></View>
          <View style={[s.chip, { backgroundColor: c(tone.wash) }]}><Text face="Inter-Bold" size={10} color={tone.ink} numberOfLines={1}>{row.chip.label}</Text></View>
        </View>
        <Text face="Inter-Bold" size={13} color="navy-900" style={{ marginTop: 4 }}>{row.title}</Text>
        <Text face="Inter-Regular" size={11.5} lh={1.45} color="slate-600" style={{ marginTop: 3 }}>{row.detail}</Text>
      </View>
      {row.path ? <Icon name="chevron-right" size={14} color="slate-500" /> : null}
    </>
  )
  if (!row.path) return <View style={s.card} aria-label={row.label}>{body}</View>
  const path = row.path
  return (
    <Pressable onPress={() => router.push(path as never)} role="link" aria-label={row.label} style={({ pressed }) => [s.card, pressed ? s.cardPressed : null]}>
      {body}
    </Pressable>
  )
}

export default function AllElectionsScreen() {
  const { access, may } = useAccess()
  const allowed = may('elections')
  // Live only: voting is online only, and this feature keeps nothing on the phone but published results (secrecy.test).
  const query = residentApi.useElectionsIndexQuery(undefined, { skip: isFidelity || !allowed })
  const saved = query.data
  const rows = electionRows(((saved?.items ?? []) as unknown) as ElectionListItem[])

  const empty = !allowed ? (
    <EmptyState tone="light" icon="privacy-note" title="Not part of your access" body={notForLevelSentence(access, 'elections')} />
  ) : query.isLoading && !saved ? (
    <View role="progressbar" aria-label="Loading your estate's elections">
      {[0, 1].map((i) => (
        <View key={i} style={s.card}><SkeletonLines tone="light" lines={2} style={s.fill} /></View>
      ))}
    </View>
  ) : query.isError && !saved ? (
    <ErrorView tone="light" message="Your estate's elections could not be loaded." onRetry={() => void query.refetch()} />
  ) : (
    <EmptyState tone="light" icon="tab-card" title="No elections yet" body="When your estate opens a vote, it appears here. One vote per household." />
  )

  return (
    <View style={s.screen}>
      <ResidentAppBar title="Elections" fallback="/settings" />
      <FlatList
        style={s.fill}
        contentContainerStyle={s.bodyPad}
        data={allowed ? rows : []}
        keyExtractor={(row) => row.id}
        renderItem={({ item }) => <Row row={item} />}
        ListEmptyComponent={empty}
        refreshControl={allowed ? <RefreshControl refreshing={query.isFetching && !query.isLoading} onRefresh={() => void query.refetch()} tintColor={c('navy-600')} /> : undefined}
      />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('white'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 6, paddingBottom: 32, paddingHorizontal: 20 },
  // Board 12's card and icon tile, as All meetings draws them.
  card: { minHeight: 64, flexDirection: 'row', alignItems: 'center', backgroundColor: c('white'), borderRadius: 16, borderWidth: 1, borderColor: c('navy-100'), padding: 14, gap: 11, boxShadow: sh([{ x: 0, y: 8, blur: 20, spread: 0, token: 'navy-800', opacity: 0.06 }]), marginTop: 8 },
  cardPressed: { backgroundColor: a('navy-100', 0.5) },
  tile: { width: 38, height: 38, flexGrow: 0, flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: c('navy-100'), borderRadius: 11, alignSelf: 'flex-start' },
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  // Board 26's status pill.
  chip: { paddingHorizontal: 9, paddingVertical: 4, borderRadius: 20, flexGrow: 0, flexShrink: 0 },
})
