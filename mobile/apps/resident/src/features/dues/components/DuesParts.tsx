/**
 * Parts the five dues screens share. The header is the kit's; the board draws its back chevron at
 * 34, the kit at 18 — the board's size is used only for the fidelity measurement.
 */

import { StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native'
import { Icon, ResidentAppBar, Text, c, isFidelity, type ColorToken, type IconName } from '@gs/core'

export function DuesBar({ title, tab = false }: { title: string; tab?: boolean }) {
  const left = isFidelity ? { icon: 'chevron-left' as IconName, iconSize: 34, label: 'Back', onPress: () => undefined } : tab ? null : ('back' as const)
  return <ResidentAppBar title={title} left={left} fallback="/(tabs)/dues" />
}

/**
 * Three tones (M98, 12 Oct 2026): navy says, amber needs attention (every ordinary problem), and `failed` is the one red,
 * an OUTLINE: white with a red-700 edge, for a payment that failed. Never a red fill: that is an open panic's colour.
 */
const TONES: Record<'navy' | 'amber' | 'failed', { fill: ColorToken; ink: ColorToken; icon: ColorToken; edge?: ColorToken }> = {
  navy: { fill: 'navy-100', ink: 'navy-800', icon: 'navy-600' },
  amber: { fill: 'amber-100', ink: 'navy-900', icon: 'amber-700' },
  failed: { fill: 'rd-white', ink: 'navy-900', icon: 'rd-red-700', edge: 'rd-red-700' },
}

/** A plain-words note: no connection, a saved figure's age, the arrears warning. The board's `.confirm-note`, in three tones. */
export function DuesNote({ text, tone = 'navy', icon = 'why-note', alert = false, size = 11.5, style }: { text: string; tone?: 'navy' | 'amber' | 'failed'; icon?: IconName; alert?: boolean; size?: number; style?: StyleProp<ViewStyle> }) {
  const t = TONES[tone]
  return (
    <View style={[s.note, { backgroundColor: c(t.fill) }, t.edge ? { borderWidth: 1, borderColor: c(t.edge) } : null, style]} role={alert ? 'alert' : 'status'}>
      <Icon name={icon} size={15} color={t.icon} style={s.noteIcon} />
      <View style={s.fill}>
        <Text face="Inter-Medium" size={size} lh={1.5} color={t.ink}>{text}</Text>
      </View>
    </View>
  )
}

/** The small grey caption over a card ("Payment history"), with the age of what is under it when that is not from just now. */
export function DuesCaption({ title, age, style }: { title: string; age?: string | null; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={[s.caption, style]}>
      <Text face="Inter-Bold" size={11.5} ls={0.2} color="slate-500" role="heading">{title}</Text>
      {age ? <Text face="Inter-SemiBold" size={10.5} color="amber-700">{`Saved ${age}: may have changed`}</Text> : null}
    </View>
  )
}

const s = StyleSheet.create({
  fill: { flex: 1 },
  note: { flexDirection: 'row', borderRadius: 12, paddingHorizontal: 12, paddingVertical: 11, gap: 9 },
  noteIcon: { width: 15, height: 15, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  caption: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', columnGap: 8, marginHorizontal: 2 },
})
