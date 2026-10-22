/**
 * resident-app-25 · Visitor at the gate — a guard is holding a visitor and asks the household; rebuilt from the
 * client's board, every value from the design's tokens. All behaviour lives in useApproval(); this file only draws it,
 * the same way for a phone and for the board's own content.
 *
 * THE BOARD, TOP TO BOTTOM, on the navy alert backdrop: "VISITOR AT THE GATE" · "Someone wants to visit Lot 47" · the
 * ring with the time left, and "Auto-holds at gate if no response" · the white card — the photo panel, "Photo captured
 * by guard at Main Gate", then "Says their name is …", "Purpose: "…"", "Vehicle: …", "Checked in by Guard …" · Deny and
 * Approve entry side by side · the line on the estate's standing gate policy.
 *
 * TIME-CRITICAL, AND WIRED EXACTLY AS BEFORE (useApproval): the ring and the clock count to the server's `respond_by`;
 * at zero nothing more can be sent and the request reads "Time ran out" — the guard follows the estate's policy; Deny
 * and Approve entry answer online only (a late answer is no answer), only while the request waits, never twice.
 *
 * NO PHOTO EXISTS: the server keeps none of a visitor. The panel is drawn as the board draws it — the outline of a
 * person — and never holds a picture; its caption says where the visitor is waiting ("Waiting at Main Gate"), never
 * the board's "Photo captured by guard", which only the design-comparison build draws (the user, 9 Oct 2026: a false
 * caption). A row the guard left empty (no purpose, no vehicle) is left out.
 *
 * NOT ON THE BOARD, AND KEPT TO THE LEAST THAT IS HONEST: what happened once it is answered, ran out or was answered by
 * someone else (the heading, one line, and Done); an answer that could not be sent, and no connection, said in the
 * card; a spinner while the request is first fetched.
 *
 * With the board's content the page fits a 390 × 844 phone and nothing moves; it can scroll only when the largest text
 * sizes take that room, so the two answers are never out of reach.
 */

import { ActivityIndicator, ScrollView, StyleSheet, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import Svg, { Circle } from 'react-native-svg'
import { Gradient, Icon, Pressable, Text, aboveGestureBar, belowStatusBar, c, isFidelity, useFidelityReady, type IconName } from '@gs/core'
import { corner, fill, font, gradient, icon, ink, inkAlpha, line, radius, size, space, stroke } from '@/design/tokens'
import { HELD_LINE, useApproval, type ApprovalModel } from '../useApproval'

/** `.ring-wrap svg` — a 96-point ring, radius 42, a 6-point stroke; the board's dash is 264 (2πr, rounded). */
const RING_SIDE = size[96]
const RING_CENTRE = size[48]
const RING_RADIUS = size[42]
const RING_STROKE = size[6]
const RING_LENGTH = Math.round(2 * Math.PI * RING_RADIUS)
const RING_BOX = `0 0 ${RING_SIDE} ${RING_SIDE}`

/** The heading once the request is no longer waiting for this household. */
const ENDED: Partial<Record<ApprovalModel['status'], string>> = {
  approved: 'You approved the entry',
  denied: 'You denied the entry',
  expired: 'Time ran out',
  gone: 'This request is closed',
}

/* ---- the board's parts --------------------------------------------------------------------------------- */

/** `.ring-wrap` · `.ring-label` — the time left, counted to the server's deadline. */
function Countdown({ m }: { m: ApprovalModel }) {
  return (
    <View style={s.countdown}>
      <View style={s.ring} role="timer" aria-label={`${m.secondsLeft} seconds left to answer`}>
        <Svg width={RING_SIDE} height={RING_SIDE} viewBox={RING_BOX} style={s.ringSvg}>
          <Circle cx={RING_CENTRE} cy={RING_CENTRE} r={RING_RADIUS} fill="none" stroke={inkAlpha.ringTrack} strokeWidth={RING_STROKE} />
          <Circle cx={RING_CENTRE} cy={RING_CENTRE} r={RING_RADIUS} fill="none" stroke={c(ink.accent)} strokeWidth={RING_STROKE} strokeLinecap="round" strokeDasharray={`${RING_LENGTH}`} strokeDashoffset={`${RING_LENGTH * (1 - m.fraction)}`} />
        </Svg>
        <View style={s.ringText}><Text {...font.poppins700_19} color={ink.onDark}>{m.clock}</Text></View>
      </View>
      <View style={s.ringLabel}><Text {...font.inter600_10_5} style={s.ringLabelInk}>{'Auto-holds at gate if no response'}</Text></View>
    </View>
  )
}

/** `.vc-row` — the icon and what the guard typed. */
function Fact({ glyph, text }: { glyph: IconName; text: string }) {
  return (
    <View style={s.fact}>
      <Icon name={glyph} size={icon[15]} color={ink.field} />
      <View style={s.factText}><Text {...font.inter400_12} color={ink.label}>{text}</Text></View>
    </View>
  )
}

/** What happened, once it is no longer waiting — and an answer that could not be sent. In the card, never red. */
function Said({ m }: { m: ApprovalModel }) {
  const ended =
    m.status === 'approved' ? { text: m.held ? HELD_LINE : 'The guard has your answer and is letting them in.', attention: m.held }
    : m.status === 'denied' ? { text: 'The guard has your answer and will turn them away.', attention: false }
    : m.status === 'expired' ? { text: `The three minutes ran out. The guard is following ${m.estate}'s gate policy for unannounced visitors. Phone the gatehouse if you need to.`, attention: true }
    : m.status === 'gone' ? { text: 'Someone in your household already answered it, or it timed out. Nothing more is needed.', attention: false }
    : null
  const offline = !m.online && !ended
  return (
    <>
      {ended ? (
        <View style={s.said}><Text {...font.inter600_12_lh1_5} color={ended.attention ? ink.onGoldTint : ink.heading} role={ended.attention ? 'alert' : 'status'}>{ended.text}</Text></View>
      ) : null}
      {m.error ? <View style={s.said}><Text {...font.inter600_12_lh1_5} color={ink.onGoldTint} role="alert">{m.error}</Text></View> : null}
      {offline ? (
        <View style={s.said}><Text {...font.inter600_12_lh1_5} color={ink.onGoldTint} role="alert">{'No connection: an answer cannot be sent, and it is never saved for later. Phone the gatehouse.'}</Text></View>
      ) : null}
    </>
  )
}

/** `.visitor-card` — the photo panel (no photo is ever kept), its caption, the guard's lines. */
function VisitorCard({ m }: { m: ApprovalModel }) {
  // Known once the request has been read; a request already gone before it was ever read has nothing to show.
  const known = m.visitor !== ''
  return (
    <View style={s.card}>
      <View style={s.photo}><Icon name="cc-row" size={icon[52]} color={ink.muted} /></View>
      {m.status === 'loading' ? <View style={s.loading}><ActivityIndicator color={fill.primary} /></View> : null}
      {known ? (
        <>
          {/* No photo exists (the server keeps none), so the phone never says one was captured (the user, 9 Oct 2026):
              the board's caption is drawn only in the design-comparison build. */}
          <Text {...font.poppins600_16} color={ink.heading}>{isFidelity ? `Photo captured by guard at ${m.post}` : `Waiting at ${m.post}`}</Text>
          <Fact glyph="cc-row" text={`Says their name is ${m.visitor}`} />
          {m.purpose ? <Fact glyph="input-3" text={`Purpose: "${m.purpose}"`} /> : null}
          {m.vehicle ? <Fact glyph="veh-row" text={`Vehicle: ${m.vehicle}`} /> : null}
          <Fact glyph="cc-row" text={`Checked in by Guard ${m.guard}`} />
        </>
      ) : null}
      <Said m={m} />
    </View>
  )
}

/** `.approve-btns` — Deny on the left, Approve entry on the right; open only while the request waits. */
function Answer({ m }: { m: ApprovalModel }) {
  const open = m.status === 'waiting'
  const busy = m.status === 'answering'
  return (
    <View style={s.buttons}>
      <Pressable onPress={() => m.answer('deny')} disabled={!open} role="button" aria-label="Deny entry" aria-disabled={!open} aria-busy={busy} style={({ pressed }) => [s.deny, pressed || !open ? s.dim : null]}>
        <Text {...font.poppins700_14} color={ink.onDark}>{'Deny'}</Text>
      </Pressable>
      <Pressable onPress={() => m.answer('approve')} disabled={!open} role="button" aria-label="Approve entry" aria-disabled={!open} aria-busy={busy} style={({ pressed }) => [s.approve, pressed || !open ? s.dim : null]}>
        <Text {...font.poppins700_14} color={ink.heading}>{busy ? 'Sending…' : 'Approve entry'}</Text>
      </Pressable>
    </View>
  )
}

/* ---- the screen ---------------------------------------------------------------------------------------- */

export default function ApprovalScreen() {
  const insets = useSafeAreaInsets()
  const { id } = useLocalSearchParams<{ id?: string }>()
  const m = useApproval(id)
  useFidelityReady(true)

  const leave = () => (router.canGoBack() ? router.back() : router.replace('/(tabs)' as never))
  const ended = ENDED[m.status]
  const counting = m.status === 'waiting' || m.status === 'answering'

  return (
    <View style={s.screen}>
      <StatusBar style="light" />
      <Gradient spec={gradient.alert} style={s.fill}>
        <ScrollView
          style={s.fill}
          contentContainerStyle={[s.page, { paddingTop: insets.top + belowStatusBar(space[2]), paddingBottom: aboveGestureBar(space[20], insets.bottom) }]}
          bounces={false}
          overScrollMode="never"
          showsVerticalScrollIndicator={false}
        >
          {/* .approval-top — 56 from the top of the board: its status bar and 2 */}
          <View style={s.top}>
            <Text {...font.inter700_10_5_ls0_3} color={ink.accent} align="center">{'VISITOR AT THE GATE'}</Text>
            <View style={s.headline}>
              <Text {...font.poppins600_17} color={ink.onDark} align="center" role="heading">{ended ?? `Someone wants to visit ${m.unit}`}</Text>
            </View>
          </View>

          {counting ? <Countdown m={m} /> : null}
          <VisitorCard m={m} />

          {ended ? (
            <View style={s.buttons}>
              <Pressable onPress={leave} role="button" aria-label="Done" style={({ pressed }) => [s.approve, pressed ? s.dim : null]}>
                <Text {...font.poppins700_14} color={ink.heading}>{'Done'}</Text>
              </Pressable>
            </View>
          ) : (
            <Answer m={m} />
          )}

          <View style={s.note}>
            <Text {...font.inter400_10_5_lh1_5} align="center" style={s.noteInk}>{`If you don't respond in time, the guard will follow ${m.estate}'s standing gate policy for unannounced visitors.`}</Text>
          </View>
        </ScrollView>
      </Gradient>
    </View>
  )
}

const button = { flex: 1, height: size[52], borderRadius: radius.button, alignItems: 'center', justifyContent: 'center' } as const

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.night },
  fill: { flex: 1 },
  page: { flexGrow: 1 },

  // .approval-top — 20 in at each side; .at2 4 below .at1
  top: { paddingHorizontal: space[20] },
  headline: { marginTop: space[4] },

  // .countdown-wrap — 20 above; its 8 below folds into the card's 18 (a browser keeps the larger of the two)
  countdown: { alignItems: 'center', marginTop: space[20] },
  ring: { width: RING_SIDE, height: RING_SIDE },
  // The ring starts at twelve o'clock.
  ringSvg: { transform: [{ rotate: '-90deg' }] },
  ringText: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, alignItems: 'center', justifyContent: 'center' },
  ringLabel: { marginTop: space[8] },
  ringLabelInk: { color: inkAlpha.onDarkCaption },

  // .visitor-card — 18 above, 20 in at each side, white, radius 18, 16 inside
  card: { marginTop: space[18], marginHorizontal: space[20], backgroundColor: fill.surface, borderRadius: radius.heroCard, padding: space[16] },
  // .vc-photo — the whole width, 150 tall, radius 14, 13 above the caption
  photo: { height: size[150], borderRadius: corner[14], backgroundColor: fill.tint, alignItems: 'center', justifyContent: 'center', marginBottom: space[13] },
  loading: { paddingVertical: space[16], alignItems: 'center' },
  // .vc-row — 8 below what precedes it, 9 between the icon and the words
  fact: { flexDirection: 'row', alignItems: 'center', gap: space[9], marginTop: space[8] },
  factText: { flex: 1, minWidth: 0 },
  said: { marginTop: space[12] },

  // .approve-btns · .a-btn — 16 below the card, 10 apart, 52 tall, radius 15
  buttons: { flexDirection: 'row', gap: space[10], marginTop: space[16], marginHorizontal: space[20] },
  deny: { ...button, backgroundColor: fill.onDarkChip, borderWidth: stroke['1.5'], borderColor: line.onDark },
  approve: { ...button, backgroundColor: fill.gold },
  dim: { opacity: 0.6 },

  // .approval-note — 14 below the buttons, 20 in at each side
  note: { marginTop: space[14], marginHorizontal: space[20] },
  noteInk: { color: inkAlpha.onDarkNote },
})
