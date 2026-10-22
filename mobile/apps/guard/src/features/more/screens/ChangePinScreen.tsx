/**
 * guard-app-35 · Change PIN. Styles are the board's own, copied from the lifted file (src/lifted/GuardApp35.tsx).
 *
 * THERE IS NO PIN ON THIS PLATFORM. A guard is identified by the enrolled handset (bound to them in the
 * console) and by the phone's own fingerprint or face check at clock-in. The server has no PIN to set,
 * and a PIN checked only on the phone would protect nothing — so the live screen does not pretend: the
 * number pad is drawn for fidelity only, and the live screen says how sign-in really works and what to
 * do if the phone is lost. (A lock in front of the app was rejected outright: nothing may ever stand
 * between a guard and the duress button.)
 */

import { Linking, Platform, ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { AppBar, Icon, Pressable, Text, a, c, fidelityScreenId, isFidelity, useFidelityReady } from '@gs/core'
import { pinFixture } from '@/fidelity/more'
import { onboarding } from '@/features/enrolment/onboardingState'

const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', '⌫']
const LOST_DRAFT = 'Security concern about my handset: '

export default function ChangePinScreen() {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-35'
  const identity = onboarding.get().identity
  useFidelityReady(true)

  if (fixture) {
    return (
      <View style={s.screen}>
        <AppBar title="Change PIN" />
        <View style={s.pinWrap}>
          <Text face="Inter-SemiBold" size={12.5} color="text-2" align="center" style={{ marginBottom: 14 }}>{pinFixture.prompt}</Text>
          <View style={s.pinDots}>{Array.from({ length: 6 }, (_, i) => <View key={i} style={i < pinFixture.filled ? s.pinDot : s.pinDot2} />)}</View>
          <View style={s.numPad}>
            {KEYS.map((k, i) => (
              <View key={i} style={[k === '' || k === '⌫' ? s.numKeyBare : s.numKey, { width: i % 3 === 2 ? 85.34 : 85.33 }]}>
                {k ? <Text face="Poppins-SemiBold" size={k === '⌫' ? 13 : 20} color={k === '⌫' ? 'text-3' : 'white'}>{k}</Text> : null}
              </View>
            ))}
          </View>
        </View>
      </View>
    )
  }

  const points: [string, string][] = [
    ['This phone is your key', 'It was enrolled to you with a one-time code and approved by a supervisor. Nobody can sign in as you from another phone without a new code and a new approval.'],
    ['Your fingerprint or face', identity === 'confirmed' ? 'At clock-in the phone itself checks it is you. Nothing about your fingerprint or face ever reaches this app or the office.' : 'This phone is not checking your fingerprint or face at clock-in. Set one up in the phone\'s settings so your shifts are confirmed by the handset.'],
    ['There is no PIN to change', 'GeminiSecure does not use a PIN, so there is nothing here to forget or to share. Keep your phone\'s own screen lock private.'],
  ]

  return (
    <View style={s.screen}>
      <AppBar title="Sign-in & security" />
      <ScrollView contentContainerStyle={s.bodyPad}>
        <View style={s.card}>
          {points.map(([title, body], i) => (
            <View key={title} style={[s.point, i > 0 ? s.pointRule : null]}>
              <Text face="Inter-Bold" size={12.5} color="white">{title}</Text>
              <Text face="Inter-Regular" size={11.5} lh={1.55} color="text-2" style={{ marginTop: 3 }}>{body}</Text>
            </View>
          ))}
        </View>

        <View style={s.warn} role="note">
          <Icon name="dispute-link" size={15} color="amber-500" style={s.warnSvg} />
          <View style={s.shrink}>
            <Text face="Inter-Bold" size={12} color="amber-500">{'Phone lost, stolen, or someone knows your screen lock?'}</Text>
            <Text face="Inter-Medium" size={11} lh={1.5} color="amber-500" style={{ marginTop: 3 }}>{'Tell your supervisor or the control room at once. They revoke this handset from the console there and then: the next time it reaches the server it is signed out and refused, and anything it saved without signal is refused with it.'}</Text>
          </View>
        </View>

        <Pressable onPress={() => router.push({ pathname: '/messages/[thread]', params: { thread: 'dispatch', draft: LOST_DRAFT } } as never)} role="button" style={({ pressed }) => [s.btnOutline, { opacity: pressed ? 0.8 : 1 }]}>
          <Icon name="tab-messages" size={17} color="amber-500" />
          <Text face="Inter-SemiBold" size={13} color="white">{'Write to the control room'}</Text>
        </Pressable>
        {Platform.OS === 'web' ? null : (
          <Pressable onPress={() => void Linking.openSettings().catch(() => undefined)} role="button" style={({ pressed }) => [s.btnOutline, { opacity: pressed ? 0.8 : 1 }]}>
            <Icon name="privacy-note" size={17} color="amber-500" />
            <Text face="Inter-SemiBold" size={13} color="white">{"Open this phone's settings"}</Text>
          </Pressable>
        )}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  shrink: { flexShrink: 1 },
  pinDot: { width: 16, height: 16, backgroundColor: c('amber-500'), borderRadius: 8, borderWidth: 2, borderColor: c('amber-500') },
  pinDot2: { width: 16, height: 16, borderRadius: 8, borderWidth: 2, borderColor: c('border-600') },
  pinDots: { flexDirection: 'row', gap: 14, marginBottom: 30 },
  numKey: { height: 60, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700') },
  numKeyBare: { height: 60, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 16 },
  numPad: { width: '100%', maxWidth: 280, flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  pinWrap: { flexDirection: 'column', alignItems: 'center', paddingTop: 20, paddingHorizontal: 24 },
  bodyPad: { paddingTop: 16, paddingBottom: 24, paddingHorizontal: 20 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 4 },
  point: { paddingVertical: 12 },
  pointRule: { borderTopWidth: 1, borderTopColor: c('border-700') },
  warnSvg: { flexGrow: 0, flexShrink: 0, marginTop: 1 },
  warn: { flexDirection: 'row', gap: 10, backgroundColor: a('amber-500', 0.1), borderRadius: 14, borderWidth: 1, borderColor: a('amber-500', 0.28), paddingHorizontal: 13, paddingVertical: 12, marginTop: 14 },
  btnOutline: { height: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 14, borderWidth: 1, borderColor: c('border-600'), gap: 9, marginTop: 12 },
})
