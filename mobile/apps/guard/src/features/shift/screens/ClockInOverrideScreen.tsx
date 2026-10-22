/**
 * Supervisor override at clock-in. NO BOARD DRAWS THIS SCREEN — it is built from the nearest
 * board's own components: guard-app-39's words and notice cards, board 28's input field, board 05's duress
 * shield. Recorded as a decision.
 *
 * It opens only where the ordinary start has closed: the phone has no fingerprint or face set up, three checks
 * in a row did not match, or — M12 §1.3 — the phone places the guard outside the rostered post's geofence or
 * cannot establish where they are. It is the ONLY way on from there, and it is always offered — a guard is
 * never stranded.
 *
 * WHAT THIS SCREEN DOES NOT DO. It does not hide where the phone is: outside, the distance is said here, sent,
 * and reported with the override. It does not hide a mocked location: that is said here, before the PIN is
 * typed, and the server records and flags it. It does not queue: with no signal it says so plainly and saves
 * nothing.
 *
 * The duress shield is on every one of these states. A guard who cannot start a shift is still a guard at
 * a gate.
 */

import { RefreshControl, ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { AppBar, EmptyState, ErrorView, GuardButton, Icon, SkeletonLines, Text, a, c, type, useFidelityReady } from '@gs/core'
import { DuressShield } from '../components/DuressShield'
import { HEADING, NO_SUPERVISOR, OFFLINE, PIN_LENGTH, WHAT_HAPPENS, WHY, reasonFrom } from '../override'
import { useOverride } from '../useOverride'

function NoticeCard({ tone, title, body }: { tone: 'red-500' | 'amber-500'; title: string; body: string }) {
  return (
    <View style={[s.noticeCard, { backgroundColor: a(tone, 0.1), borderColor: a(tone, tone === 'red-500' ? 0.3 : 0.32) }]} role="alert">
      <Icon name="qa-tile-3" size={19} color={tone} style={{ marginTop: 1 }} />
      <View style={s.shrink}>
        <Text face="Inter-Bold" size={12.5} color={tone}>{title}</Text>
        <Text face="Inter-Regular" size={11} lh={1.5} color="text-2" style={{ marginTop: 3 }}>{body}</Text>
      </View>
    </View>
  )
}

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

export default function ClockInOverrideScreen() {
  const params = useLocalSearchParams<{ reason?: string }>()
  const m = useOverride(reasonFrom(params.reason), () => router.replace('/(tabs)'))
  useFidelityReady(m.status !== 'loading')

  // What the phone saw, said before the PIN is typed: it is sent with the override and reported with it.
  const where = m.geo.state === 'outside'
    ? `This phone places you ${m.geo.distanceM ?? '?'}m from ${m.geo.fenceName ?? m.postName}${m.geo.accuracyM === null ? '' : `, sure to within ${m.geo.accuracyM}m`}. That distance is recorded with the override.`
    : m.geo.state === 'poor_fix'
      ? `This phone is only sure of your position to within ${m.geo.accuracyM}m. That is recorded with the override.`
      : m.geo.state === 'unavailable' && m.geo.why !== 'offline'
        ? 'No position came from this phone. That is recorded with the override.'
        : null

  return (
    <View style={s.screen}>
      <AppBar title="Supervisor override" />
      <ScrollView
        keyboardShouldPersistTaps="handled"
        contentContainerStyle={s.bodyPad}
        refreshControl={<RefreshControl refreshing={m.geo.state === 'checking' && m.status === 'ready'} onRefresh={m.refresh} tintColor={c('amber-500')} />}
      >
        {m.status === 'loading' ? <SkeletonLines tone="dark" lines={6} style={{ marginTop: 12 }} /> : null}

        {m.status === 'forbidden' ? (
          <View style={s.plainBlock} role="alert">
            <Text face="Inter-Bold" size={13} color="amber-500">{'This handset may not read your shift'}</Text>
            <Text face="Inter-Regular" size={11.5} lh={1.5} color="text-2" style={{ marginTop: 4 }}>
              {m.errorMessage ?? 'Contact your supervisor or the control room.'}
            </Text>
          </View>
        ) : null}

        {m.status === 'error' ? <ErrorView tone="dark" message={m.errorMessage ?? 'Your shift could not be loaded.'} onRetry={m.refresh} /> : null}

        {m.status === 'no_shift' ? (
          <EmptyState
            tone="dark"
            icon="tab-patrol"
            title="No shift to start"
            body="Nothing is rostered for you right now, so there is nothing for a supervisor to vouch for. If you were asked to cover a post, claim it under Open shifts."
            action={{ label: 'See open shifts', onPress: () => router.push('/leave' as never) }}
          />
        ) : null}

        {m.status === 'ready' ? (
          <>
            <Text face="Poppins-SemiBold" size={16} color="white" role="heading">{HEADING[m.reason]}</Text>
            <Text face="Inter-Regular" size={12} lh={1.55} color="text-2" style={{ marginTop: 6 }}>{WHY[m.reason]}</Text>
            {/* "Your supervisor can start this shift with their PIN" is not true of the demonstration
              * account, which has no supervisor — so it is not said to it. */}
            {m.passcodeIdentity ? null : <Text face="Inter-Regular" size={12} lh={1.55} color="text-2" style={{ marginTop: 8 }}>{WHAT_HAPPENS}</Text>}

            <View style={s.shiftCard}>
              <Text face="Inter-SemiBold" size={11} color="text-3">{'Shift'}</Text>
              <Text face="Inter-SemiBold" size={13} color="white" style={{ marginTop: 3 }}>{m.where}</Text>
              <Text face="Inter-Regular" size={11.5} color="text-2" style={{ marginTop: 2 }}>{m.when}</Text>
            </View>

            {/*
              * THE DEMONSTRATION ACCOUNT IS SHOWN NO PIN FORM AT ALL.
              *
              * `passcodeIdentity` is the server's word and nobody else's: it is set for the app-store
              * reviewer's account and for no real guard. That account has no supervisor anywhere who
              * could enter a PIN for it, so the two fields below would be a control that could only
              * ever fail — on the one screen standing between a reviewer and the product. Instead the
              * screen says so in a sentence and offers the way that does work.
              */}
            {m.passcodeIdentity ? (
              <>
                <NoticeCard tone="amber-500" title="A supervisor’s PIN cannot start this shift" body={NO_SUPERVISOR} />
                <GuardButton label="Back to clock in" onPress={() => router.replace('/clock-in' as never)} style={{ marginTop: 16 }} />
              </>
            ) : (
              <>
                {where ? <NoticeCard tone="amber-500" title="Where this phone is" body={where} /> : null}
                {where ? <GuardButton variant="outline" label="Check my position again" onPress={m.recheck} style={{ marginTop: 12 }} /> : null}

                {m.mocked ? (
                  <NoticeCard
                    tone="amber-500"
                    title="Location integrity check failed"
                    body="This device's location settings appear altered. The shift can still be started, and it will be flagged for review along with the override."
                  />
                ) : null}

                {m.online ? null : <NoticeCard tone="amber-500" title="No signal" body={OFFLINE} />}

                {m.alreadyStarted ? (
                  <>
                    <NoticeCard tone="amber-500" title="This shift has already started" body={m.problem ?? 'Nothing was recorded against a supervisor.'} />
                    <GuardButton label="Go to my shift" onPress={() => router.replace('/(tabs)')} style={{ marginTop: 16 }} />
                  </>
                ) : (
                  <>
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
                        onSubmitEditing={m.canSubmit ? m.submit : undefined}
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
                        aria-label="Supervisor's override PIN"
                        style={[s.input, s.pinInput, type('Inter-Bold', 20), { color: c('white') }]}
                      />
                    </Field>

                    {m.problem ? (
                      <View style={s.problem} role="alert">
                        <Icon name="qa-tile-3" size={16} color="red-500" style={{ marginTop: 1 }} />
                        <View style={s.shrink}>
                          <Text face="Inter-Regular" size={11.5} lh={1.5} color="red-500">{m.problem}</Text>
                        </View>
                      </View>
                    ) : null}

                    {m.canSubmit ? (
                      <GuardButton label="Start my shift" onPress={m.submit} loading={m.busy} style={{ marginTop: 18 }} />
                    ) : (
                      <GuardButton
                        label="Start my shift"
                        onPress={m.submit}
                        disabled
                        disabledReason={m.blockedReason ?? 'Starting your shift…'}
                        style={{ marginTop: 18 }}
                      />
                    )}

                    <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center" style={{ marginTop: 12 }}>
                      {'Every override is recorded against the supervisor who gave the PIN, flags this shift, and is reported to the Head of Security.'}
                    </Text>
                  </>
                )}
              </>
            )}
          </>
        ) : null}

        <DuressShield />
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: c('surface-900') },
  bodyPad: { paddingTop: 16, paddingBottom: 28, paddingHorizontal: 20 },
  shrink: { flexShrink: 1 },
  plainBlock: { marginTop: 10 },
  shiftCard: { backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), padding: 13, marginTop: 14 },
  // Board guard-app-38's notice card, reused: the nearest board that draws a warning on this flow.
  noticeCard: { flexDirection: 'row', gap: 11, padding: 13, borderRadius: 14, borderWidth: 1, marginTop: 14 },
  // Board guard-app-28's input field.
  input: { height: 50, backgroundColor: c('surface-700'), borderRadius: 12, borderWidth: 1, borderColor: c('border-600'), paddingHorizontal: 13 },
  pinInput: { textAlign: 'center', letterSpacing: 8 },
  problem: { flexDirection: 'row', gap: 10, padding: 12, borderRadius: 12, borderWidth: 1, marginTop: 14, backgroundColor: a('red-500', 0.1), borderColor: a('red-500', 0.3) },
})
