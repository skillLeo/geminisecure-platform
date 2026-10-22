/**
 * "Apply the estate's policy" — the two honest choices when the household cannot be asked (no
 * signal) or did not answer: admit on the guard's own decision, or deny. Both are records on the
 * gate log, captured on the phone first. Button geometry is board 28's (the scanner's Deny / Override row).
 */

import { StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { Pressable, Text, a, c } from '@gs/core'

export function PolicyChoices({ busy, saveError, onAdmit, onDeny }: { busy: boolean; saveError: string | null; onAdmit: () => void; onDeny: () => void }) {
  return (
    <View style={s.wrap}>
      <View style={s.row}>
        <Pressable onPress={onDeny} disabled={busy} role="button" aria-label="Deny entry and record it" aria-busy={busy} style={({ pressed }) => [s.deny, { opacity: pressed || busy ? 0.7 : 1 }]}>
          <Text face="Inter-SemiBold" size={13} color="white">{'Deny entry'}</Text>
        </Pressable>
        <Pressable
          onPress={onAdmit}
          disabled={busy}
          role="button"
          aria-label="Admit on my decision and record it"
          aria-busy={busy}
          accessibilityHint="Recorded on the gate log as your decision, with your name"
          style={({ pressed }) => [s.admit, { opacity: pressed || busy ? 0.7 : 1 }]}
        >
          <Text face="Inter-Bold" size={13} color="amber-500">{'Admit: my decision'}</Text>
        </Pressable>
      </View>
      {saveError ? <Text face="Inter-Medium" size={11} lh={1.5} color="red-500" align="center" style={{ marginTop: 10 }} role="alert">{saveError}</Text> : null}
      <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center" style={{ marginTop: 10 }}>
        {'Either one is recorded on the gate log with your name and the time: saved on this phone first, sent by itself.'}
      </Text>
      <Pressable onPress={() => router.push('/orders' as never)} role="link" aria-label="Read the standing orders" hitSlop={14} style={s.link}>
        <Text face="Inter-Bold" size={12} color="text-2">{'Read the standing orders'}</Text>
      </Pressable>
    </View>
  )
}

const s = StyleSheet.create({
  wrap: { width: '100%' },
  row: { flexDirection: 'row', gap: 10 },
  deny: { height: 48, flexBasis: 110, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 14, borderWidth: 1, borderColor: c('border-600') },
  admit: { height: 48, flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 14, borderWidth: 1, borderColor: c('amber-500'), backgroundColor: a('amber-500', 0.16) },
  link: { alignSelf: 'center', marginTop: 12, minHeight: 20, justifyContent: 'center' },
})
