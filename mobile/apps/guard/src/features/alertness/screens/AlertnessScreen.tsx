/**
 * guard-app-15 · Alertness Check. Styles are the board's own, copied from the lifted file
 * (src/lifted/GuardApp15.tsx).
 *
 * CONSENT FIRST: a guard who has not agreed sees what a check is, what it sends (a tap and the time —
 * no photo, no face or fingerprint data, no location) and what saying no means. Only then the check.
 * The answer is ONLINE ONLY and sent once, directly — never queued: a check answered later is late by
 * definition, and the app says so rather than pretend.
 */

import { useCallback, useEffect, useState, useSyncExternalStore } from 'react'
import { ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import Svg, { Circle } from 'react-native-svg'
import { Icon, InfoHeading, Pressable, Text, a, c, fidelityScreenId, isFidelity, now, sh, useFidelityReady } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { isExpoGo } from '@gs/core/lib'
import { guardApi } from '@gs/core/api/guard'
import { INFO } from '@/info/topics'
import { useAppSelector } from '@/store'
import { outcomeText, pickCheck, remaining } from '../alertness'
import { consent as consentStore, openChecks } from '../state'

const RING = 358
const leave = () => (router.canGoBack() ? router.back() : router.replace('/(tabs)' as never))

export default function AlertnessScreen() {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-15'
  const online = useAppSelector((s) => s.network.online)
  const postName = useAppSelector((s) => s.session.principal?.siteName) ?? 'your post'
  const open = useSyncExternalStore(openChecks.subscribe, openChecks.get, openChecks.get)
  const [consent, setConsent] = useState(() => consentStore.get())
  const [, setBeat] = useState(0)
  const [sending, setSending] = useState(false)
  const [result, setResult] = useState<ReturnType<typeof outcomeText> | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  const [respond] = guardApi.useAlertnessRespondMutation()
  useFidelityReady(true)

  const check = fixture ? null : pickCheck(open.checks, now(), open.offsetMs)
  const left = check ? remaining(check, now(), open.offsetMs) : null

  // The countdown is the server's deadline read against the server's clock; redrawn four times a second.
  useEffect(() => {
    if (fixture || !check) return
    const timer = setInterval(() => setBeat((n) => n + 1), 250)
    return () => clearInterval(timer)
  }, [fixture, check])

  const confirm = useCallback(async () => {
    if (fixture || !check || sending) return
    if (!online) { setProblem('No signal. A check can only be answered while connected: if it closes, it is recorded as missed. Tell the control room you had no signal.'); return }
    setSending(true)
    setProblem(null)
    try {
      // No score is sent: nothing about the guard's face or fingerprint is measured or leaves the phone.
      const answer = await respond({ check: check.check_id }).unwrap()
      openChecks.settle(check.check_id)
      setResult(outcomeText(answer.outcome))
    } catch (error) {
      if (isApiError(error) && (error.code === 'check_expired' || error.status === 404)) { openChecks.settle(check.check_id); setResult(outcomeText('missed')) }
      else setProblem(isApiError(error) ? error.message : 'The answer could not be sent. Check your connection and tap again: it has NOT been recorded yet.')
    } finally {
      setSending(false)
    }
  }, [fixture, check, sending, online, respond])

  const ring = (fraction: number, label: string) => (
    <View style={s.acRingWrap}>
      <Svg width={130} height={130} viewBox="0 0 130 130" fill="none" style={s.acRingWrapSvg}>
        <Circle cx="65" cy="65" r="57" fill="none" stroke={c('border-700')} strokeWidth={7} />
        <Circle cx="65" cy="65" r="57" strokeDasharray={String(RING)} strokeDashoffset={String(fixture ? 120 : Math.round(RING * (1 - fraction)))} fill="none" stroke={c(fraction < 0.25 && !fixture ? 'red-500' : 'amber-500')} strokeWidth={7} strokeLinecap="round" />
      </Svg>
      <View style={s.acRingTxt}>
        <Text face="Poppins-Bold" size={24} color="white" aria-live="off">{label}</Text>
        <Text face="Inter-Bold" size={9} color="text-3" style={{ marginTop: 2 }}>{'RESPOND BY'}</Text>
      </View>
    </View>
  )

  /* ── 1 · consent, before anything else ─────────────────────────────────── */
  if (!fixture && !consent.agreedAt) {
    return (
      <View style={s.screen}>
        <ScrollView contentContainerStyle={s.consentWrap}>
          <View style={s.consentIcon}><Icon name="why-note" size={26} color="amber-500" /></View>
          <InfoHeading topic={INFO.vitality} tone="guard" style={s.infoCentre}>
            <Text face="Poppins-SemiBold" size={20} color="white" align="center" role="heading">{'Alertness check-ins'}</Text>
          </InfoHeading>
          <Text face="Inter-Regular" size={12.5} lh={1.55} color="text-2" align="center" style={{ marginTop: 8 }}>{'Now and then during a shift the control room asks this phone to check you are alert and on post. You have two minutes to tap "I\'m here".'}</Text>
          <View style={s.consentCard}>
            {[
              ['What is sent', 'Only that you tapped, and when. No photo, no face or fingerprint data, no recording and no location is taken or sent by a check-in.'],
              ['Who sees it', 'Your supervisor and the control room, as part of this shift\'s record. It is also your proof the post was covered.'],
              ['It never gets in the way', 'A check-in never covers a duress alert or a report you are writing. It waits.'],
              ['If you say no', 'Check-ins will not be shown to you. The server records each one as missed, and your supervisor will see that. You can agree later under More.'],
              ...(isExpoGo ? [['In this preview build', 'A check-in can only reach you while the app is open on the screen. One that arrives while the app is closed is recorded as missed.']] : []),
            ].map(([title, body]) => (
              <View key={title} style={s.consentRow}>
                <Text face="Inter-Bold" size={12} color="white">{title}</Text>
                <Text face="Inter-Regular" size={11.5} lh={1.5} color="text-2" style={{ marginTop: 2 }}>{body}</Text>
              </View>
            ))}
          </View>
          <Pressable onPress={() => setConsent(consentStore.agree(now()))} role="button" style={({ pressed }) => [s.confirmBtn, { marginTop: 20, opacity: pressed ? 0.9 : 1 }]}>
            <Icon name="check" size={20} color="g-navy-900" style={s.confirmBtnSvg} />
            <View style={s.shrink}><Text face="Poppins-Bold" size={15} color="g-navy-900">{'I agree'}</Text></View>
          </Pressable>
          <Pressable onPress={() => { consentStore.decline(now()); leave() }} role="button" hitSlop={10} style={s.declineBtn}>
            <Text face="Inter-Bold" size={13} color="text-2">{consent.declinedAt ? 'Keep them off' : 'Not now'}</Text>
          </Pressable>
        </ScrollView>
      </View>
    )
  }

  /* ── 2 · the answer the server gave ────────────────────────────────────── */
  if (result) {
    return (
      <View style={s.screen}>
        <View style={s.alertScreen}>
          <View style={[s.consentIcon, { backgroundColor: a(result.tone, 0.12) }]}><Icon name={result.tone === 'green-400' ? 'check' : 'recap-item'} size={26} color={result.tone} /></View>
          <Text face="Poppins-SemiBold" size={20} color="white" align="center" role="alert">{result.title}</Text>
          <Text face="Inter-Regular" size={12.5} lh={1.55} color="text-2" align="center" style={{ marginTop: 8 }}>{result.body}</Text>
          <Pressable onPress={leave} role="button" style={({ pressed }) => [s.confirmBtn, { opacity: pressed ? 0.9 : 1 }]}>
            <View style={s.shrink}><Text face="Poppins-Bold" size={15} color="g-navy-900">{'Done'}</Text></View>
          </Pressable>
          {result.tone !== 'green-400' ? (
            <Pressable onPress={() => router.replace('/messages/dispatch' as never)} role="button" hitSlop={10} style={s.declineBtn}><Text face="Inter-Bold" size={13} color="amber-500">{'Tell the control room'}</Text></Pressable>
          ) : null}
        </View>
      </View>
    )
  }

  /* ── 3 · nothing to answer ─────────────────────────────────────────────── */
  if (!fixture && !check) {
    return (
      <View style={s.screen}>
        <View style={s.alertScreen}>
          <View style={s.consentIcon}><Icon name="check" size={26} color="green-400" /></View>
          {/* The `i` is here and on the consent screen, never on the check itself: two minutes are not for reading. */}
          <InfoHeading topic={INFO.vitality} tone="guard" style={s.infoCentre}>
            <Text face="Poppins-SemiBold" size={20} color="white" align="center">{'No check-in right now'}</Text>
          </InfoHeading>
          <Text face="Inter-Regular" size={12.5} lh={1.55} color="text-2" align="center" style={{ marginTop: 8 }}>{online ? 'When the control room asks, this screen opens by itself and you have two minutes to answer.' : 'No signal. Check-ins can only arrive and be answered while this phone is connected.'}</Text>
          <Pressable onPress={leave} role="button" style={({ pressed }) => [s.confirmBtn, { opacity: pressed ? 0.9 : 1 }]}>
            <View style={s.shrink}><Text face="Poppins-Bold" size={15} color="g-navy-900">{'Back'}</Text></View>
          </Pressable>
          <Pressable onPress={() => setConsent(consentStore.decline(now()))} role="button" hitSlop={10} style={s.declineBtn}><Text face="Inter-Bold" size={13} color="text-2">{'Turn check-ins off for me'}</Text></Pressable>
        </View>
      </View>
    )
  }

  /* ── 4 · the check ─────────────────────────────────────────────────────── */
  return (
    <View style={s.screen}>
      <View style={s.alertScreen}>
        {ring(left?.fraction ?? 0.6, fixture ? '01:12' : left?.label ?? '00:00')}
        <Text face="Poppins-SemiBold" size={20} color="white" align="center" role="alert">{'Still with us?'}</Text>
        <Text face="Inter-Regular" size={12.5} lh={1.55} color="text-2" align="center" style={{ marginTop: 8 }}>{fixture ? "Just checking in on Main Gate. Tap below to confirm you're alert and on post." : `Just checking in at ${postName}. Tap below to confirm you're alert and on post.`}</Text>
        {problem ? <Text face="Inter-SemiBold" size={11.5} lh={1.5} color="red-500" align="center" role="alert" style={{ marginTop: 14 }}>{problem}</Text> : null}
        <Pressable onPress={() => void confirm()} disabled={sending || fixture} role="button" aria-label="I'm here" style={({ pressed }) => [s.confirmBtn, { opacity: sending ? 0.6 : pressed ? 0.9 : 1 }]}>
          <Icon name="check" size={20} color="g-navy-900" style={s.confirmBtnSvg} />
          <View style={s.shrink}><Text face="Poppins-Bold" size={15} color="g-navy-900">{sending ? 'Sending…' : "I'm here"}</Text></View>
        </Pressable>
        <View style={s.whyNote}>
          <Icon name="why-note" size={15} color="text-3" style={s.whyNoteSvg} />
          <View style={s.shrink}><Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3">{"These random check-ins protect you too: they're your proof that this post was covered tonight."}</Text></View>
        </View>
      </View>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  shrink: { flexShrink: 1 },
  infoCentre: { justifyContent: 'center' },
  acRingWrapSvg: { overflow: 'hidden', transform: [{ rotate: '-90deg' }] },
  acRingTxt: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, flexDirection: 'column', alignItems: 'center', justifyContent: 'center' },
  acRingWrap: { width: 130, height: 130, flexDirection: 'row', flexWrap: 'wrap', alignItems: 'flex-start', marginBottom: 24 },
  confirmBtnSvg: { width: 20, height: 20, overflow: 'hidden' },
  confirmBtn: { width: '100%', height: 56, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), borderRadius: 16, gap: 10, boxShadow: sh([{ x: 0, y: 10, blur: 24, spread: 0, token: 'amber-500', opacity: 0.3 }]), marginTop: 28 },
  declineBtn: { minHeight: 48, alignItems: 'center', justifyContent: 'center', marginTop: 10, paddingHorizontal: 16 },
  whyNoteSvg: { width: 15, height: 15, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  whyNote: { flexDirection: 'row', gap: 9, marginTop: 22 },
  alertScreen: { width: '100%', height: '100%', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', backgroundColor: a('surface-900', 0.94), paddingHorizontal: 30 },
  consentWrap: { flexGrow: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 26, paddingVertical: 64 },
  consentIcon: { width: 60, height: 60, borderRadius: 30, alignItems: 'center', justifyContent: 'center', backgroundColor: a('amber-500', 0.12), marginBottom: 18 },
  consentCard: { width: '100%', backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 15, paddingVertical: 6, marginTop: 20 },
  consentRow: { paddingVertical: 10 },
})
