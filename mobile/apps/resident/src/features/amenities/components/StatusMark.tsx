/**
 * The mark at the top of board 31 for an answer that is not (yet) the estate's yes — the GoldTick part's geometry exactly
 * (`.check-wrap`: the two faint rings, 100 and 84, at 14% and 28%; the 62-point circle; a 30-point mark), with what is
 * true inside it. A request is a clock in the gold circle, never a tick. A declined booking is amber — an answer, not an
 * emergency (M12 §4.4, D-185). A cancelled one is grey.
 */

import { StyleSheet, View } from 'react-native'
import { Icon, type IconName } from '@gs/core'
import { fill, icon, ink, line, radius, size, space, stroke } from '@/design/tokens'
import type { AnswerMark } from '../useAmenities'

type Look = { glyph: IconName; mark: (typeof ink)[keyof typeof ink]; circle: string; ring: string }

const LOOK: Record<Exclude<AnswerMark, 'tick'>, Look> = {
  clock: { glyph: 'recap-item', mark: ink.heading, circle: fill.gold, ring: line.focus },
  declined: { glyph: 'shield-alert', mark: ink.onGoldTint, circle: fill.goldTint, ring: line.focus },
  cancelled: { glyph: 'confirm-note', mark: ink.label, circle: fill.tint, ring: line.muted },
}

export function StatusMark({ mark }: { mark: Exclude<AnswerMark, 'tick'> }) {
  const look = LOOK[mark]
  return (
    <View style={s.wrap}>
      <View style={[s.ringOuter, { borderColor: look.ring }]} />
      <View style={[s.ringInner, { borderColor: look.ring }]} />
      <View style={[s.circle, { backgroundColor: look.circle }]}>
        <Icon name={look.glyph} size={icon[30]} color={look.mark} />
      </View>
    </View>
  )
}

const s = StyleSheet.create({
  wrap: { width: size[84], height: size[84], alignItems: 'center', justifyContent: 'center', marginBottom: space[16] },
  ringOuter: { position: 'absolute', width: size[100], height: size[100], borderRadius: radius.avatar(size[100]), borderWidth: stroke['1.5'], opacity: 0.14 },
  ringInner: { position: 'absolute', width: size[84], height: size[84], borderRadius: radius.avatar(size[84]), borderWidth: stroke['1.5'], opacity: 0.28 },
  circle: { width: size[62], height: size[62], borderRadius: radius.avatar(size[62]), alignItems: 'center', justifyContent: 'center', zIndex: 2 },
})
