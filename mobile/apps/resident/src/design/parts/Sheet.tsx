/**
 * A sheet that SAYS something, in the app's own look (M50 §4.3, U4: no grey Android dialogs). The phone's own dialog is
 * drawn in the phone's system font — on the client's Samsung a handwritten one — and in nothing of the design's.
 *
 * No board draws one, so it is ChooserSheet's sheet: the design's veil, a white sheet from the bottom with Foundation's
 * modal corners (20), the title in the card-title type, its sentences under it, at most a couple of ways on (each an
 * outline button, which closes the sheet as it acts), then "Close". The backdrop, Android's back button and VoiceOver's
 * escape close it too. With nothing to say it renders nothing.
 *
 *   const note = useNote()
 *   note.show({ title: 'About GeminiSecure', lines: [m.about] })
 *   <NoteSheet note={note.note} onClose={note.close} />
 */

import { useCallback, useState } from 'react'
import { Modal, StyleSheet, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Text, useReduceMotionPref } from '@gs/core'
import { corner, fill, font, ink, role, space } from '../tokens'
import { PrimaryButton, SecondaryButton } from './Buttons'

export type NoteAction = { label: string; onPress: () => void }
export type Note = { title: string; lines: readonly string[]; actions?: readonly NoteAction[]; closeLabel?: string }

/** The design's own veil — navy-900 at .35, drawn over the live room's thumbnails (as ChooserSheet's). */
const VEIL = fill.videoThumb

export function NoteSheet({ note, onClose }: { note: Note | null; onClose: () => void }) {
  const insets = useSafeAreaInsets()
  const still = useReduceMotionPref()
  if (!note) return null

  return (
    <Modal visible transparent animationType={still ? 'none' : 'fade'} statusBarTranslucent onRequestClose={onClose}>
      <View style={s.veil}>
        {/* The backdrop closes it. Not a control: hidden from screen readers, never a tab stop (as ConfirmSheet's). */}
        <View aria-hidden onStartShouldSetResponder={() => true} onResponderRelease={onClose} style={StyleSheet.absoluteFill} />
        <View role="dialog" aria-modal aria-label={note.title} onAccessibilityEscape={onClose} style={[s.sheet, { paddingBottom: space[20] + insets.bottom }]}>
          <Text {...role.cardTitle} color={ink.heading} role="heading">{note.title}</Text>
          {note.lines.map((line, i) => (
            <View key={`${i}-${line}`} style={s.line}>
              <Text {...font.inter400_12_5_lh1_5} color={ink.label}>{line}</Text>
            </View>
          ))}
          <View style={s.buttons}>
            {(note.actions ?? []).map((action) => (
              <SecondaryButton
                key={action.label}
                label={action.label}
                onPress={() => {
                  onClose()
                  action.onPress()
                }}
              />
            ))}
            <PrimaryButton label={note.closeLabel ?? 'Close'} onPress={onClose} />
          </View>
        </View>
      </View>
    </Modal>
  )
}

/** One sheet per screen: `show` puts a note up (replacing any already up), `close` takes it down. */
export function useNote() {
  const [note, setNote] = useState<Note | null>(null)
  const close = useCallback(() => setNote(null), [])
  const show = useCallback((next: Note) => setNote(next), [])
  return { note, show, close }
}

const s = StyleSheet.create({
  veil: { flex: 1, justifyContent: 'flex-end', backgroundColor: VEIL },
  sheet: { maxHeight: '85%', backgroundColor: fill.surface, borderTopLeftRadius: corner[20], borderTopRightRadius: corner[20], paddingTop: space[20], paddingHorizontal: space[20] },
  line: { marginTop: space[8] },
  buttons: { gap: space[10], marginTop: space[18] },
})
