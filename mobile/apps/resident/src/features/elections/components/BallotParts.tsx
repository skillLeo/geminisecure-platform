/**
 * The voting boards' own pieces — 15 Community election, 24 Phase 2 election, 34 Review your vote — every value from
 * the design tokens. They draw what they are given and keep nothing: no mark lives here (ballotBox.ts holds them).
 *
 *   BallotHead        .ballot-head · .review-head   the navy card: its gold tag, the title, the line under it
 *   PositionLabel     .pos-label                    a position's name, and "Choose 1" / "Choose up to 3" on the right
 *   PositionCard      .card · .cand-row             a row per candidate: initials, name, the line under it, and a
 *                                                   radio for one seat or a box for more. Board 15 draws the rows at
 *                                                   one size (page "a"), board 24 a point smaller ("b"): both as drawn.
 *   CompactPositions  .compact-pos                  board 24's "Remaining positions": a row each, and "Choose"
 *   VoteNote          .one-vote-note(2)             the line under the cards, its icon gold
 *   Attention         (no board)                    a mark refused, marks cleared, a vote not sent — said in the
 *                                                   attention line the shared Field uses, only when it happens
 */

import { StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native'
import { Gradient, Icon, Pressable, Text, type IconName } from '@gs/core'
import { Card } from '@/design/parts'
import { corner, fill, font, gradient, icon, ink, inkAlpha, line, radius, size, space, stroke } from '@/design/tokens'
import type { OptionView, PositionView } from '../useBallotPage'

type Page = 'a' | 'b'

/** A gold link is one 11-point line, 14 high: 15 above and below makes its target 44. */
export const LINK_REACH = { top: space[15], bottom: space[15], left: space[10], right: space[10] } as const

/** `.ballot-head` (15 · 24) or `.review-head` (34). The space under it is the screen's. */
export function BallotHead({ eyebrow, heading, sub, review = false, style }: { eyebrow: string; heading: string; sub: string; review?: boolean; style?: StyleProp<ViewStyle> }) {
  return (
    <Gradient spec={gradient.hero} style={[review ? s.reviewHead : s.ballotHead, style]}>
      <Text {...font.inter700_10} color={ink.accent}>{eyebrow}</Text>
      <View style={s.headTitle}>
        <Text {...font.poppins600_16} color={ink.onDark} role="heading">{heading}</Text>
      </View>
      <Text {...font.inter400_11} style={s.headSub}>{sub}</Text>
    </Gradient>
  )
}

/** `.pos-label` — its 2 above folds into the space before it, as the board's margins do. */
export function PositionLabel({ name, rule }: { name: string; rule: string | null }) {
  return (
    <View style={s.posLabel}>
      <View style={s.shrink}><Text {...font.inter700_13_5} color={ink.heading} role="heading">{name}</Text></View>
      {rule ? <Text {...font.inter600_10_5} color={ink.secondary}>{rule}</Text> : null}
    </View>
  )
}

/** `.radio` / `.radio.on` (board 15 at 20, board 24 at 19) and `.cbox` / `.cbox.on`. */
function Mark({ multi, marked, page }: { multi: boolean; marked: boolean; page: Page }) {
  if (multi) return <View style={marked ? s.boxOn : s.box}>{marked ? <Icon name="check" size={icon[12]} color={ink.onDark} /> : null}</View>
  return <View style={[page === 'a' ? s.radioA : s.radioB, marked ? s.radioOn : null]}>{marked ? <View style={page === 'a' ? s.dotA : s.dotB} /> : null}</View>
}

/** `.cand-row` — the whole row is the control, as the board's row is one target. */
function CandidateRow({ option, multi, last, page, onPress }: { option: OptionView; multi: boolean; last: boolean; page: Page; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      role={multi ? 'checkbox' : 'radio'}
      aria-checked={option.marked}
      aria-label={option.sub ? `${option.label}, ${option.sub}` : option.label}
      style={[page === 'a' ? s.rowA : s.rowB, last ? null : s.rowLine]}
    >
      <View style={page === 'a' ? s.avatarA : s.avatarB}>
        <Text {...(page === 'a' ? font.inter700_12 : font.inter700_11)} color={ink.onTint}>{option.initials}</Text>
      </View>
      <View style={s.info}>
        <Text {...font.inter700_12_5} color={ink.heading}>{option.label}</Text>
        {option.sub ? (
          <View style={s.infoSub}><Text {...(page === 'a' ? font.inter400_10_5 : font.inter400_10)} color={ink.secondary}>{option.sub}</Text></View>
        ) : null}
      </View>
      <Mark multi={multi} marked={option.marked} page={page} />
    </Pressable>
  )
}

/** `.card` of `.cand-row`s: one position's candidates. */
export function PositionCard({ position, page, onMark }: { position: PositionView; page: Page; onMark: (optionId: number) => void }) {
  return (
    <Card>
      <View role={position.multi ? 'group' : 'radiogroup'} aria-label={`${position.name}, ${position.rule}`}>
        {position.options.map((option, i) => (
          <CandidateRow key={option.id} option={option} multi={position.multi} page={page} last={i === position.options.length - 1} onPress={() => onMark(option.id)} />
        ))}
      </View>
    </Card>
  )
}

/** Board 24's `.card` (4 above and below, 14 at the sides) of `.compact-pos` rows: a position, who is chosen, "Choose". */
export function CompactPositions({ positions, openKey, onToggle }: { positions: PositionView[]; openKey: string | null; onToggle: (key: string) => void }) {
  return (
    <Card style={s.compactCard}>
      {positions.map((p, i) => (
        <View key={p.key} style={[s.compactRow, i === positions.length - 1 ? null : s.rowLine]}>
          <View style={s.info}>
            <Text {...font.inter700_11} color={ink.secondary}>{p.name}</Text>
            <View style={s.compactName}><Text {...font.inter700_13} color={ink.heading}>{p.chosen ?? 'Not yet selected'}</Text></View>
          </View>
          <Pressable onPress={() => onToggle(p.key)} role="button" aria-expanded={openKey === p.key} aria-label={`Choose, ${p.name}`} hitSlop={LINK_REACH}>
            <Text {...font.inter700_11} color={ink.link}>{'Choose'}</Text>
          </Pressable>
        </View>
      ))}
    </Card>
  )
}

/** `.one-vote-note` (15) · `.one-vote-note2` (34): a 15-point gold icon, then 10.5 slate-600 at 1.5. Its margins are the screen's. */
export function VoteNote({ icon: glyph, text, style }: { icon: IconName; text: string; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={[s.note, style]}>
      <Icon name={glyph} size={icon[15]} color={ink.link} style={s.noteIcon} />
      <View style={s.shrink}><Text {...font.inter400_10_5_lh1_5} color={ink.label}>{text}</Text></View>
    </View>
  )
}

/** Not on a board: one honest sentence, when it is needed — the shared Field's attention line (gold, never red). */
export function Attention({ text, style }: { text: string; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={style}>
      <Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{text}</Text>
    </View>
  )
}

const mark = { flexShrink: 0, alignItems: 'center', justifyContent: 'center', borderWidth: stroke[2], borderColor: line.muted } as const

const s = StyleSheet.create({
  shrink: { flexShrink: 1 },
  // .ballot-head · .review-head
  ballotHead: { borderRadius: radius.card, padding: space[15] },
  reviewHead: { borderRadius: radius.card, paddingVertical: space[12], paddingHorizontal: space[14] },
  // h2 { margin: 3px 0 } · p, white at .7
  headTitle: { marginVertical: space[3] },
  headSub: { color: inkAlpha.onDarkSubtitle },
  // .pos-label: 11 below
  posLabel: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: space[11] },
  // .cand-row — 10 above and below on board 15, 9 on board 24; the rule under every row but the last
  rowA: { flexDirection: 'row', alignItems: 'center', gap: space[11], paddingVertical: space[10] },
  rowB: { flexDirection: 'row', alignItems: 'center', gap: space[11], paddingVertical: space[9] },
  rowLine: { borderBottomWidth: stroke[1], borderBottomColor: line.hairline },
  // .cand-avatar — 36 on board 15, 34 on board 24
  avatarA: { width: size[36], height: size[36], borderRadius: radius.avatar(size[36]), flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.tint },
  avatarB: { width: size[34], height: size[34], borderRadius: radius.avatar(size[34]), flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.tint },
  // .cand-info · .cs 1 below the name
  info: { flex: 1, minWidth: 0 },
  infoSub: { marginTop: space[1] },
  // .radio · .radio.on · .radio.on i
  radioA: { ...mark, width: size[20], height: size[20], borderRadius: radius.avatar(size[20]) },
  radioB: { ...mark, width: size[19], height: size[19], borderRadius: radius.avatar(size[19]) },
  radioOn: { borderColor: line.active },
  dotA: { width: size[11], height: size[11], borderRadius: radius.avatar(size[11]), backgroundColor: fill.primary },
  dotB: { width: size[10], height: size[10], borderRadius: radius.avatar(size[10]), backgroundColor: fill.primary },
  // .cbox · .cbox.on
  box: { ...mark, width: size[20], height: size[20], borderRadius: corner[6] },
  boxOn: { ...mark, width: size[20], height: size[20], borderRadius: corner[6], borderColor: line.active, backgroundColor: fill.primary },
  // board 24's card style="padding:4px 14px" · .compact-pos · .cp-name 2 below the role
  compactCard: { paddingVertical: space[4] },
  compactRow: { flexDirection: 'row', alignItems: 'center', gap: space[11], paddingVertical: space[11] },
  compactName: { marginTop: space[2] },
  // .one-vote-note: gap 9, 2 in at each side; its icon 1 down
  note: { flexDirection: 'row', gap: space[9], marginHorizontal: space[2] },
  noteIcon: { marginTop: space[1] },
})
