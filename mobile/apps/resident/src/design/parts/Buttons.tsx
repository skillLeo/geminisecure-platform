/**
 * The Resident boards' two buttons, for the rebuilt screens (M44 §1):
 *
 *   PrimaryButton    .btn-primary   50 tall, radius 15, navy-600 with its .32 glow, Poppins 600 14.5 white
 *   SecondaryButton  .btn-outline   50 tall, radius 15, white with a 1.5 navy-200 edge, Inter 600 13.5 navy-700,
 *                                   an optional 17-point navy-600 icon before the words
 *   ButtonRow        .btn-row       two side by side, 10 apart, each half, 6 below what precedes it
 *
 * `look="auth"` is the sign-in boards' own pair (.auth-body .btn-primary / .btn-outline): the primary's words carry .2
 * letter-spacing, and the outline's icon is 18 and gold (the fingerprint on "Log in with biometrics").
 */

import { Children, type ReactNode } from 'react'
import { ActivityIndicator, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native'
import { Icon, Pressable, Text, c, type IconName } from '@gs/core'
import { fill, icon, ink, line, radius, role, shadow, size, space, stroke } from '../tokens'

/** `name`: what a screen reader says when the words on the button need their object ("Remove Soft Dev from your household"). */
type ButtonProps = { label: string; name?: string; onPress?: () => void; loading?: boolean; disabled?: boolean; style?: StyleProp<ViewStyle>; accessibilityHint?: string; look?: 'auth' }

export function PrimaryButton({ label, name, onPress, loading, disabled, style, accessibilityHint, look }: ButtonProps) {
  const off = disabled || loading
  return (
    <Pressable onPress={off ? undefined : onPress} role="button" aria-label={name ?? label} aria-disabled={off} aria-busy={loading} accessibilityHint={accessibilityHint} style={({ pressed }) => [s.primary, pressed && !off ? s.pressed : null, disabled ? s.disabled : null, style]}>
      {loading ? <ActivityIndicator color={c(ink.onDark)} /> : <Text {...(look === 'auth' ? role.buttonAuth : role.button)} color={ink.onDark} numberOfLines={1}>{label}</Text>}
    </Pressable>
  )
}

export function SecondaryButton({ label, name, onPress, loading, disabled, style, icon: glyph, accessibilityHint, look }: ButtonProps & { icon?: IconName }) {
  const off = disabled || loading
  const auth = look === 'auth'
  return (
    <Pressable onPress={off ? undefined : onPress} role="button" aria-label={name ?? label} aria-disabled={off} aria-busy={loading} accessibilityHint={accessibilityHint} style={({ pressed }) => [s.secondary, pressed && !off ? s.pressed : null, disabled ? s.disabled : null, style]}>
      {loading ? (
        <ActivityIndicator color={c(ink.action)} />
      ) : (
        <>
          {glyph ? <Icon name={glyph} size={auth ? icon[18] : icon[17]} color={auth ? ink.link : ink.action} /> : null}
          <Text {...role.buttonOutline} color={ink.onTint} numberOfLines={1}>{label}</Text>
        </>
      )}
    </Pressable>
  )
}

/** `.btn-row .btn-outline, .btn-row .btn-primary { flex: 1 }`: each button takes an equal share. */
export function ButtonRow({ children, style }: { children: ReactNode; style?: StyleProp<ViewStyle> }) {
  return <View style={[s.row, style]}>{Children.map(children, (child) => (child ? <View style={s.half}>{child}</View> : null))}</View>
}

const s = StyleSheet.create({
  primary: { height: size[50], flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: fill.primary, borderRadius: radius.button, ...shadow.primaryButton },
  secondary: { height: size[50], flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: space[9], backgroundColor: fill.surface, borderRadius: radius.button, borderWidth: stroke['1.5'], borderColor: line.outline },
  pressed: { opacity: 0.88 },
  disabled: { opacity: 0.5 },
  row: { width: '100%', flexDirection: 'row', gap: space[10], marginTop: space[6] },
  half: { flex: 1, minWidth: 0 },
})
