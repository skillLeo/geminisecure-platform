/**
 * The in-screen checkpoint scanner — the same pattern as the gate's (features/gate/screens/ScanScreen.tsx),
 * whose viewfinder and button styles these are.
 *
 * INVARIANT 14 — RAW CAMERA FRAMES NEVER LEAVE THE DEVICE. The camera is used for one thing: the OS
 * decodes a QR code and hands this component its TEXT. No picture is taken, no frame is read, nothing
 * from the camera is stored or uploaded.
 *
 * The camera runs only while the scanner is open, the Patrol tab is in front, and no result is showing.
 * The result is a full-width panel the guard can read at arm's length; its words come from panelFor().
 */

import { useEffect } from 'react'
import { BackHandler, Linking, Platform, StyleSheet, View } from 'react-native'
import { CameraView, useCameraPermissions } from 'expo-camera'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Gradient, GuardButton, Icon, Pressable, Text, a, belowStatusBar, c, sh, type ColorToken, type GradientSpec } from '@gs/core'
import type { ScannerModel } from '../usePatrol'

const TOP_FADE: GradientSpec = { angle: 180, stops: [{ token: 'black', at: 0, opacity: 0.75 }, { token: 'black', at: 1, opacity: 0 }] }
const TONE: Record<'green' | 'amber' | 'red' | 'neutral', ColorToken> = { green: 'green-400', amber: 'amber-500', red: 'red-500', neutral: 'text-2' }

type Props = {
  m: ScannerModel
  online: boolean
  /** False when the tab is not in front: the camera is off whatever else is true. */
  active: boolean
  torch: boolean
  onTorch: (on: boolean) => void
  onTag: (text: string) => void
  onClose: () => void
  onAgain: () => void
  onNext: () => void
}

export function TagScanner({ m, online, active, torch, onTorch, onTag, onClose, onAgain, onNext }: Props) {
  const insets = useSafeAreaInsets()
  const [permission, requestPermission] = useCameraPermissions()

  /*
   * THE PHONE IS NEVER ASKED BY THE SCANNER OPENING. It used to be: the scanner requested the camera
   * from an effect on mount, so the first time a guard opened it they met the system dialog with
   * nothing on screen saying why — and the panel below, which explains it properly, only appeared
   * AFTER they had already answered. The explanation now comes first and the guard taps "Allow the
   * camera" themselves, which is also the rule the location disclosure follows (setupPermissions.ts).
   * Asking once per opening is therefore automatic: nothing asks but a tap.
   */

  // Android's back closes the scanner, not the app.
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      onClose()
      return true
    })
    return () => sub.remove()
  }, [onClose])

  const panel = m.panel
  const cameraOn = active && permission?.granted === true && panel === null
  const blocked = permission !== null && !permission.granted && !permission.canAskAgain
  const waitingForLeave = permission !== null && !permission.granted && permission.canAskAgain
  const tone = panel ? TONE[panel.tone] : 'text-2'
  const failed = panel?.tone === 'red'

  return (
    <View style={s.cover} aria-modal role="dialog" aria-label="Scan checkpoint tag">
      {cameraOn ? (
        <CameraView
          style={StyleSheet.absoluteFill}
          facing="back"
          enableTorch={torch}
          barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
          onBarcodeScanned={(scan) => onTag(scan.data)}
        />
      ) : null}

      <Gradient spec={TOP_FADE} style={[s.top, { paddingTop: insets.top + belowStatusBar(6) }]}>
        <Pressable onPress={onClose} role="button" aria-label="Close the scanner" hitSlop={7} style={s.sideBtn}>
          <Icon name="chevron-left" size={16} color="white" />
        </Pressable>
        <View style={s.shrink}>
          <Text face="Poppins-SemiBold" size={15} color="white" role="heading">{'Scan checkpoint tag'}</Text>
        </View>
        <View style={s.sideSpacer} />
      </Gradient>

      <View style={[s.target, { top: insets.top + 62 }]} pointerEvents="none">
        <Text face="Inter-Bold" size={11} ls={0.4} align="center" upper style={{ color: c('white') }}>{`Checkpoint ${m.targetPosition} of ${m.total}`}</Text>
        <Text face="Poppins-SemiBold" size={20} color="white" align="center" numberOfLines={2} style={{ marginTop: 2 }}>{m.targetLabel}</Text>
      </View>

      {panel ? null : blocked || waitingForLeave ? (
        <View style={s.blocked}>
          <Text face="Poppins-SemiBold" size={15} color="white" align="center">{blocked ? 'The camera is turned off' : 'The camera needs your permission'}</Text>
          <Text face="Inter-Regular" size={12} lh={1.5} color="text-2" align="center" style={{ marginTop: 6 }}>
            {blocked
              ? "A checkpoint is recorded by reading its tag, so scanning needs the camera. Turn it on in the phone's Settings."
              : 'A checkpoint is recorded by reading its tag. Only the code in the tag is read: no picture is taken or sent.'}
          </Text>
          {blocked ? (
            <GuardButton variant="outline" label="Open Settings" onPress={() => void Linking.openSettings()} style={s.blockedBtn} />
          ) : (
            <GuardButton variant="outline" label="Allow the camera" onPress={() => void requestPermission()} style={s.blockedBtn} />
          )}
        </View>
      ) : (
        <>
          {/* Centred between the target's name and the flash button, so it holds on a short screen too. */}
          <View style={[s.stage, { paddingTop: insets.top + 124 }]} pointerEvents="none">
            <View style={s.viewfinder}>
              <View style={s.vfCorner} />
              <View style={s.vfCorner2} />
              <View style={s.vfCorner3} />
              <View style={s.vfCorner4} />
              <View style={s.vfLine} />
            </View>
            <Text face="Inter-Medium" size={12} align="center" style={{ color: c('white'), marginTop: 15 }}>
              {Platform.OS === 'web' && !cameraOn ? 'No camera here: checkpoints are scanned with the handset' : "Line up the tag's QR code in the frame"}
            </Text>
            {online ? null : (
              <Text face="Inter-SemiBold" size={11} align="center" color="amber-500" style={s.hintOffline}>
                {'No signal: the scan is saved on this phone and checked by the server when it syncs'}
              </Text>
            )}
            {/* What this version cannot do, said where the guard meets it: no NFC reader is in the app, and the
                server takes no scan without the tag's code (there is no skip and no typed fallback). */}
            <Text face="Inter-Regular" size={10.5} lh={1.45} align="center" style={[s.hintLimits, { color: c('white') }]}>
              {"This version reads the tag's QR code only, not NFC. Tag damaged or missing? A checkpoint cannot be recorded without it: tell your supervisor."}
            </Text>
          </View>
          <View style={s.actions}>
            <Pressable
              onPress={() => onTorch(!torch)}
              disabled={!cameraOn}
              role="switch"
              aria-checked={torch}
              aria-label="Flash"
              accessibilityHint={cameraOn ? undefined : 'The camera is not running'}
              hitSlop={4}
              style={[s.saBtn, torch ? { backgroundColor: c('amber-500') } : null, cameraOn ? null : s.saBtnOff]}
            >
              <Icon name="sa-btn" size={19} color={torch ? 'g-navy-900' : 'white'} />
            </Pressable>
            <Text face="Inter-SemiBold" size={9.5} style={{ color: c('white') }}>{'Flash'}</Text>
          </View>
        </>
      )}

      {panel ? (
        <View style={[s.result, { borderTopColor: c(tone) }]} role={failed ? 'alert' : 'status'}>
          <View style={[s.resultMark, { backgroundColor: a(tone, 0.16), borderColor: c(tone) }]}>
            <Icon name={panel.tone === 'green' ? 'check' : failed ? 'qa-tile-3' : panel.tone === 'amber' ? 'offline-note' : 'sync-badge'} size={30} color={tone} />
          </View>
          <Text face="Inter-Bold" size={14} ls={0.4} color={tone} align="center" style={{ marginTop: 14 }}>{panel.headline}</Text>
          <Text face="Poppins-SemiBold" size={22} color="white" align="center" style={{ marginTop: 6 }}>{panel.title}</Text>
          <Text face="Inter-Regular" size={13} lh={1.5} color="text-2" align="center" style={{ marginTop: 8 }}>{panel.body}</Text>

          {failed ? (
            <>
              <GuardButton label="Scan again" onPress={onAgain} style={s.resultBtn} />
              <GuardButton variant="outline" label="Back to the tour" onPress={onClose} style={s.resultBtn2} />
            </>
          ) : panel.settled && m.nextLabel ? (
            <>
              <GuardButton label={`Scan next · ${m.nextLabel}`} onPress={onNext} style={s.resultBtn} />
              <GuardButton variant="outline" label="Back to the tour" onPress={onClose} style={s.resultBtn2} />
            </>
          ) : panel.settled ? (
            <GuardButton label="Back to the tour" onPress={onClose} style={s.resultBtn} />
          ) : (
            <GuardButton variant="outline" label="Back to the tour" onPress={onClose} style={s.resultBtn} />
          )}
        </View>
      ) : null}
    </View>
  )
}

const s = StyleSheet.create({
  cover: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: c('black'), zIndex: 40, overflow: 'hidden' },
  shrink: { flexShrink: 1 },
  top: { position: 'absolute', top: 0, right: 0, left: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingBottom: 16, zIndex: 5, paddingHorizontal: 18 },
  sideBtn: { width: 34, height: 34, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: a('white', 0.14), borderRadius: 17 },
  sideSpacer: { width: 34 },
  target: { position: 'absolute', right: 24, left: 24, zIndex: 5 },
  vfCorner: { position: 'absolute', top: 0, left: 0, width: 34, height: 34, borderTopWidth: 3, borderTopColor: c('amber-500'), borderLeftWidth: 3, borderLeftColor: c('amber-500'), borderTopLeftRadius: 10 },
  vfCorner2: { position: 'absolute', top: 0, right: 0, width: 34, height: 34, borderTopWidth: 3, borderTopColor: c('amber-500'), borderRightWidth: 3, borderRightColor: c('amber-500'), borderTopRightRadius: 10 },
  vfCorner3: { position: 'absolute', bottom: 0, left: 0, width: 34, height: 34, borderBottomWidth: 3, borderBottomColor: c('amber-500'), borderLeftWidth: 3, borderLeftColor: c('amber-500'), borderBottomLeftRadius: 10 },
  vfCorner4: { position: 'absolute', right: 0, bottom: 0, width: 34, height: 34, borderRightWidth: 3, borderRightColor: c('amber-500'), borderBottomWidth: 3, borderBottomColor: c('amber-500'), borderBottomRightRadius: 10 },
  vfLine: { position: 'absolute', top: '46%', right: 8, left: 8, height: 2, backgroundColor: c('amber-500'), opacity: 0.85, boxShadow: sh([{ x: 0, y: 0, blur: 8, spread: 0, token: 'amber-500', opacity: 0.7 }]) },
  stage: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, alignItems: 'center', justifyContent: 'center', paddingBottom: 110 },
  viewfinder: { width: 240, height: 240, flexShrink: 1 },
  hintOffline: { marginTop: 6, paddingHorizontal: 30 },
  hintLimits: { marginTop: 8, paddingHorizontal: 30 },
  saBtnOff: { opacity: 0.4 },
  blocked: { position: 'absolute', top: 220, left: 32, right: 32, alignItems: 'center' },
  blockedBtn: { marginTop: 16, alignSelf: 'stretch' },
  saBtn: { width: 46, height: 46, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: a('white', 0.12), borderRadius: 23 },
  actions: { position: 'absolute', right: 0, bottom: 40, left: 0, flexDirection: 'column', alignItems: 'center', gap: 6 },
  result: { position: 'absolute', right: 0, bottom: 0, left: 0, alignItems: 'center', paddingTop: 22, paddingBottom: 20, paddingHorizontal: 20, borderTopWidth: 3, borderTopLeftRadius: 22, borderTopRightRadius: 22, backgroundColor: c('surface-800'), zIndex: 10 },
  resultMark: { width: 64, height: 64, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 32, borderWidth: 1.5 },
  resultBtn: { marginTop: 18, alignSelf: 'stretch' },
  resultBtn2: { marginTop: 10, alignSelf: 'stretch' },
})
