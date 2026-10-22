/**
 * `.tl2` — the status timeline on the Resident boards (Maintenance status, Incident status), for the rebuilt screens:
 * a 21-point dot per step — done navy-600 with a white tick, active gold with a navy mark, pending white with a pale
 * navy edge — joined by a 1.5-point navy-100 line; the step's title (12.5 · 600 · navy-900, faint when pending) over
 * its line (10.5 · slate-500). 20 between steps.
 */

import { StyleSheet, View } from 'react-native'
import { Icon, Text, type IconName } from '@gs/core'
import { fill, font, icon, ink, line, radius, size, space, stroke } from '../tokens'

export type TimelineStep = { title: string; line?: string | null; state: 'done' | 'active' | 'pending'; activeIcon?: IconName }

export function Timeline({ steps }: { steps: TimelineStep[] }) {
  return (
    <View style={s.wrap}>
      {steps.map((step, i) => {
        const last = i === steps.length - 1
        return (
          <View key={`${i}-${step.title}`} style={last ? s.rowLast : s.row}>
            {last ? null : <View style={s.rule} />}
            <View style={step.state === 'done' ? s.dotDone : step.state === 'active' ? s.dotActive : s.dotPending}>
              {step.state === 'done' ? <Icon name="check" size={icon[12]} color={ink.onDark} /> : null}
              {step.state === 'active' ? <Icon name={step.activeIcon ?? 'recap-item'} size={icon[10]} color={ink.heading} /> : null}
            </View>
            <View style={s.text}>
              <Text {...font.inter600_12_5} color={step.state === 'pending' ? ink.faint : ink.heading}>{step.title}</Text>
              {step.line ? <View style={s.sub}><Text {...font.inter400_10_5} color={ink.secondary}>{step.line}</Text></View> : null}
            </View>
          </View>
        )
      })}
    </View>
  )
}

const dot = { width: size[21], height: size[21], borderRadius: radius.avatar(size[21]), flexShrink: 0, alignItems: 'center', justifyContent: 'center', zIndex: 1 } as const

const s = StyleSheet.create({
  wrap: { paddingTop: space[4], paddingHorizontal: space[4] },
  row: { flexDirection: 'row', gap: space[12], paddingBottom: space[20] },
  rowLast: { flexDirection: 'row', gap: space[12] },
  rule: { position: 'absolute', left: space[10], top: space[22], bottom: 0, width: size['1.5'], backgroundColor: fill.tint },
  dotDone: { ...dot, backgroundColor: fill.primary },
  dotActive: { ...dot, backgroundColor: fill.gold },
  dotPending: { ...dot, backgroundColor: fill.surface, borderWidth: stroke['1.5'], borderColor: line.outline },
  text: { flex: 1, minWidth: 0 },
  sub: { marginTop: space[2] },
})
