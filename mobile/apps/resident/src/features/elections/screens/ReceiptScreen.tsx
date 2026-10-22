/**
 * resident-app-16 · Vote submitted — rebuilt from the board, every value from the design tokens and shared parts.
 *
 * The board, top to bottom: the bar with no button at either end, "Vote submitted" · the gold tick in its two faint
 * rings · "Your vote is in" · the line under it · the receipt card — "YOUR RECEIPT CODE", the code, the line under it ·
 * the verify card · "Done", the page's width. It fits a 390 × 844 phone with room to spare; nothing scrolls.
 *
 * Shows only what the server returns for a receipt (useReceipt.ts): that the household's paper is in, and the day.
 * This screen never receives, reads or shows the choices — it does not import the marks, and its only route param is
 * the election id. What each part says is the hook's, the board's own sample too, in fidelity mode; this file reads no
 * mode. Live there is no code to show (the server keeps none, by design): the card keeps the board's words and draws
 * no code.
 *
 * "Elections → Verify my vote" is a confirmed missing feature (the user, 7 October): its words are the board's, drawn
 * as plain text in the board's gold, and they open nothing.
 *
 * There is no way back into the ballot from here: the bar has no back button (as drawn), the swipe-back gesture is off,
 * and Android's back button does what "Done" does. The other states (checking, no connection, error…) are not on the
 * board; each is the least that is honest, with a way home under it — without one an iPhone would have no way off
 * the screen at all.
 */

import { useEffect } from 'react'
import { ActivityIndicator, BackHandler, Platform, StyleSheet, View } from 'react-native'
import { Stack, router, useLocalSearchParams } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, Icon, Text, aboveGestureBar, useFidelityReady } from '@gs/core'
import { AppBar, PrimaryButton, SecondaryButton } from '@/design/parts'
import { corner, fill, font, icon, ink, line, radius, size, space, stroke } from '@/design/tokens'
import { ReceiptCode } from '../components/ReceiptCode'
import { useReceipt, type ReceiptModel } from '../useReceipt'

const done = () => router.dismissTo('/(tabs)')

/** `.check-wrap` at board 16's own size — 88, its rings 88 and 106, the tick 66 with a 32 check — not board 30's 84 (parts/Success). */
function Tick() {
  return (
    <View style={s.tickWrap}>
      <View style={s.ringOuter} />
      <View style={s.ringInner} />
      <View style={s.tick}><Icon name="check" size={icon[32]} color={ink.heading} /></View>
    </View>
  )
}

/** Not on the board: whether the vote is in is still being asked, or cannot be. The least that is honest. */
function NotYet({ m, id }: { m: ReceiptModel; id: string | undefined }) {
  switch (m.status) {
    case 'checking':
      return (
        <View style={s.checking} aria-busy>
          <Text {...font.inter600_12_5} color={ink.secondary}>{"Asking the server whether your household's vote is in…"}</Text>
          <ActivityIndicator color={fill.primary} />
        </View>
      )
    case 'offline':
      return <EmptyState tone="light" icon="privacy-note" title="This needs a connection" body="Whether your household's vote is in is the server's answer, and it is never guessed from this phone. Connect, then check again. Nothing is sent by checking." action={{ label: 'Check again', onPress: m.retry }} />
    case 'error':
      return <ErrorView tone="light" message={m.message ?? 'The receipt could not be loaded.'} onRetry={m.retry} />
    case 'missing':
      return <EmptyState tone="light" icon="one-vote-note" title="No such election" body="This election could not be found. It may have been withdrawn by the estate office." action={{ label: 'Back to home', onPress: done }} />
    case 'denied':
      return <EmptyState tone="light" icon="privacy-note" title="Voting is not available on this account" body="This account does not have access to the estate's elections. Contact the estate office." action={{ label: 'Back to home', onPress: done }} />
    default:
      return (
        <EmptyState
          tone="light"
          icon="one-vote-note"
          title="No vote recorded"
          body={m.body}
          // Back to the first page if it is still on the stack (the receipt was reached from the review); otherwise it replaces this screen.
          action={m.mayVote ? { label: 'Go to the ballot', onPress: () => router.dismissTo(`/elections/${id ?? ''}/ballot-a` as never) } : { label: 'Back to home', onPress: done }}
        />
      )
  }
}

export default function ReceiptScreen() {
  const insets = useSafeAreaInsets()
  const { id } = useLocalSearchParams<{ id?: string }>()
  const m = useReceipt(id)
  useFidelityReady(true)

  useEffect(() => {
    // Only Android has a hardware back button (and the web build's BackHandler is a stub that complains).
    if (Platform.OS !== 'android') return
    const watching = BackHandler.addEventListener('hardwareBackPress', () => {
      done()
      return true
    })
    return () => watching.remove()
  }, [])

  // A state whose own words already lead home needs no second way there.
  const homeGiven = m.status === 'missing' || m.status === 'denied' || (m.status === 'none' && !m.mayVote)

  return (
    <View style={s.screen}>
      <Stack.Screen options={{ gestureEnabled: false }} />
      <AppBar title={m.status === 'in' ? 'Vote submitted' : 'Vote receipt'} back={false} />

      {m.status === 'in' ? (
        <View style={s.wrap}>
          <Tick />
          <Text {...font.poppins600_21} color={ink.heading} align="center" role="heading">{'Your vote is in'}</Text>
          <View style={s.line}><Text {...font.inter400_12_5_lh1_5} color={ink.secondary} align="center">{m.body}</Text></View>
          <ReceiptCode label={m.boxLabel} code={m.boxValue} caption={m.boxCaption} style={s.receipt} />
          <View style={s.verify}>
            <Icon name="why-note" size={icon[18]} color={ink.action} />
            <View style={s.verifyText}>
              <Text {...font.inter600_11_5_lh1_4} color={ink.body}>
                {'When polls close, you can check this code against the published results in '}
                <Text {...font.inter700_11_5_lh1_4} color={ink.link}>{'Elections → Verify my vote'}</Text>
                {': without revealing who you voted for.'}
              </Text>
            </View>
          </View>
          <PrimaryButton label="Done" onPress={done} style={s.done} />
        </View>
      ) : (
        <View style={{ paddingBottom: aboveGestureBar(space[20], insets.bottom) }}>
          <NotYet m={m} id={id} />
          {homeGiven ? null : <SecondaryButton label="Back to home" onPress={done} style={s.home} />}
        </View>
      )}
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  // .receipt-wrap
  wrap: { alignItems: 'center', paddingTop: space[34], paddingHorizontal: space[24] },
  // .check-wrap · .r-ring.a · .r-ring.b · .check-circle
  tickWrap: { width: size[88], height: size[88], alignItems: 'center', justifyContent: 'center', marginBottom: space[18] },
  ringOuter: { position: 'absolute', width: size[106], height: size[106], borderRadius: radius.avatar(size[106]), borderWidth: stroke['1.5'], borderColor: line.focus, opacity: 0.14 },
  ringInner: { position: 'absolute', width: size[88], height: size[88], borderRadius: radius.avatar(size[88]), borderWidth: stroke['1.5'], borderColor: line.focus, opacity: 0.28 },
  tick: { width: size[66], height: size[66], borderRadius: radius.avatar(size[66]), alignItems: 'center', justifyContent: 'center', backgroundColor: fill.gold, zIndex: 2 },
  // .rs — 5 under the title
  line: { marginTop: space[5] },
  // .receipt-box { margin: 22px 0 14px }
  receipt: { marginTop: space[22], marginBottom: space[14] },
  // .verify-row
  verify: { alignSelf: 'stretch', flexDirection: 'row', alignItems: 'center', gap: space[11], backgroundColor: fill.surface, borderRadius: corner[14], borderWidth: stroke['1.5'], borderColor: line.outline, paddingVertical: space[12], paddingHorizontal: space[14], marginBottom: space[24] },
  verifyText: { flex: 1, minWidth: 0 },
  // .btn-primary, width 100%
  done: { alignSelf: 'stretch' },
  // The states the board does not draw
  checking: { padding: space[24], gap: space[22] },
  home: { marginTop: space[8], marginHorizontal: space[24] },
})
