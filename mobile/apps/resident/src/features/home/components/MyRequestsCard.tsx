/**
 * HOME'S "My requests" CARD (M98, Hassam: "I am unable to track that ... there must be options to view the history or
 * list"): where a household finds what it has sent the estate, one tap from Home. "2 open · #1055 In progress", and
 * "Needs your confirmation" when a fixed job waits for their answer. Not drawn for a household that has never filed
 * anything, nor while the first answer is on its way, nor for an account that may not use requests.
 */
import { StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { Icon, Pressable, Text } from '@gs/core'
import { fill, font, ink, size, space } from '@/design/tokens'
import { useAccess } from '@/features/access/useAccess'
import { useRequestsSummary } from '@/features/maintenance/useAllRequests'

export function MyRequestsCard() {
  const { may } = useAccess()
  const allowed = may('tickets')
  const summary = useRequestsSummary(!allowed)
  if (!allowed || !summary) return null
  return (
    <Pressable onPress={() => router.push('/requests' as never)} role="link" aria-label={`My requests. ${summary.line}${summary.waitingOnYou ? '. A fixed job needs your confirmation' : ''}. Open`} style={({ pressed }) => [s.card, pressed ? s.pressed : null]}>
      <View style={s.tile}><Icon name="rp-icon" size={18} color={ink.action} /></View>
      <View style={s.words}>
        <Text {...font.inter700_12_5} color={ink.heading}>{'My requests'}</Text>
        <Text {...font.inter400_11_5} color={ink.secondary} numberOfLines={1} style={s.line}>{summary.line}</Text>
        {summary.waitingOnYou ? <Text {...font.inter600_10} color={ink.onGoldTint} style={s.line}>{'Needs your confirmation'}</Text> : null}
      </View>
      <Icon name="chevron-right" size={14} color={ink.secondary} />
    </Pressable>
  )
}

const s = StyleSheet.create({
  card: { flexDirection: 'row', alignItems: 'center', gap: space[11], backgroundColor: fill.surface, borderRadius: 16, borderWidth: 1, borderColor: fill.divider, padding: space[13], marginTop: space[11], minHeight: size[48] },
  pressed: { opacity: 0.7 },
  tile: { width: 40, height: 40, borderRadius: 12, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.tint },
  words: { flex: 1, minWidth: 0 },
  line: { marginTop: space[2] },
})