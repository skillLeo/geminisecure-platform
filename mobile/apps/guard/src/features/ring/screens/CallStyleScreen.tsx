/**
 * More → Notifications → Call-style alerts (GUARD_RING_DESIGN §9.1). Whether a panic at this guard's estate will ring
 * this phone like a call — over the lock screen, with the alarm sound, until somebody answers — and, for every part
 * of that which is off, the phone's own settings screen that turns it on.
 *
 * NOTHING HERE CLAIMS WHAT THE PHONE HAS NOT SAID. Each row is read back from Android at the moment the screen is
 * open, and again whenever the guard comes back from Settings. The manufacturer's own battery manager cannot be read
 * at all, so it is advice, not a row. And the only proof there is, is a real test ring sent by the server as a push:
 * the button below asks for one. The office's console is told what this phone reports, and when.
 *
 * Styles follow the Notifications screen (guard-app-36), whose card and row this extends. No board draws this
 * screen; it is not part of the fidelity set.
 */

import { ScrollView, StyleSheet, View } from 'react-native'
import { AppBar, Icon, Pressable, Text, a, c, useFidelityReady } from '@gs/core'
import type { ReadinessRow, RowState } from '../readiness'
import { useCallStyle } from '../useCallStyle'

const STATE_WORD: Record<RowState, string> = { on: 'On', off: 'Off', low: 'Low', unknown: 'Not known' }
const STATE_COLOR: Record<RowState, 'green-400' | 'red-500' | 'amber-500' | 'text-3'> = { on: 'green-400', off: 'red-500', low: 'amber-500', unknown: 'text-3' }
const SUMMARY_COLOR = { ready: 'green-400', partial: 'amber-500', not_ready: 'red-500' } as const

function Row({ row, first, onFix }: { row: ReadinessRow; first: boolean; onFix: () => void }) {
  return (
    <>
      {first ? null : <View style={s.setDiv} />}
      <View style={s.trow}>
        <View style={s.fill}>
          <Text face="Inter-SemiBold" size={13} color="white">{row.title}</Text>
          <Text face="Inter-Regular" size={10.5} lh={1.4} color="text-3" style={{ marginTop: 2 }}>{row.detail}</Text>
        </View>
        <View style={s.side}>
          <Text face="Inter-Bold" size={11} color={STATE_COLOR[row.state]}>{STATE_WORD[row.state]}</Text>
          {row.fix && row.fixLabel ? (
            <Pressable onPress={onFix} role="button" aria-label={`${row.fixLabel}: ${row.title}`} hitSlop={10} style={s.fixBtn}>
              <Text face="Inter-Bold" size={11.5} color="g-navy-900">{row.fixLabel}</Text>
            </Pressable>
          ) : null}
        </View>
      </View>
    </>
  )
}

export default function CallStyleScreen() {
  const call = useCallStyle()
  useFidelityReady(true)

  return (
    <View style={s.screen}>
      <AppBar title="Call-style alerts" />
      <ScrollView contentContainerStyle={s.bodyPad}>
        {call.note ? (
          <View style={s.card}>
            <Text face="Inter-Regular" size={12} lh={1.5} color="text-2">{call.note}</Text>
          </View>
        ) : (
          <>
            <View style={s.card}>
              <Text face="Inter-Bold" size={15} color={call.summary ? SUMMARY_COLOR[call.summary.state] : 'text-3'}>{call.summary ? call.summary.headline : 'Checking this phone…'}</Text>
              {call.summary ? <Text face="Inter-Regular" size={11} lh={1.5} color="text-2" style={{ marginTop: 6 }}>{call.summary.detail}</Text> : null}
            </View>

            {call.rows.length > 0 ? (
              <View style={s.card}>
                {call.rows.map((row, i) => (
                  <Row key={row.key} row={row} first={i === 0} onFix={() => void (row.fix ? call.open(row.fix) : undefined)} />
                ))}
              </View>
            ) : null}

            {call.advice ? (
              <View style={s.note}>
                <Icon name="qa-tile-3" size={16} color="amber-500" style={s.noteIcon} />
                <View style={s.fill}><Text face="Inter-Regular" size={11} lh={1.5} color="text-2">{call.advice}</Text></View>
              </View>
            ) : null}

            <Pressable onPress={() => void call.sendTest()} role="button" hitSlop={6} style={[s.testBtn, call.testing ? s.testBusy : null]}>
              <Text face="Inter-Bold" size={13} color="g-navy-900">{call.testing ? 'Sending a test ring…' : 'Send a test ring'}</Text>
            </Pressable>
          </>
        )}

        {call.message ? (
          <View style={s.note}>
            <Icon name="qa-tile-3" size={16} color="amber-500" style={s.noteIcon} />
            <View style={s.fill}><Text face="Inter-Regular" size={11} lh={1.5} color="text-2">{call.message}</Text></View>
          </View>
        ) : null}

        <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginHorizontal: 2, marginTop: 8 }}>
          {'Only a real test ring proves this phone. What this screen shows is also reported to the office, so the console can say which phones can be rung like a call.'}
        </Text>
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  trow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 11, gap: 12 },
  side: { alignItems: 'flex-end', gap: 6 },
  setDiv: { height: 1, backgroundColor: c('border-700') },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14, marginBottom: 12 },
  fixBtn: { backgroundColor: c('amber-500'), borderRadius: 20, paddingHorizontal: 14, paddingVertical: 7 },
  testBtn: { backgroundColor: c('amber-500'), borderRadius: 14, paddingVertical: 14, alignItems: 'center', marginBottom: 12 },
  testBusy: { opacity: 0.6 },
  note: { flexDirection: 'row', backgroundColor: a('amber-500', 0.12), borderRadius: 12, borderWidth: 1, borderColor: a('amber-500', 0.3), paddingHorizontal: 13, paddingVertical: 12, gap: 9, marginBottom: 12 },
  noteIcon: { width: 16, height: 16, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
})
