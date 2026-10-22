/**
 * /enrol — the one-time enrolment code. No board draws this screen; it is assembled from
 * the boards' own parts: the onboarding backdrop and header (boards 29/17/30), the code
 * field of board 32 (Manual Pass Code) and the dashed rings of board 17.
 *
 * The code is letters AND digits ("4F7K-9QXM"), so the phone's keyboard is used rather
 * than board 32's numeric pad.
 */

import { useRef } from 'react'
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { router } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Gradient, GuardButton, Icon, Pressable, Text, a, belowStatusBar, c, isFidelity, sh, useFidelityReady, type GradientSpec } from '@gs/core'
import { GuardLegalLinks } from '@/features/legal/GuardLegalLinks'
import { useEnrolCode } from '../useEnrolCode'

const BACKDROP: GradientSpec = { angle: 160, stops: [{ token: 'navy-800', at: 0 }, { token: 'surface-900', at: 0.78 }] }

export default function EnrolCodeScreen() {
  const insets = useSafeAreaInsets()
  const input = useRef<TextInput>(null)
  const m = useEnrolCode(() => router.replace('/onboarding/1' as never))
  useFidelityReady(true)

  const waiting = m.phase.state === 'waiting'
  const ended = m.phase.state === 'denied' || m.phase.state === 'lost'

  return (
    <View style={s.screen}>
      <Gradient spec={BACKDROP} style={s.fill}>
        <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
          <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingTop: insets.top + belowStatusBar(6), paddingHorizontal: 20, paddingBottom: 24 }}>
            <Text face="Inter-Bold" size={10.5} ls={0.4} color="text-3" style={{ marginTop: 22 }}>
              {'GEMINISECURE · GUARD'}
            </Text>
            <Text face="Poppins-SemiBold" size={19} color="white" role="heading" style={{ marginTop: 5 }}>
              {waiting ? 'Waiting for a supervisor' : ended ? 'This phone was not enrolled' : 'Enrol this phone'}
            </Text>

            {m.phase.state === 'entry' || m.phase.state === 'sending' ? (
              <>
                <Text face="Inter-Regular" size={12} lh={1.5} color="text-2" style={{ marginTop: 6 }}>
                  {"Type the code Gemini's office gave you. It links this phone to your profile, and if this phone ever loses its sign-in, the same code sets it up again here."}
                </Text>

                {/* WHICH CASE THIS IS, in one line (M32 F3, §6.3). A guard signed out on a phone that is still theirs
                    never comes here — they sign back in with a fingerprint (the unlock screen). This screen is for a
                    phone that genuinely needs a code: a new one, one whose data was cleared, or one the server took
                    out of service — and then the server's own sentence says when, and by whom. A removed guard
                    cannot get back in tonight, so the emergency numbers go under it rather than after they have hunted
                    for them. Nothing here names what is waiting in the queue: a SILENT duress alert stays silent. */}
                <View style={s.note} role="alert">
                  <Icon name="privacy-note" size={15} color="amber-500" style={{ marginTop: 1 }} />
                  <View style={s.shrink}>
                    <Text face="Inter-SemiBold" size={11} lh={1.5} color="white">
                      {m.caseLine}
                    </Text>
                    {m.removed ? (
                      <Text face="Inter-Regular" size={10.5} lh={1.5} color="amber-500" style={{ marginTop: 6 }}>
                        {'Anything you saved without signal is still on this phone. If something is happening NOW, do not wait for the app: call 119 for the police, or 110 for fire and ambulance. A call needs no data.'}
                      </Text>
                    ) : null}
                  </View>
                </View>

                <Pressable onPress={() => input.current?.focus()} style={[s.codeInput, m.error ? { borderColor: c('red-500') } : null]} accessible={false}>
                  <TextInput
                    ref={input}
                    value={m.code}
                    onChangeText={m.setCode}
                    onSubmitEditing={m.submit}
                    editable={m.phase.state === 'entry'}
                    autoCapitalize="characters"
                    autoCorrect={false}
                    autoComplete="off"
                    spellCheck={false}
                    autoFocus
                    returnKeyType="go"
                    maxLength={9}
                    placeholder="XXXX-XXXX"
                    placeholderTextColor={c('text-3')}
                    selectionColor={c('amber-500')}
                    aria-label="Enrolment code"
                    maxFontSizeMultiplier={1.3}
                    style={s.codeText}
                  />
                </Pressable>
                <Text face="Inter-Regular" size={10.5} color="text-3" align="center">
                  {'Codes are 8 letters and numbers, like 4F7K-9QXM'}
                </Text>

                {m.error ? (
                  <View style={s.errorRow} role="alert">
                    <Icon name="privacy-note" size={15} color="red-500" style={{ marginTop: 1 }} />
                    <View style={s.shrink}>
                      <Text face="Inter-Medium" size={11.5} lh={1.5} color="red-500">
                        {m.error}
                      </Text>
                    </View>
                  </View>
                ) : null}

                {!m.online ? (
                  <View style={s.note}>
                    <Icon name="privacy-note" size={15} color="text-3" style={{ marginTop: 1 }} />
                    <View style={s.shrink}>
                      <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3">
                        {'No connection. Enrolment is the one thing this app cannot do without signal. It happens once, at the office.'}
                      </Text>
                    </View>
                  </View>
                ) : null}

                <GuardButton
                  label="Enrol this phone"
                  onPress={m.submit}
                  loading={m.phase.state === 'sending'}
                  disabled={!m.complete}
                  disabledReason="Type all 8 characters of the code first"
                  style={{ marginTop: 26 }}
                />

                <View style={s.note}>
                  <Icon name="dispute-link" size={15} color="text-3" style={{ marginTop: 1 }} />
                  <View style={s.shrink}>
                    <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3">
                      {"No code, or it has expired? Ask Gemini's office or your supervisor to issue a new one. There is no password to remember."}
                    </Text>
                  </View>
                </View>
                {/* Readable before there is any account on this phone, on the phone itself (M24 §5.2). Not on the board, so not in the measurement. */}
                {isFidelity ? null : <GuardLegalLinks style={{ marginTop: 18 }} />}
              </>
            ) : null}

            {m.phase.state === 'waiting' ? (
              <>
                <Text face="Inter-Regular" size={12} lh={1.5} color="text-2" style={{ marginTop: 6 }}>
                  {`${m.phase.guardName}, your profile at ${m.phase.siteName} is already linked to another phone. A supervisor has to approve moving it to this one.`}
                </Text>
                <View style={s.ringWrap} role="progressbar" aria-label="Waiting for a supervisor to approve this phone">
                  <View style={s.ringOuter} />
                  <View style={s.ringInner} />
                  <View style={s.ringBtn}>
                    <Icon name="privacy-note" size={36} color="amber-500" />
                  </View>
                </View>
                <Text face="Inter-Bold" size={13} color="white" align="center">
                  {'Checking every few seconds'}
                </Text>
                <Text face="Inter-Regular" size={11} lh={1.5} color="text-3" align="center" style={{ marginTop: 3, paddingHorizontal: 20 }}>
                  {'You can close the app. This phone remembers the request. The moment it is approved, your old phone stops working and this one opens.'}
                </Text>
                <GuardButton variant="outline" label="Start over with a new code" onPress={m.startOver} style={{ marginTop: 28 }} />
              </>
            ) : null}

            {m.phase.state === 'denied' || m.phase.state === 'lost' ? (
              <>
                <Text face="Inter-Regular" size={12} lh={1.5} color="text-2" style={{ marginTop: 6 }}>
                  {m.phase.state === 'denied' ? 'A supervisor declined the request to move your profile to this phone.' : m.phase.message}
                </Text>
                {m.phase.state === 'denied' && m.phase.note ? (
                  <View style={s.note}>
                    <Icon name="dispute-link" size={15} color="text-3" style={{ marginTop: 1 }} />
                    <View style={s.shrink}>
                      <Text face="Inter-SemiBold" size={10.5} color="text-3">
                        {"SUPERVISOR'S NOTE"}
                      </Text>
                      <Text face="Inter-Regular" size={11.5} lh={1.5} color="text-2" style={{ marginTop: 2 }}>
                        {m.phase.note}
                      </Text>
                    </View>
                  </View>
                ) : null}
                <GuardButton label="Enter a new code" onPress={m.startOver} style={{ marginTop: 26 }} />
              </>
            ) : null}
          </ScrollView>
        </KeyboardAvoidingView>
      </Gradient>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1, width: '100%' },
  shrink: { flexShrink: 1 },
  // board 32 · .code-input
  codeInput: { height: 64, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('amber-500'), boxShadow: sh([{ x: 0, y: 0, blur: 0, spread: 3, token: 'amber-500', opacity: 0.15 }]), marginTop: 26, marginBottom: 8 },
  codeText: { flex: 1, height: 64, textAlign: 'center', fontFamily: 'Inter-Bold', fontSize: 22, letterSpacing: 4, color: c('white'), padding: 0 },
  errorRow: { flexDirection: 'row', gap: 9, marginTop: 14 },
  // boards 29/17 · .hr-note / .privacy-note
  note: { flexDirection: 'row', backgroundColor: a('white', 0.05), borderRadius: 12, paddingHorizontal: 13, paddingVertical: 12, gap: 9, marginTop: 18 },
  // board 17 · .eb-ring-wrap
  ringWrap: { width: 108, height: 108, alignSelf: 'center', flexDirection: 'row', alignItems: 'center', justifyContent: 'center', marginTop: 34, marginBottom: 16 },
  ringOuter: { position: 'absolute', width: 126, height: 126, borderStyle: 'dashed', opacity: 0.15, borderRadius: 63, borderWidth: 1, borderColor: c('amber-500') },
  ringInner: { position: 'absolute', width: 108, height: 108, borderStyle: 'dashed', opacity: 0.3, borderRadius: 54, borderWidth: 1, borderColor: c('amber-500') },
  ringBtn: { width: 80, height: 80, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), zIndex: 2, borderRadius: 40, borderWidth: 1, borderColor: c('border-600') },
})
