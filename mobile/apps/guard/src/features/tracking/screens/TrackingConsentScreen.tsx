/**
 * "Sharing your location while on duty" — the consent screen (M11 §3.2), and the disclosure the phone's own
 * location dialogs follow. It is shown before the first shift (the clock-in screen's card) and again whenever the
 * policy's words change; the officer can open it any time from More → Location sharing and change their answer.
 *
 * WHAT IT SAYS, IN THIS ORDER: what is recorded; that it is only between clock-in and clock-out, including in the
 * background and while the app is closed, with a notification on the phone the whole time; who sees it; how long it
 * is kept; how to raise a concern — and that saying no is an answer, not a problem. The phone's dialogs appear only
 * after the officer taps Agree (dutyTracker.askPermissions), never on their own.
 *
 * Every state is drawn: loading, the service switched off, the app too old for the current words, no connection
 * (the answer has to reach the office), an error, and "this phone does not allow it all the time".
 */

import { ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { AppBar, EmptyState, ErrorView, GuardButton, SkeletonLines, Text, c, useFidelityReady } from '@gs/core'
import { POLICY } from '../trackingPolicy'
import { useTrackingConsent } from '../useTrackingConsent'

const leave = () => (router.canGoBack() ? router.back() : router.replace('/(tabs)' as never))

export default function TrackingConsentScreen() {
  const m = useTrackingConsent(leave)
  useFidelityReady(m.status !== 'loading')

  return (
    <View style={s.screen}>
      <AppBar title="Location sharing" left="back" />
      <ScrollView contentContainerStyle={s.body}>
        {m.status === 'loading' ? (
          <View style={s.card}><SkeletonLines tone="dark" lines={8} /></View>
        ) : m.status === 'error' ? (
          <ErrorView tone="dark" message={m.errorMessage ?? 'The location policy could not be loaded.'} onRetry={m.retry} />
        ) : m.status === 'off' ? (
          <EmptyState tone="dark" icon="rh-icon" title="Location sharing is switched off" body="GeminiSecure is not sharing any officer’s location with the control room. Nothing is recorded, and there is nothing to answer." />
        ) : m.status === 'out_of_date' ? (
          <EmptyState tone="dark" icon="rh-icon" title="Update the app to read the new policy" body="The office has changed the location policy since this version of the app. Update GeminiSecure Guard to read the new words before you answer. Until you do, your location is not shared." />
        ) : (
          <>
            <Text face="Poppins-SemiBold" size={17} color="white" role="heading">{POLICY.title}</Text>
            <Text face="Inter-Regular" size={13} lh={1.55} color="text-2" style={{ marginTop: 8 }}>{POLICY.lead}</Text>

            {m.answer ? (
              <View style={[s.card, s.answer]} role="status">
                <Text face="Inter-SemiBold" size={12} lh={1.5} color="white">{m.answer}</Text>
              </View>
            ) : null}

            {/* M22 §1.1: what is actually being recorded right now — never only what was agreed. */}
            {m.recording ? (
              <View style={[s.card, m.recording.warn ? s.warn : s.answer]} role={m.recording.warn ? 'alert' : 'status'}>
                <Text face="Inter-SemiBold" size={12} lh={1.5} color={m.recording.warn ? 'amber-500' : 'white'}>{m.recording.text}</Text>
              </View>
            ) : null}

            {m.sections.map((section) => (
              <View key={section.heading} style={s.card}>
                <Text face="Inter-Bold" size={12.5} color="white" role="heading">{section.heading}</Text>
                <Text face="Inter-Regular" size={12.5} lh={1.55} color="text-2" style={{ marginTop: 6 }}>{section.body}</Text>
              </View>
            ))}

            {m.permissionProblem ? (
              <View style={[s.card, s.warn]} role="alert">
                <Text face="Inter-SemiBold" size={12} lh={1.5} color="amber-500">{m.permissionProblem}</Text>
                {m.canOpenSettings ? <GuardButton variant="outline" label="Open the phone’s settings" onPress={m.openSettings} style={{ marginTop: 12 }} /> : null}
              </View>
            ) : null}

            {m.problem ? (
              <Text face="Inter-SemiBold" size={12} lh={1.5} color="red-500" role="alert" style={{ marginTop: 14 }}>{m.problem}</Text>
            ) : null}

            <GuardButton
              label={POLICY.agree}
              onPress={m.agree}
              loading={m.busy}
              disabled={!m.online}
              disabledReason="No connection. Your answer has to reach the office: connect, then answer."
              style={{ marginTop: 18 }}
            />
            <GuardButton
              variant="outline"
              label={POLICY.refuse}
              onPress={m.refuse}
              disabled={!m.online || m.busy}
              disabledReason="No connection. Your answer has to reach the office: connect, then answer."
              style={{ marginTop: 10 }}
            />
            {!m.online ? (
              <Text face="Inter-Regular" size={11.5} lh={1.5} color="text-3" style={{ marginTop: 10 }}>{'No connection. Nothing is shared until the office has your answer.'}</Text>
            ) : null}
          </>
        )}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  body: { paddingHorizontal: 18, paddingTop: 14, paddingBottom: 40 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 16, marginTop: 10 },
  answer: { borderColor: c('navy-500') },
  warn: { borderColor: c('amber-500') },
})
