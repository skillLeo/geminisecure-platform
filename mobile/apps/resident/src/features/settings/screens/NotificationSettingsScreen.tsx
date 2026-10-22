/**
 * resident-app-20 · Notifications — rebuilt from the board (M42 round 2), every value from the design tokens and parts.
 *
 * The board, top to bottom: By category (Urgent alerts · Security & access · Maintenance · Events · Governance), By
 * channel (Push notifications · Email), Quiet hours. Every row is the board's .trow — its title, its sub-line word for
 * word where the board draws one, and the shared Toggle: gold on, grey off, pale and locked for Urgent alerts.
 *
 * What each switch does is the hook's (useNotificationSettings), and every one of them works (M50 §4.2):
 *   a category      the notifier's own categories on this phone (features/notify/prefs) — what it checks before every
 *                   banner, and what the server is told for the pushes that arrive while the app is closed;
 *   Push            the phone's own permission (a tap asks, or opens the phone's Settings);
 *   Email           the account's, kept by the server: the news a push carries, by email too;
 *   Quiet hours     10:00 PM – 7:00 AM on the estate's clock, nothing but urgent alerts pushed inside them.
 * The board's SMS row is not drawn: no text-message provider is connected, and it comes back when one is (M50 §4.2).
 * Anything that stops a switch is said in the app's own sheet, never the phone's grey dialog.
 *
 * IT DOES NOT MOVE: with the board's content the page fits a 390 × 844 phone, so it is laid out fixed.
 */

import { Fragment } from 'react'
import { StyleSheet, View } from 'react-native'
import { useFidelityReady } from '@gs/core'
import { AppBar, Card, NoteSheet, SectionLabel, Toggle, useNote, type Note } from '@/design/parts'
import { fill, space } from '@/design/tokens'
import { Divider, TRow } from '../components/Rows'
import { useNotificationSettings, type NotificationSwitch } from '../useSettings'

const press = (row: NotificationSwitch, say: (note: Note) => void) => (next: boolean) => {
  if (row.notYet) say({ title: row.title, lines: [row.notYet] })
  else row.set?.(next)
}

function Switches({ rows, say }: { rows: NotificationSwitch[]; say: (note: Note) => void }) {
  return (
    <Card pad={13}>
      {rows.map((row, i) => (
        <Fragment key={row.key}>
          {i > 0 ? <Divider /> : null}
          <TRow title={row.title} line={row.line}>
            <Toggle on={row.on} locked={row.locked} onChange={press(row, say)} label={row.line ? `${row.title}. ${row.line}` : row.title} />
          </TRow>
        </Fragment>
      ))}
    </Card>
  )
}

export default function NotificationSettingsScreen() {
  const m = useNotificationSettings()
  const note = useNote()
  useFidelityReady(true)

  // What the server refused (Email with no connection, say) comes up in the same sheet as a tap's own answer.
  const shown = note.note ?? (m.problem ? { title: m.problem.title, lines: [m.problem.line] } : null)
  const close = () => {
    note.close()
    m.clearProblem()
  }

  return (
    <View style={s.screen}>
      <AppBar title="Notifications" fallback="/settings" />
      <View style={s.body}>
        <SectionLabel first>By category</SectionLabel>
        <Switches rows={m.categories} say={note.show} />

        <SectionLabel>By channel</SectionLabel>
        <Switches rows={m.channels} say={note.show} />

        <SectionLabel>Quiet hours</SectionLabel>
        <Switches rows={[m.quiet]} say={note.show} />
      </View>
      <NoteSheet note={shown} onClose={close} />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  // .body-pad
  body: { flex: 1, paddingTop: space[18], paddingHorizontal: space[20], paddingBottom: space[20] },
})
