/**
 * THE DEMONSTRATION STRIP — on every screen, for as long as the demonstration lasts.
 *
 * "Explore a demo community" opens a read-only session inside an estate of invented data. A
 * person who came in that way — a committee being sold the product, an App Store reviewer
 * finding their way round — must never be in any doubt about which of the two they are
 * looking at, on any screen, at any moment. So this is not a card on the home screen: it wraps
 * the whole navigator and is above everything.
 *
 * IT CONSUMES THE TOP SAFE AREA AND HANDS THE SCREENS ZERO. Every screen below pads itself by
 * `useSafeAreaInsets().top` to clear the status bar; the strip is what clears it now, so the
 * context beneath it says the top inset is already spent. Without that the navy headers would
 * gain a second status bar's worth of empty space apiece.
 *
 * NOTHING CHANGES FOR A REAL RESIDENT. With no demonstration session the children are returned
 * untouched — no wrapper, no provider, no re-measured board. The fidelity harness never holds
 * a session at all, and is excluded outright besides.
 */

import { StyleSheet, View } from 'react-native'
import { SafeAreaInsetsContext, useSafeAreaInsets } from 'react-native-safe-area-context'
import { Icon, Pressable, Text, belowStatusBar, c, isFidelity } from '@gs/core'
import { useAppDispatch, useAppSelector } from '@/store'
import { closeSession } from '../session'

/**
 * WHAT THE STRIP SAYS DEPENDS ON WHICH DOOR THEY CAME THROUGH, because one of the two sentences
 * would otherwise be a lie.
 *
 *   read-only   "Explore a demo community". The token holds no write ability, so "nothing you tap
 *               can change anything" is exactly true.
 *   reviewer    the full demonstration account (`auth.otp.verify.reviewer`). Same invented estate,
 *               but it CAN write — a reviewer is meant to issue a pass and cast the demonstration
 *               ballot — so it is told the truth about that instead.
 */
const WORDS = {
  readOnly: { body: 'Invented data. Nobody lives here, and nothing you tap can change anything.', label: 'You are exploring a demonstration community. Nothing here is real and nothing can be changed.' },
  reviewer: { body: 'Invented data. Nobody lives here, and anything you do affects only this demonstration.', label: 'You are signed in to a demonstration community. Nothing here is real, and anything you do affects only the demonstration.' },
} as const

export function DemoBanner({ children }: { children: React.ReactNode }) {
  const demo = useAppSelector((s) => s.session.demo)
  const reviewer = useAppSelector((s) => s.session.reviewer)
  const dispatch = useAppDispatch()
  const insets = useSafeAreaInsets()

  // Either door draws it. A reviewer signed in on the static code used to get no strip at all, and
  // the only thing telling them the estate was invented was its name (the catalogue's own line on
  // `auth.otp.verify.reviewer` is "Draw the demonstration banner from it").
  if ((!demo && !reviewer) || isFidelity) return <>{children}</>

  const words = demo ? WORDS.readOnly : WORDS.reviewer

  return (
    <View style={s.fill}>
      <View style={[s.strip, { paddingTop: insets.top + belowStatusBar(6) }]} role="status" aria-label={words.label}>
        <Icon name="privacy-note" size={15} color="navy-900" style={s.icon} />
        <View style={s.words}>
          <Text face="Inter-Bold" size={12} color="navy-900">{'Demonstration community'}</Text>
          <Text face="Inter-Regular" size={11} lh={1.35} color="navy-900">{words.body}</Text>
        </View>
        <Pressable onPress={() => void closeSession(dispatch)} role="button" aria-label="Leave the demonstration" hitSlop={12} style={s.leave}>
          <Text face="Inter-Bold" size={12} color="navy-900">{'Leave'}</Text>
        </Pressable>
      </View>

      <SafeAreaInsetsContext.Provider value={{ ...insets, top: 0 }}>
        <View style={s.fill}>{children}</View>
      </SafeAreaInsetsContext.Provider>
    </View>
  )
}

const s = StyleSheet.create({
  fill: { flex: 1 },
  strip: { flexDirection: 'row', alignItems: 'center', gap: 9, backgroundColor: c('amber-500'), paddingHorizontal: 16, paddingBottom: 7 },
  icon: { width: 15, height: 15, flexGrow: 0, flexShrink: 0 },
  words: { flex: 1, flexShrink: 1 },
  leave: { minHeight: 44, justifyContent: 'center', paddingLeft: 6 },
})
