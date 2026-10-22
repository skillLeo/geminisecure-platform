/**
 * guard-app-34 · Sync Queue. Styles are the board's own, copied from the lifted file.
 * The board draws the waiting list; "Not accepted" and "Confirmed" use the same row and
 * card, in the boards' red and green, because a refusal and a receipt are records too.
 *
 * ONE VIRTUALISED LIST (M11 A4). Everything waiting is listed, and every confirmed act stays for a day as
 * proof: a guard at a busy gate carries hundreds, and all of them used to be mounted the moment the screen
 * opened. The sections are the rows of a FlatList now: each section's title, then its rows, the card drawn
 * across them as the gate log draws its card (the first row carries the card's top edge, the last its
 * bottom). The warning is the list's header and the closing note its footer, in the same order and styles.
 */

import { FlatList, RefreshControl, StyleSheet, View } from 'react-native'
import { AppBar, EmptyState, Icon, Pressable, Text, a, c, useFidelityReady, type ColorToken } from '@gs/core'
import { OUTBOX_LABEL } from '@gs/core/offline'
import { INFO } from '@/info/topics'
import { useSyncQueue, type QueueRow, type SyncQueueModel } from '../useSyncQueue'

const TONE: Record<QueueRow['state'], ColorToken> = { pending: 'amber-500', syncing: 'amber-500', confirmed: 'green-400', refused: 'red-500' }
// The board's word for a waiting item is "Queued"; the rest are the outbox's own words.
const WORD = (state: QueueRow['state']) => (state === 'pending' ? 'Queued' : OUTBOX_LABEL[state])

function Row({ row, last, onDismiss }: { row: QueueRow; last: boolean; onDismiss?: () => void }) {
  const tone = TONE[row.state]
  return (
    <View style={last ? s.queueRowLast : s.queueRow}>
      <View style={s.qIcon}>
        <Icon name={row.icon} size={17} color={tone} style={s.qIconSvg} />
      </View>
      <View style={s.fill}>
        <Text face="Inter-Bold" size={12.5} color="white">{row.title}</Text>
        <Text face="Inter-Regular" size={10.5} color="text-3" style={{ marginTop: 1 }}>{row.detail}</Text>
        {row.trouble ? (
          <Text face="Inter-Medium" size={10.5} lh={1.45} color={row.state === 'refused' ? 'red-500' : 'amber-500'} style={{ marginTop: 4 }} role="alert">{row.trouble}</Text>
        ) : null}
        {onDismiss ? (
          <Pressable onPress={onDismiss} role="button" aria-label={`I have read this: remove ${row.title} from the list`} hitSlop={10} style={{ marginTop: 6, alignSelf: 'flex-start' }}>
            <Text face="Inter-Bold" size={11} color="text-2">{'I have read this: remove'}</Text>
          </Pressable>
        ) : null}
      </View>
      <View style={s.qStatus}>
        <Icon name={row.state === 'confirmed' ? 'check' : row.state === 'refused' ? 'qa-tile-3' : 'bubble-meta'} size={13} color={tone} style={s.qStatusSvg} />
        <View style={s.shrink}>
          <Text face="Inter-Bold" size={9.5} color={tone}>{WORD(row.state)}</Text>
        </View>
      </View>
    </View>
  )
}

/** A row of the list: a section's title, or one of the section's rows and where it sits in the section's card. */
export type QueueItem =
  | { kind: 'title'; key: string; text: string; first: boolean }
  | { kind: 'row'; key: string; row: QueueRow; first: boolean; last: boolean; dismissable: boolean }

/**
 * The three sections in the order they have always been drawn — waiting, not accepted, confirmed — each
 * its title (with its count) and then its rows; a section with no rows draws nothing. `first` on a title
 * is the one that sits at the top (the warning, when there is one, sits above them all).
 */
export function queueItems(m: Pick<SyncQueueModel, 'waiting' | 'refused' | 'confirmed' | 'warning'>): QueueItem[] {
  const sections = [
    { key: 'waiting', title: 'Waiting to upload', rows: m.waiting, first: !m.warning, dismissable: false },
    { key: 'refused', title: 'Not accepted', rows: m.refused, first: m.waiting.length === 0 && !m.warning, dismissable: true },
    { key: 'confirmed', title: 'Confirmed', rows: m.confirmed, first: m.waiting.length + m.refused.length === 0 && !m.warning, dismissable: false },
  ]
  return sections.flatMap((section): QueueItem[] =>
    section.rows.length === 0
      ? []
      : [
          { kind: 'title', key: `title:${section.key}`, text: `${section.title} · ${section.rows.length}`, first: section.first },
          ...section.rows.map((row, i): QueueItem => ({ kind: 'row', key: `row:${row.id}`, row, first: i === 0, last: i === section.rows.length - 1, dismissable: section.dismissable })),
        ],
  )
}

export default function SyncQueueScreen() {
  const m = useSyncQueue()
  useFidelityReady(true)
  const nothing = m.waiting.length + m.refused.length + m.confirmed.length === 0
  const items = queueItems(m)

  const header = (
    <>
      {m.warning ? (
        <View style={[s.assureNote, s.warnNote]} role="alert">
          <Icon name="qa-tile-3" size={17} color="amber-500" style={s.assureNoteSvg} />
          <View style={s.shrink}><Text face="Inter-SemiBold" size={11.5} lh={1.55} color="amber-500">{m.warning}</Text></View>
        </View>
      ) : null}
      {nothing ? (
        <EmptyState tone="dark" icon="check" title="Everything is sent" body="Nothing is waiting on this phone. Whatever you record with no signal will appear here until it has uploaded." />
      ) : null}
    </>
  )

  const footer = nothing ? null : (
    <View style={s.assureNote}>
      <Icon name="privacy-note" size={17} color="navy-500" style={s.assureNoteSvg} />
      <View style={s.shrink}>
        <Text face="Inter-Regular" size={11.5} lh={1.55} color="text-2">
          {m.waiting.length > 0 || m.confirmed.length === 0
            ? "Everything here is already saved safely on this device. The moment you're back in range, it uploads in order: nothing needs a manual retry, and nothing gets lost."
            : 'Everything you recorded has reached the office. Confirmed items stay here for a day as your proof.'}
        </Text>
        {m.waiting.length > 0 && m.online ? (
          <Text face="Inter-Regular" size={11.5} lh={1.55} color="text-3" style={{ marginTop: 6 }}>{'You have signal now: pull this list down to send straight away.'}</Text>
        ) : null}
      </View>
    </View>
  )

  return (
    <View style={s.screen}>
      <AppBar title="Sync queue" info={INFO.offline} />
      <FlatList
        data={items}
        keyExtractor={(item) => item.key}
        renderItem={({ item }) =>
          item.kind === 'title' ? (
            <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper style={{ marginTop: item.first ? 2 : 20, marginHorizontal: 2 }}>{item.text}</Text>
          ) : (
            <View style={[s.cardMid, item.first ? s.cardFirst : null, item.last ? s.cardLast : null]}>
              <Row row={item.row} last={item.last} onDismiss={item.dismissable ? () => m.dismiss(item.row.id) : undefined} />
            </View>
          )
        }
        ListHeaderComponent={header}
        ListFooterComponent={footer}
        contentContainerStyle={s.bodyPad}
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('amber-500')} />}
      />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  qIconSvg: { width: 17, height: 17, overflow: 'hidden' },
  qIcon: { width: 38, height: 38, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 11 },
  qStatusSvg: { width: 13, height: 13, overflow: 'hidden' },
  qStatus: { flexDirection: 'row', alignItems: 'center', flexShrink: 0, gap: 5 },
  queueRow: { flexDirection: 'row', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: c('border-700'), paddingVertical: 12, gap: 12 },
  queueRowLast: { flexDirection: 'row', alignItems: 'center', paddingVertical: 12, gap: 12 },
  // The board's .card (surface-800 · radius 16 · 1px border-700 · padding 2/13 · margin-top 8), split across
  // a section's rows: every row its sides, the first the top edge, the last the bottom.
  cardMid: { backgroundColor: c('surface-800'), borderLeftWidth: 1, borderRightWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13 },
  cardFirst: { borderTopWidth: 1, borderTopLeftRadius: 16, borderTopRightRadius: 16, paddingTop: 2, marginTop: 8 },
  cardLast: { borderBottomWidth: 1, borderBottomLeftRadius: 16, borderBottomRightRadius: 16, paddingBottom: 2 },
  assureNoteSvg: { width: 17, height: 17, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  assureNote: { flexDirection: 'row', backgroundColor: a('navy-500', 0.1), borderRadius: 14, borderWidth: 1, borderColor: a('navy-500', 0.28), padding: 13, gap: 9, marginTop: 16 },
  warnNote: { backgroundColor: a('amber-500', 0.1), borderColor: a('amber-500', 0.28), marginTop: 0, marginBottom: 14 },
})
