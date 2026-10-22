/**
 * resident-app-29 · Before we get started — rebuilt from the board (the permissions disclosure shown after sign-in),
 * every value from the design tokens and parts.
 *
 * The board, top to bottom, on its navy gradient: "Before we get started" · its intro line · three cards, each the amber
 * tile, a title and its line — Notifications, Location, Camera & microphone · and at the foot "Continue" over "You can
 * change any of these later in Settings → Privacy.". Nothing else is drawn: no state line under a card, no note.
 *
 * THIS SCREEN IS THE APP'S LOCATION DISCLOSURE. tools/gates/location-discipline.mjs holds the words SHOWN here to the
 * policy: the Location card is about location, and says it is never taken in the background. Each card says what is
 * used and why BEFORE the phone's own question, and the phone asks only when the resident taps that card — nothing is
 * asked at launch, and "Continue" always continues (usePermissions, unchanged). What the phone answered is not drawn,
 * as the board draws none: a screen reader hears it, and a card with nothing left to ask says so when it is tapped.
 *
 * TWO CARDS KEEP WORDS THAT MATCH WHAT THE APP DOES (the user, 7 October: permission text never says one thing while
 * the app does another — a store reviewer reads that as a false claim):
 *   Location             the board says "only used to pinpoint a panic alert"; Report incident pins where the phone is
 *                        by itself too (M42 §5 #15), so the words name both moments.
 *   Camera & microphone  the board's title, and its line without "video" (the app takes no video) — naming live audio
 *                        during an open alert too, the one time sound is sent (M50 §4.2 #1).
 * The board's own line for each is the hook's, in fidelity mode only (fidelity/auth.ts); this file reads no mode.
 *
 * The board measures from the physical screen edge: 88 from the top is its 54-point status bar + 34, and the foot ends
 * 30 above the bottom edge (the 26-point home indicator + 4). With the design's content the page fits a 390 × 844 phone
 * with room to spare and nothing moves; only the largest text size on a small phone can push the cards down to the
 * foot, and then they scroll in their own area above it, never under it.
 */

import { ScrollView, StyleSheet, View } from 'react-native'
import { StatusBar } from 'expo-status-bar'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Gradient, Icon, Pressable, Text, aboveGestureBar, enterApp, useFidelityReady, type IconName } from '@gs/core'
import { NoteSheet, PrimaryButton, useNote, type Note } from '@/design/parts'
import { fill, font, gradient, icon, ink, inkAlpha, radius, size, space } from '@/design/tokens'
import { STATE_LABEL, usePermissions, type PermissionKey, type PermissionsModel } from '../usePermissions'

type Card = { key: PermissionKey; icon: IconName; title: string; why: string }

/** The three cards, in the board's order and with its icons. The words are what a phone shows (see above). */
const CARDS: readonly Card[] = [
  { key: 'notifications', icon: 'tab-notices', title: 'Notifications', why: 'So we can alert you to visitors, dues reminders and emergencies the moment they happen.' },
  { key: 'location', icon: 'loc-row', title: 'Location', why: 'Added automatically when you send an alert or report an incident, so the officers on duty can find you, never tracked in the background.' },
  { key: 'camera', icon: 'photo-tile', title: 'Camera & microphone', why: 'For photos and speech-to-text on reports and requests, and live audio to the control room while you have an alert open.' },
]

/**
 * `.perm-row` — the amber tile, the title and its line. A tap asks the phone (or, once it is blocked, opens the phone's
 * Settings: usePermissions.ask). A card with nothing left to ask is still a control: it says what the phone answered.
 */
function PermissionCard({ card, m, say }: { card: Card; m: PermissionsModel; say: (note: Note) => void }) {
  const state = m.states[card.key]
  const why = m.why[card.key] ?? card.why
  // Nothing left to ask: what the phone answered, in the app's own sheet (never the phone's grey dialog, M50 §4.3).
  const press = () => {
    if (state === 'granted') return say({ title: card.title, lines: [STATE_LABEL.granted] })
    if (state === 'unavailable') return say({ title: STATE_LABEL.unavailable, lines: [m.notes[card.key]].filter((line): line is string => !!line) })
    m.ask(card.key)
  }
  return (
    <Pressable
      onPress={press}
      disabled={m.busy !== null}
      role="button"
      aria-label={`${card.title}. ${m.showState ? STATE_LABEL[state] : why}`}
      aria-busy={m.busy === card.key}
      accessibilityHint={why}
      style={({ pressed }) => [s.permRow, pressed ? s.pressed : null]}
    >
      <View style={s.permIcon}>
        <Icon name={card.icon} size={icon[20]} color={ink.accent} />
      </View>
      <View style={s.permTxt}>
        <Text {...font.inter700_13} color={ink.onDark}>{card.title}</Text>
        <Text {...font.inter400_11_lh1_45} style={s.permWhy}>{why}</Text>
      </View>
    </Pressable>
  )
}

export default function PermissionsScreen() {
  const insets = useSafeAreaInsets()
  // Into the app with nothing behind it: Back from Home leaves the app, not back to here or to Log in (enterApp).
  const m = usePermissions(() => enterApp('/(tabs)'))
  const note = useNote()
  useFidelityReady(true)

  return (
    <View style={s.screen}>
      <StatusBar style="light" />
      <Gradient spec={gradient.permissions} style={s.fill}>
        <ScrollView style={s.fill} contentContainerStyle={[s.permPad, { paddingTop: insets.top + space[34] }]} bounces={false} overScrollMode="never" showsVerticalScrollIndicator={false}>
          <Text {...font.poppins600_23} color={ink.onDark} role="heading">{'Before we get started'}</Text>
          <Text {...font.inter400_12_5_lh1_5} style={s.sub}>{"GeminiSecure needs a few permissions to keep you and your gate connected. Here's exactly why."}</Text>
          {CARDS.map((card) => (
            <PermissionCard key={card.key} card={card} m={m} say={note.show} />
          ))}
        </ScrollView>

        <View style={[s.permBottom, { paddingBottom: aboveGestureBar(space[30], insets.bottom) }]}>
          <PrimaryButton label="Continue" onPress={m.continueOn} loading={m.busy === 'all'} />
          <Text {...font.inter400_10_5} align="center" style={s.permNote}>{'You can change any of these later in Settings → Privacy.'}</Text>
        </View>
      </Gradient>
      <NoteSheet note={note.note} onClose={note.close} />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, overflow: 'hidden' },
  // .perm-screen — the gradient is the whole page; the cards above, the foot below them
  fill: { flex: 1 },
  // .perm-pad — 24 at the sides (88 from the top edge is set by the screen: the status bar + 34)
  permPad: { paddingHorizontal: space[24] },
  // .perm-pad .sub
  sub: { color: inkAlpha.onDarkSubtitle, marginTop: space[6] },
  // .perm-row
  permRow: { flexDirection: 'row', gap: space[13], backgroundColor: fill.onDarkCard, borderRadius: radius.card, padding: space[14], marginTop: space[16] },
  pressed: { opacity: 0.7 },
  // .perm-icon
  permIcon: { width: size[42], height: size[42], flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.goldIconTile, borderRadius: radius.tile },
  // .perm-txt · .pt2
  permTxt: { flex: 1, minWidth: 0 },
  permWhy: { color: inkAlpha.onDarkDetail, marginTop: space[3] },
  // .perm-bottom — 16 above and 24 at the sides (30 below is set by the screen: the home indicator + 4)
  permBottom: { paddingTop: space[16], paddingHorizontal: space[24] },
  // .perm-note
  permNote: { color: inkAlpha.onDarkHint, marginTop: space[12] },
})
