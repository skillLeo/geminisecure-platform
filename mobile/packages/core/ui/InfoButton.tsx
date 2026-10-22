/**
 * THE SMALL `i` (M32 F12) — what a feature is and what it is for, in both apps, one component.
 *
 * Beside the feature's heading: the existing icon set's `info` glyph at 16, in the secondary colour, and nothing else
 * on the screen moves for it — the touch area is the glyph's own box widened by `hitSlop`, never a box that pushes
 * the heading or the action under it. Tapped, a sheet says two or three sentences and, where it helps, what happens
 * next. The words are the app's, in one file of keyed topics (`src/info/topics.ts`), so a sentence is corrected in
 * one place.
 *
 * THE RULES (§14.2):
 *   - small: the glyph only, at the heading's height; it is never a second button beside the primary action;
 *   - the sheet is a React Native Modal, as `ConfirmSheet` is: the backdrop, Android's back and "Close" all close it,
 *     and it holds nothing — no question, no write;
 *   - it never opens over the panic or duress screen, and hides while something covers the app (the Resident App's
 *     lock), as every sheet does;
 *   - in a fidelity build it draws nothing, so no measured board changes by a pixel for having one.
 */

import { useRef, useState, type ReactNode } from 'react'
import { Modal, ScrollView, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native'
import { usePathname } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { isFidelity } from '../fidelity'
import { alpha, color, type ColorToken } from '../theme/colors'
import { useReduceMotionPref } from './a11y'
import { GuardButton } from './guard/GuardButton'
import { Icon } from './Icon'
import { useCovered } from './overlayCover'
import { Pressable } from './Pressable'
import { ResidentButton } from './resident/ResidentButton'
import { Text } from './Text'

/** One topic of an app's keyed file: what the feature is, in two or three sentences, and what happens next. */
export type InfoTopic = {
  title: string
  lines: readonly string[]
  next?: string
}

type Props = {
  topic: InfoTopic
  tone?: 'resident' | 'guard'
  /** Read out with the glyph — "About Panic". Defaults to "About " and the topic's title. */
  label?: string
  /** The glyph's colour where the heading is on a dark ground (the Resident App's navy bar: white). */
  glyph?: ColorToken
  /** The panic screen's own `i`, drawn only before anything is sent: the one sheet allowed on /panic. */
  emergencyScreen?: boolean
}

/** The glyph's size, and how far its touch area reaches past it on every side: 16 + 2 × 14 = 44. */
export const INFO_GLYPH = 16
export const INFO_REACH = 14

const EMERGENCY_ROUTE = /^\/(panic|duress)(\/|$)/

const INK: Record<'resident' | 'guard', { glyph: ColorToken; card: ColorToken; border: ColorToken; title: ColorToken; body: ColorToken; scrim: string }> = {
  resident: { glyph: 'slate-500', card: 'white', border: 'navy-100', title: 'navy-900', body: 'slate-600', scrim: alpha('navy-900', 0.55) },
  guard: { glyph: 'text-3', card: 'surface-800', border: 'border-700', title: 'white', body: 'text-2', scrim: alpha('surface-900', 0.7) },
}

export function InfoButton({ topic, tone = 'resident', label, glyph, emergencyScreen = false }: Props) {
  const [open, setOpen] = useState(false)
  const opener = useRef<View>(null)

  if (isFidelity) return null

  const ink = INK[tone]
  const name = label ?? `About ${topic.title}`

  return (
    <>
      <Pressable
        ref={opener}
        onPress={() => setOpen(true)}
        role="button"
        aria-label={name}
        accessibilityHint="Opens a short explanation"
        hitSlop={INFO_REACH}
        testID="info-button"
        style={s.glyph}
      >
        <Icon name="info" size={INFO_GLYPH} color={glyph ?? ink.glyph} />
      </Pressable>
      {/* Mounted only while open: a closed `i` is the glyph and nothing else. */}
      {open ? <InfoSheet topic={topic} tone={tone} open onClose={() => setOpen(false)} emergencyScreen={emergencyScreen} /> : null}
    </>
  )
}

/**
 * A heading with its `i` beside it. In a fidelity build it is the heading alone, exactly as it was — no row, no wrapper
 * — so the measured board does not move; otherwise a row, the heading first and shrinking, the glyph after it. The row is
 * no taller than the heading: the glyph is 16 and sits on the heading's own line.
 */
export function InfoHeading({ topic, tone = 'resident', label, glyph, emergencyScreen, style, children }: Props & { style?: StyleProp<ViewStyle>; children: ReactNode }) {
  if (isFidelity) return <>{children}</>

  return (
    <View style={[s.row, style]}>
      <View style={s.rowText}>{children}</View>
      <InfoButton topic={topic} tone={tone} label={label} glyph={glyph} emergencyScreen={emergencyScreen} />
    </View>
  )
}

/** The sheet itself, for a caller that opens it from somewhere other than the glyph. */
export function InfoSheet({ topic, tone = 'resident', open, onClose, emergencyScreen = false }: { topic: InfoTopic; tone?: 'resident' | 'guard'; open: boolean; onClose: () => void; emergencyScreen?: boolean }) {
  const insets = useSafeAreaInsets()
  const still = useReduceMotionPref()
  const covered = useCovered()
  const pathname = usePathname() ?? ''

  if (!open || covered || (!emergencyScreen && EMERGENCY_ROUTE.test(pathname))) return null

  const ink = INK[tone]

  return (
    <Modal visible transparent animationType={still ? 'none' : 'fade'} statusBarTranslucent onRequestClose={onClose}>
      <View style={[s.scrim, { backgroundColor: ink.scrim }]}>
        {/* The backdrop closes. Not a control: hidden from screen readers, and no tab stop on the web. */}
        <View aria-hidden onStartShouldSetResponder={() => true} onResponderRelease={onClose} testID="info-sheet-backdrop" style={StyleSheet.absoluteFill} />
        <View role="dialog" aria-modal aria-label={topic.title} testID="info-sheet" style={[s.card, { backgroundColor: color(ink.card), borderColor: color(ink.border), paddingBottom: insets.bottom + 20 }]}>
          <ScrollView style={s.body} bounces={false}>
            <Text face="Poppins-SemiBold" size={16} lh={1.35} color={ink.title} role="heading">{topic.title}</Text>
            {topic.lines.map((line, i) => (
              <Text key={`${i}-${line}`} face="Inter-Regular" size={13} lh={1.5} color={ink.body} style={s.line}>{line}</Text>
            ))}
            {topic.next ? (
              <>
                <Text face="Inter-Bold" size={11} ls={0.4} color={ink.title} style={s.nextHead}>{'WHAT HAPPENS NEXT'}</Text>
                <Text face="Inter-Regular" size={13} lh={1.5} color={ink.body} style={s.lineTight}>{topic.next}</Text>
              </>
            ) : null}
          </ScrollView>
          {tone === 'guard' ? (
            <GuardButton variant="outline" label="Close" onPress={onClose} style={s.close} />
          ) : (
            <ResidentButton variant="outline" label="Close" onPress={onClose} style={s.close} />
          )}
        </View>
      </View>
    </Modal>
  )
}

const s = StyleSheet.create({
  glyph: { width: INFO_GLYPH, height: INFO_GLYPH, alignItems: 'center', justifyContent: 'center' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  rowText: { flexShrink: 1 },
  scrim: { flex: 1, justifyContent: 'flex-end', alignItems: 'center' },
  card: { width: '100%', maxWidth: 520, maxHeight: '80%', borderTopLeftRadius: 20, borderTopRightRadius: 20, borderWidth: 1, borderBottomWidth: 0, paddingTop: 20, paddingHorizontal: 20 },
  body: { flexGrow: 0, flexShrink: 1 },
  line: { marginTop: 8 },
  lineTight: { marginTop: 4 },
  nextHead: { marginTop: 14 },
  close: { marginTop: 18 },
})
