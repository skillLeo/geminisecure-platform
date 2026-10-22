/**
 * guard-app-04 · Scan Visitor QR  and  guard-app-28 · Override Admit — one screen, the
 * sheet changes with the verdict. Styles are the boards' own, copied from the lifted files.
 *
 * INVARIANT 14 — RAW CAMERA FRAMES NEVER LEAVE THE DEVICE. The camera is used for one
 * thing: the OS decodes a QR code and hands this screen its TEXT. No picture is taken, no
 * frame is read, nothing from the camera is stored or uploaded.
 *
 * The gate never waits on the network: with no signal the handset verifies the signature
 * itself, and the admission is saved on the phone first (useGateCheck).
 */

import { useCallback, useState } from 'react'
import { Linking, Platform, StyleSheet, View } from 'react-native'
import { CameraView, useCameraPermissions } from 'expo-camera'
import { router, useFocusEffect } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Gradient, GuardButton, Icon, InfoHeading, Pressable, Text, a, belowStatusBar, c, fidelityScreenId, isFidelity, sh, time12, useFidelityReady, type GradientSpec } from '@gs/core'
import { scanFixtures } from '@/fidelity/gate'
import { INFO } from '@/info/topics'
import { VerdictSheet } from '../components/VerdictSheet'
import { useGateCheck } from '../useGateCheck'

const TOP_FADE: GradientSpec = { angle: 180, stops: [{ token: 'black', at: 0, opacity: 0.75 }, { token: 'black', at: 1, opacity: 0 }] }

export default function ScanScreen() {
  const insets = useSafeAreaInsets()
  const fixture = isFidelity ? (scanFixtures[fidelityScreenId() ?? ''] ?? null) : null
  const m = useGateCheck(fixture)
  const [permission, requestPermission] = useCameraPermissions()
  const [torch, setTorch] = useState(false)
  const [focused, setFocused] = useState(true)
  useFidelityReady(true)

  // The camera runs only while this screen is in front.
  useFocusEffect(
    useCallback(() => {
      setFocused(true)
      return () => {
        setFocused(false)
        setTorch(false)
      }
    }, []),
  )

  /*
   * THE PHONE IS NEVER ASKED BY THIS SCREEN OPENING. It used to be: an effect requested the camera on
   * mount, so a guard's first visit met the system dialog with nothing on screen saying why, and the
   * words that explain it only appeared after they had already answered. The explanation comes first
   * now and the guard taps "Allow the camera" themselves — the same rule the location disclosure
   * follows (features/enrolment/setupPermissions.ts). Typing the code by hand is offered either way.
   */
  const v = m.verdict
  const refusal = v !== null && !v.admitAllowed
  const cameraOn = !isFidelity && focused && permission?.granted === true
  const cameraBlocked = !isFidelity && permission !== null && !permission.granted && !permission.canAskAgain
  /** Not granted, and the phone will still ask: say what the camera is for, then let the guard ask for it. */
  const cameraAsk = !isFidelity && permission !== null && !permission.granted && permission.canAskAgain
  // Board 04 leaves its top-bar SVGs unsized, so they swell to fill the 34px button; board 28 — the same
  // screen, drawn later — sizes them at 16. 16 is the design; the app does not change glyph size between states.
  const topIcon = 16

  return (
    <View style={s.screen}>
      <View style={s.scanScreen}>
        {cameraOn ? (
          <CameraView
            style={StyleSheet.absoluteFill}
            facing="back"
            enableTorch={torch}
            barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
            onBarcodeScanned={v || m.checking || m.outcome ? undefined : (scan) => m.check(scan.data)}
          />
        ) : null}

        <Gradient spec={TOP_FADE} style={[s.scanTop, { paddingTop: insets.top + belowStatusBar(6) }]}>
          <Pressable onPress={() => (router.canGoBack() ? router.back() : router.replace('/(tabs)'))} role="button" aria-label="Back" hitSlop={7} style={s.sideBtn}>
            <Icon name="chevron-left" size={topIcon} color="white" />
          </Pressable>
          <View style={s.shrink}>
            <InfoHeading topic={INFO.scan} tone="guard">
              <Text face="Poppins-SemiBold" size={15} color="white" role="heading">{'Scan visitor pass'}</Text>
            </InfoHeading>
          </View>
          <Pressable onPress={() => router.push('/gate-activity' as never)} role="button" aria-label="Gate activity" hitSlop={7} style={s.sideBtn}>
            <Icon name="side-btn" size={topIcon} color="white" />
          </Pressable>
        </Gradient>

        {refusal ? null : (
          <>
            {cameraBlocked || cameraAsk ? (
              <View style={s.blocked}>
                <Text face="Poppins-SemiBold" size={15} color="white" align="center">{cameraBlocked ? 'The camera is turned off' : 'Scanning needs the camera'}</Text>
                <Text face="Inter-Regular" size={12} lh={1.5} color="text-2" align="center" style={{ marginTop: 6 }}>
                  {cameraBlocked
                    ? "Scanning needs the camera. Turn it on in the phone's Settings, or type the code printed under the visitor's QR pass."
                    : "A visitor's pass is a QR code, so the camera reads that code and nothing else: no picture is taken, kept or sent. You can type the code printed under the pass instead."}
                </Text>
                {cameraBlocked ? (
                  <GuardButton variant="outline" label="Open Settings" onPress={() => void Linking.openSettings()} style={{ marginTop: 16, alignSelf: 'stretch' }} />
                ) : (
                  <GuardButton variant="outline" label="Allow the camera" onPress={() => void requestPermission()} style={{ marginTop: 16, alignSelf: 'stretch' }} />
                )}
              </View>
            ) : (
              <>
                <View style={s.viewfinder} pointerEvents="none">
                  <View style={s.vfCorner} />
                  <View style={s.vfCorner2} />
                  <View style={s.vfCorner3} />
                  <View style={s.vfCorner4} />
                  <View style={s.vfLine} />
                </View>
                <View style={s.scanHint} pointerEvents="none">
                  <Text face="Inter-Medium" size={12} align="center" style={{ color: c('white') }}>
                    {m.checking ? 'Checking the pass…' : Platform.OS === 'web' && !isFidelity && !cameraOn ? 'No camera here: use manual entry' : "Line up the visitor's QR code in the frame"}
                  </Text>
                  {!isFidelity && !m.online ? (
                    <Text face="Inter-SemiBold" size={11} align="center" color="amber-500" style={{ marginTop: 6, paddingHorizontal: 30 }}>
                      {m.hasKeys ? `No signal: passes are checked on this phone${m.knowledgeAge ? ` (cancelled list from ${time12(m.knowledgeAge)})` : ''}` : 'No signal, and this phone has not yet received the pass keys: use walk-up entry until it reconnects'}
                    </Text>
                  ) : null}
                </View>
              </>
            )}

            <View style={[s.scanActions, { bottom: v || m.outcome || isFidelity ? 230 : Math.max(60, insets.bottom + 40) }]}>
              <View style={s.scanAct}>
                <Pressable
                  onPress={() => setTorch((on) => !on)}
                  disabled={!cameraOn}
                  role="switch"
                  aria-checked={torch}
                  aria-label="Flash"
                  accessibilityHint={cameraOn ? undefined : 'The camera is not running'}
                  hitSlop={4}
                  style={[s.saBtn, torch ? { backgroundColor: c('amber-500') } : null]}
                >
                  <Icon name="sa-btn" size={19} color={torch ? 'g-navy-900' : 'white'} />
                </Pressable>
                <Text face="Inter-SemiBold" size={9.5} style={{ color: c('white') }}>{'Flash'}</Text>
              </View>
              <View style={s.scanAct}>
                <Pressable onPress={() => router.push('/scan/manual' as never)} role="button" aria-label="Manual entry" hitSlop={4} style={s.saBtn}>
                  <Icon name="side-btn" size={19} color="white" />
                </Pressable>
                <Text face="Inter-SemiBold" size={9.5} style={{ color: c('white') }}>{'Manual entry'}</Text>
              </View>
            </View>
          </>
        )}

        <VerdictSheet m={m} presetReason={fixture?.reason ?? ''} />
      </View>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  scanScreen: { width: '100%', height: '100%', backgroundColor: c('black') },
  shrink: { flexShrink: 1 },
  sideBtn: { width: 34, height: 34, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: a('white', 0.14), borderRadius: 17 },
  scanTop: { position: 'absolute', top: 0, right: 0, left: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingBottom: 16, zIndex: 5, paddingHorizontal: 18 },
  vfCorner: { position: 'absolute', top: 0, left: 0, width: 34, height: 34, borderTopWidth: 3, borderTopColor: c('amber-500'), borderLeftWidth: 3, borderLeftColor: c('amber-500'), borderTopLeftRadius: 10 },
  vfCorner2: { position: 'absolute', top: 0, right: 0, width: 34, height: 34, borderTopWidth: 3, borderTopColor: c('amber-500'), borderRightWidth: 3, borderRightColor: c('amber-500'), borderTopRightRadius: 10 },
  vfCorner3: { position: 'absolute', bottom: 0, left: 0, width: 34, height: 34, borderBottomWidth: 3, borderBottomColor: c('amber-500'), borderLeftWidth: 3, borderLeftColor: c('amber-500'), borderBottomLeftRadius: 10 },
  vfCorner4: { position: 'absolute', right: 0, bottom: 0, width: 34, height: 34, borderRightWidth: 3, borderRightColor: c('amber-500'), borderBottomWidth: 3, borderBottomColor: c('amber-500'), borderBottomRightRadius: 10 },
  vfLine: { position: 'absolute', top: '46%', right: 8, left: 8, height: 2, backgroundColor: c('amber-500'), opacity: 0.85, boxShadow: sh([{ x: 0, y: 0, blur: 8, spread: 0, token: 'amber-500', opacity: 0.7 }]) },
  viewfinder: { position: 'absolute', top: 190, left: '50%', width: 240, height: 240, transform: [{ translateX: '-50%' }] },
  scanHint: { position: 'absolute', top: 445, right: 0, left: 0 },
  blocked: { position: 'absolute', top: 210, left: 32, right: 32, alignItems: 'center' },
  saBtn: { width: 46, height: 46, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: a('white', 0.12), borderRadius: 23 },
  scanAct: { flexDirection: 'column', alignItems: 'center', flexShrink: 1, gap: 6 },
  scanActions: { position: 'absolute', right: 0, left: 0, flexDirection: 'row', justifyContent: 'center', gap: 16 },
})
