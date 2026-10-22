/**
 * /unlock — signing back in on a phone that is still this guard's (M32 F3). NO BOARD DRAWS THIS SCREEN: it is built
 * from the enrolment screen's backdrop, header and dashed ring (boards 29/17/30), and the clock-in override's fields
 * (board 28) for the supervisor's PIN.
 *
 * A fingerprint or face, asked by the phone — never a code, never the office. When the phone cannot match one (three
 * misses, or nothing set up), a supervisor's PIN does it instead, checked by the server and recorded against them.
 * Signing in needs signal, and the screen says so. A guard who cannot get in now is told the emergency numbers, which
 * need no app and no data.
 */

import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Gradient, GuardButton, Icon, Pressable, Text, a, belowStatusBar, c, type, useFidelityReady, type GradientSpec } from '@gs/core'
import { PIN_LENGTH } from '@/features/shift/override'
import { useUnlock } from '../useUnlock'

const BACKDROP: GradientSpec = { angle: 160, stops: [{ token: 'navy-800', at: 0 }, { token: 'surface-900', at: 0.78 }] }

export const EMERGENCY = 'If something is happening NOW, do not wait for the app: call 119 for the police, or 110 for fire and ambulance. A call needs no data.'

function Field({ label, hint, error, children }: { label: string; hint: string; error?: string; children: React.ReactNode }) {
  return (
    <View style={{ marginTop: 14 }}>
      <Text face="Inter-SemiBold" size={11.5} color="text-2">{label}</Text>
      <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginTop: 2, marginBottom: 7 }}>{hint}</Text>
      {children}
      {error ? <Text face="Inter-SemiBold" size={10.5} lh={1.5} color="red-500" role="alert" style={{ marginTop: 6 }}>{error}</Text> : null}
    </View>
  )
}

export default function UnlockScreen() {
  const insets = useSafeAreaInsets()
  const m = useUnlock()
  useFidelityReady(true)

  const sensor = m.passcode ? "this phone's passcode" : 'your fingerprint or face'
  const pinWhy =
    m.biometrics === 'none'
      ? "This phone has no fingerprint or face set up, so a supervisor's PIN signs you back in."
      : m.attemptsLeft === 0
        ? "This phone could not match your fingerprint or face after 3 tries, so a supervisor's PIN signs you back in."
        : "A supervisor's PIN signs you back in instead of your fingerprint."

  return (
    <View style={s.screen}>
      <Gradient spec={BACKDROP} style={s.fill}>
        <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
          <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingTop: insets.top + belowStatusBar(6), paddingHorizontal: 20, paddingBottom: 24 }}>
            <Text face="Inter-Bold" size={10.5} ls={0.4} color="text-3" style={{ marginTop: 22 }}>
              {'GEMINISECURE · GUARD'}
            </Text>
            <Text face="Poppins-SemiBold" size={19} color="white" role="heading" style={{ marginTop: 5 }}>
              {'Sign back in'}
            </Text>
            <Text face="Inter-Regular" size={12} lh={1.5} color="text-2" style={{ marginTop: 6 }}>
              {m.why}
            </Text>

            {m.who ? (
              <View style={s.whoCard}>
                <Text face="Inter-SemiBold" size={13} color="white">{m.who.name}</Text>
                <Text face="Inter-Regular" size={11} color="text-3" style={{ marginTop: 2 }}>
                  {[m.who.reference, m.who.siteName].filter(Boolean).join(' · ')}
                </Text>
              </View>
            ) : null}

            {m.notActive ? (
              <View style={[s.notice, { backgroundColor: a('amber-500', 0.1), borderColor: a('amber-500', 0.32) }]} role="alert">
                <Icon name="qa-tile-3" size={19} color="amber-500" style={{ marginTop: 1 }} />
                <View style={s.shrink}>
                  <Text face="Inter-Regular" size={11.5} lh={1.5} color="text-2">{m.notActive}</Text>
                </View>
              </View>
            ) : m.pinOpen ? (
              <>
                <Text face="Inter-Regular" size={12} lh={1.5} color="text-2" style={{ marginTop: 18 }}>{pinWhy}</Text>
                <Field
                  label="Supervisor's sign-in address"
                  hint="The email address your supervisor signs in to the office console with. They type this and their PIN themselves."
                  error={m.fieldErrors.supervisor}
                >
                  <TextInput
                    value={m.fields.supervisor}
                    onChangeText={m.setSupervisor}
                    autoCapitalize="none"
                    autoCorrect={false}
                    autoComplete="off"
                    spellCheck={false}
                    inputMode="email"
                    maxLength={190}
                    placeholder="e.g. d.brown@gmail.com"
                    placeholderTextColor={c('text-3')}
                    selectionColor={c('amber-500')}
                    keyboardAppearance="dark"
                    maxFontSizeMultiplier={1.3}
                    aria-label="Supervisor's sign-in address"
                    style={[s.input, type('Inter-Regular', 13), { color: c('white') }]}
                  />
                </Field>
                <Field
                  label={`Supervisor's ${PIN_LENGTH}-digit PIN`}
                  hint="Hand the phone to your supervisor. The PIN is sent once to be checked and is never saved on this phone."
                  error={m.fieldErrors.pin}
                >
                  <TextInput
                    value={m.fields.pin}
                    onChangeText={m.setPin}
                    onSubmitEditing={m.vouch}
                    secureTextEntry
                    autoCapitalize="none"
                    autoCorrect={false}
                    autoComplete="off"
                    spellCheck={false}
                    inputMode="numeric"
                    keyboardType="number-pad"
                    maxLength={PIN_LENGTH}
                    textContentType="oneTimeCode"
                    placeholder={'•'.repeat(PIN_LENGTH)}
                    placeholderTextColor={c('text-3')}
                    selectionColor={c('amber-500')}
                    keyboardAppearance="dark"
                    maxFontSizeMultiplier={1.3}
                    aria-label="Supervisor's PIN"
                    style={[s.input, s.pinInput, type('Inter-Bold', 20), { color: c('white') }]}
                  />
                </Field>
                <GuardButton label="Sign back in" onPress={m.vouch} loading={m.busy} style={{ marginTop: 18 }} />
                <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center" style={{ marginTop: 12 }}>
                  {"The PIN is checked by the office's server and recorded against the supervisor who gave it."}
                </Text>
              </>
            ) : (
              <>
                <Pressable onPress={m.unlock} role="button" aria-label={`Sign in with ${sensor}`} style={s.ringWrap}>
                  <View style={s.ringOuter} />
                  <View style={s.ringInner} />
                  <View style={s.ringBtn}>
                    <Icon name="bio-btn" size={36} color="amber-500" />
                  </View>
                </Pressable>
                <GuardButton label={m.passcode ? 'Sign in with the passcode' : 'Sign in with your fingerprint'} onPress={m.unlock} loading={m.busy} style={{ marginTop: 4 }} />
                {m.attemptsLeft < 3 ? (
                  <Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-500" align="center" role="alert" style={{ marginTop: 10 }}>
                    {`That did not match. ${m.attemptsLeft} ${m.attemptsLeft === 1 ? 'try' : 'tries'} left, then a supervisor's PIN is asked for.`}
                  </Text>
                ) : null}
                {m.passcode ? null : <GuardButton variant="outline" label="Use a supervisor's PIN instead" onPress={m.openPin} style={{ marginTop: 12 }} />}
              </>
            )}

            {m.problem ? (
              <View style={s.problem} role="alert">
                <Icon name="privacy-note" size={15} color="red-500" style={{ marginTop: 1 }} />
                <View style={s.shrink}>
                  <Text face="Inter-Medium" size={11.5} lh={1.5} color="red-500">{m.problem}</Text>
                </View>
              </View>
            ) : null}

            {!m.online ? (
              <View style={s.note}>
                <Icon name="privacy-note" size={15} color="text-3" style={{ marginTop: 1 }} />
                <View style={s.shrink}>
                  <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3">
                    {"No connection. Signing back in needs signal: the office's server checks that this is your phone. Anything you saved without signal is still on it."}
                  </Text>
                </View>
              </View>
            ) : null}

            <View style={s.note}>
              <Icon name="privacy-note" size={15} color="amber-500" style={{ marginTop: 1 }} />
              <View style={s.shrink}>
                <Text face="Inter-SemiBold" size={10.5} lh={1.5} color="amber-500">{EMERGENCY}</Text>
              </View>
            </View>

            <Pressable onPress={m.notMine} role="button" hitSlop={8} style={s.notMine}>
              <Text face="Inter-SemiBold" size={11.5} color="text-3" align="center">{'Not your phone? Set it up for yourself with a new code'}</Text>
            </Pressable>
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
  whoCard: { backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), padding: 13, marginTop: 16 },
  // the clock-in override's notice card and fields (boards guard-app-38 and -28)
  notice: { flexDirection: 'row', gap: 11, padding: 13, borderRadius: 14, borderWidth: 1, marginTop: 18 },
  input: { height: 50, backgroundColor: c('surface-700'), borderRadius: 12, borderWidth: 1, borderColor: c('border-600'), paddingHorizontal: 13 },
  pinInput: { textAlign: 'center', letterSpacing: 8 },
  problem: { flexDirection: 'row', gap: 9, padding: 12, borderRadius: 12, borderWidth: 1, marginTop: 14, backgroundColor: a('red-500', 0.1), borderColor: a('red-500', 0.3) },
  // boards 29/17 · .hr-note / .privacy-note
  note: { flexDirection: 'row', backgroundColor: a('white', 0.05), borderRadius: 12, paddingHorizontal: 13, paddingVertical: 12, gap: 9, marginTop: 18 },
  // board 17 · .eb-ring-wrap
  ringWrap: { width: 108, height: 108, alignSelf: 'center', flexDirection: 'row', alignItems: 'center', justifyContent: 'center', marginTop: 30, marginBottom: 18 },
  ringOuter: { position: 'absolute', width: 126, height: 126, borderStyle: 'dashed', opacity: 0.15, borderRadius: 63, borderWidth: 1, borderColor: c('amber-500') },
  ringInner: { position: 'absolute', width: 108, height: 108, borderStyle: 'dashed', opacity: 0.3, borderRadius: 54, borderWidth: 1, borderColor: c('amber-500') },
  ringBtn: { width: 80, height: 80, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), zIndex: 2, borderRadius: 40, borderWidth: 1, borderColor: c('border-600') },
  // 48 dp at least, as every way off a first screen (M32 F10).
  notMine: { minHeight: 48, justifyContent: 'center', marginTop: 16 },
})
