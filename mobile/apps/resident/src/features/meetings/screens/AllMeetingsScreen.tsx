/**
 * All meetings — every meeting the estate has published to this household: upcoming first, then the past.
 *
 * NO BOARD DRAWS THIS SCREEN. It is a named screen in the specification, and its absence is the
 * first thing a resident notices. It is assembled from the boards' own parts, value for value:
 *   - the card, its icon tile and its type line are board 12's notice card (resident-app-12);
 *   - the section headings and the status chip are board 26's ("Status" · the pill on "Your requests");
 *   - when and where are said in the Meeting Lobby's own words (resident-app-13, meetings.ts).
 * Every row opens the existing lobby. All behaviour lives in useAllMeetings().
 *
 * Six states: a skeleton while the first answer is on its way · "No meetings yet" · the list · the server's
 * sentence with a retry · a 403 said plainly (it outranks a saved copy) · a saved copy with the true reason it is
 * not fresh. Older meetings are fetched a page at a time and need a connection — the button says so when there is
 * none, and the list says when it has reached the end.
 */

import { RefreshControl, SectionList, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { EmptyState, ErrorView, Icon, Pressable, ResidentAppBar, ResidentButton, SkeletonLines, Text, a, c, sh, type ColorToken } from '@gs/core'
import type { ChipTone, MeetingRow } from '../meetingList'
import { useAllMeetings, type AllMeetingsModel } from '../useAllMeetings'

const TONE: Record<ChipTone, { ink: ColorToken; wash: ColorToken }> = {
  navy: { ink: 'navy-700', wash: 'navy-100' },
  amber: { ink: 'amber-700', wash: 'amber-100' },
  green: { ink: 'green-700', wash: 'success-100' },
  slate: { ink: 'slate-600', wash: 'navy-100' },
}

function Row({ row }: { row: MeetingRow }) {
  const tone = TONE[row.chip.tone]
  return (
    <Pressable onPress={() => router.push(row.path as never)} role="link" aria-label={row.label} style={({ pressed }) => [s.card, pressed ? s.cardPressed : null]}>
      <View style={s.tile}><Icon name="input" size={18} color="navy-600" /></View>
      <View style={s.fill}>
        <View style={s.top}>
          <View style={s.shrink}><Text face="Inter-Bold" size={9.5} ls={0.2} color="slate-600" numberOfLines={1}>{row.kind.toUpperCase()}</Text></View>
          <View style={[s.chip, { backgroundColor: c(tone.wash) }]}><Text face="Inter-Bold" size={10} color={tone.ink} numberOfLines={1}>{row.chip.label}</Text></View>
        </View>
        <Text face="Inter-Bold" size={13} color="navy-900" style={{ marginTop: 4 }}>{row.title}</Text>
        <Text face="Inter-Regular" size={11.5} lh={1.45} color="slate-600" style={{ marginTop: 3 }}>{row.when}</Text>
        <Text face="Inter-Regular" size={11.5} lh={1.45} color="slate-600">{row.where}</Text>
        {row.reply ? <Text face="Inter-SemiBold" size={11} lh={1.45} color="navy-700" style={{ marginTop: 5 }}>{row.reply}</Text> : null}
        {row.minutes ? <Text face="Inter-SemiBold" size={11} lh={1.45} color="green-700" style={{ marginTop: row.reply ? 1 : 5 }}>{'Minutes are ready'}</Text> : null}
      </View>
      <Icon name="chevron-right" size={14} color="slate-500" />
    </Pressable>
  )
}

function Loading() {
  return (
    <View role="progressbar" aria-label="Loading your estate's meetings">
      {[0, 1, 2].map((i) => (
        <View key={i} style={s.card}><SkeletonLines tone="light" lines={3} style={s.fill} /></View>
      ))}
    </View>
  )
}

function Footer({ m }: { m: AllMeetingsModel }) {
  if (m.status !== 'ready') return null
  return (
    <View style={s.footer}>
      {m.older.available ? (
        <>
          {m.older.blocked ? (
            <ResidentButton variant="outline" label="Show older meetings" onPress={m.older.load} disabled disabledReason={m.older.blocked} />
          ) : (
            <ResidentButton variant="outline" label="Show older meetings" onPress={m.older.load} loading={m.older.loading} />
          )}
          {m.older.blocked ? <Text face="Inter-Regular" size={11.5} lh={1.5} color="slate-600" align="center" style={s.footNote} role="status">{m.older.blocked}</Text> : null}
        </>
      ) : null}
      {m.older.problem ? <Text face="Inter-SemiBold" size={11.5} lh={1.5} color="amber-700" align="center" style={s.footNote} role="alert">{m.older.problem}</Text> : null}
      {m.endNote ? <Text face="Inter-Regular" size={11.5} lh={1.5} color="slate-600" align="center" style={s.footNote}>{m.endNote}</Text> : null}
    </View>
  )
}

export default function AllMeetingsScreen() {
  const m = useAllMeetings()

  const empty =
    m.status === 'loading' ? <Loading />
    : m.status === 'error' ? <ErrorView tone="light" message={m.errorMessage ?? "Your estate's meetings could not be loaded."} onRetry={m.refresh} />
    : m.status === 'denied' ? <EmptyState tone="light" icon="privacy-note" title="Not available for this account" body={m.errorMessage ?? 'Your account cannot see the estate’s meetings.'} />
    : <EmptyState tone="light" icon="input" title="No meetings yet" body="When your estate calls a general or phase meeting, it appears here with its date, its place and its agenda, and stays here afterwards." />

  return (
    <View style={s.screen}>
      <ResidentAppBar title="All meetings" fallback="/(tabs)" />
      <SectionList
        style={s.fill}
        contentContainerStyle={s.bodyPad}
        sections={m.sections.map((section) => ({ ...section, data: section.rows }))}
        keyExtractor={(row) => row.id}
        renderItem={({ item }) => <Row row={item} />}
        renderSectionHeader={({ section }) => <Text face="Inter-Bold" size={11.5} ls={0.2} color="slate-600" style={s.heading} role="heading">{section.title}</Text>}
        stickySectionHeadersEnabled={false}
        ListHeaderComponent={m.staleNote ? <View style={s.stale} role="status"><Text face="Inter-SemiBold" size={11.5} lh={1.45} color="amber-700">{m.staleNote}</Text></View> : null}
        ListEmptyComponent={empty}
        ListFooterComponent={<Footer m={m} />}
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('navy-600')} />}
      />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('white'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 6, paddingBottom: 32, paddingHorizontal: 20 },
  // Board 26's section heading.
  heading: { marginTop: 12, marginHorizontal: 2 },
  // Board 12's card and icon tile.
  card: { minHeight: 64, flexDirection: 'row', alignItems: 'center', backgroundColor: c('white'), borderRadius: 16, borderWidth: 1, borderColor: c('navy-100'), padding: 14, gap: 11, boxShadow: sh([{ x: 0, y: 8, blur: 20, spread: 0, token: 'navy-800', opacity: 0.06 }]), marginTop: 8 },
  cardPressed: { backgroundColor: a('navy-100', 0.5) },
  tile: { width: 38, height: 38, flexGrow: 0, flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: c('navy-100'), borderRadius: 11, alignSelf: 'flex-start' },
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  // Board 26's status pill.
  chip: { paddingHorizontal: 9, paddingVertical: 4, borderRadius: 20, flexGrow: 0, flexShrink: 0 },
  footer: { marginTop: 18 },
  footNote: { marginTop: 8, marginHorizontal: 2 },
  stale: { backgroundColor: c('amber-100'), borderRadius: 12, padding: 12, marginTop: 12 },
})
