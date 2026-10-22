/**
 * guard-app-06 · Waiting on Resident. Styles are the board's own, copied from the lifted file
 * (src/lifted/GuardApp06.tsx). The board draws the waiting state; approved (green), declined
 * (red) and no answer (amber) reuse its ring, type and card. It never hangs — see useApprovalWait().
 */

import { ScrollView, StyleSheet, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { AppBar, EmptyState, ErrorView, GuardButton, Icon, Pressable, SkeletonLines, Text, a, c, useFidelityReady } from '@gs/core'
import { OverrideChoice } from '../components/OverrideChoice'
import { PolicyChoices } from '../components/PolicyChoices'
import { RecordedPanel } from '../components/RecordedPanel'
import { StatusRing } from '../components/StatusRing'
import { useApprovalWait } from '../useApprovalWait'
import type { WaitingParams } from '../useWalkUp'

const GLYPH = { approved: 'check', denied: 'act-result', expired: 'qa-tile-3' } as const

export default function WaitingScreen() {
  const params = useLocalSearchParams<Partial<Record<keyof WaitingParams, string>>>()
  const m = useApprovalWait(params)
  useFidelityReady(m.status !== 'loading')
  const home = () => router.replace('/(tabs)')

  return (
    <View style={s.screen}>
      <AppBar title="Requesting entry" />
      <ScrollView contentContainerStyle={s.waitScreen}>
        {m.status === 'loading' ? <SkeletonLines tone="dark" lines={6} style={s.full} /> : null}
        {m.status === 'missing' ? (
          <EmptyState tone="dark" icon="tab-profile" title="No request to wait on" body="This screen follows a walk-up request. Start one from the walk-up form." action={{ label: 'New walk-up visitor', onPress: () => router.replace('/walk-up' as never) }} />
        ) : null}
        {m.status === 'not_found' ? (
          <EmptyState tone="dark" icon="qa-tile-3" title="That request is not on record" body={m.errorMessage ?? 'No such walk-up request at this estate.'} action={{ label: 'New walk-up visitor', onPress: () => router.replace('/walk-up' as never) }} />
        ) : null}
        {m.status === 'forbidden' ? (
          <EmptyState tone="dark" icon="side-btn-7" title="Not available on this handset" body="This phone is not allowed to read walk-up requests. Contact your supervisor. Scanning a pass still works." />
        ) : null}
        {m.status === 'error' ? <ErrorView tone="dark" message={m.errorMessage ?? 'The request could not be loaded.'} onRetry={m.retry} /> : null}

        {m.status === 'ready' && m.recorded ? <RecordedPanel recorded={m.recorded} onDone={home} /> : null}

        {m.status === 'ready' && !m.recorded ? (
          <>
            {/* Nobody can answer in the app: said first, and there is no count to wait out. */}
            {m.cannotAnswer ? (
              <View style={s.signalNote} role="alert">
                <Icon name="offline-note" size={14} color="amber-500" style={s.noteSvg} />
                <View style={s.reqRowSpan}>
                  <Text face="Inter-SemiBold" size={12} lh={1.5} color="amber-500">{m.cannotAnswer}</Text>
                </View>
              </View>
            ) : null}

            {m.cannotAnswer ? (
              <StatusRing tone="amber-500" offset={0}>
                <Icon name={GLYPH.expired} size={40} color="amber-500" />
              </StatusRing>
            ) : m.view === 'waiting' ? (
              <View role="timer" aria-live="off" aria-label={m.count.known ? `${m.count.label} left for the household to answer` : 'Waiting for the household'}>
                <StatusRing tone="amber-500" offset={m.ringOffset}>
                  <Text face="Poppins-Bold" size={22} color="white">{m.count.label}</Text>
                </StatusRing>
              </View>
            ) : (
              <StatusRing tone={m.copy.tone} offset={0}>
                <Icon name={GLYPH[m.view]} size={40} color={m.copy.tone} />
              </StatusRing>
            )}

            <View role={m.view === 'waiting' ? undefined : 'alert'} style={s.full}>
              <Text face="Poppins-SemiBold" size={18} color={m.view === 'waiting' ? 'white' : m.copy.tone} align="center" role="heading">{m.copy.title}</Text>
              <Text face="Inter-Regular" size={12} lh={1.5} color="text-2" align="center" style={{ marginTop: 6 }}>{m.copy.body}</Text>
            </View>

            {m.noSignal ? (
              <View style={s.signalNote} role="status">
                <Icon name="offline-note" size={14} color="amber-500" style={s.noteSvg} />
                <View style={s.reqRowSpan}>
                  <Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-500">
                    {m.view === 'waiting'
                      ? 'No signal: still counting; the answer will show when the connection returns.'
                      : 'No signal: the time to answer has passed on this phone. If the household did answer, it will show when the connection returns.'}
                  </Text>
                </View>
              </View>
            ) : null}

            <View style={s.reqCard}>
              {m.lines.map((line, i) => (
                <View key={i} style={s.reqRow}>
                  <Icon name={line.icon} size={16} color="amber-500" style={s.reqRowSvg} />
                  <View style={s.reqRowSpan}>
                    <Text face="Inter-Medium" size={12} color="text-2">{line.text}</Text>
                  </View>
                </View>
              ))}
            </View>

            {m.view === 'waiting' && !m.deciding ? (
              <>
                <Pressable onPress={m.decideNow} role="button" aria-label="Follow standing gate policy now" accessibilityHint="Stop waiting and record your own decision" style={({ pressed }) => [s.btnOutline, { opacity: pressed ? 0.7 : 1 }]}>
                  <Text face="Inter-SemiBold" size={13} color="white">{'Follow standing gate policy now'}</Text>
                </Pressable>
                <View style={s.standingNote}>
                  <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center">{m.standingNote}</Text>
                </View>
              </>
            ) : null}

            {m.view === 'expired' || m.deciding ? (
              <View style={s.actions}>
                <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper align="center" style={{ marginBottom: 10 }}>{"Apply the estate's policy"}</Text>
                {/* M21 §1.7: a household whose visitors are held — the policy's admit is an override, with a reason. */}
                {m.restricted ? (
                  <OverrideChoice approved={false} busy={m.busy} saveError={m.saveError} onOverride={m.admitOnOverride} onDeny={m.deny} />
                ) : (
                  <PolicyChoices busy={m.busy} saveError={m.saveError} onAdmit={m.admitOnOwnDecision} onDeny={m.deny} />
                )}
                {m.deciding && !m.cannotAnswer ? (
                  <Pressable onPress={m.keepWaiting} role="button" aria-label="Keep waiting for the household" hitSlop={14} style={s.keepWaiting}>
                    <Text face="Inter-Bold" size={12} color="amber-500">{'Keep waiting'}</Text>
                  </Pressable>
                ) : null}
              </View>
            ) : null}

            {m.view === 'approved' && m.restricted ? (
              <View style={s.actions}>
                <OverrideChoice approved busy={m.busy} saveError={m.saveError} onOverride={m.admitOnOverride} onDeny={m.denyRestricted} />
              </View>
            ) : null}

            {m.view === 'approved' && !m.restricted ? (
              <View style={s.actions}>
                <GuardButton label="Admit entry" onPress={m.admit} loading={m.busy} />
                {m.saveError ? <Text face="Inter-Medium" size={11} lh={1.5} color="red-500" align="center" style={{ marginTop: 10 }} role="alert">{m.saveError}</Text> : null}
                <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center" style={s.caption}>
                  {'Recorded on the gate log as admitted on the household’s approval: saved on this phone first, sent by itself.'}
                </Text>
              </View>
            ) : null}

            {m.view === 'denied' ? (
              <View style={s.actions}>
                <Pressable onPress={m.deny} disabled={m.busy} role="button" aria-label="Record denial" aria-busy={m.busy} style={({ pressed }) => [s.denyBtn, { opacity: pressed || m.busy ? 0.7 : 1 }]}>
                  <Text face="Inter-Bold" size={13} color="red-500">{'Record denial'}</Text>
                </Pressable>
                {m.saveError ? <Text face="Inter-Medium" size={11} lh={1.5} color="red-500" align="center" style={{ marginTop: 10 }} role="alert">{m.saveError}</Text> : null}
                <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center" style={s.caption}>
                  {'A denial is a record too: it goes on the gate log with your name and the time.'}
                </Text>
              </View>
            ) : null}
          </>
        ) : null}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  full: { width: '100%' },
  waitScreen: { flexDirection: 'column', alignItems: 'center', paddingTop: 40, paddingBottom: 28, paddingHorizontal: 24 },
  reqRowSvg: { width: 16, height: 16, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  reqRowSpan: { flexShrink: 1 },
  reqRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 6, gap: 11 },
  reqCard: { width: '100%', backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 15, marginTop: 22 },
  btnOutline: { width: '100%', height: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 14, borderWidth: 1, borderColor: c('border-600'), gap: 9, marginTop: 20 },
  standingNote: { paddingHorizontal: 10, marginTop: 12 },
  noteSvg: { flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  signalNote: { width: '100%', flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 12, borderWidth: 1, borderColor: a('amber-500', 0.28), paddingHorizontal: 12, paddingVertical: 10, gap: 9, marginTop: 14 },
  actions: { width: '100%', marginTop: 20 },
  caption: { marginTop: 10, paddingHorizontal: 10 },
  keepWaiting: { alignSelf: 'center', marginTop: 14, minHeight: 20, justifyContent: 'center' },
  denyBtn: { height: 52, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 15, borderWidth: 1, borderColor: c('red-500'), backgroundColor: a('red-500', 0.16) },
})
