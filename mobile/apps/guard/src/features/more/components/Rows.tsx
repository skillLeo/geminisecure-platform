/**
 * The boards' settings furniture (guard-app-25 · 26 · 27 · 36): a section heading, a card, a row with an
 * icon tile and a chevron, the hairline between rows, and the boards' own switch. Styles are the boards'.
 */

import type { ReactNode } from 'react'
import { StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native'
import { Icon, Pressable, Text, c, type IconName } from '@gs/core'

export const Heading = ({ children, top = 12 }: { children: string; top?: number }) => (
  <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={{ marginTop: top, marginLeft: 2, marginRight: 2 }}>{children}</Text>
)

export const ListCard = ({ children, style }: { children: ReactNode; style?: StyleProp<ViewStyle> }) => <View style={[s.listCard, style]}>{children}</View>
export const Divider = ({ inset = 46 }: { inset?: number }) => <View style={[s.setDiv, { marginLeft: inset }]} />

type RowProps = { icon: IconName; title: string; detail?: string; onPress: () => void; vertical?: number; titleSize?: number; badge?: string | null }

export function NavRow({ icon, title, detail, onPress, vertical = 12, titleSize = 13, badge }: RowProps) {
  return (
    <Pressable onPress={onPress} role="button" aria-label={detail ? `${title}. ${detail}` : title} style={({ pressed }) => [s.setRow, { paddingVertical: vertical, opacity: pressed ? 0.75 : 1 }]}>
      <View style={s.setIcon}><Icon name={icon} size={16} color="amber-500" style={s.setIconSvg} /></View>
      <View style={s.fill}>
        <Text face="Inter-SemiBold" size={titleSize} color="white">{title}</Text>
        {detail ? <Text face="Inter-Regular" size={10.5} color="text-3" style={{ marginTop: 1 }}>{detail}</Text> : null}
      </View>
      {badge ? <View style={s.badge}><Text face="Inter-Bold" size={9.5} color="g-navy-900">{badge}</Text></View> : null}
      <Icon name="chevron-right" size={16} color="text-3" style={s.chevSvg} />
    </Pressable>
  )
}

/** The boards' switch. `locked` is on and cannot be turned off — drawn greyed, as board 36 draws "Always on for safety". */
export function Switch({ on, locked = false, onChange, label }: { on: boolean; locked?: boolean; onChange?: (next: boolean) => void; label: string }) {
  const track = locked ? 'border-600' : on ? 'amber-500' : 'surface-700'
  const knob = locked ? 'text-3' : on ? 'g-navy-900' : 'text-3'
  return (
    <Pressable onPress={() => onChange?.(!on)} disabled={locked || !onChange} role="switch" aria-checked={on} aria-disabled={locked} aria-label={locked ? `${label}: always on` : label} hitSlop={12} style={[s.switch, { backgroundColor: c(track) }]}>
      <View style={[s.switchI, on || locked ? { right: 2.5 } : { left: 2.5 }, { backgroundColor: c(knob) }]} />
    </Pressable>
  )
}

const s = StyleSheet.create({
  fill: { flex: 1 },
  listCard: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13, paddingVertical: 2, marginTop: 8 },
  setIconSvg: { width: 16, height: 16, overflow: 'hidden' },
  setIcon: { width: 34, height: 34, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 10 },
  chevSvg: { width: 16, height: 16, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  setRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  setDiv: { height: 1, backgroundColor: c('border-700') },
  badge: { minWidth: 19, height: 19, paddingHorizontal: 4, alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), borderRadius: 9.5 },
  switchI: { position: 'absolute', top: 2.5, width: 18, height: 18, borderRadius: 9 },
  switch: { width: 40, height: 23, flexGrow: 0, flexShrink: 0, borderRadius: 20 },
})
