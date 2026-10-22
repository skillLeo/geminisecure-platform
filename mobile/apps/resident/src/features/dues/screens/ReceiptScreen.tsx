/**
 * resident-app-30 · Payment successful — rebuilt from the board (the user, 7 October: the eleventh screen), every value
 * from the design tokens and the resident boards' success pattern (design/parts Success).
 *
 * The board, top to bottom, under the status bar and no app bar: the gold tick in its two faint rings · "Payment
 * successful" · "$12,400.00 paid to Phoenix Park Village 1 · receipt #4472" · the card — its lines, then "Your balance"
 * under its rule · the navy-100 note · "Download PDF" and "Done", side by side.
 *
 * What each part SAYS is the hook's (useDues.ts usePaymentSuccess → duesLogic.ts successView), the design's own sample
 * too, in fidelity mode; this file reads no mode. Live it is the estate's receipt: the demonstration Pay just recorded
 * ("Payment successful"), or a payment the office recorded, opened from the history ("Payment received") — a reversed
 * one in amber, never the gold tick. The outline button shares the receipt as text on a phone (there is no PDF), and
 * says so. Not on the board, and kept to the least that is honest: a spinner, the kit's words when there is nothing to
 * show. THE PAGE DOES NOT SCROLL.
 */

import { ActivityIndicator, StyleSheet, View } from 'react-native'
import { StatusBar } from 'expo-status-bar'
import { useLocalSearchParams } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, Icon, Text, useFidelityReady } from '@gs/core'
import { DetailLines, InfoCard, PrimaryButton, SecondaryButton, Success, ButtonRow } from '@/design/parts'
import { fill, font, icon, ink, radius, size, space } from '@/design/tokens'
import type { SuccessView } from '../duesLogic'
import { usePaymentSuccess, type PaymentSuccessModel } from '../useDues'

/** A reversed payment: the same layout, its mark amber and its words saying what happened — never the gold tick. */
function Reversed({ view, m }: { view: SuccessView; m: PaymentSuccessModel }) {
  return (
    <View style={s.wrap}>
      <View style={s.markWrap}>
        <View style={s.mark}>
          <Icon name="shield-alert" size={icon[30]} color={ink.onGoldTint} />
        </View>
      </View>
      <Text {...font.poppins600_20} color={ink.heading} align="center" role="heading">{view.title}</Text>
      <View style={s.sub}><Text {...font.inter400_12_lh1_5} color={ink.secondary} align="center">{view.line}</Text></View>
      <DetailLines lines={view.details} total={view.total ?? undefined} />
      <InfoCard text={view.note} />
      <ButtonRow style={s.buttons}>
        <SecondaryButton label={m.secondary.label} icon="side-btn-2" onPress={m.secondary.onPress} />
        <PrimaryButton label="Done" onPress={m.done} />
      </ButtonRow>
    </View>
  )
}

export default function ReceiptScreen() {
  const insets = useSafeAreaInsets()
  const { payment } = useLocalSearchParams<{ payment?: string }>()
  const m = usePaymentSuccess(typeof payment === 'string' && payment !== '' ? payment : undefined)
  useFidelityReady(m.status === 'ready')
  const view = m.view

  return (
    <View style={[s.screen, { paddingTop: insets.top, paddingBottom: insets.bottom }]}>
      <StatusBar style="dark" />
      {view && view.reversed ? <Reversed view={view} m={m} /> : null}
      {view && !view.reversed ? (
        <Success
          title={view.title}
          line={view.line}
          details={view.details}
          total={view.total ?? undefined}
          info={view.note}
          buttons={[
            <SecondaryButton key="secondary" label={m.secondary.label} icon="side-btn-2" onPress={m.secondary.onPress} />,
            <PrimaryButton key="done" label="Done" onPress={m.done} />,
          ]}
        />
      ) : null}
      {view && m.problem ? (
        <View style={s.problem}>
          <Text {...font.inter500_11_5} color={ink.onGoldTint} align="center" role="alert">{m.problem}</Text>
        </View>
      ) : null}

      {!view && m.status === 'loading' ? <View style={s.loading}><ActivityIndicator color={fill.primary} /></View> : null}
      {!view && m.status === 'error' ? <ErrorView tone="light" message={m.errorMessage ?? 'This receipt could not be loaded.'} onRetry={m.refresh} /> : null}
      {!view && m.status === 'missing' ? <EmptyState tone="light" icon="help-icon-3" title="No such receipt" body="The estate has no payment of your household's with that number." /> : null}
      {!view && m.status === 'denied' ? <EmptyState tone="light" icon="tab-card" title="Receipts aren't open to your account" body={m.errorMessage ?? "Your account is not linked to a home on the estate's register yet."} /> : null}
      {!view && m.status === 'empty' ? <EmptyState tone="light" icon="help-icon-3" title="No payments recorded yet" body="When the estate office records a payment for your home, its receipt appears here." /> : null}
      {!view && m.status !== 'loading' ? (
        <View style={s.back}>
          <SecondaryButton label="Back" onPress={m.done} />
        </View>
      ) : null}
    </View>
  )
}

const s = StyleSheet.create({
  // .screen — white, and nothing on it scrolls
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  // .receipt-wrap, for the reversed receipt (the Success part draws the rest)
  wrap: { alignItems: 'center', paddingTop: space[30], paddingHorizontal: space[24] },
  // .check-wrap's place and size, holding the amber mark of a reversal instead of the gold tick
  markWrap: { width: size[84], height: size[84], alignItems: 'center', justifyContent: 'center', marginBottom: space[16] },
  mark: { width: size[62], height: size[62], borderRadius: radius.avatar(size[62]), alignItems: 'center', justifyContent: 'center', backgroundColor: fill.goldTint },
  sub: { marginTop: space[5] },
  buttons: { marginTop: space[16] },
  problem: { paddingHorizontal: space[24], marginTop: space[10] },
  loading: { paddingTop: space[24], alignItems: 'center' },
  back: { paddingHorizontal: space[24], marginTop: space[8] },
})
