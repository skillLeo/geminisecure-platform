/**
 * guard-app-29 · -17 · -30 — Onboarding steps 1–3 (boards 08 Onboarding and 05 Enrolment), and
 * a fourth step the boards do not draw: "Before you start work" (alerts and what a position is
 * read for — setupPermissions.ts). One screen, four steps; every style value
 * on the first three is the boards' own, copied from the lifted files, and the fourth is built
 * from those same parts — the ruled card and its icon rows from -30, the note block from -29,
 * the section heading from -36 and the kit's primary and outline buttons.
 *
 * The boards measure from the physical screen edge: 60 from the top is the 54px status bar
 * plus 6, and the button sits 30 above the bottom edge (the 26px home indicator plus 4).
 * A board-fidelity run still draws three steps, so the boards are measured unchanged.
 */

import { ScrollView, StyleSheet, View } from 'react-native'
import { Redirect, router, useLocalSearchParams } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Gradient, GuardButton, Icon, Pressable, Text, a, aboveGestureBar, belowStatusBar, c, enterApp, isFidelity, useFidelityReady, type GradientSpec } from '@gs/core'
import { useAppSelector } from '@/store'
import { ALERTS } from '../setupPermissions'
import { MAX_IDENTITY_ATTEMPTS, useOnboarding, type Identity, type OnboardingModel } from '../useOnboarding'

const BACKDROP: GradientSpec = { angle: 160, stops: [{ token: 'navy-800', at: 0 }, { token: 'surface-900', at: 0.78 }] }

const HEAD = {
  1: { title: (m: OnboardingModel) => (m.firstName ? `Welcome, ${m.firstName}` : 'Welcome'), sub: "Gemini's office has already set up your profile. Take a moment to confirm everything is correct before you continue." },
  2: { title: () => 'Set up this device', sub: "Your profile was created by Gemini's office. Confirm your details, then enrol your fingerprint or face to finish setup." },
  3: { title: () => 'Uniform & equipment', sub: "Confirm what you've been issued at the office today." },
} as const

const IDENTITY_COPY: Record<Identity, { title: string; sub: string }> = {
  idle: { title: 'Tap to enrol your fingerprint or face', sub: "Used to verify it's really you at every clock-in" },
  failed: { title: "Didn't match: tap to try again", sub: 'Make sure your finger fully covers the sensor, or look straight at the camera' },
  confirmed: { title: 'Confirmed: that is all clock-in will ask', sub: 'You will be asked the same way at the start of every shift' },
  unavailable: { title: 'No fingerprint or face is set up on this phone', sub: "You can continue. Add one in the phone's Settings when you can, until then the office sees your clock-ins as not confirmed by the phone." },
  not_confirmed: { title: 'Not confirmed', sub: `That did not match ${MAX_IDENTITY_ATTEMPTS} times. You can continue: clock-in will ask again, and your supervisor can help if it keeps failing.` },
}

function parseStep(raw: string | string[] | undefined): 1 | 2 | 3 | 4 {
  const n = Number(Array.isArray(raw) ? raw[0] : raw)
  return n === 2 ? 2 : n === 3 ? 3 : n === 4 ? 4 : 1
}

export default function OnboardingScreen() {
  const insets = useSafeAreaInsets()
  const params = useLocalSearchParams<{ step?: string }>()
  const status = useAppSelector((s) => s.session.status)
  // Setup done: into the app with the four steps CLEARED from the history (enterApp). Clock In's Home button went back
  // into step 3 (found on the emulator, 26 Sep).
  const m = useOnboarding(parseStep(params.step), (to) => (to === 'done' ? enterApp('/clock-in') : router.push(`/onboarding/${to}` as never)))
  useFidelityReady(true)

  if (!isFidelity && status === 'signedOut') return <Redirect href={'/enrol' as never} />

  // Step 4 has no board, so its words come from the step itself rather than from this table.
  const head = m.step === 4 ? { title: m.permissions.title, sub: m.permissions.sub } : { title: HEAD[m.step].title(m), sub: HEAD[m.step].sub }
  const ok = m.identity === 'confirmed'
  const bad = m.identity === 'failed' || m.identity === 'not_confirmed'

  return (
    <View style={s.screen}>
      <Gradient spec={BACKDROP} style={s.enrolScreen}>
        {/* Room for the button bar — and on step 4 for the second button under it. */}
        <ScrollView style={s.fill} contentContainerStyle={{ paddingBottom: 52 + (m.step === 4 ? 58 : 0) + aboveGestureBar(30, insets.bottom) + 20 }}>
          <View style={[s.enrolTop, { paddingTop: insets.top + belowStatusBar(6) }]}>
            <View style={s.stepRow} role="progressbar" aria-label={`Step ${m.step} of ${m.steps}`}>
              {Array.from({ length: m.steps }, (_, i) => i + 1).map((n) => (
                <View key={n} style={n < m.step ? s.stepDone : n === m.step ? s.stepNow : s.stepTodo} />
              ))}
            </View>
            <Text face="Inter-Bold" size={10.5} ls={0.4} color="text-3" style={{ marginTop: 18 }}>{`STEP ${m.step} OF ${m.steps}`}</Text>
            <Text face="Poppins-SemiBold" size={19} color="white" role="heading" style={{ marginTop: 5 }}>{head.title}</Text>
            <Text face="Inter-Regular" size={12} lh={1.5} color="text-2" style={{ marginTop: 6 }}>{head.sub}</Text>
          </View>

          {m.step === 1 ? (
            <>
              <View style={[s.idCard, { marginTop: 20 }]}>
                {m.rows.map((row, i) => (
                  <View key={row.label} style={i === m.rows.length - 1 ? s.idRowLast : s.idRowRuled}>
                    <View style={s.shrink}><Text face="Inter-SemiBold" size={11.5} color="text-3">{row.label}</Text></View>
                    <View style={s.shrink}><Text face="Inter-Bold" size={12.5} color="white">{row.value}</Text></View>
                  </View>
                ))}
              </View>
              <View style={[s.note, { marginTop: 16 }]}>
                <Icon name="dispute-link" size={15} color="text-3" style={s.noteIcon} />
                <View style={s.shrink}>
                  <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3">{"Something wrong here? Don't edit it yourself: contact Gemini's HR team so your records and payslip stay accurate."}</Text>
                </View>
              </View>
              {m.detailsPartial ? (
                <View style={[s.note, { marginTop: 10 }]}>
                  <Icon name="privacy-note" size={15} color="text-3" style={s.noteIcon} />
                  <View style={s.shrink}>
                    <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3">{"Your date of birth, TRN, NIS number, salary account and emergency contact are kept by Gemini's HR office. They are not shown in this app."}</Text>
                  </View>
                </View>
              ) : null}
            </>
          ) : null}

          {m.step === 2 ? (
            <>
              <View style={[s.idCard, { marginTop: 20 }]}>
                {m.rows.map((row) => (
                  <View key={row.label} style={s.idRowPlain}>
                    <View style={s.shrink}><Text face="Inter-SemiBold" size={11.5} color="text-3">{row.label}</Text></View>
                    <View style={s.shrink}><Text face="Inter-Bold" size={12.5} color="white">{row.value}</Text></View>
                  </View>
                ))}
                <View style={s.deviceLink}>
                  <Icon name="check" size={16} color="green-400" style={s.deviceLinkIcon} />
                  <View style={s.shrink}><Text face="Inter-SemiBold" size={11.5} color="green-400">{`This ${m.deviceName} is now linked to your profile`}</Text></View>
                </View>
              </View>

              <View style={s.enrolBio}>
                <Pressable
                  onPress={m.confirmIdentity}
                  disabled={m.identityBusy || ok || m.identity === 'unavailable' || m.identity === 'not_confirmed'}
                  role="button"
                  aria-label={IDENTITY_COPY[m.identity].title}
                  aria-busy={m.identityBusy}
                  accessibilityHint={ok ? 'Already confirmed' : m.identity === 'unavailable' ? 'This phone has no fingerprint or face set up' : m.identity === 'not_confirmed' ? 'No attempts left; continue and clock-in will ask again' : undefined}
                  style={s.ebRingWrap}
                >
                  <View style={s.eRing} />
                  <View style={s.eRing2} />
                  <View style={[s.ebBtn, bad ? { borderColor: c('red-500') } : ok ? { borderColor: c('green-400') } : null]}>
                    <Icon name={ok ? 'check' : 'bio-btn'} size={36} color={bad ? 'red-500' : ok ? 'green-400' : 'amber-500'} style={s.ebBtnIcon} />
                  </View>
                </Pressable>
                <Text face="Inter-Bold" size={13} color={bad ? 'red-500' : 'white'} align="center">{IDENTITY_COPY[m.identity].title}</Text>
                <View style={s.bs}>
                  <Text face="Inter-Regular" size={11} lh={m.identity === 'idle' ? undefined : 1.5} color="text-3" align="center">{IDENTITY_COPY[m.identity].sub}</Text>
                </View>
              </View>

              <View style={[s.note, { marginTop: 18 }]}>
                <Icon name="privacy-note" size={15} color="text-3" style={s.noteIcon} />
                <View style={s.shrink}>
                  <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3">{'Your fingerprint or face image stays on this device only. GeminiSecure never receives or stores the image itself: only a signed confirmation that it matched.'}</Text>
                </View>
              </View>
            </>
          ) : null}

          {m.step === 3 ? (
            <>
              <View style={[s.idCard, { marginTop: 16 }]}>
                {m.kit === null ? (
                  <View style={s.checkItem}>
                    <View style={s.ciIcon}><Icon name="eq-item-4" size={17} color="amber-500" style={s.ciIconSvg} /></View>
                    <View style={s.ciTxt}>
                      <Text face="Inter-SemiBold" size={12.5} color="white">{'Your kit is on the office issue sheet'}</Text>
                      <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginTop: 2 }}>{'The list of what you were issued is not shown in the app yet. Check your uniform, radio, torch and ID card against the sheet you signed at the office.'}</Text>
                    </View>
                  </View>
                ) : (
                  m.kit.map((item, i) => (
                    <View key={item.name}>
                      {i > 0 ? <View style={s.setDiv} /> : null}
                      <View style={s.checkItem}>
                        <View style={s.ciIcon}><Icon name={item.icon} size={17} color="amber-500" style={s.ciIconSvg} /></View>
                        <View style={s.ciTxt}>
                          <Text face="Inter-SemiBold" size={12.5} color="white">{item.name}</Text>
                          {item.detail ? <Text face="Inter-Regular" size={10.5} color="text-3" style={{ marginTop: 1 }}>{item.detail}</Text> : null}
                        </View>
                        <View style={s.issuedTag}><Text face="Inter-Bold" size={9} color="green-400" numberOfLines={1}>{'Issued'}</Text></View>
                      </View>
                    </View>
                  ))
                )}
              </View>

              <Pressable onPress={m.toggleAcknowledged} role="checkbox" aria-checked={m.acknowledged} hitSlop={12} style={s.ppeAck}>
                <View style={m.acknowledged ? s.cboxOn : s.cboxOff}>
                  {m.acknowledged ? <Icon name="check" size={12} color="g-navy-900" style={s.cboxSvg} /> : null}
                </View>
                <View style={s.shrink}>
                  <Text face="Inter-Regular" size={11.5} lh={1.5} color="text-2">{"I've received the equipment above and understand how to use it, including any PPE for this site."}</Text>
                </View>
              </Pressable>

              {m.kit === null ? (
                <View style={[s.note, { marginTop: 18 }]}>
                  <Icon name="privacy-note" size={15} color="text-3" style={s.noteIcon} />
                  <View style={s.shrink}>
                    <Text face="Inter-SemiBold" size={10.5} lh={1.5} color="text-2">{'No signal? Keep working.'}</Text>
                    <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3">{'Clock-ins, scans, patrol points and reports are saved on this phone first and sent by themselves when signal returns. Until then they show as "Pending sync". Nothing is lost, and nothing needs sending twice.'}</Text>
                  </View>
                </View>
              ) : null}
            </>
          ) : null}

          {m.step === 4 ? (
            <>
              <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={s.sectionHead}>{m.permissions.alerts.heading}</Text>
              <View style={[s.idCard, { marginTop: 8 }]}>
                {ALERTS.map((row, i) => (
                  <View key={row.title}>
                    {i > 0 ? <View style={s.setDiv} /> : null}
                    <View style={s.checkItem}>
                      <View style={s.ciIcon}><Icon name={row.icon} size={17} color="amber-500" style={s.ciIconSvg} /></View>
                      <View style={s.ciTxt}>
                        <Text face="Inter-SemiBold" size={12.5} color="white">{row.title}</Text>
                        <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginTop: 1 }}>{row.detail}</Text>
                      </View>
                    </View>
                  </View>
                ))}
              </View>

              {m.permissions.alerts.note ? (
                <View style={[s.note, { marginTop: 12 }]}>
                  <Icon name="privacy-note" size={15} color="text-3" style={s.noteIcon} />
                  <View style={s.shrink}><Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3">{m.permissions.alerts.note}</Text></View>
                </View>
              ) : null}

              {m.permissions.alerts.line ? (
                <View style={[s.note, { marginTop: 12 }]}>
                  <Icon name={m.permissions.alerts.line.tone === 'green-400' ? 'check' : 'qa-tile-3'} size={15} color={m.permissions.alerts.line.tone} style={s.noteIcon} />
                  <View style={s.shrink}><Text face="Inter-Regular" size={10.5} lh={1.5} color={m.permissions.alerts.line.tone === 'green-400' ? 'green-400' : 'text-2'}>{m.permissions.alerts.line.text}</Text></View>
                </View>
              ) : null}

              <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={s.sectionHead}>{m.permissions.location.heading}</Text>
              <View style={[s.idCard, { marginTop: 8 }]}>
                <View style={s.checkItem}>
                  <View style={s.ciIcon}><Icon name="loc-row" size={17} color="amber-500" style={s.ciIconSvg} /></View>
                  <View style={s.ciTxt}><Text face="Inter-Regular" size={11.5} lh={1.5} color="text-2">{m.permissions.location.disclosure}</Text></View>
                </View>
              </View>
              <View style={[s.note, { marginTop: 12 }]}>
                <Icon name="privacy-note" size={15} color="text-3" style={s.noteIcon} />
                <View style={s.shrink}><Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3">{m.permissions.location.without}</Text></View>
              </View>
            </>
          ) : null}
        </ScrollView>

        <View style={[s.enrolBottom, { paddingBottom: aboveGestureBar(30, insets.bottom) }]}>
          {m.primaryBlockedReason ? (
            <GuardButton label={m.primaryLabel} onPress={m.next} disabled disabledReason={m.primaryBlockedReason} />
          ) : (
            <GuardButton label={m.primaryLabel} onPress={m.next} loading={m.busy} />
          )}
          {m.step === 4 && m.permissions.secondary && m.secondaryPress ? (
            <GuardButton label={m.permissions.secondary.label} onPress={m.secondaryPress} variant="outline" style={{ marginTop: 10 }} />
          ) : null}
        </View>
      </Gradient>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  enrolScreen: { width: '100%', height: '100%' },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  stepDone: { height: 4, flex: 1, backgroundColor: c('amber-500'), borderRadius: 4 },
  stepNow: { height: 4, flex: 1, backgroundColor: c('amber-500'), opacity: 0.5, borderRadius: 4 },
  stepTodo: { height: 4, flex: 1, backgroundColor: c('border-700'), borderRadius: 4 },
  stepRow: { flexDirection: 'row', gap: 6 },
  enrolTop: { paddingHorizontal: 20 },
  idRowRuled: { flexDirection: 'row', justifyContent: 'space-between', borderBottomWidth: 1, borderBottomColor: c('border-700'), paddingVertical: 7 },
  idRowLast: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 7 },
  idRowPlain: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 6 },
  deviceLinkIcon: { width: 16, height: 16, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  deviceLink: { flexDirection: 'row', alignItems: 'center', paddingTop: 12, borderTopWidth: 1, borderTopColor: c('border-700'), gap: 10, marginTop: 12 },
  idCard: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 15, marginLeft: 20, marginRight: 20 },
  noteIcon: { width: 15, height: 15, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  note: { flexDirection: 'row', backgroundColor: a('white', 0.05), borderRadius: 12, paddingHorizontal: 13, paddingVertical: 12, gap: 9, marginLeft: 20, marginRight: 20 },
  eRing: { position: 'absolute', width: 126, height: 126, borderStyle: 'dashed', opacity: 0.15, borderRadius: 63, borderWidth: 1, borderColor: c('amber-500') },
  eRing2: { position: 'absolute', width: 108, height: 108, borderStyle: 'dashed', opacity: 0.3, borderRadius: 54, borderWidth: 1, borderColor: c('amber-500') },
  ebBtnIcon: { width: 36, height: 36, overflow: 'hidden' },
  ebBtn: { width: 80, height: 80, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), zIndex: 2, borderRadius: 40, borderWidth: 1, borderColor: c('border-600') },
  ebRingWrap: { width: 108, height: 108, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', marginBottom: 16 },
  bs: { paddingHorizontal: 30, marginTop: 3 },
  enrolBio: { flexDirection: 'column', alignItems: 'center', marginTop: 26 },
  ciIconSvg: { width: 17, height: 17, overflow: 'hidden' },
  ciIcon: { width: 36, height: 36, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 10 },
  ciTxt: { flex: 1 },
  issuedTag: { backgroundColor: a('green-400', 0.14), flexShrink: 1, borderRadius: 20, paddingHorizontal: 9, paddingVertical: 4 },
  checkItem: { flexDirection: 'row', alignItems: 'center', paddingVertical: 12, gap: 12 },
  setDiv: { height: 1, backgroundColor: c('border-700') },
  cboxSvg: { width: 12, height: 12, overflow: 'hidden' },
  cboxOn: { width: 19, height: 19, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), borderRadius: 6, borderWidth: 2, borderColor: c('amber-500'), marginTop: 1 },
  cboxOff: { width: 19, height: 19, flexGrow: 0, flexShrink: 0, borderRadius: 6, borderWidth: 2, borderColor: c('border-600'), marginTop: 1 },
  ppeAck: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, marginTop: 16, marginLeft: 20, marginRight: 20 },
  // Step 4's section heading — board guard-app-36's own ("By category", "On this phone"), at this screen's margin.
  sectionHead: { marginTop: 20, marginLeft: 22, marginRight: 22 },
  // OPAQUE (M18 §5): with a see-through footer the step's last paragraph scrolled up behind "Skip for now" and read
  // through it, on the one screen every officer passes. The colour is the gradient's own foot, so nothing shifts.
  enrolBottom: { position: 'absolute', right: 0, bottom: 0, left: 0, paddingHorizontal: 20, paddingTop: 12, backgroundColor: c('surface-900') },
})
