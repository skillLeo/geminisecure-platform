/**
 * What a marking screen shows INSTEAD of the paper, for every gate state that is not "open".
 * No paper, no position and no choice is mounted under any of these — this file cannot draw one:
 * it is given the gate and nothing else.
 *
 * An ineligible household gets a plain holding screen: the agreed sentence, and the server's own
 * `reason` if it gives one. Nothing here explains, argues or offers a way to dispute — that is a
 * conversation for the estate office.
 *
 * No board draws these states; they are kept to the least that is honest, in the design's tokens.
 */

import { StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { EmptyState, ErrorView, Pressable, SkeletonLines, Text } from '@gs/core'
import { font, ink, size, space } from '@/design/tokens'
import { NOT_OPEN_TO_HOUSEHOLD, RESULTS_NOT_YET } from '../paper'
import type { GateModel } from '../useBallotGate'

const home = () => router.dismissTo('/(tabs)')

export function BallotHolding({ gate, id, onRetry }: { gate: Exclude<GateModel, { state: 'open' }>; id: string | undefined; onRetry: () => void }) {
  switch (gate.state) {
    case 'checking':
      return (
        <View style={s.checking} aria-busy>
          <Text {...font.inter600_12_5} color={ink.secondary}>{'Checking that your household may vote…'}</Text>
          <SkeletonLines tone="light" lines={3} />
          <SkeletonLines tone="light" lines={4} />
        </View>
      )
    case 'ineligible':
      return (
        <View role="alert" style={s.centre}>
          <EmptyState tone="light" icon="one-vote-note" title="Voting is not open to this household" body={NOT_OPEN_TO_HOUSEHOLD} style={s.flush} />
          {/* The server's own reason, as the server wrote it — and nothing added to it. */}
          {gate.reason ? (
            <View style={s.reason}><Text {...font.inter600_12_lh1_5} color={ink.label} align="center">{gate.reason}</Text></View>
          ) : null}
          <Pressable onPress={home} role="button" aria-label="Back to home" hitSlop={size[10]} style={s.homeLink}>
            <Text {...font.inter700_13} color={ink.action}>{'Back to home'}</Text>
          </Pressable>
        </View>
      )
    case 'voted':
      return <EmptyState tone="light" icon="confirm-note" title="Your household's vote is in" body="One paper per household. A vote that has been cast cannot be shown again, changed or taken back." action={{ label: 'See the receipt', onPress: () => router.replace(`/elections/${id ?? ''}/receipt` as never) }} />
    case 'closed':
      // `voting_open: false` is all the server says — it does not say whether the poll has not opened YET or has
      // closed, so neither is claimed.
      return <EmptyState tone="light" icon="confirm-note" title="Voting is not open" body={`Voting is not open for this election right now. If it has not started yet, the estate office will announce when it opens. ${RESULTS_NOT_YET}`} action={{ label: 'Election results', onPress: () => router.replace(`/elections/${id ?? ''}/results` as never) }} />
    case 'offline':
      return <EmptyState tone="light" icon="privacy-note" title="Voting needs a connection" body="A vote is sent to the estate's server while you watch: it is never saved on this phone to send later. Connect, then try again." action={{ label: 'Try again', onPress: onRetry }} />
    case 'missing':
      return <EmptyState tone="light" icon="one-vote-note" title="No such election" body="This election could not be found. It may have been withdrawn by the estate office." action={{ label: 'Back to home', onPress: home }} />
    case 'denied':
      return <EmptyState tone="light" icon="privacy-note" title="Voting is not available on this account" body="This account does not have access to the estate's elections. Contact the estate office." action={{ label: 'Back to home', onPress: home }} />
    case 'error':
      return <ErrorView tone="light" message={gate.message} onRetry={onRetry} />
  }
}

const s = StyleSheet.create({
  checking: { padding: space[20], gap: space[22] },
  centre: { alignItems: 'center' },
  flush: { paddingBottom: 0 },
  reason: { marginTop: space[10], paddingHorizontal: space[28] },
  homeLink: { marginTop: space[16], minHeight: size[44], justifyContent: 'center' },
})
