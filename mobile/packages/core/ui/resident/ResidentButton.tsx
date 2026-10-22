/**
 * The Resident App's buttons — ONE SYSTEM OF THREE (M18D §1), so a resident can tell at a glance which control does the
 * thing, which is the other way, and which is only a link:
 *
 *   primary     THE action on a card or screen — one of them. 50 tall, radius 15, navy-600, Poppins-SemiBold 14.5
 *               white (ls 0.2), navy glow. Disabled: navy-200, no glow. "Pay $3,400", "Send the invite".
 *   secondary   the other way: 50 tall, radius 15, white on a navy-600 border, Inter-SemiBold 13.5 navy-700, icon gap 9.
 *               ("outline" is its old name, kept so the lifted boards still read.)
 *   tertiary    a link, no box: 44 tall for the thumb, Inter-SemiBold 13.5 navy-700, underlined. "Not now", "Got it".
 *   destructive the act that cannot be undone (leave / cancel / delete): a red OUTLINE, white with a red-700 border and
 *               red-700 words. Never solid red (M98, 12 Oct 2026): solid red is an open panic's colour and nothing else.
 *   danger      as primary in solid red-700: ONLY the control that raises an emergency alert.
 *
 * INVARIANT 11 — no dead controls: `disabledReason` is required whenever `disabled` is set,
 * and is what a screen reader says. Haptic on press.
 *
 * It takes a `ref` (React 19 passes it as a prop), so the control that opened a sheet can be given focus back.
 */

import type { Ref } from 'react'
import { ActivityIndicator, type StyleProp, type View, type ViewStyle } from 'react-native'
import * as Haptics from 'expo-haptics'
import { palette, type ColorToken } from '../../theme/colors'
import { sh } from '../../theme/shadows'
import { Icon } from '../Icon'
import { Pressable } from '../Pressable'
import { Text } from '../Text'
import type { IconName } from '../icons/registry'

export type ResidentButtonVariant = 'primary' | 'secondary' | 'tertiary' | 'outline' | 'destructive' | 'danger'

type Base = { label: string; onPress: () => void; loading?: boolean; icon?: IconName; iconColor?: ColorToken; style?: StyleProp<ViewStyle>; variant?: ResidentButtonVariant; ref?: Ref<View> }
type Props = Base & ({ disabled?: false; disabledReason?: never } | { disabled: boolean; disabledReason: string })

/** The one red: solid for the emergency alert's button, an outline and its words for a destructive act. */
const RED: ColorToken = 'red-700'

export function ResidentButton({ label, onPress, loading, icon, iconColor, style, variant = 'primary', disabled, disabledReason, ref }: Props) {
  const off = !!disabled || !!loading
  const solid = variant === 'primary' || variant === 'danger'
  const link = variant === 'tertiary'
  const fill: ColorToken = variant === 'danger' ? RED : 'navy-600'
  // The boards' own outline keeps its navy-200 hairline; the live secondary is drawn on navy-600, so it reads as a button.
  const edge: ColorToken = variant === 'outline' ? 'navy-200' : variant === 'destructive' ? RED : 'navy-600'

  const box: ViewStyle = solid
    ? { backgroundColor: palette[disabled ? 'navy-200' : fill], ...(disabled ? null : { boxShadow: sh([{ x: 0, y: 10, blur: 20, spread: 0, token: fill, opacity: 0.32 }]) }) }
    : link
      ? { minHeight: 44, paddingVertical: 10 }
      : { backgroundColor: palette.white, borderWidth: variant === 'outline' ? 1 : 1.5, borderColor: palette[disabled ? 'navy-200' : edge] }

  const ink: ColorToken = solid ? 'white' : disabled ? 'slate-500' : variant === 'destructive' ? RED : 'navy-700'

  /*
   * minHeight, not height. 50 is what the board draws at the board's own text size, and a FIXED
   * height slices the second line off a long label when the phone's text size is turned up — on the
   * Sign out panel that second line is the warning that unsent work will be destroyed (2026-09-22).
   */
  return (
    <Pressable
      ref={ref}
      onPress={() => {
        void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => undefined)
        onPress()
      }}
      disabled={off}
      role="button"
      aria-label={label}
      aria-disabled={off}
      aria-busy={!!loading}
      accessibilityHint={disabled ? disabledReason : undefined}
      style={({ pressed }) => [{ minHeight: 50, paddingVertical: 12, borderRadius: 15, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 9, opacity: pressed ? 0.85 : 1 }, box, style]}
    >
      {loading ? (
        <ActivityIndicator color={palette[ink]} />
      ) : (
        <>
          {icon ? <Icon name={icon} size={18} color={iconColor ?? (solid ? 'white' : variant === 'destructive' ? RED : 'amber-600')} /> : null}
          {solid ? (
            <Text face="Poppins-SemiBold" size={14.5} ls={0.2} color={ink}>{label}</Text>
          ) : link ? (
            <Text face="Inter-SemiBold" size={13.5} color={ink} style={{ textDecorationLine: 'underline' }}>{label}</Text>
          ) : (
            <Text face="Inter-SemiBold" size={13.5} color={ink}>{label}</Text>
          )}
        </>
      )}
    </Pressable>
  )
}
