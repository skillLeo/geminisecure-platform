/**
 * guard-app-14 · Messages (a thread). Styles are the board's own, copied from the lifted file
 * (src/lifted/GuardApp14.tsx).
 *
 * The board draws a named person, "● Online", a call button and an attach button. The server has no
 * presence, serves no number and a message is text only — so the live thread is "Control room" or
 * "Site broadcast", with neither control. What has not reached dispatch says "Waiting to send".
 */

import { useRef } from 'react'
import { KeyboardAvoidingView, Platform, RefreshControl, ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, Icon, Pressable, SkeletonLines, Text, a, aboveGestureBar, c, now, useFidelityReady } from '@gs/core'
import { threadFixture } from '@/fidelity/messages'
import { MAX_BODY, dayHeading, type ThreadKind } from '../messages'
import { useThread } from '../useMessages'

const back = () => (router.canGoBack() ? router.back() : router.replace('/(tabs)/messages' as never))

function Bubble({ mine, body, meta, tone, icon, top, last }: { mine: boolean; body: string; meta: string | null; tone: 'text-3' | 'amber-500' | 'red-500'; icon: 'check' | 'bubble-meta' | null; top: number; last: boolean }) {
  if (!mine) {
    return (
      <View style={[s.bubbleRow, { marginTop: top }, last ? s.lastRow : null]}>
        <View style={s.bubble}><Text face="Inter-Regular" size={13} lh={1.45} color="text-2">{body}</Text></View>
        {meta ? <View style={s.theirMeta}><Text face="Inter-SemiBold" size={9.5} color="text-3">{meta}</Text></View> : null}
      </View>
    )
  }
  return (
    <View style={[s.bubbleRowMine, { marginTop: top }, last ? s.lastRow : null]}>
      <View style={s.mineCol}>
        <View style={s.bubble2}><Text face="Inter-Medium" size={13} lh={1.45} color="g-navy-900">{body}</Text></View>
        {meta ? (
          <View style={s.bubbleMeta} role={tone === 'red-500' ? 'alert' : undefined}>
            <View style={s.shrink}><Text face="Inter-SemiBold" size={9.5} color={tone}>{meta}</Text></View>
            {icon ? <Icon name={icon} size={12} color={tone} style={s.bubbleMetaSvg} /> : null}
          </View>
        ) : null}
      </View>
    </View>
  )
}

export default function ThreadScreen() {
  const params = useLocalSearchParams<{ thread?: string; draft?: string }>()
  const kind: ThreadKind = params.thread === 'broadcast' ? 'broadcast' : 'dispatch'
  const m = useThread(kind, typeof params.draft === 'string' ? params.draft : '')
  const insets = useSafeAreaInsets()
  const scroll = useRef<ScrollView | null>(null)
  useFidelityReady(m.status !== 'loading')

  const title = m.fixture ? threadFixture.title : kind === 'broadcast' ? 'Site broadcast' : 'Control room'
  const subtitle = m.fixture ? threadFixture.presence : kind === 'broadcast' ? 'From dispatch to everyone at this estate' : m.online ? 'Your line to dispatch' : 'No signal: messages wait on this phone'
  const empty = m.status === 'ready' && m.groups.length === 0

  return (
    <KeyboardAvoidingView style={s.screen} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      {/* M18E: live, the status bar is allowed for once — the inset, then a 56-point row with everything centred, so the
          back button never rises under the clock. The board's own geometry under the fidelity harness. */}
      <View style={m.fixture ? [s.appbar, { height: insets.top + 46 }] : [s.appbarLive, { paddingTop: insets.top + 8, paddingLeft: 14 + insets.left, paddingRight: 14 + insets.right }]}>
        <Pressable onPress={back} role="button" aria-label="Back" hitSlop={7} style={({ pressed }) => [s.sideBtn, { backgroundColor: a('white', pressed ? 0.16 : 0.08) }]}>
          <Icon name="chevron-left" size={18} color="white" style={s.sideBtnSvg} />
        </Pressable>
        <View style={s.fill}>
          <Text face="Poppins-SemiBold" size={14.5} color="white" align="center" role="heading" numberOfLines={1}>{title}</Text>
          <Text face="Inter-SemiBold" size={10} color={m.fixture ? 'green-400' : !m.online && kind === 'dispatch' ? 'amber-500' : 'text-3'} align="center" numberOfLines={1} style={{ marginTop: 2 }}>{subtitle}</Text>
        </View>
        {m.fixture ? (
          <View style={s.sideBtn}><Icon name="call-btn" size={18} color="white" style={s.sideBtnSvg} /></View>
        ) : (
          <View style={s.sideSpacer} />
        )}
      </View>

      <ScrollView
        ref={scroll}
        style={s.fill}
        contentContainerStyle={s.chatScroll}
        onContentSizeChange={() => scroll.current?.scrollToEnd({ animated: false })}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('amber-500')} />}
      >
        {m.fixture ? (
          <>
            <Text face="Inter-SemiBold" size={10} color="text-3" align="center" style={{ marginTop: 6 }}>{threadFixture.day}</Text>
            {threadFixture.rows.map((row, i) => (
              <Bubble key={row.id} mine={row.mine} body={row.body} meta={row.time} tone={row.waiting ? 'amber-500' : 'text-3'} icon={row.mine ? (row.waiting ? 'bubble-meta' : 'check') : null} top={i === 0 ? 14 : 12} last={i === threadFixture.rows.length - 1} />
            ))}
          </>
        ) : (
          <>
            {m.status === 'loading' ? <SkeletonLines tone="dark" lines={6} style={{ marginTop: 12 }} /> : null}
            {m.status === 'error' ? <ErrorView tone="dark" message={m.errorMessage ?? 'Messages could not be loaded.'} onRetry={m.refresh} /> : null}
            {m.status === 'forbidden' ? <EmptyState tone="dark" icon="tab-messages" title="Not available on this handset" body="This phone is not allowed to read or send messages. Contact your supervisor." /> : null}
            {m.status === 'ready' && m.staleNote ? (
              <View style={s.staleNote} role="status">
                <Icon name="offline-note" size={14} color="amber-500" style={s.noteSvg} />
                <View style={s.shrink}><Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-500">{m.staleNote}</Text></View>
              </View>
            ) : null}
            {empty ? (
              <EmptyState
                tone="dark"
                icon="tab-messages"
                title={kind === 'broadcast' ? 'No broadcasts yet' : 'No messages yet'}
                body={kind === 'broadcast' ? 'When dispatch sends a message to everyone at this estate, it appears here.' : 'Write to the control room below. With no signal your message is kept on this phone and sent when the connection is back.'}
              />
            ) : null}
            {m.status === 'ready'
              ? m.groups.map((group, g) => (
                  <View key={group.iso}>
                    <Text face="Inter-SemiBold" size={10} color="text-3" align="center" style={{ marginTop: g === 0 ? 6 : 18 }}>{dayHeading(group.iso, now())}</Text>
                    {group.rows.map((row, i) => (
                      <Bubble
                        key={row.id}
                        mine={row.mine}
                        body={row.body}
                        meta={row.state === 'sent' ? row.time : `${row.note} · ${row.time}`}
                        tone={row.state === 'waiting' ? 'amber-500' : row.state === 'refused' ? 'red-500' : 'text-3'}
                        icon={row.mine ? (row.state === 'sent' ? 'check' : row.state === 'waiting' ? 'bubble-meta' : null) : null}
                        top={i === 0 ? 14 : 12}
                        last={g === m.groups.length - 1 && i === group.rows.length - 1}
                      />
                    ))}
                  </View>
                ))
              : null}
          </>
        )}
      </ScrollView>

      {m.fixture ? (
        <View style={[s.chatInputRow, { paddingBottom: insets.bottom }]}>
          <View style={s.att}><Icon name="att" size={16} color="text-2" style={s.attSvg} /></View>
          <View style={s.ciField}><View style={s.shrink}><Text face="Inter-Regular" size={12.5} color="text-3">{threadFixture.placeholder}</Text></View></View>
          <View style={s.send}><Icon name="send" size={15} color="g-navy-900" style={s.sendSvg} /></View>
        </View>
      ) : m.canWrite && m.status !== 'forbidden' ? (
        <View>
          {m.problem ? <Text face="Inter-SemiBold" size={11} lh={1.45} color="red-500" role="alert" style={s.problem}>{m.problem}</Text> : null}
          <View style={[s.chatInputRow, { paddingBottom: aboveGestureBar(12, insets.bottom) }]}>
            <View style={s.ciField}>
              <TextInput
                value={m.draft}
                onChangeText={m.setDraft}
                placeholder="Message the control room…"
                placeholderTextColor={c('text-3')}
                maxLength={MAX_BODY}
                multiline
                aria-label="Message to the control room"
                style={s.ciInput}
              />
            </View>
            <Pressable onPress={m.send} disabled={m.sending} role="button" aria-label={m.online ? 'Send' : 'Save to send when the connection is back'} hitSlop={6} style={({ pressed }) => [s.send, { opacity: m.sending ? 0.5 : pressed ? 0.8 : 1 }]}>
              <Icon name="send" size={15} color="g-navy-900" style={s.sendSvg} />
            </Pressable>
          </View>
        </View>
      ) : !m.canWrite ? (
        <View style={[s.readOnly, { paddingBottom: aboveGestureBar(12, insets.bottom) }]}>
          <Text face="Inter-Regular" size={11.5} lh={1.45} color="text-3" align="center">{'Broadcasts cannot be replied to here.'}</Text>
          <Pressable onPress={() => router.replace('/messages/dispatch' as never)} role="button" hitSlop={8} style={{ marginTop: 4 }}>
            <Text face="Inter-Bold" size={12} color="amber-500" align="center">{'Write to the control room'}</Text>
          </Pressable>
        </View>
      ) : null}
    </KeyboardAvoidingView>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  sideBtnSvg: { width: 18, height: 18, overflow: 'hidden' },
  sideBtn: { width: 34, height: 34, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: a('white', 0.08), borderRadius: 17 },
  sideSpacer: { width: 34, flexGrow: 0, flexShrink: 0 },
  appbar: { flexDirection: 'row', alignItems: 'flex-end', paddingBottom: 16, borderBottomWidth: 1, borderBottomColor: c('border-700'), backgroundColor: c('surface-800'), paddingHorizontal: 18 },
  appbarLive: { flexDirection: 'row', alignItems: 'center', minHeight: 56, paddingBottom: 8, borderBottomWidth: 1, borderBottomColor: c('border-700'), backgroundColor: c('surface-800') },
  chatScroll: { paddingHorizontal: 18, paddingVertical: 16 },
  bubble: { maxWidth: '78%', borderTopLeftRadius: 14, borderTopRightRadius: 14, borderBottomRightRadius: 14, borderBottomLeftRadius: 3, backgroundColor: c('surface-800'), flexShrink: 1, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13, paddingVertical: 10 },
  bubble2: { maxWidth: '78%', borderTopLeftRadius: 14, borderTopRightRadius: 14, borderBottomRightRadius: 3, borderBottomLeftRadius: 14, backgroundColor: c('amber-500'), paddingHorizontal: 13, paddingVertical: 10 },
  bubbleRow: { flexDirection: 'row', flexWrap: 'wrap' },
  bubbleRowMine: { flexDirection: 'row', justifyContent: 'flex-end' },
  mineCol: { flexShrink: 1 },
  theirMeta: { width: '100%', marginTop: 4 },
  lastRow: { marginBottom: 12 },
  bubbleMetaSvg: { width: 12, height: 12, overflow: 'hidden' },
  bubbleMeta: { flexDirection: 'row', alignItems: 'center', justifyContent: 'flex-end', gap: 5, marginTop: 4 },
  attSvg: { width: 16, height: 16, overflow: 'hidden' },
  att: { width: 36, height: 36, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 18 },
  ciField: { minHeight: 40, flex: 1, flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-700'), borderRadius: 20, paddingHorizontal: 14 },
  ciInput: { flex: 1, fontFamily: 'Inter-Regular', fontSize: 12.5, color: c('white'), paddingVertical: 10, maxHeight: 96 },
  sendSvg: { width: 15, height: 15, overflow: 'hidden' },
  send: { width: 36, height: 36, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), borderRadius: 18 },
  chatInputRow: { flexDirection: 'row', alignItems: 'center', paddingTop: 12, borderTopWidth: 1, borderTopColor: c('border-700'), backgroundColor: c('surface-800'), paddingHorizontal: 16, gap: 10 },
  problem: { paddingHorizontal: 18, paddingTop: 8, backgroundColor: c('surface-800') },
  readOnly: { paddingTop: 12, paddingHorizontal: 18, borderTopWidth: 1, borderTopColor: c('border-700'), backgroundColor: c('surface-800') },
  noteSvg: { flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  staleNote: { flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 12, borderWidth: 1, borderColor: a('amber-500', 0.28), paddingHorizontal: 12, paddingVertical: 10, gap: 9, marginBottom: 6 },
})
