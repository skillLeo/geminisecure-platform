/**
 * The Resident boards' form pieces, for the rebuilt screens (M44 §1), every value from the token module:
 *
 *   SectionLabel   .section-label            11.5 · 700 · slate-500, letter-spacing .2
 *   Field          .field label + .input     the label, then a 48-point box with its icon; focused it turns white
 *                                            with the gold ring. The placeholder is drawn by us in Inter, so a phone
 *                                            never falls back to its own font for it (Q12, answered 7 October).
 *   Segment        .seg / .seg-item          the four-way switch at the top of Invite visitor
 *   Chip           .cat-chip / .f-chip       the round choices; `small` is the filter row's
 *   Toggle         .switch                   off slate, on gold with a navy knob, locked pale navy
 */

import { forwardRef, useState, type ReactNode } from 'react'
import { StyleSheet, TextInput, View, type TextInputProps } from 'react-native'
import { Icon, Pressable, Text, c, type IconName } from '@gs/core'
import { fill, font, icon, ink, line, radius, role, shadow, size, space, stroke } from '../tokens'

/**
 * `.section-label { margin: 2px 2px 7px }`. In the board its 2-point top margin COLLAPSES into the card above (a card's
 * margin-bottom is 10 or 12), so after a card it adds nothing; React Native does not collapse margins, so the label
 * draws no top margin — and `first` gives it back where nothing sits above it (the first thing in a body).
 */
export function SectionLabel({ children, gap = 'normal', first = false }: { children: string; gap?: 'normal' | 'wide'; first?: boolean }) {
  return (
    <View style={[gap === 'wide' ? s.sectionWide : s.section, first ? s.sectionFirst : null]}>
      <Text {...role.sectionLabel} color={ink.secondary}>{children}</Text>
    </View>
  )
}

type FieldProps = Omit<TextInputProps, 'style' | 'placeholderTextColor'> & {
  label?: string
  /** The mark at the box's start — or none, where no mark says anything true about the field (M50 §5.3e). */
  icon?: IconName
  /** A chooser rather than a text box: the row opens a list (the design's chevron). */
  onPress?: () => void
  /** Open: drawn as the design draws a focused box. */
  open?: boolean
  chevron?: boolean
  error?: string | null
  /** Under the box, the design's spacing kept: the next field follows 15 below. */
  last?: boolean
  trailing?: ReactNode
}

export const Field = forwardRef<TextInput, FieldProps>(function Field({ label, icon: glyph, onPress, open, chevron, error, last, trailing, value, placeholder, onFocus, onBlur, ...input }, ref) {
  const [focused, setFocused] = useState(false)
  const lit = open || focused
  const box = [s.input, lit ? s.inputFocused : null, error ? s.inputError : null]
  const shown = typeof value === 'string' && value !== ''

  const inner = (
    <>
      {glyph ? <Icon name={glyph} size={icon[18]} color={ink.field} style={s.inputIcon} /> : null}
      <View style={s.inputText}>
        {onPress ? (
          <Text {...font.inter500_14} color={shown ? ink.heading : ink.secondary} numberOfLines={1}>{shown ? value : (placeholder ?? '')}</Text>
        ) : (
          <>
            {!shown && placeholder ? (
              <View style={s.placeholder}>
                <Text {...font.inter400_14} color={ink.secondary} numberOfLines={1}>{placeholder}</Text>
              </View>
            ) : null}
            <TextInput
              ref={ref}
              value={value}
              aria-label={label ?? placeholder}
              onFocus={(e) => { setFocused(true); onFocus?.(e) }}
              onBlur={(e) => { setFocused(false); onBlur?.(e) }}
              style={s.textInput}
              {...input}
            />
          </>
        )}
      </View>
      {trailing}
      {chevron ? <Icon name="chev" size={icon[16]} color={ink.secondary} style={s.chev} /> : null}
    </>
  )

  return (
    <View style={last ? null : s.field}>
      {label ? <View style={s.label}><Text {...role.label} color={ink.label}>{label}</Text></View> : null}
      {onPress ? (
        <Pressable onPress={onPress} role="button" aria-label={label ? `${label}: ${shown ? value : placeholder ?? ''}` : undefined} aria-expanded={open} style={box}>{inner}</Pressable>
      ) : (
        <View style={box}>{inner}</View>
      )}
      {error ? <View style={s.error}><Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{error}</Text></View> : null}
    </View>
  )
})

export function Segment<T extends string>({ items, value, onChange, label }: { items: { value: T; label: string }[]; value: T; onChange: (v: T) => void; label: string }) {
  return (
    <View style={s.seg} role="radiogroup" aria-label={label}>
      {items.map((item) => {
        const on = item.value === value
        return (
          <Pressable key={item.value} onPress={() => onChange(item.value)} role="radio" aria-checked={on} aria-label={item.label} style={on ? s.segOn : s.segItem}>
            <Text {...font.inter700_11_5} color={on ? ink.onDark : ink.secondary} align="center" numberOfLines={2}>{item.label}</Text>
          </Pressable>
        )
      })}
    </View>
  )
}

/** One choice of several (a radio) — or, `many`, one of several that may all be on (a checkbox). `name` is what is read out. */
export function Chip({ label, on, onPress, small, many, name }: { label: string; on?: boolean; onPress?: () => void; small?: boolean; many?: boolean; name?: string }) {
  const look = [small ? s.chipSmall : s.chip, on ? s.chipOn : null]
  const words = <Text {...(small ? font.inter600_11_5 : font.inter600_12)} color={on ? ink.onDark : ink.onTint} numberOfLines={1}>{label}</Text>
  return onPress ? (
    <Pressable onPress={onPress} role={many ? 'checkbox' : 'radio'} aria-checked={!!on} aria-label={name ?? label} hitSlop={size[4]} style={look}>{words}</Pressable>
  ) : (
    <View style={look}>{words}</View>
  )
}

/** Chips in a row that wraps (`wrap`) or scrolls in place — the caller puts it in a horizontal ScrollView. */
export function ChipRow({ children, wrap }: { children: ReactNode; wrap?: boolean }) {
  return <View style={wrap ? s.chipsWrap : s.chips}>{children}</View>
}

export function Toggle({ on, onChange, locked, label }: { on: boolean; onChange?: (next: boolean) => void; locked?: boolean; label: string }) {
  const track = locked ? s.trackLocked : on ? s.trackOn : s.track
  const knob = locked ? s.knobLocked : on ? s.knobOn : s.knob
  return (
    <Pressable onPress={locked || !onChange ? undefined : () => onChange(!on)} role="switch" aria-checked={on} aria-disabled={locked} aria-label={label} hitSlop={size[10]} style={track}>
      <View style={knob} />
    </Pressable>
  )
}

const s = StyleSheet.create({
  section: { marginHorizontal: space[2], marginBottom: space[7] },
  sectionWide: { marginHorizontal: space[2], marginBottom: space[8] },
  sectionFirst: { marginTop: space[2] },

  field: { marginBottom: space[15] },
  label: { marginBottom: space[6] },
  input: { height: size[48], flexDirection: 'row', alignItems: 'center', backgroundColor: fill.tint, borderRadius: radius.field, borderWidth: stroke['1.5'], borderColor: 'transparent', paddingHorizontal: space[14] },
  inputFocused: { backgroundColor: fill.surface, borderColor: line.focus, ...shadow.focusRing },
  // M42 §6.3: red is for life-safety only — "not for form errors". A field that needs attention takes the gold edge.
  inputError: { borderColor: line.focus },
  inputIcon: { marginRight: space[10] },
  inputText: { flex: 1, minWidth: 0, justifyContent: 'center' },
  // `pointerEvents` as a style, not a prop: React Native Web warns on the prop, and a warning puts its dev toast on screen.
  placeholder: { position: 'absolute', left: 0, right: 0, pointerEvents: 'none' },
  textInput: { fontFamily: font.inter500_14.face, fontSize: font.inter500_14.size, color: c(ink.heading), padding: 0, margin: 0 },
  chev: { marginLeft: 'auto', marginRight: space[10] },
  error: { marginTop: space[6] },

  seg: { flexDirection: 'row', backgroundColor: fill.tint, borderRadius: radius.segment, padding: space[4], marginBottom: space[20] },
  segItem: { flex: 1, borderRadius: radius.segmentItem, paddingHorizontal: space[4], paddingVertical: space[9] },
  segOn: { flex: 1, borderRadius: radius.segmentItem, paddingHorizontal: space[4], paddingVertical: space[9], backgroundColor: fill.primary, ...shadow.segmentActive },

  chip: { backgroundColor: fill.tint, borderRadius: radius.chip, paddingVertical: space[9], paddingHorizontal: space[14] },
  chipSmall: { backgroundColor: fill.tint, borderRadius: radius.chip, paddingVertical: space[8], paddingHorizontal: space[14] },
  chipOn: { backgroundColor: fill.primary },
  chips: { flexDirection: 'row', gap: space[8] },
  chipsWrap: { flexDirection: 'row', flexWrap: 'wrap', gap: space[8] },

  track: { width: size[40], height: size[23], borderRadius: radius.chip, backgroundColor: fill.switchOff, flexShrink: 0 },
  trackOn: { width: size[40], height: size[23], borderRadius: radius.chip, backgroundColor: fill.gold, flexShrink: 0 },
  trackLocked: { width: size[40], height: size[23], borderRadius: radius.chip, backgroundColor: fill.divider, flexShrink: 0 },
  knob: { position: 'absolute', top: space['2.5'], left: space['2.5'], width: size[18], height: size[18], borderRadius: radius.avatar(size[18]), backgroundColor: fill.surface },
  knobOn: { position: 'absolute', top: space['2.5'], right: space['2.5'], width: size[18], height: size[18], borderRadius: radius.avatar(size[18]), backgroundColor: fill.night },
  knobLocked: { position: 'absolute', top: space['2.5'], right: space['2.5'], width: size[18], height: size[18], borderRadius: radius.avatar(size[18]), backgroundColor: fill.dot },
})
