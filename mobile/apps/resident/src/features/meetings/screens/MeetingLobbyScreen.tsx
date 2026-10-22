/**
 * resident-app-13 · Meeting (the AGM lobby) — rebuilt from the client's board, every value from the design tokens and
 * parts.
 *
 * THE BOARD, TOP TO BOTTOM: the slim app bar (the back chevron, "Meeting", the spacer) · the navy hero — the gold
 * "ANNUAL GENERAL MEETING", the title, "Saturday 27 September · 10:00 AM · Hosted in-app", the "Verified as Lot 47 ·
 * Andrea Fletcher" badge, "Quorum" with "142 of 450 households · need 25%" over its gold bar · the "Agenda" card, a
 * time and a line per item · "Join meeting" · the lock line. Nothing else is drawn.
 *
 * What each part SAYS is the hook's (useMeetings.ts useMeetingLobby) — the board's own sample in fidelity mode; this file
 * reads no mode. Live, the meeting's own type, title, day and time, the server's words for where it is held, the
 * signed-in lot and name, and its agenda.
 *
 * DATA GAP: the server sends no quorum figure (it is counted from the register at the meeting), so live "Quorum" stands
 * over an empty bar. "Join meeting" does exactly what it did before the rebuild: while the meeting's link is open it
 * opens the meeting's own screen; otherwise it is greyed and the reason is written under it. No room is built behind it.
 *
 * FIXED, NOT A PAGE THAT SCROLLS: with the board's content it fits 390 × 844 with room to spare. A longer agenda keeps
 * the card no taller than the room left, and only its rows scroll, inside the card.
 */

import { ActivityIndicator, RefreshControl, ScrollView, StyleSheet, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, Gradient, Icon, Text, aboveGestureBar, c, useFidelityReady } from '@gs/core'
import { AppBar, Card, PrimaryButton } from '@/design/parts'
import { corner, fill, font, gradient, icon, ink, inkAlpha, line, radius, role, size, space, stroke } from '@/design/tokens'
import { useMeetingLobby, type AgendaRow, type LobbyModel } from '../useMeetings'

/** `.meet-hero` — the navy card: kind, title, when and where, who is verified, and the quorum. */
function Hero({ m }: { m: LobbyModel }) {
  return (
    <Gradient spec={gradient.hero} style={s.hero}>
      <Text {...font.inter700_10_5_ls0_2} color={ink.accent}>{m.tag}</Text>
      <View style={s.heroTitle}>
        <Text {...font.poppins600_19} color={ink.onDark} role="heading">{m.title}</Text>
      </View>
      <Text {...font.inter400_12} style={s.when}>{m.when}</Text>
      {m.verified ? (
        <View style={s.badge}>
          <Icon name="why-note" size={icon[13]} color={ink.accent} />
          <View style={s.shrink}>
            <Text {...font.inter600_10_5} color={ink.onDark}>{m.verified}</Text>
          </View>
        </View>
      ) : null}
      <View style={s.quorum}>
        <View style={s.quorumTop}>
          <View style={s.shrink}>
            <Text {...font.inter600_10_5} style={s.quorumWords}>{'Quorum'}</Text>
          </View>
          {m.quorum ? (
            <View style={s.shrink}>
              <Text {...font.inter600_10_5} style={s.quorumWords}><Text face={font.inter700_10_5.face} color={ink.onDark}>{m.quorum.count}</Text>{m.quorum.rest}</Text>
            </View>
          ) : null}
        </View>
        <View style={s.track}>
          {m.quorum ? <View style={[s.bar, { width: m.quorum.fill }]} /> : null}
        </View>
      </View>
    </Gradient>
  )
}

/** `.agenda-row` — the item's time in its 52-wide column, then the item. */
function AgendaLine({ row, last }: { row: AgendaRow; last: boolean }) {
  return (
    <View style={last ? s.row : [s.row, s.rowRule]} role="listitem">
      <View style={s.time}>
        <Text {...font.inter700_11} color={ink.action}>{row.time ?? ''}</Text>
      </View>
      <View style={s.grow}>
        <Text {...font.inter600_12_5} color={ink.heading}>{row.text}</Text>
      </View>
    </View>
  )
}

/** `.card` with its `.card-title` — as tall as its rows, never taller than the room left; then its rows scroll inside it. */
function Agenda({ m }: { m: LobbyModel }) {
  return (
    <Card style={s.agenda}>
      <View style={s.cardTitle}>
        <Text {...role.cardTitle} color={ink.heading} role="heading">{'Agenda'}</Text>
      </View>
      <ScrollView style={s.rows} role="list" aria-label="Agenda" refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c(ink.action)} />}>
        {m.agenda.map((row, i) => <AgendaLine key={row.key} row={row} last={i === m.agenda.length - 1} />)}
      </ScrollView>
    </Card>
  )
}

/** No board draws these: the least that is honest, and a way on. */
function States({ m }: { m: LobbyModel }) {
  return (
    <ScrollView style={s.grow} contentContainerStyle={s.statePad} refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c(ink.action)} />}>
      {m.status === 'loading' ? <View style={s.loading}><ActivityIndicator color={c(ink.action)} /></View> : null}
      {m.status === 'error' ? <ErrorView tone="light" message={m.errorMessage ?? 'The meeting could not be loaded.'} onRetry={m.refresh} /> : null}
      {m.status === 'denied' ? <EmptyState tone="light" icon="privacy-note" title="Not available for this account" body={m.errorMessage ?? 'Your account cannot see the estate’s meetings.'} /> : null}
      {m.status === 'missing' ? (
        <EmptyState
          tone="light"
          icon="input"
          title={m.askedForNext ? 'No meeting is scheduled' : "We can't find that meeting"}
          body={
            m.stale
              ? `It is not among the meetings saved on this phone. Pull down to check again${m.online ? '' : ' once you are connected'}.`
              : m.askedForNext
                ? 'When your estate calls a general or phase meeting, it will appear here with its agenda. Notice is usually given two to three weeks ahead.'
                : 'It is not among the meetings on this phone. It may be for another phase, or further back than the list has been opened: All meetings reaches every one.'
          }
          action={{ label: 'See all meetings', onPress: () => router.replace('/meetings' as never) }}
        />
      ) : null}
    </ScrollView>
  )
}

export default function MeetingLobbyScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const m = useMeetingLobby(typeof id === 'string' ? id : undefined)
  const insets = useSafeAreaInsets()
  useFidelityReady(m.status === 'ready')

  return (
    <View style={s.screen}>
      <AppBar title="Meeting" />
      {m.meeting ? (
        // The board's 20 under the last line, or the home indicator's strip where that is deeper — not both.
        <View style={[s.page, { paddingBottom: aboveGestureBar(space[20], insets.bottom) }]}>
          <Hero m={m} />
          <Agenda m={m} />
          {/* Exactly as before the rebuild: open while the link is, greyed with its reason otherwise. */}
          <PrimaryButton label="Join meeting" onPress={m.openRoom} disabled={!m.join.canJoin} accessibilityHint={m.join.reason ?? undefined} />
          {m.join.reason ? (
            <View style={s.reason}>
              <Text {...font.inter400_11_5_lh1_5} color={ink.secondary} align="center">{m.join.reason}</Text>
            </View>
          ) : null}
          <View style={s.lock}>
            <Icon name="privacy-note" size={icon[15]} color={ink.secondary} style={s.lockIcon} />
            <View style={s.grow}>
              <Text {...font.inter400_11_lh1_55} color={ink.secondary}>{m.lockNote}</Text>
            </View>
          </View>
        </View>
      ) : (
        <States m={m} />
      )}
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  grow: { flex: 1, minWidth: 0 },
  shrink: { flexShrink: 1 },
  // .content-scroll > .body-pad, laid out fixed
  page: { flex: 1, paddingTop: space[18], paddingHorizontal: space[20] },
  statePad: { paddingTop: space[18], paddingHorizontal: space[20], paddingBottom: space[20] },
  loading: { paddingTop: space[24], alignItems: 'center' },

  // .meet-hero · its h2 (margin 5 0 3) · .m-when
  hero: { borderRadius: radius.heroCard, padding: space[18], marginBottom: space[16], overflow: 'hidden' },
  heroTitle: { marginTop: space[5], marginBottom: space[3] },
  when: { color: inkAlpha.onDarkMeta },
  // .verified-badge
  badge: { flexDirection: 'row', alignItems: 'center', alignSelf: 'flex-start', gap: space[6], marginTop: space[12], paddingVertical: space[6], paddingHorizontal: space[11], borderRadius: radius.chip, backgroundColor: fill.onDarkButtonDim },
  // .quorum-wrap (margin 16 0 4) · .quorum-top · .quorum-bar and its gold fill
  quorum: { marginTop: space[16], marginBottom: space[4] },
  quorumTop: { flexDirection: 'row', justifyContent: 'space-between', marginBottom: space[6] },
  quorumWords: { color: inkAlpha.onDarkSubtitle },
  track: { flexDirection: 'row', height: size[7], borderRadius: corner[6], backgroundColor: fill.onDarkBar, overflow: 'hidden' },
  bar: { borderRadius: corner[6], backgroundColor: fill.gold },

  // .card (the Card part: padding 14, 12 below) · .card-title (11 below) · .agenda-row
  agenda: { flexShrink: 1 },
  cardTitle: { marginBottom: space[11] },
  rows: { flexGrow: 0 },
  row: { flexDirection: 'row', gap: space[11], paddingVertical: space[9] },
  rowRule: { borderBottomWidth: stroke[1], borderBottomColor: line.hairline },
  time: { width: size[52], flexShrink: 0 },

  // Under "Join meeting" only while it is greyed: why, and when it opens.
  reason: { marginTop: space[8] },
  // .lock-note (margin 16 0 18, 2 in at each side) and its padlock, 1 down
  lock: { flexDirection: 'row', gap: space[9], marginTop: space[16], marginBottom: space[18], paddingHorizontal: space[2] },
  lockIcon: { marginTop: space[1] },
})
