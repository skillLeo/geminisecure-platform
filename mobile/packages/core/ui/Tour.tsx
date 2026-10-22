/**
 * THE FIRST-RUN TOUR (M32 F11) — a short guided look round, in both apps, one component.
 *
 * Five cards at most, each one short sentence beside an illustration built from the existing icon set. Next and Skip on
 * every card, Back on every card after the first: Skip works from the first; in the Guard App Back is there on the first
 * too (off, and saying why), in the Resident App it is not drawn there (M47 §3.4); and the last card's Next reads "Done".
 * Android's back button skips.
 *
 * WHEN IT SHOWS is the app's to decide (the Resident App: once per ACCOUNT, src/once/once.ts; the Guard App: once per
 * guard on this phone, and again from More). What this component guarantees on top: it is a React Native Modal, as the sheets are, and it
 * is never drawn over an emergency — the panic or duress screen, a ringing alert, a scan or a shift start never has it
 * on top of them, because it hides the moment any such screen is the one in front, and while the Resident App's lock
 * covers the app. Under a fidelity build it draws nothing.
 */

import { useState } from 'react'
import { Modal, StyleSheet, View } from 'react-native'
import { usePathname } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { isFidelity } from '../fidelity'
import { alpha, color, type ColorToken } from '../theme/colors'
import { useReduceMotionPref } from './a11y'
import { GuardButton } from './guard/GuardButton'
import { Icon } from './Icon'
import type { IconName } from './icons/registry'
import { useCovered } from './overlayCover'
import { Pressable } from './Pressable'
import { ResidentButton } from './resident/ResidentButton'
import { Text } from './Text'

export type TourCard = { key: string; icon: IconName; title: string; body: string }

/** Five at most (§14.1). */
export const TOUR_MAX = 5

/**
 * The screens no tour is ever drawn over: the resident's panic, the guard's duress, an alert or an alertness check, the
 * call-style ring, the gate scanner and a walk-up, clocking in and out and the override. A pathname under any of them
 * hides it. (The app's own hook also closes it whenever its screen is not the one in front: a Modal stays on top of a
 * screen pushed over the one that opened it.)
 */
export const TOUR_NEVER_OVER = /^\/(panic|duress|alert|alertness|call-style|scan|walk-up|clock-in|clock-in-override|clock-out)(\/|$)/

const INK: Record<'resident' | 'guard', { card: ColorToken; border: ColorToken; title: ColorToken; body: ColorToken; well: ColorToken; glyph: ColorToken; dot: ColorToken; dotOn: ColorToken; skip: ColorToken; scrim: string }> = {
  resident: { card: 'white', border: 'navy-100', title: 'navy-900', body: 'slate-600', well: 'navy-100', glyph: 'navy-600', dot: 'navy-200', dotOn: 'navy-600', skip: 'navy-600', scrim: alpha('navy-900', 0.55) },
  guard: { card: 'surface-800', border: 'border-700', title: 'white', body: 'text-2', well: 'surface-700', glyph: 'amber-500', dot: 'border-600', dotOn: 'amber-500', skip: 'amber-500', scrim: alpha('surface-900', 0.75) },
}

type Props = {
  cards: readonly TourCard[]
  tone?: 'resident' | 'guard'
  open: boolean
  /** `done` from the last card's Done; `skipped` from Skip or Android's back, on any card. */
  onClose: (how: 'done' | 'skipped') => void
}

export function Tour({ cards, tone = 'resident', open, onClose }: Props) {
  const [at, setAt] = useState(0)
  const insets = useSafeAreaInsets()
  const still = useReduceMotionPref()
  const covered = useCovered()
  const pathname = usePathname() ?? ''

  if (isFidelity || !open || covered || TOUR_NEVER_OVER.test(pathname) || cards.length === 0) return null

  const ink = INK[tone]
  const shown = cards.slice(0, TOUR_MAX)
  const card = shown[Math.min(at, shown.length - 1)]
  if (card === undefined) return null
  const first = at === 0
  const last = at >= shown.length - 1
  const close = (how: 'done' | 'skipped') => {
    setAt(0)
    onClose(how)
  }
  const next = () => (last ? close('done') : setAt((n) => n + 1))
  const back = () => setAt((n) => Math.max(0, n - 1))
  const guard = tone === 'guard'
  const FIRST = 'This is the first card.'

  return (
    <Modal visible transparent animationType={still ? 'none' : 'fade'} statusBarTranslucent onRequestClose={() => close('skipped')}>
      <View style={[s.scrim, { backgroundColor: ink.scrim, paddingTop: insets.top + 16, paddingBottom: insets.bottom + 16 }]}>
        <View role="dialog" aria-modal aria-label={`A look round, ${at + 1} of ${shown.length}: ${card.title}`} testID="tour" style={[s.card, { backgroundColor: color(ink.card), borderColor: color(ink.border) }]}>
          <View style={s.top}>
            <Text face="Inter-Bold" size={11} ls={0.4} color={ink.body}>{`${at + 1} OF ${shown.length}`}</Text>
            <Pressable onPress={() => close('skipped')} role="button" aria-label="Skip the tour" hitSlop={12} testID="tour-skip" style={s.skip}>
              <Text face="Inter-Bold" size={13} color={ink.skip}>{'Skip'}</Text>
            </Pressable>
          </View>

          <View style={[s.well, { backgroundColor: color(ink.well) }]} aria-hidden>
            <Icon name={card.icon} size={44} color={ink.glyph} />
          </View>

          <Text face="Poppins-SemiBold" size={18} lh={1.3} color={ink.title} role="heading" align="center" style={s.title}>{card.title}</Text>
          <Text face="Inter-Regular" size={13.5} lh={1.5} color={ink.body} align="center" style={s.body}>{card.body}</Text>

          <View style={s.dots} aria-hidden>
            {shown.map((c, i) => (
              <View key={c.key} style={[s.dot, { backgroundColor: color(i === at ? ink.dotOn : ink.dot) }]} />
            ))}
          </View>

          <View style={s.row}>
            {/* M47 §3.4: the Resident App's first card has no Back at all — there is nowhere to go back to. The Guard App's
                tour is drawn as it was. */}
            {guard || !first ? (
              <View style={s.cell}>
                {guard ? (
                  <GuardButton variant="outline" label="Back" onPress={back} disabled={first} disabledReason={FIRST} />
                ) : (
                  <ResidentButton variant="outline" label="Back" onPress={back} />
                )}
              </View>
            ) : null}
            <View style={s.cell}>
              {guard ? <GuardButton label={last ? 'Done' : 'Next'} onPress={next} /> : <ResidentButton label={last ? 'Done' : 'Next'} onPress={next} />}
            </View>
          </View>
        </View>
      </View>
    </Modal>
  )
}

const s = StyleSheet.create({
  scrim: { flex: 1, justifyContent: 'center', alignItems: 'center', paddingHorizontal: 20 },
  card: { width: '100%', maxWidth: 420, borderRadius: 20, borderWidth: 1, paddingHorizontal: 20, paddingTop: 16, paddingBottom: 20 },
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  skip: { minHeight: 44, minWidth: 44, alignItems: 'flex-end', justifyContent: 'center' },
  well: { width: 96, height: 96, borderRadius: 48, alignSelf: 'center', alignItems: 'center', justifyContent: 'center', marginTop: 8 },
  title: { marginTop: 18 },
  body: { marginTop: 8 },
  dots: { flexDirection: 'row', justifyContent: 'center', gap: 6, marginTop: 18 },
  dot: { width: 7, height: 7, borderRadius: 4 },
  row: { flexDirection: 'row', gap: 10, marginTop: 18 },
  cell: { flex: 1 },
})
