/**
 * The Pressable both apps use. Import it from `@gs/core`, NEVER from `react-native`
 * (the gate `tools/gates/no-rn-pressable.mjs` holds that line).
 *
 * WHY. NativeWind wraps React Native's Pressable on Android and iOS, and its wrapper reads
 * the `style` prop as a plain style object. A style FUNCTION — `style={({ pressed }) => …}`,
 * React Native's own idiom for press feedback — is not called: it is dropped, and the
 * control loses its box, its height and its colour. On web the wrapper is a pass-through,
 * so the fidelity harness cannot see it; it was found on a real phone (the
 * enrolment button drawn as bare text on a real handset, where the computer had drawn it correctly).
 *
 * So the function is resolved HERE, with the pressed state tracked by this component, and
 * what reaches React Native is always a plain style. Children given as a function —
 * `{({ pressed }) => <Card pressed={pressed}>…</Card>}`, a card that darkens as one piece
 * (M50 §6.7a) — are resolved the same way, from the same state.
 */

import { forwardRef, useCallback, useState } from 'react'
import { Pressable as RNPressable, type GestureResponderEvent, type PressableProps, type PressableStateCallbackType, type View } from 'react-native'

// `hovered` exists only in react-native-web's typing of the state; no screen here reads it.
const IDLE = { pressed: false, hovered: false } as PressableStateCallbackType
const DOWN = { pressed: true, hovered: false } as PressableStateCallbackType

export type { PressableProps }

export const Pressable = forwardRef<View, PressableProps>(function Pressable({ style, children, onPressIn, onPressOut, ...rest }, ref) {
  const [pressed, setPressed] = useState(false)

  const pressIn = useCallback(
    (e: GestureResponderEvent) => {
      setPressed(true)
      onPressIn?.(e)
    },
    [onPressIn],
  )
  const pressOut = useCallback(
    (e: GestureResponderEvent) => {
      setPressed(false)
      onPressOut?.(e)
    },
    [onPressOut],
  )

  const state = pressed ? DOWN : IDLE
  const resolved = typeof style === 'function' ? style(state) : style
  const content = typeof children === 'function' ? children(state) : children
  return <RNPressable ref={ref} {...rest} onPressIn={pressIn} onPressOut={pressOut} style={resolved}>{content}</RNPressable>
})
