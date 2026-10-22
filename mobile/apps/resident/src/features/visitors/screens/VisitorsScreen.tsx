/**
 * resident-app-08 · Visitors ("Visitor Tracking") — rebuilt from the board (M42 §5 #7), every value from the design
 * tokens and parts.
 *
 * A TAB screen: the navigator draws the tab bar. The bar: the back chevron, "Visitors", and the white "+" that opens
 * Invite visitor — no panic shield (M42 §6.1). Two underlined tabs: "On site now", first and the default, then
 * "History". "Expected" is deleted (M42 §5 #7).
 *
 *   On site now   a card per visitor — initials · name · "Arrived 2:14 PM · Guest of Andrea Fletcher" (or "· Contractor
 *                 pass") · the duration badge; then everyone still expected today, as Home lists them (M47 §5.3) —
 *                 what the visit is for, and the window in the badge — then "Recent activity", a row per gate event:
 *                 the arrow tile (→ entered, ← exited) · name · "Entered · Main Gate · by Guard Whyte" · its time.
 *   History       the past passes, in the same card.
 *
 * MY E-PASS, ITS OWN ROW (M50 §6.1, superseding M47 §5.3): the resident's own pass is a credential, not a visitor — so
 * never a card with a duration badge, never in History — but it belongs on this screen. It is one row at the top of "On
 * site now", gold where the cards are white, set apart from them; every resident reaches it, whatever their level, and
 * it opens the e-Pass. The board does not draw it, so the board's own picture (fidelity mode) is drawn without it.
 *
 * NOBODY ON SITE IS SAID, IN ONE LINE (M50 §6.2). The tab lists the visitors the gate let in today and the passes still
 * open today, worked out again every minute — so the last expected visitor drops off when their window closes, and the
 * list used to go blank with nothing in its place. Now an empty tab says so in one line, with one way on.
 *
 * What each line SAYS is the hook's (useVisitorTracking → tracking.ts): the server tells a resident when, where and by
 * whom a visitor was let in, and nothing about leaving — so no live row says "Exited". This file draws what it is
 * handed — the design's own sample too, in fidelity mode — and reads no mode of its own. A card or row of a real pass
 * opens it.
 *
 * ONE LINE EACH (M47 §5.6: "Not used · Guest of Hassam / Ahmed" wrapped): the badge and the time sit beside the NAME,
 * so the line under it has the card's whole width — the board's own longest, "Arrived 2:14 PM · Guest of Andrea
 * Fletcher", is one line on a 360-point phone. Nothing is cut short to fit: a line longer still wraps.
 *
 * FIXED, NOT A PAGE THAT SCROLLS: the bar and the tabs stay put; only the list under them scrolls, and only when real
 * data runs longer than the screen — with the design's content nothing does.
 */

import { useMemo, useState } from 'react'
import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { EmptyState, ErrorView, Icon, Pressable, Text, useFidelityReady } from '@gs/core'
import { AppBar, PRESSED } from '@/design/parts'
import { corner, fill, font, icon, ink, line, radius, size, space, stroke } from '@/design/tokens'
import type { ActivityRow, SiteCard } from '../tracking'
import { useVisitorTracking, type TrackingModel } from '../useVisitorTracking'

export type VisitorsTab = 'onSite' | 'history'

const TABS: { key: VisitorsTab; label: string }[] = [
  { key: 'onSite', label: 'On site now' },
  { key: 'history', label: 'History' },
]

const openPass = (passId: string) => router.push(`/visitors/pass/${passId}` as never)

/** Said when the server refused the list with no sentence of its own. */
const NOT_LINKED = "Your account is not linked to a home on the estate's register yet. Once the estate approves your claim, you can invite visitors here."

/** `.seg2` — the two tabs over the navy-100 rule; the open one navy-700 with its navy-600 underline. */
function Tabs({ tab, onChange }: { tab: VisitorsTab; onChange: (tab: VisitorsTab) => void }) {
  return (
    <View style={s.seg2} role="tablist">
      {TABS.map((item) => {
        const on = item.key === tab
        return (
          <Pressable key={item.key} onPress={() => onChange(item.key)} role="tab" aria-selected={on} aria-label={item.label} hitSlop={space[10]} style={s.seg2Item}>
            <Text {...font.inter700_13} color={on ? ink.onTint : ink.secondary}>{item.label}</Text>
            {on ? <View style={s.seg2Underline} /> : null}
          </Pressable>
        )
      })}
    </View>
  )
}

/** `.site-card` — the avatar's initials, the name with the badge on its right, and the line under both. */
function VisitorCard({ card }: { card: SiteCard }) {
  const inside = (
    <>
      <View style={s.avatar}>
        <Text {...font.inter700_11_5} color={ink.onTint}>{card.initials}</Text>
      </View>
      <View style={s.info}>
        <View style={s.top}>
          <View style={s.name}><Text {...font.inter700_13} color={ink.heading}>{card.name}</Text></View>
          <View style={s.durChip}>
            <Text {...font.inter700_10} color={ink.onTint}>{card.badge}</Text>
          </View>
        </View>
        <Text {...font.inter400_11} color={ink.secondary} style={s.cardLine}>{card.line}</Text>
      </View>
    </>
  )
  const passId = card.passId
  return passId ? (
    <Pressable onPress={() => openPass(passId)} role="button" aria-label={`${card.name}, ${card.line}, ${card.badge}`} style={({ pressed }) => [s.siteCard, pressed ? s.pressed : null]}>
      {inside}
    </Pressable>
  ) : (
    <View style={s.siteCard}>{inside}</View>
  )
}

/** `.sec-title` — 20 below the cards (or the tabs), 10 above its rows. */
function RecentActivityTitle({ first }: { first: boolean }) {
  return (
    <Text {...font.inter700_12} color={ink.label} role="heading" style={first ? s.secTitleFirst : s.secTitle}>
      {'Recent activity'}
    </Text>
  )
}

/** `.activity-row` — the arrow tile (navy-600 → in, slate-500 ← out), the name with the time on its right, the line under both. */
function GateRow({ row, last }: { row: ActivityRow; last: boolean }) {
  const inside = (
    <>
      <View style={s.aIcon}>
        <Icon name={row.entered ? 'a-icon' : 'a-icon-2'} size={icon[14]} color={row.entered ? ink.action : ink.secondary} />
      </View>
      <View style={s.info}>
        <View style={s.rowTop}>
          <View style={s.name}><Text {...font.inter600_12} color={ink.heading}>{row.name}</Text></View>
          <Text {...font.inter600_10_5} color={ink.secondary} align="right">{row.time}</Text>
        </View>
        <Text {...font.inter400_10_5} color={ink.secondary} style={s.rowLine}>{row.line}</Text>
      </View>
    </>
  )
  const look = last ? s.activityRowLast : s.activityRow
  const passId = row.passId
  return passId ? (
    <Pressable onPress={() => openPass(passId)} role="button" aria-label={`${row.name}, ${row.line}, ${row.time}`} style={({ pressed }) => [look, pressed ? s.pressed : null]}>
      {inside}
    </Pressable>
  ) : (
    <View style={look}>{inside}</View>
  )
}

/** My e-Pass (M50 §6.1): the resident's own pass, one gold row over the visitors — set apart from them, never a card among them. */
function EpassRow() {
  return (
    <Pressable onPress={() => router.push('/visitors/pass/e-pass' as never)} role="button" aria-label="My e-Pass. Your own pass for the gate" style={({ pressed }) => [s.epassRow, pressed ? s.epassPressed : null]}>
      <View style={s.epassIcon}><Icon name="qa-tile-4" size={icon[17]} color={ink.onGoldTint} /></View>
      <View style={s.info}>
        <Text {...font.inter700_13} color={ink.heading}>{'My e-Pass'}</Text>
        <Text {...font.inter400_11} color={ink.secondary} style={s.cardLine}>{'Your own pass for the gate'}</Text>
      </View>
      <Icon name="chev" size={icon[16]} color={ink.secondary} />
    </Pressable>
  )
}

/** An empty tab, in one line (M50 §6.2) — and, on "On site now", the one way on: inviting somebody. */
function EmptyTab({ tab }: { tab: VisitorsTab }) {
  return tab === 'onSite' ? (
    <EmptyState tone="light" icon="tab-visitors" title="No visitors on site or expected today" action={{ label: 'Invite visitor', onPress: () => router.push('/visitors/invite' as never) }} />
  ) : (
    <EmptyState tone="light" icon="tab-visitors" title="No past visitors yet" />
  )
}

type Item = { kind: 'card'; key: string; card: SiteCard } | { kind: 'title'; key: string; first: boolean } | { kind: 'row'; key: string; row: ActivityRow; last: boolean }

/** The open tab's list, in the board's order: the cards, then "Recent activity" and its rows — the title only over rows. */
export function itemsOf(m: Pick<TrackingModel, 'status' | 'onSite' | 'activity' | 'history'>, tab: VisitorsTab): Item[] {
  if (m.status !== 'ready') return []
  const cards = (tab === 'history' ? m.history : m.onSite).map((card): Item => ({ kind: 'card', key: card.key, card }))
  if (tab === 'history' || m.activity.length === 0) return cards
  const rows = m.activity.map((row, i): Item => ({ kind: 'row', key: row.key, row, last: i === m.activity.length - 1 }))
  return [...cards, { kind: 'title', key: 'recent-activity', first: cards.length === 0 }, ...rows]
}

export default function VisitorsScreen() {
  const m = useVisitorTracking()
  const [tab, setTab] = useState<VisitorsTab>('onSite')
  const { status, onSite, activity, history } = m
  const items = useMemo(() => itemsOf({ status, onSite, activity, history }, tab), [status, onSite, activity, history, tab])
  useFidelityReady(status === 'ready')

  // Not on the board, and kept to the least that is honest: a spinner on the first load, the kit's own words otherwise.
  const unloaded =
    m.status === 'loading' ? (
      <View style={s.loading}>
        <ActivityIndicator color={fill.primary} />
      </View>
    ) : m.status === 'error' ? (
      <ErrorView tone="light" message={m.errorMessage ?? 'Your visitors could not be loaded.'} onRetry={m.refresh} />
    ) : m.status === 'denied' ? (
      // The server's own sentence: what passes are for, this person's access and who can change it — or that the
      // account has no home on the register yet (M50 §4.1: nothing says "not available yet").
      <EmptyState tone="light" icon="tab-visitors" title="Visitor passes aren't open to your account" body={m.errorMessage ?? NOT_LINKED} />
    ) : m.status === 'ready' ? (
      <EmptyTab tab={tab} />
    ) : null

  return (
    <View style={s.screen}>
      {/* The "+" says what it is (M50 §6.12): "Invite" under it. */}
      <AppBar title="Visitors" right={{ icon: 'side-btn-8', label: 'Invite visitor', caption: 'Invite', onPress: () => router.push('/visitors/invite' as never) }} />
      <View style={s.head}>
        <Tabs tab={tab} onChange={setTab} />
      </View>
      <FlatList
        key={tab}
        style={s.list}
        contentContainerStyle={s.trackBody}
        data={items}
        keyExtractor={(item) => item.key}
        renderItem={({ item }) =>
          item.kind === 'card' ? <VisitorCard card={item.card} /> : item.kind === 'title' ? <RecentActivityTitle first={item.first} /> : <GateRow row={item.row} last={item.last} />
        }
        ListHeaderComponent={tab === 'onSite' && m.epass ? <EpassRow /> : null}
        ListEmptyComponent={unloaded}
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={fill.primary} colors={[fill.primary]} />}
      />
    </View>
  )
}

const s = StyleSheet.create({
  // .screen
  screen: { flex: 1, backgroundColor: fill.surface },
  // .track-body's top and sides, above the tabs — which stay put while the list under them scrolls
  head: { paddingTop: space[16], paddingHorizontal: space[20] },
  list: { flex: 1 },
  // .track-body under the tabs: .seg2's margin-bottom of 16, the sides, and the bottom
  trackBody: { paddingTop: space[16], paddingHorizontal: space[20], paddingBottom: space[20] },
  // .seg2 · .seg2-item · .seg2-item.active:after (laid over the rule, taking no space)
  seg2: { flexDirection: 'row', gap: space[22], borderBottomWidth: stroke['1.5'], borderBottomColor: line.hairline },
  seg2Item: { paddingBottom: space[11] },
  seg2Underline: { position: 'absolute', left: 0, right: 0, bottom: space['-1.5'], height: size['2.5'], borderRadius: corner[2], backgroundColor: fill.primary },
  // .site-card · .avatar · .site-info · .site-info .p · .dur-chip — the chip beside the name, the card's 12 between
  // them, so the line under both has the whole width (M47 §5.6)
  siteCard: { flexDirection: 'row', alignItems: 'center', gap: space[12], padding: space[12], borderWidth: stroke[1], borderColor: line.hairline, borderRadius: radius.card, marginBottom: space[10] },
  avatar: { width: size[34], height: size[34], borderRadius: radius.avatar(size[34]), flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.tint },
  info: { flex: 1, minWidth: 0 },
  top: { flexDirection: 'row', alignItems: 'center', gap: space[12] },
  name: { flex: 1, minWidth: 0 },
  cardLine: { marginTop: space[2] },
  durChip: { flexShrink: 0, paddingVertical: space[5], paddingHorizontal: space[9], borderRadius: radius.chip, backgroundColor: fill.tint },
  // .sec-title: margin 20 0 10 — a card's own 10 below it is half of the 20 (the board's margins collapse; a phone's add)
  secTitle: { marginTop: space[10], marginBottom: space[10] },
  secTitleFirst: { marginTop: space[4], marginBottom: space[10] },
  // .activity-row · .activity-row:last-child · .a-icon · .a-info .p — the time beside the name, the row's 11 between
  rowTop: { flexDirection: 'row', alignItems: 'center', gap: space[11] },
  activityRow: { flexDirection: 'row', alignItems: 'center', gap: space[11], paddingVertical: space[9], borderBottomWidth: stroke[1], borderBottomColor: line.hairline },
  activityRowLast: { flexDirection: 'row', alignItems: 'center', gap: space[11], paddingVertical: space[9] },
  aIcon: { width: size[30], height: size[30], borderRadius: corner[9], flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.tint },
  rowLine: { marginTop: space[1] },
  // Pressed: a card's or a row's fill one step darker, as one piece (M50 §6.7a) — never a fade drawn through it
  pressed: PRESSED,
  loading: { paddingTop: space[24], alignItems: 'center' },
  // My e-Pass (M50 §6.1): the site card's shape in the design's gold — its colour for what is the resident's own, as on
  // Profile and Home — with the cards' 10 below it and 6 more, so it reads as apart from them
  epassRow: { flexDirection: 'row', alignItems: 'center', gap: space[12], padding: space[12], borderRadius: radius.card, backgroundColor: fill.goldTint, marginBottom: space[16] },
  epassIcon: { width: size[34], height: size[34], borderRadius: radius.avatar(size[34]), flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.surface },
  // …and pressed, its gold one step deeper, as one piece
  epassPressed: { backgroundColor: fill.goldIconTile },
})
