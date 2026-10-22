/**
 * resident-app-34 · Review your vote — rebuilt from the board, every value from the design tokens and shared parts.
 *
 * The board, top to bottom: the bar (back, "Review your vote", the spacer) · the review card — "FINAL REVIEW · LOT 47",
 * "Check your selections carefully" and the line under it · for each ballot its label, then a card of position ·
 * choice rows ending in a gold "Edit Ballot A" · the note · "Submit my vote".
 *
 * Behind the same gate as the ballot: the review (which shows the choices) is mounted only while the server says this
 * household may vote. The send happens here, once, online only (useReview.ts). What each part says is the hook's, the
 * board's own sample too, in fidelity mode; this file reads no mode.
 *
 * THE BOARD DOES NOT FIT ITS OWN PHONE (D3, 7 October): with its fourteen rows it runs 36 points past 390 × 844, its
 * "Submit my vote" cut. So this screen's vertical gaps are tightened just enough, and nothing else — no type is
 * smaller, nothing is left out:
 *   each row            6 above and below → 5 (28 over the fourteen rows)
 *   the body's top      18 → 14 · under the review card 12 → 8 · under the note 12 → 8
 * 40 points in all, with 4 to spare. Laid out fixed: the review card stays at the top and the note and the button at
 * the foot; only the ballots' cards scroll, in their own area — on a smaller phone, or for a paper longer than the
 * board's. Not on the board, and drawn only when they happen: why "Submit my vote" did not send (above it), and the
 * paper's other moments — nothing marked, sending, not confirmed, not received, not accepted — each the least that is
 * honest.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { ActivityIndicator, BackHandler, Platform, ScrollView, StyleSheet, View } from 'react-native'
import { Stack, router, useFocusEffect, useLocalSearchParams } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, Pressable, Text, aboveGestureBar, useFidelityReady } from '@gs/core'
import { AppBar, Card, PrimaryButton, SecondaryButton } from '@/design/parts'
import { fill, font, ink, line, role, space, stroke } from '@/design/tokens'
import { Attention, BallotHead, LINK_REACH, VoteNote } from '../components/BallotParts'
import { BallotHolding } from '../components/BallotHolding'
import { aftermathNote, gateShut, type Aftermath } from '../paper'
import { useBallotGate, type GateModel } from '../useBallotGate'
import { useBallotSecrecy } from '../useBallotSecrecy'
import { useReview, type ReviewGroup } from '../useReview'

/** One card of position · choice rows, and its gold edit link (`.card` · `.rev-row` · `.edit-link`). */
function Ballot({ group, id }: { group: ReviewGroup; id: string }) {
  return (
    <View>
      {/* .section-label — board 34's own: 6 below it (its 2 above folds into the space before it) */}
      <View style={s.label}><Text {...role.sectionLabel} color={ink.secondary} role="heading">{group.heading}</Text></View>
      <Card style={s.card}>
        {group.rows.map((row) => (
          <View key={row.key} style={s.row}>
            <View style={s.position}><Text {...font.inter700_11} color={ink.secondary}>{row.position}</Text></View>
            <View style={s.choice}><Text {...font.inter600_12} color={row.blank ? ink.secondary : ink.heading} align="right">{row.value}</Text></View>
          </View>
        ))}
        <View style={s.editLink}>
          <Pressable onPress={() => router.dismissTo(`/elections/${id}/${group.page}` as never)} role="link" aria-label={group.edit} hitSlop={LINK_REACH}>
            <Text {...font.inter700_11} color={ink.link}>{group.edit}</Text>
          </Pressable>
        </View>
      </Card>
    </View>
  )
}

function Review({ id, recheck, goReceipt, onSending, onAftermath }: { id: string; recheck: (options?: { settle?: boolean }) => Promise<GateModel>; goReceipt: () => void; onSending: (sending: boolean) => void; onAftermath: (aftermath: Aftermath) => void }) {
  const insets = useSafeAreaInsets()
  const m = useReview(id, recheck, goReceipt)
  const toBallot = () => router.dismissTo(`/elections/${id}/ballot-a` as never)
  // .body-pad's 20 below — or the home-indicator inset, where that is deeper.
  const bottom = { paddingBottom: aboveGestureBar(space[20], insets.bottom) }

  // While the paper is in the air the screen holds still (see ReviewScreen): whatever the answer
  // is, it has to be told to someone who is still looking at this screen.
  const sending = m.status === 'sending'
  useEffect(() => {
    onSending(sending)
    return () => onSending(false)
  }, [sending, onSending])

  // What became of a paper that was handed over is told to the screen ABOVE this one, and is NOT taken
  // back when this one unmounts: if the gate stops being "open" (the server is failing, the poll closed
  // meanwhile) a holding screen replaces the review, and it still has to say what is and is not known.
  const aftermath: Aftermath = m.status === 'sending' || m.status === 'unconfirmed' ? 'lost' : m.status === 'refused' ? 'refused' : null
  useEffect(() => onAftermath(aftermath), [aftermath, onAftermath])

  if (m.status === 'loading') return <View style={s.loading}><ActivityIndicator color={fill.primary} /></View>
  if (m.status === 'error') return <ErrorView tone="light" message={m.message ?? 'The ballot could not be loaded.'} onRetry={m.retry} />
  if (m.status === 'missing') return <EmptyState tone="light" icon="one-vote-note" title="No such election" body="This election could not be found. It may have been withdrawn by the estate office." action={{ label: 'Back to home', onPress: () => router.dismissTo('/(tabs)') }} />

  if (m.status === 'empty') {
    return (
      <View style={s.plain}>
        {m.wipedNote ? <Attention text={m.wipedNote} /> : null}
        <EmptyState tone="light" icon="one-vote-note" title="Nothing is marked yet" body="A blank paper is not a vote. Mark at least one choice, then come back to review and send it." action={{ label: 'Go to the ballot', onPress: toBallot }} />
      </View>
    )
  }

  if (m.status === 'sending') {
    return (
      <View style={s.plain} role="status" aria-busy>
        <Text {...font.poppins600_17} color={ink.heading} role="heading">{'Sending your vote…'}</Text>
        <View style={s.plainLine}><Text {...font.inter400_12_5_lh1_5} color={ink.secondary}>{'Keep the app open. Your paper is sent once, and only while you watch.'}</Text></View>
        <View style={s.plainButton}><ActivityIndicator color={fill.primary} /></View>
      </View>
    )
  }

  if (m.status === 'unconfirmed' || m.status === 'not-received' || m.status === 'refused') {
    return (
      <ScrollView contentContainerStyle={[s.plain, bottom]}>
        <Text {...font.poppins600_17} color={ink.heading} role="heading">
          {m.status === 'unconfirmed' ? 'We are not sure your vote arrived' : m.status === 'not-received' ? 'Your vote was not received' : 'Your vote was not accepted'}
        </Text>
        {m.problem ? <Attention text={m.problem} style={s.plainLine} /> : null}
        <View style={s.plainLine}>
          <Text {...font.inter400_12_5_lh1_5} color={ink.secondary}>
            {m.status === 'unconfirmed'
              ? 'The connection dropped before the server answered. The app will NOT send your paper again by itself: a household has one vote. It is asking the server whether your paper is in.'
              : m.status === 'not-received'
                ? 'Nothing was counted. Marks are never kept on this phone, so the paper has to be marked again.'
                : 'The app never sends a refused paper again. Your marks were cleared for privacy.'}
          </Text>
        </View>
        {m.status === 'unconfirmed' ? (
          <>
            <PrimaryButton label={m.online ? 'Check again' : 'Check again when connected'} onPress={m.checkAgain} loading={m.checking} style={s.plainButton} />
            <SecondaryButton label="Go to the receipt screen" onPress={goReceipt} style={s.plainSecond} />
          </>
        ) : (
          <PrimaryButton label={m.status === 'not-received' ? 'Mark my paper again' : 'Back to the ballot'} onPress={toBallot} style={s.plainButton} />
        )}
      </ScrollView>
    )
  }

  return (
    <View style={[s.page, bottom]}>
      <BallotHead review eyebrow={m.eyebrow} heading="Check your selections carefully" sub="You can still edit any position below. Once submitted, your vote is final." style={s.head} />
      <ScrollView style={s.ballots} contentContainerStyle={s.ballotsPad} bounces={false} overScrollMode="never" showsVerticalScrollIndicator={false}>
        {m.groups.map((group) => <Ballot key={group.key} group={group} id={id} />)}
      </ScrollView>
      <VoteNote icon="confirm-note" text={m.note} style={s.note} />
      {m.problem ? <Attention text={m.problem} style={s.problem} /> : null}
      <PrimaryButton label="Submit my vote" onPress={m.cast} style={s.cta} />
    </View>
  )
}

export default function ReviewScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const { gate, recheck } = useBallotGate(id)
  useBallotSecrecy('review', gate.state)
  useFidelityReady(true)

  // ONE way to the receipt, taken once — whether the send succeeded, the server said "already voted",
  // or the gate itself learned that the household's paper is in.
  const gone = useRef(false)
  const goReceipt = useCallback(() => {
    if (gone.current || !id) return
    gone.current = true
    router.replace(`/elections/${id}/receipt` as never)
  }, [id])

  const voted = gate.state === 'voted'
  useFocusEffect(
    useCallback(() => {
      if (voted) goReceipt()
    }, [voted, goReceipt]),
  )

  // WHILE THE PAPER IS IN THE AIR there is no way off this screen — no header back button, no
  // swipe-back, no Android back. Leaving mid-send would land the household on a blank ballot with
  // nobody left to tell them whether their one vote arrived. It lasts one request (15 s at most).
  const [sending, setSending] = useState(false)

  // A send that did not end in a receipt, remembered here so it outlives <Review> (see aftermathNote).
  const [aftermath, setAftermath] = useState<Aftermath>(null)
  const note = aftermathNote(aftermath, gate.state)

  // WHILE NOBODY KNOWS WHETHER THE PAPER ARRIVED, "back" does not lead to the ballot: it would be a blank
  // paper (the marks are gone) inviting a second vote, with nothing on it to say the first may be in. The way
  // off this screen is the receipt screen — which asks the server, sends nothing, and leads home. The bar's
  // back would lead to the ballot, so it is not drawn then; the receipt screen is offered on the page instead.
  const unknown = aftermath === 'lost' && !sending && !gateShut(gate.state)
  useEffect(() => {
    if ((!sending && !unknown) || Platform.OS !== 'android') return
    const watching = BackHandler.addEventListener('hardwareBackPress', () => {
      if (unknown) goReceipt()
      return true
    })
    return () => watching.remove()
  }, [sending, unknown, goReceipt])

  return (
    <View style={s.screen}>
      <Stack.Screen options={{ gestureEnabled: !sending && !unknown }} />
      <AppBar title="Review your vote" back={!sending && !unknown} fallback="/(tabs)" />
      {gate.state === 'open' && id ? (
        <Review id={id} recheck={recheck} goReceipt={goReceipt} onSending={setSending} onAftermath={setAftermath} />
      ) : gate.state === 'voted' || gate.state === 'open' ? null : (
        <ScrollView contentContainerStyle={s.holding}>
          {note ? <Attention text={note} style={s.aftermath} /> : null}
          {unknown ? <SecondaryButton label="Go to the receipt screen" onPress={goReceipt} style={s.aftermath} /> : null}
          <BallotHolding gate={gate} id={id} onRetry={() => void recheck()} />
        </ScrollView>
      )}
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  loading: { paddingTop: space[24], alignItems: 'center' },
  // .body-pad: 18 above on the board, 14 here (D3); its 20 below is set by the screen
  page: { flex: 1, paddingTop: space[14] },
  // .review-head: 12 below on the board, 8 here (D3); the body's 20 at the sides
  head: { marginHorizontal: space[20], marginBottom: space[8] },
  // The ballots' cards: as tall as they are, and no taller than the room left — only then do they scroll, in place.
  // The body's 20 at the sides sits inside, so the cards' shadows are not cut at the edges.
  ballots: { flexGrow: 0, flexShrink: 1 },
  ballotsPad: { paddingHorizontal: space[20] },
  // .section-label { margin: 2px 2px 6px }
  label: { marginHorizontal: space[2], marginBottom: space[6] },
  // .card — board 34's own: 11 above and below, 13 at the sides, 9 under it
  card: { paddingVertical: space[11], paddingHorizontal: space[13], marginBottom: space[9] },
  // .rev-row — 6 above and below on the board, 5 here (D3); a rule under every row (the edit link is the card's last)
  row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: space[10], paddingVertical: space[5], borderBottomWidth: stroke[1], borderBottomColor: line.hairline },
  position: { flexGrow: 0, flexShrink: 0, flexBasis: '40%' },
  choice: { flex: 1, minWidth: 0 },
  // .edit-link
  editLink: { flexDirection: 'row', justifyContent: 'flex-end', marginTop: space[4] },
  // .one-vote-note2 { margin: 10px 2px 12px } — 10 above (the last card's 9 already given), 2 in from the body's 20;
  // 12 below on the board, 8 here (D3)
  note: { marginTop: space[1], marginHorizontal: space[22], marginBottom: space[8] },
  problem: { marginHorizontal: space[22], marginBottom: space[10] },
  cta: { marginHorizontal: space[20] },
  // The paper's other moments, and the holding screen
  plain: { paddingTop: space[24], paddingHorizontal: space[20], paddingBottom: space[30] },
  plainLine: { marginTop: space[12] },
  plainButton: { marginTop: space[20] },
  plainSecond: { marginTop: space[12] },
  holding: { paddingBottom: space[30] },
  aftermath: { marginTop: space[8], marginHorizontal: space[20] },
})
