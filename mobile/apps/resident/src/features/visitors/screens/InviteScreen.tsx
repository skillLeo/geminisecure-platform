/**
 * resident-app-07 · Invite visitor — rebuilt from the board (M42 §5 #6), every value from the design tokens and parts.
 *
 * The board, top to bottom: the four kinds of pass (Single visit · Recurring · Contractor · Delivery); Visitor name;
 * Phone number; Purpose of visit, a dropdown; the Arrival window, its day and time side by side; Generate QR pass; the
 * footer's two lines. The client's M42 instructions add exactly two things, straight after Purpose of visit: How they
 * arrive (Walk in · Drive in · Ride in, M47 §7.1) and, for Drive in only, Vehicle plate. Every tab is this same form.
 *
 * THE ARRIVAL WINDOW SAYS THE REAL DATE AND THE REAL TIMES (M47 §7.2–7.3, M48 §2.3): "Wed, Oct 7" and "1:28 AM – 5:30 AM",
 * never "Today" or "Now". It is the one place the type may be smaller: 12.5 in these two boxes, not the fields' 14, each
 * on one line, shrinking a little further only for an unusually long range. The two stay side by side in one row; the
 * time box takes three parts of it to the date's two, because a full range is the longer of the two.
 *
 * Generate QR pass issues the pass as it always has (useInvite) and opens it (/visitors/pass/[id]); with no connection
 * the invitation is kept as a draft, and the button says so.
 *
 * THE FOOTER IS MADE TRUE (M47 §7.5): nothing sends a pass by SMS or WhatsApp by itself, so it says the resident sends it
 * on the next screen (passes.ts, footerLine).
 *
 * IT DOES NOT MOVE. With the design's content the page fits a 390 × 844 phone with room to spare, plate field and all,
 * and nothing scrolls. Only when something takes that room — the keyboard over the plate field, the largest text size
 * — can it scroll, so no field and no button is ever out of reach.
 */

import { useEffect, useRef, useState } from 'react'
import { Keyboard, KeyboardAvoidingView, Platform, ScrollView, StyleSheet, View, type TextInput } from 'react-native'
import { router } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Icon, Pressable, Text, aboveGestureBar, useFidelityReady } from '@gs/core'
import { AppBar, Field, PrimaryButton, Segment } from '@/design/parts'
import { approvalFixture, inviteFixture } from '@/fidelity/visitors'
import { fill, font, icon, ink, line, radius, role, shadow, size, space, stroke } from '@/design/tokens'
import { ChooserSheet, type Choice } from '../components/ChooserSheet'
import { useInvite, type InviteModel } from '../useVisitors'

type Sheet = 'purpose' | 'arrival' | 'day' | 'lastDay' | 'start' | 'end'

/**
 * AN EMPTY BOX SHOWS THE BOARD'S OWN EXAMPLE, in the design's placeholder grey (`.input .placeholder`, slate-500) — the
 * user, 7 October: "no placeholder in any input as the design". The words are the board's (fidelity/visitors.ts),
 * never typed again here; the plate's is board 25's ("PC 8821"). Grey, and gone at the first letter: never a value.
 */
const EXAMPLE = inviteFixture
const EXAMPLE_PLATE = approvalFixture.vehicle.split(', ').pop() ?? ''


/** What the open dropdown offers. A day and a time are each chosen in two steps where they have two ends. */
function sheetOf(sheet: Sheet | null, m: InviteModel, go: (next: Sheet | null) => void): { title: string; choices: Choice[] } | null {
  switch (sheet) {
    case 'purpose':
      return { title: 'Purpose of visit', choices: m.purposes.map((p) => ({ key: p, label: p, on: p === m.input.purpose, pick: () => { m.set('purpose', p); go(null) } })) }
    case 'arrival':
      return { title: 'How they arrive', choices: m.arrivals.map((a) => ({ key: a.value, label: a.label, on: a.value === m.input.arrival, pick: () => { m.set('arrival', a.value); go(null) } })) }
    case 'day':
      return { title: m.spansDays ? 'From' : 'Day', choices: m.days.map((d) => ({ key: d.iso, label: d.label, on: d.iso === m.input.fromDay, pick: () => { m.set('fromDay', d.iso); go(m.spansDays ? 'lastDay' : null) } })) }
    case 'lastDay':
      return { title: 'Until', choices: m.days.filter((d) => d.iso >= m.input.fromDay).map((d) => ({ key: d.iso, label: d.label, on: d.iso === m.input.toDay, pick: () => { m.set('toDay', d.iso); go(null) } })) }
    case 'start':
      return { title: 'From', choices: m.startTimes.map((t) => ({ key: String(t.value), label: t.label, on: t.value === m.input.fromMinutes, pick: () => { m.chooseStart(t.value); go('end') } })) }
    case 'end':
      return { title: 'Until', choices: m.endTimes.map((t) => ({ key: String(t.value), label: t.label, on: t.value === m.input.toMinutes, pick: () => { m.set('toMinutes', t.value); go(null) } })) }
    default:
      return null
  }
}

/**
 * One of the Arrival window's two boxes: the Field part's chooser box exactly — the same tokens — with its words one size
 * smaller (M47 §7.3, the one place the type may shrink) and always on ONE line: never "A / M" split across two. A range
 * longer than the box is fitted by shrinking it a little more, never by cutting it.
 */
function WindowBox({ glyph, label, value, open, onPress }: { glyph: 'input' | 'recap-item'; label: string; value: string; open: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} role="button" aria-label={`${label}: ${value}`} aria-expanded={open} style={[s.box, open ? s.boxOpen : null]}>
      <Icon name={glyph} size={icon[18]} color={ink.field} style={s.boxIcon} />
      <View style={s.boxText}><Text {...font.inter500_12_5} color={ink.heading} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.8}>{value}</Text></View>
    </Pressable>
  )
}

export default function InviteScreen() {
  const m = useInvite((result) => (result.kind === 'issued' ? router.replace(`/visitors/pass/${result.id}` as never) : router.replace('/(tabs)/visitors' as never)))
  const [sheet, setSheet] = useState<Sheet | null>(null)
  const insets = useSafeAreaInsets()
  useFidelityReady(true)

  const phone = useRef<TextInput>(null)
  const scroll = useRef<ScrollView>(null)
  // The plate field sits low: when the keyboard comes up over it, the page is moved to show it.
  const typingPlate = useRef(false)
  useEffect(() => {
    const shown = Keyboard.addListener('keyboardDidShow', () => {
      if (typingPlate.current) scroll.current?.scrollToEnd({ animated: true })
    })
    return () => shown.remove()
  }, [])

  const choose = (next: Sheet) => {
    Keyboard.dismiss()
    setSheet(next)
  }
  const chooser = sheetOf(sheet, m, setSheet)
  const arrival = m.arrivals.find((a) => a.value === m.input.arrival)?.label ?? ''
  const note = m.errors.window ?? m.problem

  return (
    <View style={s.screen}>
      <AppBar title="Invite visitor" fallback="/(tabs)/visitors" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
        {/* The board's 20 below the footer, or the home indicator's strip where that is deeper — not both. */}
        <ScrollView ref={scroll} style={s.fill} contentContainerStyle={[s.body, { paddingBottom: aboveGestureBar(space[20], insets.bottom) }]} bounces={false} overScrollMode="never" keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
          <Segment label="Kind of pass" items={m.categories} value={m.input.category} onChange={m.setCategory} />

          <Field label="Visitor name" icon="cc-row" value={m.input.name} placeholder={EXAMPLE.name} onChangeText={(t) => m.set('name', t)} open={m.nameLit} error={m.errors.name} autoCapitalize="words" autoCorrect={false} returnKeyType="next" submitBehavior="submit" onSubmitEditing={() => phone.current?.focus()} />
          <Field ref={phone} label="Phone number" icon="call-btn" value={m.input.phone} placeholder={EXAMPLE.phone} onChangeText={(t) => m.set('phone', t)} error={m.errors.phone} keyboardType="phone-pad" autoComplete="tel" />
          <Field label="Purpose of visit" icon="input-3" value={m.input.purpose} placeholder={EXAMPLE.purpose} onPress={() => choose('purpose')} open={sheet === 'purpose'} chevron error={m.errors.purpose} />
          <Field label="How they arrive" icon="tab-patrol" value={arrival} placeholder={m.arrivals.find((a) => a.value === EXAMPLE.arrival)?.label} onPress={() => choose('arrival')} open={sheet === 'arrival'} chevron error={m.errors.arrival} />
          {m.input.arrival === 'drive' ? (
            <Field label="Vehicle plate" icon="item-tag" value={m.input.plate} placeholder={EXAMPLE_PLATE} onChangeText={(t) => m.set('plate', t)} error={m.errors.plate} autoCapitalize="characters" autoCorrect={false} maxLength={16} onFocus={() => { typingPlate.current = true }} onBlur={() => { typingPlate.current = false }} />
          ) : null}

          <View style={s.field}><View style={s.label}><Text {...role.label} color={ink.label}>{'Arrival window'}</Text></View></View>
          <View style={s.when}>
            <View style={s.twoCol}>
              <View style={s.dayCol}><WindowBox glyph="input" label="Arrival day" value={m.dayText} onPress={() => choose('day')} open={sheet === 'day' || sheet === 'lastDay'} /></View>
              <View style={s.timeCol}><WindowBox glyph="recap-item" label="Arrival time" value={m.timeText} open={sheet === 'start' || sheet === 'end'} onPress={() => choose('start')} /></View>
            </View>
            {note ? <View style={s.note}><Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{note}</Text></View> : null}
          </View>

          <PrimaryButton label={m.submitLabel} onPress={m.submit} loading={m.busy} />

          <View style={s.hintRow}>
            <Icon name="invite-note" size={icon[15]} color={ink.secondary} style={s.hintIcon} />
            <View style={s.hintText}><Text {...font.inter400_11_lh1_5} color={ink.secondary}>{m.footer}</Text></View>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
      <ChooserSheet open={chooser !== null} title={chooser?.title ?? ''} choices={chooser?.choices ?? []} onClose={() => setSheet(null)} />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface },
  fill: { flex: 1 },
  // .form-body
  body: { paddingTop: space[20], paddingHorizontal: space[20] },
  // .field around the Arrival window's label: its own 6 below, the field's 15
  field: { marginBottom: space[15] },
  label: { marginBottom: space[6] },
  // .two-col, margin-bottom 15, its gap 10; the date takes two parts of the row and the time three (M47 §7.3: a full
  // range on one line in its box at 360 wide)
  when: { marginBottom: space[15] },
  twoCol: { flexDirection: 'row', gap: space[10] },
  dayCol: { flex: 2, minWidth: 0 },
  timeCol: { flex: 3, minWidth: 0 },
  note: { marginTop: space[6] },
  // .input — the Field part's chooser box, for WindowBox
  box: { height: size[48], flexDirection: 'row', alignItems: 'center', backgroundColor: fill.tint, borderRadius: radius.field, borderWidth: stroke['1.5'], borderColor: 'transparent', paddingHorizontal: space[14] },
  boxOpen: { backgroundColor: fill.surface, borderColor: line.focus, ...shadow.focusRing },
  boxIcon: { marginRight: space[10] },
  boxText: { flex: 1, minWidth: 0, justifyContent: 'center' },
  // .hint-row: gap 9, 16 below the button, 2 in at each side; its shield 1 down
  hintRow: { flexDirection: 'row', gap: space[9], marginTop: space[16], paddingHorizontal: space[2] },
  hintIcon: { marginTop: space[1] },
  hintText: { flex: 1, minWidth: 0 },
})
