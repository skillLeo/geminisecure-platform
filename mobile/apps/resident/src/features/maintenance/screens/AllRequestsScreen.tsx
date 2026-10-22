/**
 * All requests — everything this household has sent the estate office, newest first.
 *
 * NO BOARD DRAWS THIS SCREEN. It is a named screen in the specification, and its absence is the
 * first thing a resident notices. It is assembled from the boards' own parts, value for value:
 *   - the card, its icon tile and its status pill are board 26's (resident-app-26) — the very row "Your requests"
 *     draws under the new-request form;
 *   - the filter chips are board 12's (resident-app-12).
 * The list holds BOTH kinds of thing the server files as a ticket — a maintenance request and a security report —
 * each marked as what it is, each opening the screen that already shows it (Maintenance Status · Incident Status).
 * A row still on this phone says so and opens nothing: the server has given it no id. All behaviour lives in
 * useAllRequests().
 *
 * Six states: a skeleton while the first answer is on its way · "Nothing sent yet", with the two ways to send
 * something · the list · the server's sentence with a retry · a 403 said plainly (it outranks a saved copy) · a
 * saved copy with the true reason it is not fresh. Older requests are fetched a page at a time and need a
 * connection — the button says so when there is none, and the list says when it has reached the end.
 */

import { RefreshControl, SectionList, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { EmptyState, ErrorView, Icon, Pressable, ResidentButton, SkeletonLines, Text, a, c, now, sh, type ColorToken } from '@gs/core'
import { sinceWords } from '@gs/core/lib'
import { AppBar } from '@/design/parts'
import { SavedRow } from '../components/SavedRow'
import { tabEntry, type ChipTone, type RequestRow } from '../requestList'
import { useAllRequests, type AllRequestsModel } from '../useAllRequests'

/** `red` (a request the estate did not accept) is an OUTLINE: white, a red-700 edge, red-700 words (M98). Never a red fill. */
const TONE: Record<ChipTone, { ink: ColorToken; wash: ColorToken; edge?: ColorToken }> = {
  navy: { ink: 'navy-700', wash: 'navy-100' },
  amber: { ink: 'amber-700', wash: 'amber-100' },
  green: { ink: 'green-700', wash: 'success-100' },
  slate: { ink: 'slate-600', wash: 'navy-100' },
  red: { ink: 'red-700', wash: 'rd-white', edge: 'rd-red-700' },
}

/** Maintenance | Incidents, one control, the open count beside each (M98: "easy to use, easy to get the point"). */
function Tabs({ m }: { m: AllRequestsModel }) {
  return (
    <View style={s.seg} role="tablist" aria-label="What to show">
      {m.tabs.map((tab) => {
        const on = tab.key === m.tab
        const words = tab.open > 0 ? `${tab.label} · ${tab.open} open` : tab.label
        return (
          <Pressable key={tab.key} onPress={() => m.setTab(tab.key)} role="tab" aria-selected={on} aria-label={`Show ${words}`} style={on ? s.segOn : s.segOff}>
            <Text face="Inter-SemiBold" size={12.5} color={on ? 'white' : 'navy-700'} numberOfLines={1} align="center">{words}</Text>
          </Pressable>
        )
      })}
    </View>
  )
}

function Row({ row }: { row: RequestRow }) {
  // "Saved on this phone … — not sent yet": restyled from the design tokens, with its honest mark (components/SavedRow).
  if (row.queue === 'pending' || row.queue === 'syncing') return <SavedRow row={row} />
  const tone = TONE[row.chip.tone]
  const body = (
    <>
      <View style={s.tile}><Icon name={row.kind === 'report' ? 'qa-tile-3' : 'rp-icon'} size={18} color="navy-600" /></View>
      <View style={s.fill}>
        <Text face="Inter-Bold" size={9.5} ls={0.2} color="slate-600" numberOfLines={1}>{row.kindLabel.toUpperCase()}</Text>
        <Text face="Inter-Bold" size={12.5} color="navy-900" numberOfLines={2} style={{ marginTop: 2 }}>{row.title}</Text>
        <Text face="Inter-Regular" size={11} lh={1.45} color={row.queue === 'refused' ? 'amber-700' : 'slate-600'} style={{ marginTop: 2 }} numberOfLines={row.queue === 'refused' ? 4 : 2}>{row.detail}</Text>
        {row.updated ? <Text face="Inter-Regular" size={10.5} color="rd-slate-500" style={{ marginTop: 2 }} numberOfLines={1}>{`Updated ${sinceWords(row.updated, now())}`}</Text> : null}
      </View>
      <View style={[s.pill, { backgroundColor: c(tone.wash) }, tone.edge ? { borderWidth: 1, borderColor: c(tone.edge) } : null]}><Text face="Inter-Bold" size={10} color={tone.ink} numberOfLines={1}>{row.chip.label}</Text></View>
      {row.path ? <Icon name="chevron-right" size={14} color="slate-500" /> : null}
    </>
  )
  const path = row.path
  return path ? (
    <Pressable onPress={() => router.push(path as never)} role="link" aria-label={row.label} style={({ pressed }) => [s.row, pressed ? s.rowPressed : null]}>{body}</Pressable>
  ) : (
    <View style={s.row} role="status" aria-label={row.label}>{body}</View>
  )
}

function Loading() {
  return (
    <View role="progressbar" aria-label="Loading your requests">
      {[0, 1, 2, 3].map((i) => (
        <View key={i} style={s.row}><SkeletonLines tone="light" lines={2} style={s.fill} /></View>
      ))}
    </View>
  )
}

function Footer({ m }: { m: AllRequestsModel }) {
  if (m.status !== 'ready') return null
  return (
    <View style={s.footer}>
      {m.older.available ? (
        <>
          {m.older.blocked ? (
            <ResidentButton variant="outline" label="Show older requests" onPress={m.older.load} disabled disabledReason={m.older.blocked} />
          ) : (
            <ResidentButton variant="outline" label="Show older requests" onPress={m.older.load} loading={m.older.loading} />
          )}
          {m.older.blocked ? <Text face="Inter-Regular" size={11.5} lh={1.5} color="slate-600" align="center" style={s.footNote} role="status">{m.older.blocked}</Text> : null}
        </>
      ) : null}
      {m.older.problem ? <Text face="Inter-SemiBold" size={11.5} lh={1.5} color="amber-700" align="center" style={s.footNote} role="alert">{m.older.problem}</Text> : null}
      {m.endNote ? <Text face="Inter-Regular" size={11.5} lh={1.5} color="slate-600" align="center" style={s.footNote}>{m.endNote}</Text> : null}
    </View>
  )
}

export default function AllRequestsScreen() {
  const m = useAllRequests()
  const hasRows = m.rows.length > 0

  const header = (
    <>
      <Tabs m={m} />
      {m.staleNote ? <View style={s.stale} role="status"><Text face="Inter-SemiBold" size={11.5} lh={1.45} color="amber-700">{m.staleNote}</Text></View> : null}
      {/* What is still on this phone is listed even when the estate's list is not — so the reason it is missing is said above it. */}
      {hasRows && m.status === 'denied' ? <View style={s.plain} role="status"><Text face="Inter-Regular" size={11.5} lh={1.5} color="slate-600">{m.errorMessage ?? 'Your account cannot see the requests the estate office holds.'}</Text></View> : null}
      {hasRows && m.status === 'error' ? (
        <Pressable onPress={m.refresh} role="button" aria-label="The estate's list could not be loaded. Try again" style={s.plain}>
          <Text face="Inter-Regular" size={11.5} lh={1.5} color="slate-600">{`${m.errorMessage ?? "The estate's list could not be loaded."} `}<Text face="Inter-Bold" color="navy-600">{'Try again'}</Text></Text>
        </Pressable>
      ) : null}
      {hasRows && m.status === 'loading' ? <SkeletonLines tone="light" lines={2} style={{ marginTop: 12 }} /> : null}
    </>
  )

  const empty =
    m.status === 'loading' ? <Loading />
    : m.status === 'error' ? <ErrorView tone="light" message={m.errorMessage ?? 'Your requests could not be loaded.'} onRetry={m.refresh} />
    : m.status === 'denied' ? <EmptyState tone="light" icon="privacy-note" title="Not available for this account" body={m.errorMessage ?? 'Your account cannot see maintenance requests.'} />
    : m.nothingYet ? (
      <EmptyState
        tone="light"
        icon="rp-icon"
        title="Nothing sent yet"
        body="When you ask for something to be fixed, or report something to the estate office, it appears here, and you can follow it step by step."
        action={{ label: 'Report a problem', onPress: () => router.push('/maintenance/new' as never) }}
      />
    ) : m.tab === 'request' ? (
      <EmptyState tone="light" icon="rp-icon" title="No maintenance requests" body="When something needs fixing, report it and follow it here." action={{ label: 'Report a problem', onPress: () => router.push(tabEntry('request').form as never) }} />
    ) : (
      <EmptyState tone="light" icon="qa-tile-3" title="No incident reports" body="When you report something to the security team, it appears here." action={{ label: 'Report an incident', onPress: () => router.push(tabEntry('report').form as never) }} />
    )

  return (
    <View style={s.screen}>
      {/* The plus, as Visitors and amenities draw theirs: the list first, then the form (Hassam, 12 Oct). */}
      <AppBar title="My requests" fallback="/(tabs)" right={{ icon: 'side-btn-8', label: tabEntry(m.tab).plus, caption: 'Add', onPress: () => router.push(tabEntry(m.tab).form as never) }} />
      <SectionList
        style={s.fill}
        contentContainerStyle={s.bodyPad}
        sections={m.sections.map((section) => ({ key: section.key, title: section.title, data: section.rows }))}
        keyExtractor={(row) => row.key}
        renderItem={({ item }) => <Row row={item} />}
        renderSectionHeader={({ section }) => <Text face="Inter-Bold" size={11} ls={0.3} color="slate-600" style={s.sectionHead} role="heading">{section.title.toUpperCase()}</Text>}
        stickySectionHeadersEnabled={false}
        ListHeaderComponent={header}
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
  bodyPad: { paddingTop: 10, paddingBottom: 32, paddingHorizontal: 20 },
  // The two tabs: one segmented control, each half 44 tall to a finger.
  seg: { flexDirection: 'row', backgroundColor: c('navy-100'), borderRadius: 14, padding: 4, gap: 4 },
  segOn: { flex: 1, minHeight: 44, justifyContent: 'center', backgroundColor: c('navy-600'), borderRadius: 11, paddingHorizontal: 8 },
  segOff: { flex: 1, minHeight: 44, justifyContent: 'center', borderRadius: 11, paddingHorizontal: 8 },
  sectionHead: { marginTop: 16, marginBottom: 2 },
  // Board 26's card, icon tile and status pill — "Your requests"' own row.
  row: { minHeight: 64, flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: c('white'), borderRadius: 16, borderWidth: 1, borderColor: c('navy-100'), paddingHorizontal: 12, paddingVertical: 10, marginTop: 8, boxShadow: sh([{ x: 0, y: 8, blur: 20, spread: 0, token: 'navy-800', opacity: 0.06 }]) },
  rowPressed: { backgroundColor: a('navy-100', 0.5) },
  tile: { width: 40, height: 40, flexGrow: 0, flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: c('navy-100'), borderRadius: 12 },
  pill: { paddingHorizontal: 9, paddingVertical: 4, borderRadius: 20, flexGrow: 0, flexShrink: 0 },
  footer: { marginTop: 18 },
  footNote: { marginTop: 8, marginHorizontal: 2 },
  stale: { backgroundColor: c('amber-100'), borderRadius: 12, padding: 12, marginTop: 8 },
  plain: { backgroundColor: c('navy-100'), borderRadius: 12, padding: 12, marginTop: 8, minHeight: 44, justifyContent: 'center' },
})
