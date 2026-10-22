/**
 * The settings boards' furniture (resident-app-18 · 19 · 20 · 33 · 38 · 39): a section heading, a row with an
 * icon tile and a chevron, the hairline between rows, the boards' own switch, the navy note, and the
 * confirmation the boards do not draw (the kit's ConfirmSheet, over the screen). Styles are the boards'.
 *
 * A row with no `onPress` is a DRAWING (a plain View): that is how a board-only row is shown for the
 * fidelity measurement, and how a fact with nothing to open is shown on a phone — never a dead control.
 */

import type { ReactNode, RefObject } from 'react'
import { StyleSheet, View } from 'react-native'
import { ConfirmSheet, Icon, Pressable, Text, c, type IconName } from '@gs/core'

export const Heading = ({ children, top = 10 }: { children: string; top?: number }) => (
  <Text face="Inter-Bold" size={11.5} ls={0.2} color="slate-500" role="heading" style={{ marginTop: top, marginLeft: 2, marginRight: 2 }}>{children}</Text>
)

export const Divider = ({ inset = 46 }: { inset?: number }) => <View style={[s.setDiv, { marginLeft: inset }]} />

/** Small print under a card: what a control really does, or why something is not here. */
export const Caption = ({ children, top = 8 }: { children: ReactNode; top?: number }) => (
  <Text face="Inter-Regular" size={11} lh={1.5} color="slate-500" style={{ marginTop: top, marginHorizontal: 2 }}>{children}</Text>
)

type NavRowProps = {
  icon: IconName
  title: string
  detail?: string | null
  onPress?: () => void
  /** Drawn unless the row opens nothing. */
  chevron?: boolean
  tile?: 34 | 36
  iconSize?: number
  vertical?: number
  titleSize?: number
  detailLh?: number
  // 'red' is for the one row that cannot be undone (deleting the account) — never for an ordinary warning — and is a red
  // OUTLINE, never a fill (M98, 12 Oct 2026): solid red is an open panic's colour and nothing else.
  tone?: 'navy' | 'amber' | 'red'
  bold?: boolean
  /** The board's danger row lets its text shrink instead of filling. */
  shrinkText?: boolean
}

export function NavRow({ icon, title, detail, onPress, chevron = true, tile = 34, iconSize = 16, vertical = 12, titleSize = 13, detailLh, tone = 'navy', bold = false, shrinkText = false }: NavRowProps) {
  const body = (
    <>
      <View style={[s.tile, { width: tile, height: tile, backgroundColor: c(tone === 'amber' ? 'amber-100' : tone === 'red' ? 'white' : 'navy-100') }, tone === 'red' ? { borderWidth: 1, borderColor: c('rd-red-700') } : null]}>
        <Icon name={icon} size={iconSize} color={tone === 'amber' ? 'amber-700' : tone === 'red' ? 'red-700' : 'navy-600'} style={{ width: iconSize, height: iconSize, overflow: 'hidden' }} />
      </View>
      <View style={shrinkText ? s.shrink : s.fill}>
        <Text face={bold ? 'Inter-Bold' : 'Inter-SemiBold'} size={titleSize} color="navy-900">{title}</Text>
        {detail ? <Text face="Inter-Regular" size={10.5} lh={detailLh} color="slate-500" style={{ marginTop: detailLh ? 2 : 1 }}>{detail}</Text> : null}
      </View>
      {chevron ? <Icon name="chevron-right" size={16} color="slate-300" style={s.chevSvg} /> : null}
    </>
  )
  if (!onPress) return <View style={[s.row, { paddingVertical: vertical }]}>{body}</View>
  return (
    <Pressable onPress={onPress} role="button" aria-label={detail ? `${title}. ${detail}` : title} style={({ pressed }) => [s.row, { paddingVertical: vertical, opacity: pressed ? 0.7 : 1 }]}>
      {body}
    </Pressable>
  )
}

export type SwitchState = 'on' | 'off' | 'locked'

/**
 * The boards' switch. `locked` is ON and cannot be turned off — drawn pale, as board 20 draws "always on for
 * safety" — and says why to a screen reader. With no `onChange` it is a drawing (the boards' own rows).
 */
export function Switch({ state, onChange, label, reason, busy = false }: { state: SwitchState; onChange?: (next: boolean) => void; label: string; reason?: string; busy?: boolean }) {
  const on = state !== 'off'
  const track = state === 'locked' ? 'navy-200' : on ? 'amber-500' : 'slate-300'
  const knob = state === 'locked' ? 'navy-500' : on ? 'navy-900' : 'white'
  const drawing = (
    <View style={[s.switchI, on ? { right: 2.5 } : { left: 2.5 }, { backgroundColor: c(knob) }]} />
  )
  // A board's own row (fidelity only): a picture of a switch, never a control.
  if (!onChange && !reason) return <View style={[s.switch, { backgroundColor: c(track) }]}>{drawing}</View>
  return (
    <Pressable
      onPress={() => onChange?.(!on)}
      disabled={state === 'locked' || busy}
      role="switch"
      aria-checked={on}
      aria-disabled={state === 'locked'}
      aria-busy={busy}
      aria-label={state === 'locked' ? `${label}: always on` : label}
      accessibilityHint={reason}
      hitSlop={12}
      style={[s.switch, { backgroundColor: c(track), opacity: busy ? 0.6 : 1 }]}
    >
      {drawing}
    </Pressable>
  )
}

export function ToggleRow({ title, detail, children, vertical = 12 }: { title: string; detail?: string | null; children: ReactNode; vertical?: number }) {
  return (
    <View style={[s.row, { paddingVertical: vertical }]}>
      <View style={s.fill}>
        <Text face="Inter-SemiBold" size={13} color="navy-900">{title}</Text>
        {detail ? <Text face="Inter-Regular" size={10.5} lh={1.4} color="slate-500" style={{ marginTop: 2 }}>{detail}</Text> : null}
      </View>
      {children}
    </View>
  )
}

/** Board 38's navy note. `warn` is the same box in amber, for something the resident should not miss. */
export function Note({ text, icon = 'why-note', tone = 'info', top = 11 }: { text: string; icon?: IconName; tone?: 'info' | 'warn'; top?: number }) {
  return (
    <View style={[s.infoBanner, { marginTop: top, backgroundColor: c(tone === 'warn' ? 'amber-100' : 'navy-100') }]} role={tone === 'warn' ? 'alert' : undefined}>
      <Icon name={icon} size={17} color={tone === 'warn' ? 'amber-700' : 'navy-600'} style={s.infoBannerSvg} />
      <View style={s.shrink}>
        <Text face="Inter-Regular" size={11} lh={1.5} color={tone === 'warn' ? 'amber-700' : 'navy-800'}>{text}</Text>
      </View>
    </View>
  )
}

/**
 * Asked BEFORE anything that cannot be undone: what will happen, in sentences, then two plain buttons — in the
 * ConfirmSheet over the screen (@gs/core), never a card swapped into the page where it could open below the fold.
 * The row that opens it stays where it is; `returnFocusTo` is that row, for a screen reader coming back.
 */
export function Confirm({ open, title, lines, blocked, problem, confirmLabel, cancelLabel, working, onConfirm, onCancel, returnFocusTo }: { open: boolean; title: string; lines: string[]; blocked?: string | null; problem?: string | null; confirmLabel: string; cancelLabel?: string; working: boolean; onConfirm: () => void; onCancel: () => void; returnFocusTo?: RefObject<View | null> }) {
  return <ConfirmSheet open={open} title={title} lines={lines} blocked={blocked} problem={problem} confirmLabel={confirmLabel} cancelLabel={cancelLabel} working={working} onConfirm={onConfirm} onCancel={onCancel} returnFocusTo={returnFocusTo} />
}

const s = StyleSheet.create({
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  tile: { flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 10 },
  chevSvg: { width: 16, height: 16, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  setDiv: { height: 1, backgroundColor: c('navy-100') },
  switchI: { position: 'absolute', top: 2.5, width: 18, height: 18, borderRadius: 9 },
  switch: { width: 40, height: 23, flexGrow: 0, flexShrink: 0, borderRadius: 20 },
  infoBannerSvg: { width: 17, height: 17, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  infoBanner: { flexDirection: 'row', borderRadius: 14, paddingHorizontal: 13, paddingVertical: 12, gap: 10 },
})
