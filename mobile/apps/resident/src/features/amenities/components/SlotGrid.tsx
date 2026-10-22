/**
 * Board 22's slot grid (`.slot-grid` › `.slot`), every value from the design tokens: three blocks to a row, 8 apart;
 * each 10 × 4 in, radius 11, navy-100, its hours in 11.5 · 700 navy-700. Chosen (`.slot.active`): navy-600, white.
 * Booked (`.slot.booked`) — and, live, begun or closed: slate-300, struck out, and not a control.
 *
 * The board's grid is two rows. A day with more blocks than that scrolls inside those two rows — the page itself does
 * not move — and opens at the first block that can still be chosen, the hours already gone above it.
 */

import { useEffect, useRef, useState } from 'react'
import { ScrollView, StyleSheet, View } from 'react-native'
import { Pressable, Text } from '@gs/core'
import { fill, font, ink, radius, space } from '@/design/tokens'
import type { SlotCell } from '../useAmenities'

const COLUMNS = 3
/** How many rows the board's grid shows. */
const BOARD_ROWS = 2
/** A block's touch area reaches into the 8 between the rows, never past it. */
const REACH = { top: space[4], bottom: space[4] }

function Cell({ slot, onPick }: { slot: SlotCell; onPick: (index: number) => void }) {
  // Begun, booked or closed: it says so to a screen reader, and is not a control.
  if (slot.off) {
    return (
      <View style={s.slot} accessible aria-label={slot.said}>
        <Text {...font.inter700_11_5} color={ink.faint} align="center" style={s.struck}>{slot.label}</Text>
      </View>
    )
  }
  return (
    <Pressable onPress={() => onPick(slot.index)} role="checkbox" aria-checked={slot.picked} aria-label={slot.said} hitSlop={REACH} style={slot.picked ? s.slotOn : s.slot}>
      <Text {...font.inter700_11_5} color={slot.picked ? ink.onDark : ink.onTint} align="center">{slot.label}</Text>
    </Pressable>
  )
}

export function SlotGrid({ slots, onPick, label, resetKey }: { slots: SlotCell[]; onPick: (index: number) => void; label: string; resetKey: string }) {
  const scroll = useRef<ScrollView>(null)
  const [rowHeight, setRowHeight] = useState(0)
  const rows = Array.from({ length: Math.ceil(slots.length / COLUMNS) }, (_, r) => slots.slice(r * COLUMNS, (r + 1) * COLUMNS))
  const firstOpen = slots.findIndex((slot) => !slot.off)
  const openRow = firstOpen < 0 ? 0 : Math.floor(firstOpen / COLUMNS)

  // A day opens at its first block that can still be chosen.
  useEffect(() => {
    if (rowHeight > 0) scroll.current?.scrollTo({ y: openRow * (rowHeight + space[8]), animated: false })
  }, [resetKey, rowHeight]) // eslint-disable-line react-hooks/exhaustive-deps

  // Two of the first row's height, and the gap between them, once it has been measured.
  const frame = rowHeight > 0 ? { maxHeight: rowHeight * BOARD_ROWS + space[8] * (BOARD_ROWS - 1) } : null

  return (
    <ScrollView ref={scroll} style={frame} contentContainerStyle={s.rows} nestedScrollEnabled role="group" aria-label={label}>
      {rows.map((row, r) => (
        <View key={row[0]?.key ?? r} style={s.row} onLayout={r === 0 ? (e) => setRowHeight(e.nativeEvent.layout.height) : undefined}>
          {row.map((slot) => (
            <Cell key={slot.key} slot={slot} onPick={onPick} />
          ))}
          {Array.from({ length: COLUMNS - row.length }, (_, i) => (
            <View key={`empty-${i}`} style={s.empty} />
          ))}
        </View>
      ))}
    </ScrollView>
  )
}

const block = { flex: 1, minWidth: 0, borderRadius: radius.buttonSmall, paddingVertical: space[10], paddingHorizontal: space[4] } as const

const s = StyleSheet.create({
  rows: { gap: space[8] },
  row: { flexDirection: 'row', gap: space[8] },
  slot: { ...block, backgroundColor: fill.tint },
  slotOn: { ...block, backgroundColor: fill.primary },
  empty: { flex: 1 },
  struck: { textDecorationLine: 'line-through' },
})
