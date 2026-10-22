/**
 * What a dropdown on Invite visitor opens (M42 §5 #6; the user, 7 October: "a modest sheet built from tokens").
 *
 * No board draws one, and the app has none in the design's look (ConfirmSheet asks a question; it does not choose), so
 * it is the plainest thing the tokens make: a white sheet from the bottom with Foundation's modal corners (20), the
 * field's own name as its title, then one 48-point row per choice — the field's own type, a hairline between — and a
 * tick on the one chosen. Choosing closes it; so do the backdrop, Android's back button and VoiceOver's escape. A list
 * longer than the sheet (the days, the half hours — forty-nine rows at most) scrolls inside it, and opens at the one
 * already chosen.
 */

import { useRef } from 'react'
import { Modal, ScrollView, StyleSheet, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Icon, Pressable, Text, useReduceMotionPref } from '@gs/core'
import { corner, fill, font, icon, ink, line, role, size, space, stroke } from '@/design/tokens'

export type Choice = { key: string; label: string; on: boolean; pick: () => void }

const ROW = size[48]
/** The design's own veil — navy-900 at .35, drawn over the live room's thumbnails. No board draws a sheet. */
const VEIL = fill.videoThumb

export function ChooserSheet({ open, title, choices, onClose }: { open: boolean; title: string; choices: Choice[]; onClose: () => void }) {
  const insets = useSafeAreaInsets()
  const still = useReduceMotionPref()
  const list = useRef<ScrollView>(null)
  // Opened at the chosen one, two rows above it in sight — once the sheet and its rows both have their size.
  const offset = Math.max(0, choices.findIndex((choice) => choice.on) - 2) * ROW
  const toChosen = () => list.current?.scrollTo({ y: offset, animated: false })

  return (
    <Modal visible={open} transparent animationType={still ? 'none' : 'fade'} statusBarTranslucent onRequestClose={onClose}>
      <View style={s.veil}>
        {/* The backdrop closes it. Not a control: hidden from screen readers, never a tab stop (as ConfirmSheet's). */}
        <View aria-hidden onStartShouldSetResponder={() => true} onResponderRelease={onClose} style={StyleSheet.absoluteFill} />
        <View role="dialog" aria-modal aria-label={title} onAccessibilityEscape={onClose} style={[s.sheet, { paddingBottom: space[20] + insets.bottom }]}>
          <View style={s.title}><Text {...role.cardTitle} color={ink.heading} role="heading">{title}</Text></View>
          <ScrollView key={title} ref={list} testID="chooser-list" style={s.list} role="radiogroup" aria-label={title} onLayout={toChosen} onContentSizeChange={toChosen}>
            {choices.map((item) => (
              <Pressable key={item.key} onPress={item.pick} role="radio" aria-checked={item.on} aria-label={item.label} style={({ pressed }) => [s.row, pressed ? s.pressed : null]}>
                <View style={s.rowText}><Text {...font.inter500_14} color={ink.heading}>{item.label}</Text></View>
                {item.on ? <Icon name="check" size={icon[16]} color={ink.action} /> : null}
              </Pressable>
            ))}
          </ScrollView>
        </View>
      </View>
    </Modal>
  )
}

const s = StyleSheet.create({
  veil: { flex: 1, justifyContent: 'flex-end', backgroundColor: VEIL },
  sheet: { maxHeight: '75%', backgroundColor: fill.surface, borderTopLeftRadius: corner[20], borderTopRightRadius: corner[20], paddingTop: space[20], paddingHorizontal: space[20] },
  title: { paddingBottom: space[12] },
  list: { flexGrow: 0 },
  row: { height: ROW, flexDirection: 'row', alignItems: 'center', gap: space[12], borderTopWidth: stroke[1], borderTopColor: line.hairline },
  rowText: { flex: 1, minWidth: 0 },
  pressed: { opacity: 0.7 },
})
