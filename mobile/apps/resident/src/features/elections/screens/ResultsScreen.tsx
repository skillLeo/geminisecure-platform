/**
 * resident-app-28 · Election results — rebuilt from the board, every value from the design tokens and shared parts.
 *
 * The board, top to bottom: the bar (back, "Election results", the spacer) · the results card — the gold "CERTIFIED BY
 * RETURNING OFFICER" badge, the election, "Certified Sep 28, 8:02 PM", "Overall turnout" and its count, the turnout
 * bar, the five phase bars · per position its label and a card of candidates — initials (a crown on a winner), name,
 * the line under it, the marks and their share · the verify card.
 *
 * Results exist here ONLY when the server publishes them (useResults.ts). What each part says is the hook's, the
 * board's own sample too, in fidelity mode; this file reads no mode. Live, every candidate is listed with the certified
 * count, most marks first; the server's result has no per-phase turnout, names no winner and has no line under a name,
 * so the phase bars, the crowns and those lines are drawn only when the hook has them (the board's sample).
 *
 * "Verify your vote was counted" is a confirmed missing feature (the user, 7 October): its words are the board's, drawn
 * as plain text in the board's gold, and they open nothing.
 *
 * LAID OUT FIXED: the results card stays at the top and the verify card under the list; only the positions — a real
 * result runs far longer than the board's two — scroll, in their own area, where a pull asks the server again. With the
 * board's content nothing moves at 390 × 844, nor at 360 × 800.
 */

import { ActivityIndicator, RefreshControl, ScrollView, StyleSheet, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { EmptyState, ErrorView, Gradient, Icon, Text, useFidelityReady } from '@gs/core'
import { AppBar, Card, SectionLabel } from '@/design/parts'
import { corner, fill, font, gradient, icon, ink, inkAlpha, line, radius, size, space, stroke } from '@/design/tokens'
import { PhaseBars, TurnoutBar } from '../components/Turnout'
import type { ResultRow } from '../paper'
import { useResults } from '../useResults'

/** `.winner-row` — initials (and a crown, where the result names a winner), name and its line, marks and share. */
function Row({ row, last }: { row: ResultRow; last: boolean }) {
  return (
    <View style={[s.row, last ? null : s.rowLine]}>
      <View style={s.avatar}>
        <Text {...font.inter700_11} color={ink.onTint}>{row.initials}</Text>
        {row.crown ? <View style={s.crown}><Icon name="w-crown" size={icon[9]} color={ink.heading} /></View> : null}
      </View>
      <View style={s.info}>
        <Text {...font.inter700_12_5} color={ink.heading}>{row.label}</Text>
        {row.sub ? <View style={s.infoSub}><Text {...font.inter400_10} color={ink.secondary}>{row.sub}</Text></View> : null}
      </View>
      <View accessible aria-label={`${row.votes} marks${row.share ? `, ${row.share} of households that voted` : ''}`}>
        <Text {...font.inter700_12_5} color={ink.onTint} align="right">{row.votes}</Text>
        {row.share ? <Text {...font.inter600_9} color={ink.faint} align="right">{row.share}</Text> : null}
      </View>
    </View>
  )
}

export default function ResultsScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const m = useResults(id)
  useFidelityReady(true)
  const v = m.view

  return (
    <View style={s.screen}>
      <AppBar title="Election results" fallback="/(tabs)" />
      {m.status === 'loading' ? (
        <View style={s.loading}><ActivityIndicator color={fill.primary} /></View>
      ) : m.status === 'not-yet' ? (
        <EmptyState tone="light" icon="confirm-note" title="No results yet" body={m.message ?? ''} action={{ label: 'Check again', onPress: m.refresh }} />
      ) : m.status === 'missing' ? (
        <EmptyState tone="light" icon="one-vote-note" title="No such election" body="This election could not be found. It may have been withdrawn by the estate office." action={{ label: 'Back to home', onPress: () => router.dismissTo('/(tabs)') }} />
      ) : m.status === 'denied' ? (
        <EmptyState tone="light" icon="privacy-note" title="Results are not available on this account" body="This account does not have access to the estate's elections. Contact the estate office." />
      ) : m.status === 'error' || !v ? (
        <ErrorView tone="light" message={m.message ?? 'The results could not be loaded.'} onRetry={m.refresh} />
      ) : (
        <View style={s.page}>
          <Gradient spec={gradient.hero} style={s.hero}>
            <View style={s.badge}>
              <Icon name="why-note" size={icon[12]} color={ink.accent} />
              <Text {...font.inter700_10} color={ink.accent}>{v.badge}</Text>
            </View>
            <Text {...font.poppins600_17} color={ink.onDark} role="heading">{v.title}</Text>
            {v.certified ? <View style={s.certified}><Text {...font.inter400_11} style={s.onDark}>{v.certified}</Text></View> : null}
            <View style={s.turnoutRow}>
              <Text {...font.inter600_10_5} style={s.onDark}>{'Overall turnout'}</Text>
              <Text {...font.inter600_10_5} style={s.onDark}>
                <Text {...font.inter700_10_5} color={ink.onDark}>{v.turnoutCast}</Text>
                {v.turnoutRest}
              </Text>
            </View>
            <TurnoutBar percent={v.turnoutPercent} />
            {m.phases.length > 0 ? <PhaseBars phases={m.phases} /> : null}
          </Gradient>

          <ScrollView style={s.list} contentContainerStyle={s.listPad} overScrollMode="never" showsVerticalScrollIndicator={false} refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={fill.primary} />}>
            {v.groups.map((group) => (
              <View key={group.key}>
                <SectionLabel gap="wide">{group.heading}</SectionLabel>
                <Card>
                  {group.rows.map((row, r) => <Row key={row.id} row={row} last={r === group.rows.length - 1} />)}
                </Card>
              </View>
            ))}
          </ScrollView>

          <View style={s.verify}>
            <Icon name="why-note" size={icon[18]} color={ink.action} />
            <View style={s.verifyText}>
              <Text {...font.inter600_11_5_lh1_4} color={ink.body}>
                {'Have your receipt code? '}
                <Text {...font.inter700_11_5_lh1_4} color={ink.link}>{'Verify your vote was counted'}</Text>
              </Text>
            </View>
          </View>
        </View>
      )}
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  loading: { paddingTop: space[24], alignItems: 'center' },
  // .body-pad: 18 above and the board's 20 below. Under the verify card (no control) the board's own 20 is kept, not the
  // home-indicator inset: with the inset the board's content would scroll by two points at 360 × 800.
  page: { flex: 1, paddingTop: space[18], paddingBottom: space[20] },
  // .results-hero: 16 inside and 16 below; the body's 20 at the sides
  hero: { borderRadius: radius.card, padding: space[16], marginHorizontal: space[20], marginBottom: space[16] },
  // .certified-badge
  badge: { alignSelf: 'flex-start', flexDirection: 'row', alignItems: 'center', gap: space[6], backgroundColor: fill.goldBadge, borderRadius: radius.chip, paddingVertical: space[5], paddingHorizontal: space[11], marginBottom: space[9] },
  // .results-hero p — 3 under the title; the turnout words, white at .7
  certified: { marginTop: space[3] },
  onDark: { color: inkAlpha.onDarkSubtitle },
  // .turnout-row
  turnoutRow: { flexDirection: 'row', justifyContent: 'space-between', marginTop: space[14], marginBottom: space[6] },
  // The positions: as tall as they are, and no taller than the room left — only then do they scroll, in place.
  // The body's 20 at the sides sits inside, so the cards' shadows are not cut at the edges.
  list: { flexGrow: 0, flexShrink: 1 },
  listPad: { paddingHorizontal: space[20] },
  // .winner-row · .w-avatar · .w-crown · .w-info
  row: { flexDirection: 'row', alignItems: 'center', gap: space[11], paddingVertical: space[10] },
  rowLine: { borderBottomWidth: stroke[1], borderBottomColor: line.hairline },
  avatar: { width: size[36], height: size[36], borderRadius: radius.avatar(size[36]), flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.tint },
  crown: { position: 'absolute', top: space['-6'], right: space['-4'], width: size[16], height: size[16], borderRadius: radius.avatar(size[16]), alignItems: 'center', justifyContent: 'center', backgroundColor: fill.gold },
  info: { flex: 1, minWidth: 0 },
  infoSub: { marginTop: space[1] },
  // .verify-cta — its 4 above folds into the last card's 12; the body's 20 at the sides
  verify: { flexDirection: 'row', alignItems: 'center', gap: space[11], backgroundColor: fill.tint, borderRadius: corner[14], paddingVertical: space[13], paddingHorizontal: space[14], marginHorizontal: space[20] },
  verifyText: { flex: 1, minWidth: 0 },
})
