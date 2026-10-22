/**
 * A HOUSEHOLD WHOSE VISITORS ARE HELD AT THE GATE (M21 §1.7). The estate's arrears restriction applies to a walk-up as
 * it does to a guest pass: the household's own "yes" does not lift it. So instead of "Admit entry", the guard reads the
 * gate's one sentence — never an amount, never why — and either denies, or admits on an override with a reason, which
 * is the gate log's own verdict with the guard's name on it. Button geometry is PolicyChoices'.
 */

import { useState } from 'react'
import { StyleSheet, TextInput, View } from 'react-native'
import { Icon, Pressable, Text, a, c } from '@gs/core'
import { RESTRICTED_SENTENCE } from '@/features/gate/verdict'
import { OVERRIDE_REASON_MIN } from '../useApprovalWait'

export function OverrideChoice({ approved, busy, saveError, onOverride, onDeny }: { approved: boolean; busy: boolean; saveError: string | null; onOverride: (reason: string) => void; onDeny: () => void }) {
  const [reason, setReason] = useState('')
  const [focused, setFocused] = useState(false)
  const ready = reason.trim().length >= OVERRIDE_REASON_MIN

  return (
    <View style={s.wrap}>
      <View style={s.note} role="alert">
        <Icon name="privacy-note" size={14} color="amber-500" style={s.noteSvg} />
        <View style={s.shrink}>
          <Text face="Inter-SemiBold" size={12} lh={1.5} color="amber-500">{RESTRICTED_SENTENCE}</Text>
          <Text face="Inter-Regular" size={11} lh={1.5} color="text-2" style={{ marginTop: 3 }}>
            {approved ? 'The household approved this visitor, but its visitors are held at the gate. Admitting them is an override, with a reason.' : 'Admitting this visitor is an override, with a reason.'}
          </Text>
        </View>
      </View>

      <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper style={{ marginTop: 16 }}>{'Why you are admitting them'}</Text>
      <View style={[s.input, focused ? { borderColor: c('amber-500') } : null]}>
        <TextInput
          value={reason}
          onChangeText={setReason}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          placeholder="e.g. Management rang to allow the visit"
          placeholderTextColor={c('text-3')}
          selectionColor={c('amber-500')}
          maxLength={160}
          aria-label="Why you are admitting them"
          maxFontSizeMultiplier={1.3}
          style={s.inputText}
        />
      </View>

      <View style={s.row}>
        <Pressable onPress={onDeny} disabled={busy} role="button" aria-label="Deny entry and record it" aria-busy={busy} style={({ pressed }) => [s.deny, { opacity: pressed || busy ? 0.7 : 1 }]}>
          <Text face="Inter-SemiBold" size={13} color="white">{'Deny entry'}</Text>
        </Pressable>
        <Pressable
          onPress={() => onOverride(reason)}
          disabled={busy || !ready}
          role="button"
          aria-label="Admit on override and record it"
          aria-disabled={!ready}
          aria-busy={busy}
          accessibilityHint={ready ? 'Recorded on the gate log as an override, with your reason and your name' : `Say why first: at least ${OVERRIDE_REASON_MIN} characters`}
          style={({ pressed }) => [s.admit, { opacity: pressed || busy || !ready ? 0.6 : 1 }]}
        >
          <Text face="Inter-Bold" size={13} color="amber-500">{'Admit on override'}</Text>
        </Pressable>
      </View>
      {saveError ? <Text face="Inter-Medium" size={11} lh={1.5} color="red-500" align="center" style={{ marginTop: 10 }} role="alert">{saveError}</Text> : null}
      <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center" style={{ marginTop: 10 }}>
        {'Either one is recorded on the gate log with your name and the time: saved on this phone first, sent by itself.'}
      </Text>
    </View>
  )
}

const s = StyleSheet.create({
  wrap: { width: '100%' },
  shrink: { flexShrink: 1 },
  note: { flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 12, borderWidth: 1, borderColor: a('amber-500', 0.28), paddingHorizontal: 12, paddingVertical: 10, gap: 9 },
  noteSvg: { flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  input: { height: 48, flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, marginTop: 6 },
  inputText: { flex: 1, minWidth: 0, height: 46, fontFamily: 'Inter-Medium', fontSize: 13.5, color: c('white'), padding: 0 },
  row: { flexDirection: 'row', gap: 10, marginTop: 14 },
  deny: { height: 48, flexBasis: 110, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 14, borderWidth: 1, borderColor: c('border-600') },
  admit: { height: 48, flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 14, borderWidth: 1, borderColor: c('amber-500'), backgroundColor: a('amber-500', 0.16) },
})
