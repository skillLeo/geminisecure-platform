/**
 * The sheet that answers a pass check — shared by the scanner (guard-app-04), manual code
 * entry (-32) and the override state (-28). Styles are the boards' own (lifted 04 and 28).
 *
 *   green            Admit entry · Deny                                   (board 04)
 *   red / amber      the reason, then EITHER a written override OR walk-up entry   (board 28)
 *   afterwards       what was recorded, and whether it is still waiting to sync
 *
 * A restricted household reaches this file as the fixed sentence in `verdict.detail`
 * (verdict.ts) — there is nothing else here to show.
 */

import { useEffect, useState } from 'react'
import { ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { router } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { GuardButton, Icon, Pressable, Text, a, aboveGestureBar, c, isFidelity, type ColorToken } from '@gs/core'
import { showGateFlash } from '../gateFlash'
import { REASON_MAX, REASON_MIN, type GateCheckModel } from '../useGateCheck'
import { windowLabel, type GateVerdict } from '../verdict'

const OUTCOME_MS = 2600

const initials = (name: string | null) => (name ? name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('') : '?')
const visiting = (v: GateVerdict) => (v.unit ? `Visiting ${v.unit}${v.household ? ` · ${v.household}` : ''}` : v.source === 'offline' ? 'A QR code carries no name or unit: ask the visitor who they are visiting' : 'Unit not on record')

function Who({ v }: { v: GateVerdict }) {
  return (
    <View style={s.resultRow}>
      <View style={s.rAvatar}>
        <Text face="Inter-Bold" size={14} color="white">{initials(v.pass?.visitorName ?? null)}</Text>
      </View>
      <View style={s.shrink}>
        <Text face="Poppins-SemiBold" size={15} color="white">{v.pass?.visitorName ?? 'Pass holder'}</Text>
        <Text face="Inter-Regular" size={11} color="text-2" style={{ marginTop: 2 }}>{visiting(v)}</Text>
      </View>
    </View>
  )
}

function Headline({ text, tone }: { text: string; tone: ColorToken }) {
  return (
    <View style={s.resultTop} role="alert">
      <View style={[s.rtDot, { backgroundColor: c(tone) }]} />
      <View style={s.shrink}>
        <Text face="Inter-Bold" size={11.5} ls={0.3} color={tone}>{text}</Text>
      </View>
    </View>
  )
}

function Note({ text, tone }: { text: string; tone: ColorToken }) {
  return (
    <View style={[s.warn, { backgroundColor: a(tone, 0.1), borderColor: a(tone, 0.28) }]}>
      <Icon name="qa-tile-3" size={16} color={tone} style={s.warnIcon} />
      <View style={s.shrink}><Text face="Inter-SemiBold" size={11} lh={1.5} color={tone}>{text}</Text></View>
    </View>
  )
}

/** Board 04 — a pass the guard may admit on. */
function AdmitSheet({ m, v, bottom }: { m: GateCheckModel; v: GateVerdict; bottom: number }) {
  return (
    <View style={[s.resultSheet, { paddingBottom: bottom }]}>
      <Headline text={v.headline} tone="green-400" />
      <Who v={v} />
      {v.pass ? (
        <View style={s.resultMeta}>
          <View style={s.rmChip}>
            <Text face="Inter-Bold" size={9} color="text-3">{'WINDOW'}</Text>
            <Text face="Inter-SemiBold" size={11.5} color="white" style={{ marginTop: 2 }}>{windowLabel(v.pass)}</Text>
          </View>
          <View style={s.rmChip}>
            <Text face="Inter-Bold" size={9} color="text-3">{v.source === 'online' ? 'PURPOSE' : 'USE'}</Text>
            <Text face="Inter-SemiBold" size={11.5} color="white" numberOfLines={1} style={{ marginTop: 2 }}>
              {v.source === 'online' ? (v.pass.purpose ?? 'Not given') : v.pass.singleUse ? 'Single visit' : 'Repeat visits'}
            </Text>
          </View>
        </View>
      ) : null}
      {v.detail ? <Note text={v.detail} tone="amber-500" /> : null}
      <View style={s.admitRow}>
        <Pressable onPress={m.deny} disabled={m.busy} role="button" aria-label="Deny entry" style={({ pressed }) => [s.btnOutline, { height: 48, flexBasis: 100, opacity: pressed ? 0.7 : 1 }]}>
          <Text face="Inter-SemiBold" size={13} color="white">{'Deny'}</Text>
        </Pressable>
        <GuardButton label="Admit entry" onPress={m.admit} loading={m.busy} style={{ flex: 1 }} />
      </View>
    </View>
  )
}

/** Board 28 — a pass the system advises against. Admitting takes a written reason; anything else is a walk-up. */
function RefusalSheet({ m, v, bottom, presetReason, againLabel }: { m: GateCheckModel; v: GateVerdict; bottom: number; presetReason: string; againLabel: string }) {
  const tone: ColorToken = v.tone === 'amber' ? 'amber-500' : 'red-500'
  const [reason, setReason] = useState(presetReason)
  const [tried, setTried] = useState(false)
  const length = reason.trim().length
  const valid = length >= REASON_MIN && length <= REASON_MAX

  return (
    <View style={[s.resultSheet, s.refusalSheet, { borderTopColor: a(tone, 0.4), paddingBottom: bottom }]}>
      {/*
        THE TWO BUTTONS ARE NEVER IN THE SCROLLING PART.
        The sheet is capped at 78% of the screen, and on a 360-point phone with the keyboard up —
        which it always is here, because an override REQUIRES a typed reason — that cap is measured
        against a window Android has already shrunk. Everything above the buttons scrolls; the row
        that admits or denies the visitor cannot be pushed off the bottom by a long refusal, a long
        reason or a large text setting. Found by putting the app on a narrow phone, 2026-09-22.
        `automaticallyAdjustKeyboardInsets` is the iOS half (it does nothing on Android).
      */}
      <ScrollView
        style={s.sheetBody}
        contentContainerStyle={s.sheetBodyContent}
        keyboardShouldPersistTaps="handled"
        automaticallyAdjustKeyboardInsets
        showsVerticalScrollIndicator={false}
      >
        <Headline text={v.headline} tone={tone} />
        {v.pass ? <Who v={v} /> : null}
        {v.detail ? <Note text={v.detail} tone={tone} /> : null}

        {v.overrideAllowed ? (
          <View style={s.noteField}>
            <Text face="Inter-Bold" size={11} color="text-2">{'Reason for override'}</Text>
            <TextInput
              value={reason}
              onChangeText={setReason}
              multiline
              numberOfLines={2}
              maxLength={REASON_MAX}
              placeholder="Why are you admitting against the advice? Your supervisor reads this."
              placeholderTextColor={c('text-3')}
              selectionColor={c('amber-500')}
              aria-label="Reason for override"
              maxFontSizeMultiplier={1.3}
              style={[s.noteInput, { color: c(isFidelity ? 'text-3' : 'white') }, tried && !valid ? { borderColor: c('red-500') } : null]}
            />
            {isFidelity ? null : (
              <Text face="Inter-Regular" size={10.5} color={tried && !valid ? 'red-500' : 'text-3'} style={{ marginTop: 5 }} role={tried && !valid ? 'alert' : undefined}>
                {tried && !valid ? `Write at least ${REASON_MIN} characters saying why.` : `${length}/${REASON_MAX} · recorded on the gate log with your name`}
              </Text>
            )}
          </View>
        ) : null}
      </ScrollView>

      {v.overrideAllowed ? (
        <View style={s.admitRowTight}>
          <Pressable onPress={m.deny} disabled={m.busy} role="button" aria-label="Deny entry" style={({ pressed }) => [s.btnOutline, { height: 46, flexBasis: 96, opacity: pressed ? 0.7 : 1 }]}>
            <Text face="Inter-SemiBold" size={13} color="white">{'Deny'}</Text>
          </Pressable>
          <Pressable
            onPress={() => (valid ? m.override(reason) : setTried(true))}
            disabled={m.busy}
            role="button"
            aria-label="Override and admit"
            aria-busy={m.busy}
            accessibilityHint={valid ? 'Admits the visitor and records your reason' : `Write a reason of at least ${REASON_MIN} characters first`}
            style={({ pressed }) => [s.overrideBtn, { backgroundColor: a(tone, 0.16), borderColor: c(tone), opacity: pressed || m.busy ? 0.7 : 1 }]}
          >
            <Text face="Inter-Bold" size={13} color={tone}>{'Override & admit'}</Text>
          </Pressable>
        </View>
      ) : (
        <View style={s.admitRowTight}>
          <Pressable onPress={m.reset} role="button" aria-label={againLabel} style={({ pressed }) => [s.btnOutline, { height: 48, flexBasis: 120, opacity: pressed ? 0.7 : 1 }]}>
            <Text face="Inter-SemiBold" size={13} color="white">{againLabel}</Text>
          </Pressable>
          <GuardButton label="Walk-up entry" onPress={() => router.push('/walk-up' as never)} style={{ flex: 1, height: 48 }} />
        </View>
      )}
    </View>
  )
}

const OUTCOME_TITLE = { admitted: 'ENTRY RECORDED', overridden: 'OVERRIDE RECORDED', denied: 'DENIAL RECORDED' } as const

export function VerdictSheet({ m, presetReason = '', againLabel = 'Scan again', nextLabel = 'Scan next visitor' }: { m: GateCheckModel; presetReason?: string; againLabel?: string; nextLabel?: string }) {
  const insets = useSafeAreaInsets()
  const bottom = aboveGestureBar(28, insets.bottom)
  const { outcome, reset, verdict: v } = m

  /*
   * AN ADMISSION GOES HOME, WITH ITS MESSAGE (the client, 27 Sep). The visitor is through the gate: the officer is taken
   * back to Home, which says what was recorded (gateFlash.ts). A denial stays here, ready for the next person by itself.
   */
  useEffect(() => {
    if (!outcome) return
    if (!isFidelity && outcome.kind !== 'denied') {
      showGateFlash(outcome)
      reset()
      if (router.canDismiss()) router.dismissAll()
      router.replace('/(tabs)' as never)
      return
    }
    const timer = setTimeout(reset, OUTCOME_MS)
    return () => clearTimeout(timer)
  }, [outcome, reset])

  if (v) return v.admitAllowed ? <AdmitSheet m={m} v={v} bottom={bottom} /> : <RefusalSheet key={v.pass?.id ?? v.code} m={m} v={v} bottom={bottom} presetReason={presetReason} againLabel={againLabel} />
  if (!outcome) return null

  const tone: ColorToken = outcome.kind === 'denied' ? 'red-500' : 'green-400'
  return (
    <View style={[s.resultSheet, { paddingBottom: bottom }]} role="status">
      <Headline text={OUTCOME_TITLE[outcome.kind]} tone={tone} />
      <Text face="Poppins-SemiBold" size={15} color="white" style={{ marginTop: 10 }}>{outcome.subject}</Text>
      <Text face="Inter-Regular" size={11} lh={1.5} color="text-2" style={{ marginTop: 2 }}>
        {outcome.pending ? 'Saved on this phone: pending sync. It is sent by itself when signal returns.' : 'Saved to the gate log.'}
      </Text>
      <GuardButton variant="outline" label={nextLabel} onPress={reset} style={{ marginTop: 14 }} />
    </View>
  )
}

const s = StyleSheet.create({
  shrink: { flexShrink: 1 },
  rtDot: { width: 9, height: 9, borderRadius: 4.5 },
  resultTop: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  rAvatar: { width: 46, height: 46, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 23 },
  resultRow: { flexDirection: 'row', alignItems: 'center', gap: 12, marginTop: 12 },
  rmChip: { flex: 1, backgroundColor: c('surface-700'), borderRadius: 10, paddingHorizontal: 10, paddingVertical: 8 },
  resultMeta: { flexDirection: 'row', gap: 10, marginTop: 14 },
  warnIcon: { width: 16, height: 16, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  warn: { flexDirection: 'row', borderRadius: 12, borderWidth: 1, paddingHorizontal: 12, paddingVertical: 11, gap: 9, marginTop: 12 },
  noteField: { marginTop: 14 },
  // The board's box is two 19.36px lines (a 12.5px span on the sheet's 16px strut) + 22 padding + its 1.5px border ≈ 63.7.
  // maxHeight so a reason typed up to REASON_MAX cannot grow the box without bound and push the
  // buttons out of a sheet that is already capped (ClockOutScreen's note box is capped the same way).
  noteInput: { minHeight: 63.72, maxHeight: 132, backgroundColor: c('surface-700'), borderRadius: 12, borderWidth: 1, borderColor: c('border-600'), paddingHorizontal: 13, paddingVertical: 11, marginTop: 7, fontFamily: 'Inter-Regular', fontSize: 12.5, lineHeight: 19.36, textAlignVertical: 'top' },
  btnOutline: { flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 14, borderWidth: 1, borderColor: c('border-600'), gap: 9 },
  overrideBtn: { height: 48, flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 14, borderWidth: 1 },
  admitRow: { flexDirection: 'row', gap: 10, marginTop: 16 },
  admitRowTight: { flexDirection: 'row', gap: 10, marginTop: 14 },
  resultSheet: { position: 'absolute', right: 0, bottom: 0, left: 0, paddingTop: 18, borderTopWidth: 1, borderTopColor: c('border-700'), borderTopLeftRadius: 22, borderTopRightRadius: 22, backgroundColor: c('surface-800'), paddingHorizontal: 20, zIndex: 10 },
  refusalSheet: { maxHeight: '78%', overflow: 'hidden' },
  // The scrolling part takes what is left after the button row, and no more — `flexShrink` is what
  // makes the cap above shorten THIS instead of hiding the buttons under the bottom edge.
  sheetBody: { flexGrow: 0, flexShrink: 1 },
  sheetBodyContent: { flexGrow: 0 },
})
