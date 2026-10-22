/**
 * The Resident App's labelled input, from the boards (01 Onboarding and Auth · .field / .input):
 *   label   Inter-SemiBold 12, slate-600, 6 above the box
 *   box     48 tall, radius 14, 14 either side, an 18px navy-500 icon 10 before the text
 *           idle     navy-100 fill, no border
 *           focused  white fill, 1px amber-500 border and a 3px amber glow at 18%
 *           error    white fill, 1px amber-700 border; the message, amber-700, sits under the box. Never red (M98,
 *                    12 Oct 2026): a wrong entry drawn in a panic's red would teach that red means "you typed it wrong"
 *   text    Inter-Medium 14.5 text-primary; placeholder Inter-Regular text-placeholder (M18B: the one lighter value)
 *
 * `forceFocused` draws the focused state without focus — the boards draw one field focused,
 * and the fidelity harness has no keyboard.
 */

import { forwardRef, useState, type ReactNode } from 'react'
import { TextInput, View, type TextInputProps } from 'react-native'
import { palette } from '../../theme/colors'
import { sh } from '../../theme/shadows'
import { Icon } from '../Icon'
import { Text } from '../Text'
import type { IconName } from '../icons/registry'

type Props = Omit<TextInputProps, 'style'> & {
  label: string
  icon?: IconName
  error?: string | null
  hint?: string | null
  forceFocused?: boolean
  /** Something at the right edge of the box — the eye of a secret field, a unit, a clear button. */
  trailing?: ReactNode
  marginTop?: number
}

export const ResidentField = forwardRef<TextInput, Props>(function ResidentField({ label, icon, error, hint, forceFocused, trailing, marginTop = 15, onFocus, onBlur, ...input }, ref) {
  const [focused, setFocused] = useState(false)
  const lit = focused || !!forceFocused
  const filled = typeof input.value === 'string' && input.value.length > 0

  return (
    <View style={{ marginTop }}>
      <Text face="Inter-SemiBold" size={12} ls={0.1} color="slate-600">{label}</Text>
      <View
        style={{
          height: 48,
          flexDirection: 'row',
          alignItems: 'center',
          borderRadius: 14,
          borderWidth: 1,
          paddingHorizontal: 14,
          marginTop: 6,
          backgroundColor: palette[lit || error ? 'white' : 'navy-100'],
          borderColor: error ? palette['amber-700'] : lit ? palette['amber-500'] : 'transparent',
          ...(lit && !error ? { boxShadow: sh([{ x: 0, y: 0, blur: 0, spread: 3, token: 'amber-500', opacity: 0.18 }]) } : null),
        }}
      >
        {icon ? <Icon name={icon} size={18} color="navy-500" style={{ marginRight: 10 }} /> : null}
        <TextInput
          ref={ref}
          {...input}
          onFocus={(e) => {
            setFocused(true)
            onFocus?.(e)
          }}
          onBlur={(e) => {
            setFocused(false)
            onBlur?.(e)
          }}
          aria-label={input['aria-label'] ?? label}
          aria-invalid={!!error}
          placeholderTextColor={palette['text-placeholder']}
          selectionColor={palette['amber-600']}
          maxFontSizeMultiplier={1.3}
          style={{ flex: 1, height: 46, padding: 0, fontFamily: filled ? 'Inter-Medium' : 'Inter-Regular', fontSize: 14.5, color: palette['text-primary'] }}
        />
        {trailing}
      </View>
      {error ? (
        <Text face="Inter-Medium" size={11.5} lh={1.45} color="amber-700" style={{ marginTop: 5 }} role="alert">{error}</Text>
      ) : hint ? (
        <Text face="Inter-Regular" size={11.5} lh={1.45} color="slate-500" style={{ marginTop: 5 }}>{hint}</Text>
      ) : null}
    </View>
  )
})
