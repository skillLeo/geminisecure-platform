/**
 * The Guard App's buttons, from the boards:
 *   primary   52 tall, radius 15, amber-500, Poppins-Bold 14.5 in g-navy-900, amber glow
 *             disabled: surface-700, label text-3, no glow (board 37)
 *   outline   48 tall, radius 14, 1.5 border-600, Inter-SemiBold 13 white, icon gap 9
 *   warning   46 tall, radius 14, 1 amber-500 border, Inter-SemiBold 13 amber (board 38)
 *   danger    as primary in red-700 with a white label — the act that cannot be undone, asked in a
 *             ConfirmSheet (sign out, remove the app from this phone). Disabled: as primary.
 *
 * INVARIANT 11 — no dead controls. A disabled button must say why: `disabledReason`
 * is required whenever `disabled` is set, and is read out as the accessibility hint.
 * Haptics on press (a gloved hand in the dark gets confirmation it can feel).
 *
 * It takes a `ref` (React 19 passes it as a prop), so the control that opened a sheet can be given focus back.
 */

import type { Ref } from 'react'
import { ActivityIndicator, type StyleProp, type View, type ViewStyle } from 'react-native'
import { Pressable } from '../Pressable'
import * as Haptics from 'expo-haptics'
import { palette, type ColorToken } from '../../theme/colors'
import { shadows } from '../../theme/shadows'
import { Icon } from '../Icon'
import { Text } from '../Text'
import type { IconName } from '../icons/registry'

type Base = { label: string; onPress: () => void; loading?: boolean; icon?: IconName; style?: StyleProp<ViewStyle>; variant?: 'primary' | 'outline' | 'warning' | 'danger'; ref?: Ref<View> }
type Props = Base & ({ disabled?: false; disabledReason?: never } | { disabled: boolean; disabledReason: string })

export function GuardButton({ label, onPress, loading, icon, style, variant = 'primary', disabled, disabledReason, ref }: Props) {
  const off = !!disabled || !!loading
  const solid = variant === 'primary' || variant === 'danger'
  const press = () => {
    void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium).catch(() => undefined)
    onPress()
  }

  const box: ViewStyle =
    variant === 'primary'
      ? { height: 52, borderRadius: 15, backgroundColor: palette[disabled ? 'surface-700' : 'amber-500'], ...(disabled ? null : shadows.amberButton) }
      : variant === 'danger'
        ? { height: 52, borderRadius: 15, backgroundColor: palette[disabled ? 'surface-700' : 'red-700'] }
        : variant === 'outline'
          ? { height: 48, borderRadius: 14, borderWidth: 1.5, borderColor: palette['border-600'] }
          : { height: 46, borderRadius: 14, borderWidth: 1, borderColor: palette['amber-500'] }

  const ink: ColorToken =
    variant === 'primary' ? (disabled ? 'text-3' : 'g-navy-900')
    : variant === 'danger' ? (disabled ? 'text-3' : 'white')
    : variant === 'outline' ? 'white'
    : 'amber-500'

  return (
    <Pressable
      ref={ref}
      onPress={press}
      disabled={off}
      role="button"
      aria-label={label}
      aria-disabled={off}
      aria-busy={!!loading}
      accessibilityHint={disabled ? disabledReason : undefined}
      style={({ pressed }) => [{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 9, opacity: pressed ? 0.85 : 1 }, box, style]}
    >
      {loading ? (
        <ActivityIndicator color={palette[ink]} />
      ) : (
        <>
          {icon ? <Icon name={icon} size={17} color={ink} /> : null}
          {solid ? (
            <Text face="Poppins-Bold" size={14.5} color={ink}>{label}</Text>
          ) : (
            <Text face="Inter-SemiBold" size={13} color={ink}>{label}</Text>
          )}
        </>
      )}
    </Pressable>
  )
}
