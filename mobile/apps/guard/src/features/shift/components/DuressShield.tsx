/**
 * The duress shield, where Home's is out of reach — the clock-in screen once the ordinary start has closed, and
 * the supervisor-override screen. A guard who cannot start a shift is still a guard at a gate: the alert must not
 * be one screen further away because a fingerprint did not match.
 *
 * It is Home's shield (board guard-app-05) at the same size and with the same two ways in: a tap opens the duress
 * screen; a thumb rested on it for two seconds raises the SILENT alert, and nothing visible or audible happens.
 */

import { StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import * as Haptics from 'expo-haptics'
import { Icon, InfoHeading, Pressable, Text, c, isFidelity, sh } from '@gs/core'
import { raiseDuress, SILENT_HOLD_MS } from '@/features/duress/useDuress'
import { INFO } from '@/info/topics'

export function DuressShield() {
  const open = () => {
    void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Heavy).catch(() => undefined)
    router.push('/duress' as never)
  }

  return (
    <View style={s.row}>
      <View style={s.words}>
        {/* The `i` sits with the words, never on the shield: nothing may stand between a thumb and it (M32 F12). */}
        <InfoHeading topic={INFO.duress} tone="guard" label="About the duress alert">
          <Text face="Inter-SemiBold" size={11.5} color="text-2">{'In danger?'}</Text>
        </InfoHeading>
        <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3">{'The duress alert works whether or not your shift has started.'}</Text>
      </View>
      {/* RED, as Home's shield is (the client, 27 Sep, and 6 Oct: "make them red"); the board's amber in the fidelity build. */}
      <Pressable onPress={open} onLongPress={() => void raiseDuress('silent')} delayLongPress={SILENT_HOLD_MS} role="button" aria-label="Duress alert" accessibilityHint="Opens the duress alert" style={({ pressed }) => [s.fab, isFidelity ? null : s.fabRed, { opacity: pressed ? 0.85 : 1 }]}>
        <Icon name="shield-alert" size={24} color={isFidelity ? 'g-navy-900' : 'white'} />
      </Pressable>
    </View>
  )
}

const s = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 14, marginTop: 22, paddingTop: 16, borderTopWidth: 1, borderTopColor: c('border-700') },
  words: { flex: 1 },
  // Home's shield: 56, the same glow — amber in the fidelity build, Home's `fabRed` live.
  fab: { width: 56, height: 56, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), borderRadius: 28, boxShadow: sh([{ x: 0, y: 10, blur: 22, spread: 0, token: 'amber-500', opacity: 0.4 }]) },
  fabRed: { backgroundColor: c('red-700'), boxShadow: sh([{ x: 0, y: 10, blur: 22, spread: 0, token: 'red-700', opacity: 0.45 }]) },
})
