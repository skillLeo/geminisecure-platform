/**
 * The states every screen has besides "populated": loading, empty, error.
 * A skeleton, never a centred spinner. An error is a plain sentence and a way forward —
 * never a raw API message (api/errors.ts has already replaced anything that was one).
 * Both surfaces: `tone="dark"` for the Guard App, `tone="light"` for the Resident App.
 */

import { useEffect } from 'react'
import { View, type DimensionValue, type StyleProp, type ViewStyle } from 'react-native'
import { Pressable } from './Pressable'
import Animated, { Easing, cancelAnimation, useAnimatedStyle, useReducedMotion, useSharedValue, withRepeat, withTiming } from 'react-native-reanimated'
import { useReduceMotionPref } from './a11y'
import { palette, type ColorToken } from '../theme/colors'
import { isFidelity } from '../fidelity'
import { Icon } from './Icon'
import { Text } from './Text'
import type { IconName } from './icons/registry'

export type Tone = 'dark' | 'light'

const INK: Record<Tone, { bone: ColorToken; title: ColorToken; body: ColorToken; tile: ColorToken; icon: ColorToken; action: ColorToken }> = {
  dark: { bone: 'surface-700', title: 'white', body: 'text-2', tile: 'surface-800', icon: 'text-3', action: 'amber-500' },
  light: { bone: 'navy-100', title: 'navy-900', body: 'slate-500', tile: 'navy-100', icon: 'navy-600', action: 'navy-600' },
}

export function Skeleton({ tone, width = '100%', height, radius = 8, style }: { tone: Tone; width?: DimensionValue; height: number; radius?: number; style?: StyleProp<ViewStyle> }) {
  const pulse = useSharedValue(1)
  // Still when the PHONE asks for reduced motion (reanimated reads that) or the app's own setting does (ui/a11y.ts).
  const systemStill = useReducedMotion()
  const appStill = useReduceMotionPref()
  useEffect(() => {
    if (isFidelity) return
    if (systemStill || appStill) { cancelAnimation(pulse); pulse.value = 0.7; return }
    pulse.value = withRepeat(withTiming(0.45, { duration: 800, easing: Easing.inOut(Easing.quad) }), -1, true)
  }, [pulse, systemStill, appStill])
  const fade = useAnimatedStyle(() => ({ opacity: pulse.value }))
  return <Animated.View aria-hidden style={[{ width, height, borderRadius: radius, backgroundColor: palette[INK[tone].bone] }, fade, style]} />
}

/** A stack of skeleton bars standing in for a card's lines. */
export function SkeletonLines({ tone, lines = 3, style }: { tone: Tone; lines?: number; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={[{ gap: 10 }, style]} role="progressbar" aria-label="Loading">
      {Array.from({ length: lines }, (_, i) => (
        <Skeleton key={i} tone={tone} height={i === 0 ? 16 : 12} width={i === 0 ? '62%' : i % 2 ? '92%' : '78%'} />
      ))}
    </View>
  )
}

/** `body` may be left out: an empty list that needs one short line says it in the title alone (M50 §6.2). */
type Notice = { tone: Tone; icon: IconName; title: string; body?: string; action?: { label: string; onPress: () => void }; style?: StyleProp<ViewStyle> }

function NoticeView({ tone, icon, title, body, action, style }: Notice) {
  const ink = INK[tone]
  return (
    <View style={[{ alignItems: 'center', paddingVertical: 36, paddingHorizontal: 28 }, style]}>
      <View style={{ width: 64, height: 64, borderRadius: 32, alignItems: 'center', justifyContent: 'center', backgroundColor: palette[ink.tile], marginBottom: 14 }}>
        <Icon name={icon} size={26} color={ink.icon} />
      </View>
      <Text face="Poppins-SemiBold" size={15} color={ink.title} align="center" role="heading">{title}</Text>
      {body ? <Text face="Inter-Regular" size={12} lh={1.5} color={ink.body} align="center" style={{ marginTop: 6 }}>{body}</Text> : null}
      {action ? (
        <Pressable onPress={action.onPress} role="button" aria-label={action.label} hitSlop={10} style={{ marginTop: 16, minHeight: 44, justifyContent: 'center' }}>
          <Text face="Inter-Bold" size={13} color={ink.action}>{action.label}</Text>
        </Pressable>
      ) : null}
    </View>
  )
}

export const EmptyState = (p: Notice) => <NoticeView {...p} />

/** `message` is already a sentence written for the person holding the phone. */
export function ErrorView({ tone, message, onRetry, style }: { tone: Tone; message: string; onRetry?: () => void; style?: StyleProp<ViewStyle> }) {
  return <NoticeView tone={tone} icon="qa-tile-3" title="That didn't load" body={message} action={onRetry ? { label: 'Try again', onPress: onRetry } : undefined} style={style} />
}
