/**
 * resident-app-15 · Community election (Ballot A) and resident-app-24 · Phase 2 election (Ballot B) — rebuilt from the
 * boards, every value from the design tokens: one paper over two pages.
 *
 * The board, top to bottom: the bar (back, the title, the spacer) · the ballot card — its gold tag, the election, the
 * line under it · each open position: its name with "Choose 1" / "Choose up to 3", then its card of candidates · on
 * board 24, "Remaining positions", a row each, opened with "Choose" · board 15's note on who votes · the button.
 *
 * THE GATE COMES FIRST. `useBallotGate` asks the server, online, whether this household may vote. `<Paper>` — the
 * only thing here that fetches or draws a choice — is mounted ONLY when the answer is "open". Ineligible, voted,
 * closed, offline, unknown: a holding screen, and no ballot exists anywhere in the tree (not a disabled one).
 *
 * What each part says is the hook's (useBallotPage), the boards' own sample too, in fidelity mode; this file reads no
 * mode. LAID OUT FIXED: the ballot card stays at the top and the note and the button at the foot; only the positions,
 * when a paper's real candidates run longer than the board's, scroll — in their own area. With the board's content
 * nothing moves at 390 × 844. Not on the board, and drawn only when it happens: a mark refused for one too many (under
 * that position's card) and marks cleared for privacy (above the first position).
 */

import { ActivityIndicator, ScrollView, StyleSheet, View } from 'react-native'
import { Redirect, router, useLocalSearchParams } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, aboveGestureBar, useFidelityReady } from '@gs/core'
import { AppBar, PrimaryButton } from '@/design/parts'
import { fill, space } from '@/design/tokens'
import { Attention, BallotHead, CompactPositions, PositionCard, PositionLabel, VoteNote } from '../components/BallotParts'
import { BallotHolding } from '../components/BallotHolding'
import { useBallotGate } from '../useBallotGate'
import { useBallotPage, useBallotTitle, type PositionView } from '../useBallotPage'
import { useBallotSecrecy } from '../useBallotSecrecy'

const home = () => router.dismissTo('/(tabs)')

function Paper({ id, page }: { id: string; page: 'a' | 'b' }) {
  const insets = useSafeAreaInsets()
  const m = useBallotPage(id, page)

  if (m.status === 'loading') return <View style={s.loading}><ActivityIndicator color={fill.primary} /></View>
  if (m.status === 'error') return <ErrorView tone="light" message={m.message ?? 'The ballot could not be loaded.'} onRetry={m.retry} />
  if (m.status === 'missing') return <EmptyState tone="light" icon="one-vote-note" title="No such election" body="This election could not be found. It may have been withdrawn by the estate office." action={{ label: 'Back to home', onPress: home }} />
  if (m.status === 'empty') return <EmptyState tone="light" icon="one-vote-note" title="Nothing to vote on yet" body={`The estate office has not put any positions or choices on the paper for ${m.heading}. Check again shortly, or contact the estate office.`} action={{ label: 'Check again', onPress: m.retry }} />
  // A short paper has no second page: its only page is the first.
  if (m.status === 'no-second-page') return <Redirect href={`/elections/${id}/ballot-a` as never} />

  const opened = m.compact.find((p) => p.key === m.openKey) ?? null
  const position = (p: PositionView) => (
    <View key={p.key}>
      <PositionLabel name={p.name} rule={p.rule} />
      <PositionCard position={p} page={page} onMark={(optionId) => m.mark(p.key, optionId)} />
      {m.limit?.key === p.key ? <Attention text={m.limit.text} style={s.limit} /> : null}
    </View>
  )

  return (
    <View style={[s.page, { paddingBottom: aboveGestureBar(space[20], insets.bottom) }]}>
      <BallotHead eyebrow={m.eyebrow} heading={m.heading} sub={m.sub} style={s.head} />
      <ScrollView style={s.positions} contentContainerStyle={s.positionsPad} bounces={false} overScrollMode="never" showsVerticalScrollIndicator={false}>
        {m.wipedNote ? <Attention text={m.wipedNote} style={s.wiped} /> : null}
        {m.expanded.map(position)}
        {m.compact.length > 0 ? (
          <>
            <PositionLabel name="Remaining positions" rule={null} />
            <CompactPositions positions={m.compact} openKey={m.openKey} onToggle={m.toggleOpen} />
            {opened ? position(opened) : null}
          </>
        ) : null}
      </ScrollView>
      {m.note ? <VoteNote icon="one-vote-note" text={m.note} style={s.note} /> : null}
      <PrimaryButton label={m.cta} onPress={() => router.push(`/elections/${id}/${m.next}` as never)} style={m.note ? s.cta : s.ctaAfterCards} />
    </View>
  )
}

export function BallotScreen({ page }: { page: 'a' | 'b' }) {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const { gate, recheck } = useBallotGate(id)
  useBallotSecrecy(page === 'a' ? 'page-a' : 'page-b', gate.state)
  const title = useBallotTitle(page)
  useFidelityReady(true)

  return (
    <View style={s.screen}>
      <AppBar title={title} fallback="/(tabs)" />
      {gate.state === 'open' && id ? (
        <Paper id={id} page={page} />
      ) : gate.state === 'voted' && id ? (
        // The household's paper is in: the receipt, never the choices.
        <Redirect href={`/elections/${id}/receipt` as never} />
      ) : gate.state === 'open' ? null : (
        <BallotHolding gate={gate} id={id} onRetry={() => void recheck()} />
      )}
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  loading: { paddingTop: space[24], alignItems: 'center' },
  // .body-pad: 18 above; its 20 below is the screen's, or the home-indicator inset where that is deeper
  page: { flex: 1, paddingTop: space[18] },
  // .ballot-head: 18 below, the body's 20 at the sides
  head: { marginHorizontal: space[20], marginBottom: space[18] },
  // The positions: as tall as they are, and no taller than the room left — only then do they scroll, in place.
  // The body's 20 at the sides sits inside, so the cards' shadows are not cut at the edges.
  positions: { flexGrow: 0, flexShrink: 1 },
  positionsPad: { paddingHorizontal: space[20] },
  // under the card it belongs to (the card's own 12 above it), 12 to the next position
  limit: { marginHorizontal: space[2], marginBottom: space[12] },
  wiped: { marginHorizontal: space[2], marginBottom: space[18] },
  // .one-vote-note: 6 above folds into the card's 12; 2 in from the body's 20; 18 to the button
  note: { marginHorizontal: space[22], marginBottom: space[18] },
  cta: { marginHorizontal: space[20] },
  // board 24's button: 16 above, the last card's 12 already given
  ctaAfterCards: { marginHorizontal: space[20], marginTop: space[4] },
})
