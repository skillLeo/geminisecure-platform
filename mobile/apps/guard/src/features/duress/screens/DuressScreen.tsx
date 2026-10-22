/**
 * guard-app-13 · Duress Alert Active — board 04 Duress Alertness and Orders. The board draws the
 * ACTIVE state; arming, the held state, cancelling and the closed summary use the same parts.
 * Styles are the board's own, copied from the lifted file.
 *
 * This screen is the AUDIBLE path and the place a guard checks a SILENT alert when it is safe to.
 * The silent alert itself is raised from Home's shield (useDuress.ts) and changes nothing here or
 * anywhere until the guard chooses to open this screen.
 *
 * Cancelling a mistake stays IN PLACE — choosing the reason is the second step, on a ten-second countdown — and
 * the reason picker is scrolled into view the moment it opens, so it can never open below the fold.
 */

import { useEffect, useRef, useState } from 'react'
import { Animated, Easing, Linking, ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import * as Haptics from 'expo-haptics'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Gradient, Icon, Pressable, Text, a, belowStatusBar, c, fidelityScreenId, isFidelity, sh, useFidelityReady, useReduceMotionPref, type GradientSpec } from '@gs/core'
import { DRILL_LABEL, DRILL_NOTE } from '@gs/core/safety'
import { duressFixture } from '@/fidelity/duress'
import { CANCEL_REASONS, HOLD_MS, useDuress } from '../useDuress'

const BACKDROP: GradientSpec = { angle: 180, stops: [{ token: 'navy-800', at: 0 }, { token: 'surface-900', at: 1 }] }

// The phone's own dialler: needs no data, and the app never stands between a guard and a call.
const call119 = () => void Linking.openURL('tel:119').catch(() => undefined)

export default function DuressScreen() {
  const insets = useSafeAreaInsets()
  const board = isFidelity && fidelityScreenId() === 'guard-app-13'
  const m = useDuress(board, () => (router.canGoBack() ? router.back() : router.replace('/(tabs)')))
  useFidelityReady(true)

  const phase = board ? 'active' : m.phase
  const [reason, setReason] = useState<string | null>(null)
  const [choosing, setChoosing] = useState(false)

  // A new alert can be raised from here whenever none is live: before the first, and after one closed or did not get through.
  const canRaise = phase === 'arm' || phase === 'closed' || phase === 'failed'
  const progress = useRef(new Animated.Value(0)).current
  const holding = useRef<Animated.CompositeAnimation | null>(null)
  const startHold = () => {
    if (!canRaise) return
    void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium).catch(() => undefined)
    holding.current = Animated.timing(progress, { toValue: 1, duration: HOLD_MS, easing: Easing.linear, useNativeDriver: true })
    holding.current.start(({ finished }) => {
      if (finished) m.fire()
    })
  }
  const endHold = () => {
    holding.current?.stop()
    Animated.timing(progress, { toValue: 0, duration: 160, useNativeDriver: true }).start()
  }
  useEffect(() => () => holding.current?.stop(), [])

  // The reason picker opens under its button, often below the fold on a small handset: it is brought into view.
  const scroll = useRef<ScrollView>(null)
  const still = useReduceMotionPref()
  const showPicker = () => scroll.current?.scrollToEnd({ animated: !still })

  const steps = board ? duressFixture.steps.map((step) => ({ ...step })) : m.steps.filter((step) => step.key !== 'resolved' || step.done).map((step) => ({ title: step.title, detail: step.detail, done: step.done }))
  const title =
    phase === 'arm'
      ? 'Hold to raise a duress alert'
      : phase === 'sending'
        ? 'Sending…'
        : phase === 'queued'
          ? 'Alert held, not yet received'
          : phase === 'failed'
            ? 'Alert not received'
            : phase === 'closed'
              ? m.closedAs === 'false_alarm'
                ? 'Alert cancelled'
                : 'Alert closed'
              : 'Alert sent'

  /** Hold again for a NEW alert — never a tap — once the last one is closed or did not get through. */
  const raiseAgain = (
    <Pressable
      onPressIn={startHold}
      onPressOut={endHold}
      role="button"
      aria-label="Raise a new duress alert"
      accessibilityHint={`Hold for ${HOLD_MS / 1000} seconds to send a new alert. It is never sent by a single tap.`}
      style={[s.againBtn, board ? null : s.ringRed]}
    >
      <Animated.View style={[s.againFill, board ? null : s.againFillRed, { transform: [{ scaleX: progress }] }]} />
      <Text face="Inter-Bold" size={12.5} color={board ? 'amber-500' : 'red-500'}>{'Hold to raise a new alert'}</Text>
    </Pressable>
  )

  return (
    <View style={s.screen}>
      <Gradient spec={BACKDROP} style={s.fill}>
        <ScrollView ref={scroll} contentContainerStyle={{ paddingBottom: Math.max(28, insets.bottom + 12) }}>
          <View style={[s.duressTop, { paddingTop: insets.top + belowStatusBar(6) }]}>
            <Pressable onPress={() => (router.canGoBack() ? router.back() : router.replace('/(tabs)'))} role="button" aria-label={phase === 'active' || phase === 'queued' ? 'Back: the alert stays active' : 'Back'} hitSlop={8} style={s.sideBtn}>
              <Icon name="side-btn-5" size={16} color="white" />
            </Pressable>
            <View style={s.shrink}><Text face="Poppins-SemiBold" size={14.5} color="white" role="heading">{'Duress alert'}</Text></View>
            <View style={s.spacer} />
          </View>

          {/* A DRILL says so before anything else (M10 §1.3). A guard's own press never is one; the server decides. */}
          {!board && m.drill && phase !== 'arm' && phase !== 'sending' ? (
            <View style={s.drill} role="alert">
              <Text face="Inter-Bold" size={14} ls={1.2} color="amber-500" align="center">{DRILL_LABEL}</Text>
              <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-2" align="center" style={{ marginTop: 2 }}>{DRILL_NOTE}</Text>
            </View>
          ) : null}

          {phase === 'arm' || phase === 'sending' ? null : (
            <View style={s.typeChip}>
              <View style={s.typeChipSpan}><Text face="Inter-Bold" size={10} ls={0.4} color="amber-500">{board ? duressFixture.chip : m.chip}</Text></View>
            </View>
          )}

          <View style={s.duressHero}>
            {phase === 'arm' || phase === 'sending' ? (
              <Pressable
                onPressIn={startHold}
                onPressOut={endHold}
                disabled={phase === 'sending'}
                role="button"
                aria-label="Raise a duress alert"
                aria-busy={phase === 'sending'}
                accessibilityHint={`Hold for ${HOLD_MS / 1000} seconds to send. It is never sent by a single tap.`}
                style={s.holdWrap}
              >
                {/* RED, as Home's shield is (the client, 27 Sep, and 6 Oct: "make them red"); the board's amber in the fidelity build. */}
                <View style={[s.holdRingOuter, board ? null : s.ringRed]} />
                <View style={[s.holdRing, board ? null : s.ringRed]} />
                <Animated.View style={[s.holdFill, board ? null : s.fillRed, { opacity: progress.interpolate({ inputRange: [0, 1], outputRange: [0.25, 1] }), transform: [{ scale: progress.interpolate({ inputRange: [0, 1], outputRange: [0.62, 1] }) }] }]} />
                <View style={[s.holdIcon, board ? null : s.fillRed]}><Icon name="d-icon" size={42} color={board ? 'g-navy-900' : 'white'} /></View>
              </Pressable>
            ) : (
              <View style={s.dIconWrap}>
                <View style={[s.dRing, board ? null : s.ringRed]} />
                <View style={[s.dRing2, board ? null : s.ringRed]} />
                <View style={[s.dIcon, board ? null : s.fillRed, phase === 'queued' ? { backgroundColor: c('white') } : null]}><Icon name={phase === 'closed' ? 'check' : 'd-icon'} size={32} color={board || phase === 'queued' ? 'g-navy-900' : 'white'} /></View>
              </View>
            )}
            <Text face="Poppins-SemiBold" size={20} color="white" align="center">{title}</Text>
            <Text face="Inter-Regular" size={11.5} color="text-2" align="center" style={{ marginTop: 4, paddingHorizontal: 24 }}>
              {board ? duressFixture.sentLine : phase === 'arm' ? `Keep holding for ${HOLD_MS / 1000} seconds · release to stop` : phase === 'sending' ? 'Do not close the app' : m.sentLine}
            </Text>
            {/* Where the response stands, in the server's words: "Help is on the way" only once an officer was SENT. */}
            {!board && phase === 'active' && m.headline ? (
              <View style={{ marginTop: 10, paddingHorizontal: 24 }} role="status">
                <Text face="Inter-SemiBold" size={13} color="white" align="center">{m.headline.headline}</Text>
                {m.headline.detail ? <Text face="Inter-Regular" size={10.5} lh={1.45} color="text-3" align="center" style={{ marginTop: 2 }}>{m.headline.detail}</Text> : null}
              </View>
            ) : null}
          </View>

          {phase === 'arm' ? (
            <View style={s.coordCard}>
              <View style={s.shrink}>
                <Text face="Inter-Bold" size={12.5} color="white">{'Dispatch sees it at once'}</Text>
                <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginTop: 3 }}>
                  {'With your name, your post and, when the phone has one, your position. No signal? It is held on this phone and sent the moment any signal returns. You have ten seconds to cancel a mistake.'}
                </Text>
              </View>
            </View>
          ) : null}

          {phase === 'queued' ? (
            <View style={[s.coordCard, { borderColor: c('red-500') }]} role="alert">
              <View style={s.shrink}>
                <Text face="Inter-Bold" size={12.5} color="red-500">{'NOT RECEIVED YET'}</Text>
                {/* Said by WHY it is held: "no connection" only when that is the reason (B126). */}
                <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-2" style={{ marginTop: 3 }}>{m.heldLine}</Text>
              </View>
            </View>
          ) : null}

          {/* It will never be sent: the server said no, or the phone lost it. Said plainly, with a way out (B126). */}
          {phase === 'failed' ? (
            <View style={[s.coordCard, { borderColor: c('red-500') }]} role="alert">
              <View style={s.shrink}>
                <Text face="Inter-Bold" size={12.5} color="red-500">{'NOT RECEIVED'}</Text>
                <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-2" style={{ marginTop: 3 }}>{m.failure}</Text>
                <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginTop: 6 }}>{'In danger now? Use your radio, or call 119. A call needs no data.'}</Text>
                <Pressable onPress={call119} role="button" aria-label="Call 119" style={s.cancelBtn}>
                  <Text face="Inter-Bold" size={12.5} color="red-500">{'Call 119'}</Text>
                </Pressable>
                {raiseAgain}
                <Pressable onPress={m.finish} role="button" aria-label="Done" style={[s.confirmCancel, { backgroundColor: c('surface-700') }]}>
                  <Text face="Inter-Bold" size={12.5} color="white">{'Done'}</Text>
                </Pressable>
              </View>
            </View>
          ) : null}

          {phase === 'active' || phase === 'closed' ? (
            <View style={s.timeline}>
              {steps.map((step, i) => (
                <View key={step.title} style={i === steps.length - 1 ? s.tRowLast : s.tRow}>
                  {i === steps.length - 1 ? null : <View style={s.rail} />}
                  <View style={step.done ? s.tDot : s.tDotOpen}>{step.done ? <Icon name="check" size={12} color="g-navy-900" /> : null}</View>
                  <View style={s.shrink}>
                    <Text face="Inter-SemiBold" size={12.5} color="white">{step.title}</Text>
                    {step.detail ? <Text face="Inter-Regular" size={10.5} color="text-3" style={{ marginTop: 2 }}>{step.detail}</Text> : null}
                  </View>
                </View>
              ))}
            </View>
          ) : null}

          {phase === 'active' && board ? (
            <>
              <View style={s.radar}>
                <View style={s.ring} />
                <View style={s.ring2} />
                <View style={s.pin} />
                <View style={s.glabel}><Text face="Inter-SemiBold" size={9.5} color="text-2">{duressFixture.radarLabel}</Text></View>
              </View>
              <View style={s.coordCard}>
                <View style={s.cAvatar}><Text face="Inter-Bold" size={11} color="white">{duressFixture.coordinator.initials}</Text></View>
                <View style={s.shrink}>
                  <Text face="Inter-Bold" size={12.5} color="white">{duressFixture.coordinator.title}</Text>
                  <Text face="Inter-Regular" size={10.5} color="text-3" style={{ marginTop: 1 }}>{duressFixture.coordinator.detail}</Text>
                </View>
              </View>
            </>
          ) : null}

          {phase === 'active' && !board ? (
            <View style={s.coordCard}>
              <View style={s.shrink}>
                <Text face="Inter-Bold" size={12.5} color="white">{m.withPosition ? 'Your position went with the alert' : 'No position went with the alert'}</Text>
                <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginTop: 3 }}>
                  {m.withPosition ? 'Dispatch has where you were when you raised it. The phone does not keep sending your position: say on the radio if you move.' : 'The phone had no fix to send. Dispatch knows your post: say on the radio where you are if you can.'}
                </Text>
                {m.canCancel ? (
                  <>
                    <Pressable onPress={() => setChoosing((on) => !on)} role="button" aria-label={`Raised by mistake? Cancel within ${m.cancelSecondsLeft} seconds`} aria-expanded={choosing} style={s.cancelBtn}>
                      <Text face="Inter-Bold" size={12.5} color="red-500">{`Raised by mistake? Cancel (${m.cancelSecondsLeft}s)`}</Text>
                    </Pressable>
                    {choosing ? (
                      <View role="radiogroup" aria-label="Why are you cancelling?" onLayout={showPicker}>
                        {CANCEL_REASONS.map((r) => (
                          <Pressable key={r} onPress={() => setReason(r)} role="radio" aria-checked={reason === r} style={[s.reason, reason === r ? { borderColor: c('amber-500') } : null]}>
                            <Text face="Inter-SemiBold" size={12.5} color="white">{r}</Text>
                          </Pressable>
                        ))}
                        <Pressable
                          onPress={() => (reason ? m.cancel(reason) : undefined)}
                          disabled={!reason || m.cancelBusy}
                          role="button"
                          aria-label="Cancel the alert"
                          aria-disabled={!reason || m.cancelBusy}
                          accessibilityHint={reason ? undefined : 'Choose a reason first'}
                          style={[s.confirmCancel, { backgroundColor: c(reason ? 'red-500' : 'surface-700') }]}
                        >
                          <Text face="Inter-Bold" size={12.5} color={reason ? 'g-navy-900' : 'text-3'}>{m.cancelBusy ? 'Cancelling…' : reason ? 'Cancel the alert' : 'Choose a reason first'}</Text>
                        </Pressable>
                      </View>
                    ) : null}
                  </>
                ) : null}
                {m.cancelError ? <Text face="Inter-Medium" size={11} lh={1.45} color="red-500" style={{ marginTop: 8 }} role="alert">{m.cancelError}</Text> : null}
              </View>
            </View>
          ) : null}

          {phase === 'closed' ? (
            <View style={s.coordCard}>
              <View style={s.shrink}>
                <Text face="Inter-Bold" size={12.5} color="white">{m.closedAs === 'false_alarm' ? 'Cancelled in time' : 'Closed by dispatch'}</Text>
                <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginTop: 3 }}>
                  {m.closedAs === 'false_alarm' ? 'Dispatch has been told why. Nothing more is needed.' : 'If you still need help, raise a new alert or use your radio.'}
                </Text>
                {raiseAgain}
                <Pressable onPress={m.finish} role="button" aria-label="Done" style={[s.confirmCancel, { backgroundColor: c('amber-500') }]}>
                  <Text face="Inter-Bold" size={12.5} color="g-navy-900">{'Done'}</Text>
                </Pressable>
              </View>
            </View>
          ) : null}

          <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center" style={{ marginTop: 14, marginHorizontal: 24 }}>
            {phase === 'arm' || phase === 'sending'
              ? 'Being watched? Do not use this screen. On Home, rest your thumb on the shield for two seconds: nothing will show, and dispatch will know.'
              : phase === 'failed'
                ? 'Dispatch may not have this alert. Raise a new one if you still need help.'
                : "Only dispatch can close this alert. Stay where it's safe to do so."}
          </Text>
        </ScrollView>
      </Gradient>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { width: '100%', height: '100%' },
  shrink: { flexShrink: 1 },
  spacer: { width: 32 },
  sideBtn: { width: 32, height: 32, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: a('white', 0.1), borderRadius: 16 },
  duressTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 20 },
  typeChipSpan: { backgroundColor: a('amber-500', 0.12), flexShrink: 1, borderRadius: 20, borderWidth: 1, borderColor: a('amber-500', 0.3), paddingHorizontal: 12, paddingVertical: 5 },
  typeChip: { flexDirection: 'row', justifyContent: 'center', marginTop: 6 },
  // DRILL: the board's amber chip, made a full-width notice so it is read before the rest.
  drill: { backgroundColor: a('amber-500', 0.14), borderRadius: 14, borderWidth: 2, borderColor: c('amber-500'), paddingHorizontal: 14, paddingVertical: 10, marginTop: 8, marginHorizontal: 20 },
  dRing: { position: 'absolute', width: 114, height: 114, opacity: 0.12, borderRadius: 57, borderWidth: 1, borderColor: c('amber-500') },
  dRing2: { position: 'absolute', width: 96, height: 96, opacity: 0.25, borderRadius: 48, borderWidth: 1, borderColor: c('amber-500') },
  dIcon: { width: 70, height: 70, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), zIndex: 2, borderRadius: 35 },
  dIconWrap: { width: 96, height: 96, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', marginBottom: 14 },
  duressHero: { flexDirection: 'column', alignItems: 'center', paddingTop: 20, paddingBottom: 16 },
  holdWrap: { width: 184, height: 184, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', marginBottom: 16 },
  holdRingOuter: { position: 'absolute', width: 184, height: 184, opacity: 0.12, borderRadius: 92, borderWidth: 1, borderColor: c('amber-500') },
  holdRing: { position: 'absolute', width: 156, height: 156, opacity: 0.3, borderRadius: 78, borderWidth: 2, borderColor: c('amber-500') },
  holdFill: { position: 'absolute', width: 156, height: 156, borderRadius: 78, backgroundColor: c('amber-500') },
  holdIcon: { width: 96, height: 96, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), zIndex: 2, borderRadius: 48 },
  rail: { position: 'absolute', top: 22, left: 10, width: 1.5, height: '100%', backgroundColor: c('border-700') },
  tDot: { width: 21, height: 21, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), zIndex: 1, borderRadius: 10.5 },
  tDotOpen: { width: 21, height: 21, flexGrow: 0, flexShrink: 0, zIndex: 1, borderRadius: 10.5, borderWidth: 1, borderColor: c('border-600') },
  tRow: { flexDirection: 'row', paddingBottom: 20, gap: 12 },
  tRowLast: { flexDirection: 'row', gap: 12 },
  timeline: { paddingTop: 4, paddingBottom: 14, paddingHorizontal: 24 },
  ring: { position: 'absolute', top: '50%', left: '50%', width: 130, height: 130, borderStyle: 'dashed', opacity: 0.4, borderRadius: 65, borderWidth: 1, borderColor: c('navy-500'), transform: [{ translateX: '-50%' }, { translateY: '-50%' }] },
  ring2: { position: 'absolute', top: '50%', left: '50%', width: 80, height: 80, borderStyle: 'dashed', opacity: 0.7, borderRadius: 40, borderWidth: 1, borderColor: c('navy-500'), transform: [{ translateX: '-50%' }, { translateY: '-50%' }] },
  pin: { position: 'absolute', top: '50%', left: '50%', width: 14, height: 14, backgroundColor: c('amber-500'), borderRadius: 7, boxShadow: sh([{ x: 0, y: 0, blur: 0, spread: 6, token: 'amber-500', opacity: 0.22 }]), transform: [{ translateX: '-50%' }, { translateY: '-50%' }] },
  glabel: { position: 'absolute', bottom: 9, left: 9, backgroundColor: a('black', 0.35), borderRadius: 20, paddingHorizontal: 8, paddingVertical: 3 },
  radar: { height: 100, backgroundColor: c('surface-800'), overflow: 'hidden', borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), marginHorizontal: 20 },
  cAvatar: { width: 36, height: 36, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 18 },
  coordCard: { flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), padding: 13, gap: 11, marginTop: 14, marginHorizontal: 20 },
  cancelBtn: { height: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: c('red-500'), marginTop: 12 },
  reason: { minHeight: 48, flexDirection: 'row', alignItems: 'center', borderRadius: 12, borderWidth: 1, borderColor: c('border-600'), paddingHorizontal: 12, marginTop: 8 },
  confirmCancel: { height: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 12, marginTop: 12 },
  againBtn: { height: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', overflow: 'hidden', borderRadius: 12, borderWidth: 1, borderColor: c('amber-500'), marginTop: 12 },
  againFill: { position: 'absolute', top: 0, bottom: 0, left: 0, right: 0, backgroundColor: a('amber-500', 0.28) },
  // The alert's red, live (Home's `fabRed` and `fabRingRed`): the fill red-700, the rings and outlines red-500.
  fillRed: { backgroundColor: c('red-700') },
  ringRed: { borderColor: c('red-500') },
  againFillRed: { backgroundColor: a('red-700', 0.35) },
})
