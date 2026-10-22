/**
 * resident-app-12 · Notices — rebuilt from the board (M42 §5 #11), every value from the design tokens and parts.
 *
 * A TAB screen: the navigator draws the tab bar. The bar: a spacer, "Notices", and the funnel. The four chips are the
 * design's — All · Urgent · Security · Events. Each card: the unread dot, the picture tile, the topic pills, the title,
 * two lines of the notice, and its time ("Today · 8:15 AM"). "Urgent" is GOLD (M42: red is for life-safety only).
 *
 * The server sends no topic yet (a notice is general or urgent), so live a card carries "Urgent" when it is and no other
 * pill, and Security and Events hold nothing; the design's topics come from its fixture, in fidelity mode only.
 * The funnel's one choice is "Unread notices only" — the design deleted the Unread chip.
 *
 * MEETINGS AND THE VOTE, AT THE TOP (the user, 8 Oct 2026): a vote that is open and the next meeting, as cards above
 * the notices on "All" (useCommunity — a Member's; nothing for a Dependent or a Helper). A resident who missed the
 * notification finds them here, and in Settings → Governance.
 */

import { useState } from 'react'
import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { EmptyState, ErrorView, Icon, Pressable, Text, c, useFidelityReady } from '@gs/core'
import { AppBar, Badge, Card, Chip, ChipRow, SecondaryButton } from '@/design/parts'
import { corner, fill, font, icon, ink, size, space } from '@/design/tokens'
import type { Row as CommunityRow } from '@/features/home/community'
import { useCommunity } from '@/features/home/useCommunity'
import { ChooserSheet } from '@/features/visitors/components/ChooserSheet'
import { useNotices, type NoticeCard, type NoticeListModel } from '../useNotices'

function Chips({ m }: { m: NoticeListModel }) {
  return (
    <View style={s.chips} role="radiogroup" aria-label="Filter notices">
      <ChipRow>
        {m.chips.map((chip) => (
          <Chip key={chip.key} label={chip.label} on={chip.key === m.filter} onPress={() => m.setFilter(chip.key)} />
        ))}
      </ChipRow>
    </View>
  )
}

function NoticeRow({ card }: { card: NoticeCard }) {
  return (
    // Pressed, the card darkens as one piece (M50 §6.7a): its own fill, never a fade drawn through it.
    <Pressable onPress={() => router.push(`/notices/${card.id}` as never)} role="button" aria-label={card.label}>
      {({ pressed }) => (
      <Card style={s.card} pressed={pressed}>
        {card.unread ? <View style={s.unreadDot} /> : null}
        <View style={card.urgent ? s.tileUrgent : s.tile}>
          <Icon name={card.icon} size={icon[18]} color={card.urgent ? ink.onGoldTint : ink.action} />
        </View>
        <View style={s.body}>
          {card.tags.length > 0 ? (
            <View style={s.tagRow}>
              {card.tags.map((tag) => <Badge key={tag.label} label={tag.label} urgent={tag.urgent} />)}
            </View>
          ) : null}
          <Text {...font.inter700_13} color={ink.heading}>{card.title}</Text>
          <View style={s.snip}><Text {...font.inter400_11_5_lh1_45} color={ink.secondary} numberOfLines={2}>{card.preview}</Text></View>
          <View style={s.time}><Text {...font.inter600_10} color={ink.faint}>{card.posted}</Text></View>
        </View>
      </Card>
      )}
    </Pressable>
  )
}

/** A meeting or the vote, as a card in the notices' own look; it opens the meeting, the ballot, the receipt or the results. */
function CommunityCard({ row }: { row: CommunityRow }) {
  return (
    <Pressable onPress={() => router.push(row.path as never)} role="link" aria-label={`${row.title}. ${row.detail}`}>
      {({ pressed }) => (
        <Card style={s.card} pressed={pressed}>
          <View style={s.tile}>
            <Icon name={row.icon} size={icon[18]} color={ink.action} />
          </View>
          <View style={s.body}>
            <Text {...font.inter700_13} color={ink.heading}>{row.title}</Text>
            <View style={s.snip}><Text {...font.inter400_11_5_lh1_45} color={ink.secondary} numberOfLines={2}>{row.detail}</Text></View>
          </View>
        </Card>
      )}
    </Pressable>
  )
}

/** "Show older notices" — only when the server has said there is an older page (never on the board). */
function Older({ m }: { m: NoticeListModel }) {
  if (m.status !== 'ready' || !m.older.available) return null
  // Law 1 (M53, M54 §2.5): switched off, it says why in one line.
  return (
    <View>
      <SecondaryButton label="Show older notices" onPress={m.older.load} loading={m.older.loading} disabled={!!m.older.blocked} accessibilityHint={m.older.blocked ?? undefined} />
      {m.older.blocked ? <Text {...font.inter400_11_lh1_45} color={ink.label} align="center" style={{ marginTop: space[6] }}>{m.older.blocked}</Text> : null}
    </View>
  )
}

export default function NoticesScreen() {
  const m = useNotices()
  const community = useCommunity()
  useFidelityReady(m.status === 'ready')

  // The funnel's choice, in the app's own chooser (never the phone's grey dialog, M50 §4.3); choosing closes it.
  const [choosing, setChoosing] = useState(false)
  const show = (unreadOnly: boolean) => () => {
    m.setUnreadOnly(unreadOnly)
    setChoosing(false)
  }

  const empty =
    m.status === 'loading' ? <View style={s.loading}><ActivityIndicator color={c(ink.action)} /></View>
    : m.status === 'error' ? <ErrorView tone="light" message={m.errorMessage ?? "The estate's notices could not be loaded."} onRetry={m.refresh} />
    : m.status === 'denied' ? <EmptyState tone="light" icon="tab-notices" title="Notices aren't open to your account" body={m.errorMessage ?? 'Your account cannot see notices.'} />
    : null

  return (
    <View style={s.screen}>
      {/* The funnel says what it is (M50 §6.12): "Filter" under it. */}
      <AppBar title="Notices" back={false} right={{ icon: 'side-btn-6', label: m.unreadOnly ? 'Filter: unread notices only' : 'Filter', caption: 'Filter', onPress: () => setChoosing(true) }} />
      <FlatList
        style={s.fill}
        contentContainerStyle={s.bodyPad}
        data={m.status === 'ready' ? m.cards : []}
        keyExtractor={(card) => card.id}
        renderItem={({ item }) => <NoticeRow card={item} />}
        ListHeaderComponent={
          m.status === 'ready' ? (
            <>
              <Chips m={m} />
              {m.filter === 'all' && community.length > 0 ? (
                <View style={s.community}>
                  {community.map((row) => <CommunityCard key={row.key} row={row} />)}
                </View>
              ) : null}
            </>
          ) : null
        }
        ListEmptyComponent={empty}
        ListFooterComponent={<Older m={m} />}
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c(ink.action)} />}
      />
      <ChooserSheet
        open={choosing}
        title="Show"
        choices={[
          { key: 'all', label: 'All notices', on: !m.unreadOnly, pick: show(false) },
          { key: 'unread', label: 'Unread notices only', on: m.unreadOnly, pick: show(true) },
        ]}
        onClose={() => setChoosing(false)}
      />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface },
  fill: { flex: 1 },
  // .body-pad
  bodyPad: { paddingTop: space[16], paddingHorizontal: space[20], paddingBottom: space[20] },
  // .chip-row, with the board's own margin-bottom of 16
  chips: { marginBottom: space[16] },
  // The meeting and vote cards, spaced as the notice cards under them are.
  community: { gap: space[11], marginBottom: space[11] },
  // .card.notice-card2 — the card's own look is the shared Card; margin-left 13 makes room for the unread dot
  card: { flexDirection: 'row', gap: space[11], marginLeft: space[13] },
  // .unread-dot
  unreadDot: { position: 'absolute', left: space['-13'], top: space[6], width: size[7], height: size[7], borderRadius: corner.round(size[7]), backgroundColor: fill.gold },
  // .n-icon · .n-icon.urgent
  tile: { width: size[38], height: size[38], borderRadius: corner[11], flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.tint },
  tileUrgent: { width: size[38], height: size[38], borderRadius: corner[11], flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.urgentTint },
  // .n-body · .tag-row · .n-snip · .n-time
  body: { flex: 1, minWidth: 0 },
  tagRow: { flexDirection: 'row', alignItems: 'center', gap: space[7], marginBottom: space[4] },
  snip: { marginTop: space[3] },
  time: { marginTop: space[6] },
  loading: { paddingTop: space[24], alignItems: 'center' },
})
