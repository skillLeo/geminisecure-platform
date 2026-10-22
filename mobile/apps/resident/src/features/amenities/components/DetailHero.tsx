/**
 * Board 22's hero card (`.detail-hero`), every value from the design tokens: 150 points tall in the hero gradient,
 * radius 16, 14 in, 16 above what follows. The place's picture is a 44-point watermark 14 in from the top right, in
 * the board's 35% gold; at the bottom left, the name (Poppins 600 18, white) and the line under it (11, white at 75%,
 * 2 below).
 */

import { StyleSheet, View } from 'react-native'
import { Gradient, Icon, Text, type IconName } from '@gs/core'
import { font, gradient, icon, ink, inkAlpha, radius, size, space } from '@/design/tokens'

/**
 * `.detail-hero svg.bgicon { color: rgba(255,182,39,.35) }` — the design's gold at 35% (inkAlpha.heroWatermark). An
 * Icon takes a palette colour, not a translucent one, so the 35% sits on its wrapper: the same picture on a phone.
 */
const WATERMARK = 0.35

export function DetailHero({ name, meta, picture }: { name: string; meta: string; picture: IconName }) {
  return (
    <Gradient spec={gradient.hero} style={s.hero}>
      <View style={s.watermark}>
        <Icon name={picture} size={icon[44]} color={ink.accent} />
      </View>
      <View style={s.text}>
        <Text {...font.poppins600_18} color={ink.onDark} role="heading">{name}</Text>
        {meta ? (
          <View style={s.meta}>
            <Text {...font.inter400_11} style={s.metaInk}>{meta}</Text>
          </View>
        ) : null}
      </View>
    </Gradient>
  )
}

const s = StyleSheet.create({
  hero: { height: size[150], flexDirection: 'row', alignItems: 'flex-end', borderRadius: radius.card, overflow: 'hidden', padding: space[14], marginBottom: space[16] },
  watermark: { position: 'absolute', top: space[14], right: space[14], opacity: WATERMARK },
  text: { flex: 1, minWidth: 0 },
  meta: { marginTop: space[2] },
  metaInk: { color: inkAlpha.onDarkMeta },
})
