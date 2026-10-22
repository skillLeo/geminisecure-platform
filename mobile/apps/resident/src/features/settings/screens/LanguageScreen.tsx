/**
 * resident-app-38 · Language & accessibility — rebuilt from the board (M50 §6.5), every value from the design tokens and
 * parts. Five blocks and no paragraphs; behaviour lives in useAccessibility(), the board's words in languageFixture.
 *
 *   LANGUAGE        the globe, "English (Jamaica)", "App language and date formats" and the chevron. It opens the list
 *                   of the app's languages — English (Jamaica), chosen, the one the app is written in.
 *   TEXT SIZE       A · the slider · A (both the board's 16), and the board's own preview, "This is how your text will
 *                   look". A real, LOCAL choice in four steps (prefs.ts), applied by the type layer: the root layout
 *                   binds it to core's <Text>, so every screen grows at once. The two "A" buttons step it, and so does
 *                   the track: a tap lands on the nearest step and a drag follows the finger; a screen reader adjusts
 *                   it as a slider. NO CAP: the steps run to 1.5×, the phone's own setting adds on top, and a page
 *                   taller than the phone scrolls (M50 §6.5: capping it "limits a resident with poor eyesight to
 *                   protect a layout").
 *   DISPLAY         High contrast and Reduce motion, each a real switch kept on this phone and applied by the root
 *                   layout — the light screens' text in black (core <Text>, ui/a11y); screens changing without a
 *                   transition and the kit's pulses standing still (the phone's own reduce-motion setting counts too).
 *   THE CARD        "GeminiSecure is fully compatible with VoiceOver and TalkBack screen readers."
 *
 * DELETED WITH M50 §6.5: the language row's sub-line about the estate's clock, "Standard —" before the preview, the
 * paragraphs under Text size and under Display (the second explained why High contrast was missing — it is built),
 * the "Time" section (the same words as the Home card already deleted), and the old screen-reader sentence.
 *
 * IT DOES NOT MOVE while it fits: at the default size the page fits a 390 × 844 phone and nothing scrolls; only when
 * the chosen size makes it taller than the phone does it scroll, without bouncing.
 */

import { useRef, useState } from 'react'
import { ScrollView, StyleSheet, View, type AccessibilityActionEvent, type DimensionValue, type GestureResponderEvent } from 'react-native'
import * as Haptics from 'expo-haptics'
import { Icon, Pressable, Text, useFidelityReady } from '@gs/core'
import { AppBar, Card, IconTile, PRESSED, SectionLabel, Toggle } from '@/design/parts'
import { corner, fill, font, icon, ink, line, radius, role, shadow, size, space, stroke } from '@/design/tokens'
import { ChooserSheet } from '@/features/visitors/components/ChooserSheet'
import { Divider, TRow } from '../components/Rows'
import { textSizeAt } from '../prefs'
import { useAccessibility } from '../useSettings'

/** `.size-track i` — the board's 17-point thumb. */
const THUMB = size[17]
/** An "A" button's touch area, grown to 48. */
const STEP_REACH = size[6]

export default function LanguageScreen() {
  const m = useAccessibility()
  const words = m.words
  const [trackWidth, setTrackWidth] = useState(0)
  const [choosing, setChoosing] = useState(false)
  // The step the finger is on, so a drag across one step clicks once and not on every move.
  const under = useRef(m.size)
  under.current = m.size
  // Where the track begins on the screen, taken as the finger lands.
  const origin = useRef(0)
  useFidelityReady(true)

  const thumbLeft: DimensionValue = m.thumbLeft ?? Math.round(m.fraction * Math.max(0, trackWidth - THUMB))
  const at = m.sizes.findIndex((choice) => choice.key === m.size)
  const languages = [{ key: 'en-JM', label: words.language.title, on: true, pick: () => setChoosing(false) }]

  // `locationX` is from the track's left edge only while the finger is over the track (its children take no
  // touches), and every step resizes the text, which moves the track up or down the page from under the finger;
  // Android then measures `locationX` from whatever IS under it, and the drag stopped after one step (found on the
  // emulator, 26 Sep: Largest dragged to the far left stopped at Larger). The track never moves sideways, so the
  // finger's place on the SCREEN, less the track's start taken as the finger landed, holds for the whole drag.
  //
  // TRUE, so Android's own ScrollView cannot take the drag (M13, 26 Sep): each step makes the text larger and the page
  // longer, and once it can scroll, the ScrollView would intercept a finger moving with the page and cancel the slider.
  // A grant that returns true asks every parent not to intercept until the finger lifts (PanResponder's default).
  const land = (e: GestureResponderEvent): boolean => {
    origin.current = e.nativeEvent.pageX - e.nativeEvent.locationX
    follow(e)
    return true
  }
  const follow = (e: GestureResponderEvent) => {
    const next = textSizeAt(e.nativeEvent.pageX - origin.current, trackWidth, THUMB)
    if (next === under.current) return
    under.current = next
    m.setSize(next)
    void Haptics.selectionAsync().catch(() => undefined)
  }
  const adjust = (e: AccessibilityActionEvent) => {
    if (e.nativeEvent.actionName === 'increment') m.larger()
    else if (e.nativeEvent.actionName === 'decrement') m.smaller()
  }

  // `.size-btn`: both of the board's "A"s are the same 16 — the track between them says which way is larger.
  const step = (label: string, enabled: boolean, onPress: () => void, why: string) => (
    <Pressable onPress={onPress} disabled={!enabled} role="button" aria-label={label} aria-disabled={!enabled} accessibilityHint={enabled ? undefined : why} hitSlop={STEP_REACH} style={({ pressed }) => [s.sizeButton, pressed ? s.sizeButtonPressed : null]}>
      <Text {...font.inter700_16} color={enabled ? ink.onTint : ink.faint}>{'A'}</Text>
    </Pressable>
  )

  return (
    <View style={s.screen}>
      <AppBar title="Language & accessibility" fallback="/settings" />
      <ScrollView style={s.fill} contentContainerStyle={s.body} bounces={false} overScrollMode="never" showsVerticalScrollIndicator={false}>
        <SectionLabel first>{'Language'}</SectionLabel>
        <Card pad={13} style={s.card}>
          {/* `.lang-row` */}
          <Pressable onPress={() => setChoosing(true)} role="button" aria-label={`Language: ${words.language.title}. ${words.language.detail}`} style={({ pressed }) => [s.languageRow, pressed ? PRESSED : null]}>
            <IconTile name="set-icon-3" glyph={17} />
            <View style={s.text}>
              <Text {...role.rowTitle} color={ink.heading}>{words.language.title}</Text>
              <View style={s.languageLine}><Text {...font.inter400_10_5} color={ink.secondary}>{words.language.detail}</Text></View>
            </View>
            <Icon name="chevron-right" size={icon[16]} color={ink.faint} />
          </Pressable>
        </Card>

        {/* The board's .section-label keeps its 2 above every label on this board. */}
        <SectionLabel first>{'Text size'}</SectionLabel>
        <Card pad={13} style={s.card}>
          <View style={s.sizeRow}>
            {step('Smaller text', m.canShrink, m.smaller, 'This is the smallest size.')}
            <View
              style={s.sizeTouch}
              onLayout={(e) => setTrackWidth(e.nativeEvent.layout.width)}
              onStartShouldSetResponder={() => true}
              onMoveShouldSetResponder={() => true}
              // Held until the finger lifts: the page around it does not take the drag away and scroll instead.
              onResponderTerminationRequest={() => false}
              onResponderGrant={land}
              onResponderMove={follow}
              role="slider"
              aria-label="Text size"
              aria-valuemin={0}
              aria-valuemax={m.sizes.length - 1}
              aria-valuenow={Math.max(0, at)}
              aria-valuetext={m.sizeLabel}
              accessibilityActions={[{ name: 'increment' }, { name: 'decrement' }]}
              onAccessibilityAction={adjust}
            >
              <View style={s.sizeTrack}>
                <View style={[s.sizeThumb, { left: thumbLeft }]} />
              </View>
            </View>
            {step('Larger text', m.canGrow, m.larger, 'This is the largest size.')}
          </View>
          {/* The board's words and its 15, and nothing else: core's <Text> multiplies it by the chosen size and by the
              phone's own setting, exactly as on every other screen — this line IS the app's text. */}
          <View style={s.preview}>
            <Text {...font.inter600_15} color={ink.heading}>{words.preview}</Text>
          </View>
        </Card>

        <SectionLabel first>{'Display'}</SectionLabel>
        <Card pad={13} style={s.card}>
          <TRow title={words.display[0].title} line={words.display[0].detail} pad={11}>
            <Toggle on={m.highContrast} onChange={m.setHighContrast} label={`${words.display[0].title}. ${words.display[0].detail}`} />
          </TRow>
          <Divider />
          <TRow title={words.display[1].title} line={words.display[1].detail} pad={11}>
            <Toggle on={m.reduceMotion} onChange={m.setReduceMotion} label={`${words.display[1].title}. ${words.display[1].detail}`} />
          </TRow>
        </Card>

        {/* `.info-banner` */}
        <View style={s.banner}>
          <Icon name="why-note" size={icon[17]} color={ink.action} style={s.bannerIcon} />
          <View style={s.text}><Text {...font.inter400_11_lh1_5} color={ink.body}>{words.banner}</Text></View>
        </View>
      </ScrollView>

      <ChooserSheet open={choosing} title="Language" choices={languages} onClose={() => setChoosing(false)} />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  fill: { flex: 1 },
  // .body-pad
  body: { paddingTop: space[16], paddingHorizontal: space[20], paddingBottom: space[20] },
  // .card on this board: 11 below, not the shared card's 10
  card: { marginBottom: space[11] },
  text: { flex: 1, minWidth: 0 },
  // .lang-row · .lang-txt .l2
  languageRow: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[5] },
  languageLine: { marginTop: space[1] },
  // .size-row · .size-btn · .size-track · .size-track i
  sizeRow: { flexDirection: 'row', alignItems: 'center', gap: space[14], paddingTop: space[6], paddingBottom: space[4] },
  sizeButton: { width: size[36], height: size[36], flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.tint, borderRadius: radius.iconTile },
  sizeButtonPressed: { backgroundColor: fill.divider },
  // The finger's area: the row's full height (the "A" buttons' 36), the drawn track centred in it.
  sizeTouch: { flex: 1, height: size[36], flexDirection: 'row', alignItems: 'center' },
  sizeTrack: { flex: 1, height: size[5], backgroundColor: fill.tint, borderRadius: corner[4], pointerEvents: 'none' },
  sizeThumb: { position: 'absolute', top: space['-6'], width: THUMB, height: THUMB, borderRadius: radius.avatar(THUMB), backgroundColor: fill.primary, borderWidth: stroke[3], borderColor: line.onDarkSolid, ...shadow.knob, pointerEvents: 'none' },
  // .preview-box
  preview: { backgroundColor: fill.tint, borderRadius: radius.tile, paddingVertical: space[12], paddingHorizontal: space[14], marginTop: space[12] },
  // .info-banner
  banner: { flexDirection: 'row', gap: space[10], backgroundColor: fill.tint, borderRadius: radius.field, paddingVertical: space[12], paddingHorizontal: space[13] },
  bannerIcon: { marginTop: space[1] },
})
