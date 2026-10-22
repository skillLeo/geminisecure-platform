/**
 * guard-app-18 · Messages Inbox. Styles are the board's own, copied from the lifted file
 * (src/lifted/GuardApp18.tsx).
 *
 * The board draws guard-to-guard "Direct messages" and an online dot. The server keeps exactly two
 * conversations for a guard — the estate's broadcasts and the guard's own line to dispatch — so the
 * live inbox shows those two, and nothing it cannot stand behind.
 */

import { RefreshControl, ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { AppBar, EmptyState, ErrorView, Gradient, Icon, Pressable, SkeletonLines, Text, a, c, useFidelityReady, type GradientSpec } from '@gs/core'
import { inboxFixture } from '@/fidelity/messages'
import { useInbox } from '../useMessages'

const AVATAR: GradientSpec = { angle: 135, stops: [{ token: 'navy-600', at: 0 }, { token: 'navy-800', at: 1 }] }
const open = (thread: 'broadcast' | 'dispatch') => router.push(`/messages/${thread}` as never)

type RowProps = { avatar: 'broadcast' | 'dispatch' | { initials: string; online: boolean }; name: string; when: string | null; preview: string; unread: number; last: boolean; waiting?: boolean; onPress?: () => void }

function ThreadRow({ avatar, name, when, preview, unread, last, waiting, onPress }: RowProps) {
  const body = (
    <>
      {avatar === 'broadcast' ? (
        <Gradient spec={AVATAR} style={s.thAvatar}><Icon name="tab-messages" size={20} color="white" style={s.thAvatarSvg} /></Gradient>
      ) : avatar === 'dispatch' ? (
        <View style={s.thAvatar2}><Icon name="tab-messages" size={20} color="white" style={s.thAvatarSvg} /></View>
      ) : (
        <View style={s.thAvatar2}>
          <Text face="Inter-Bold" size={13} color="white">{avatar.initials}</Text>
          {avatar.online ? <View style={s.onlineDot} /> : null}
        </View>
      )}
      <View style={s.fill}>
        <View style={s.thTop}>
          <View style={s.thName}><Text face="Inter-Bold" size={13} color="white" numberOfLines={1}>{name}</Text></View>
          {when ? <View style={s.thTime}><Text face="Inter-SemiBold" size={10} color={waiting ? 'amber-500' : 'text-3'}>{when}</Text></View> : null}
        </View>
        <View style={s.thPreview}><Text face="Inter-Regular" size={11.5} color="text-2" numberOfLines={1}>{preview}</Text></View>
      </View>
      {unread > 0 ? <View style={s.unreadBadge}><Text face="Inter-Bold" size={9.5} color="g-navy-900">{unread > 9 ? '9+' : String(unread)}</Text></View> : null}
    </>
  )
  const row = last ? s.threadRow : s.threadRow2
  if (!onPress) return <View style={row}>{body}</View>
  return (
    <Pressable onPress={onPress} role="button" aria-label={`${name}. ${unread > 0 ? `${unread} unread. ` : ''}${preview}`} style={({ pressed }) => [row, { opacity: pressed ? 0.8 : 1 }]}>
      {body}
    </Pressable>
  )
}

const Heading = ({ children, top }: { children: string; top: number }) => (
  <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={{ marginTop: top, marginLeft: 2, marginRight: 2 }}>{children}</Text>
)

export default function MessagesInboxScreen() {
  const m = useInbox()
  useFidelityReady(m.status !== 'loading')
  // Unread counts are the server's (per guard, per phone) plus what this phone's sync queue is still
  // waiting to tell it; both arrive through the store, so coming back from a thread needs nothing re-read here.

  return (
    <View style={s.screen}>
      <AppBar title="Messages" left={null} right={{ icon: 'side-btn-6', label: 'Write to dispatch', onPress: () => open('dispatch') }} />
      <ScrollView contentContainerStyle={s.bodyPad} refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('amber-500')} />}>
        {m.fixture ? (
          <>
            <Heading top={2}>{'Site broadcast'}</Heading>
            <View style={s.card}>
              <ThreadRow avatar="broadcast" name={inboxFixture.broadcast.name} when={inboxFixture.broadcast.when} preview={inboxFixture.broadcast.preview} unread={inboxFixture.broadcast.unread} last />
            </View>
            <Heading top={16}>{'Direct messages'}</Heading>
            <View style={s.card2}>
              {inboxFixture.direct.map((t, i) => (
                <ThreadRow key={t.id} avatar={{ initials: t.initials, online: t.online }} name={t.name} when={t.when} preview={t.preview} unread={t.unread} last={i === inboxFixture.direct.length - 1} />
              ))}
            </View>
          </>
        ) : (
          <>
            {m.status === 'loading' ? <SkeletonLines tone="dark" lines={5} style={{ marginTop: 12 }} /> : null}
            {m.status === 'error' ? <ErrorView tone="dark" message={m.errorMessage ?? 'Messages could not be loaded.'} onRetry={m.refresh} /> : null}
            {m.status === 'forbidden' ? <EmptyState tone="dark" icon="tab-messages" title="Not available on this handset" body="This phone is not allowed to read or send messages. Contact your supervisor. In an emergency use the duress button on Home." /> : null}
            {m.status === 'ready' ? (
              <>
                {m.staleNote ? (
                  <View style={s.staleNote} role="status">
                    <Icon name="offline-note" size={14} color="amber-500" style={s.noteSvg} />
                    <View style={s.shrink}><Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-500">{m.staleNote}</Text></View>
                  </View>
                ) : null}
                <Heading top={2}>{'Site broadcast'}</Heading>
                <View style={s.card}>
                  <ThreadRow avatar="broadcast" name={m.siteName} when={m.threads.broadcast.when} preview={m.threads.broadcast.preview ?? 'Nothing has been broadcast to this estate yet.'} unread={m.threads.broadcast.unread} last onPress={() => open('broadcast')} />
                </View>
                <Heading top={16}>{'Dispatch'}</Heading>
                <View style={s.card2}>
                  <ThreadRow avatar="dispatch" name="Control room" when={m.threads.dispatch.when} waiting={m.threads.dispatch.when === 'Waiting to send'} preview={m.threads.dispatch.preview ?? 'Write to the control room: it is kept on this phone until it is sent.'} unread={m.threads.dispatch.unread} last onPress={() => open('dispatch')} />
                </View>
                <Text face="Inter-Regular" size={11} lh={1.5} color="text-3" style={s.footnote}>{'Messages here go to the control room, not to other guards. In an emergency do not write: hold the duress button on Home.'}</Text>
              </>
            ) : null}
          </>
        )}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  thAvatarSvg: { width: 20, height: 20, overflow: 'hidden' },
  thAvatar: { width: 44, height: 44, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 22 },
  thAvatar2: { width: 44, height: 44, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 22 },
  onlineDot: { position: 'absolute', right: 0, bottom: 0, width: 11, height: 11, backgroundColor: c('green-400'), borderRadius: 5.5, borderWidth: 2, borderColor: c('surface-900') },
  thName: { flexShrink: 1 },
  thTime: { flexGrow: 0, flexShrink: 0 },
  thTop: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', gap: 8 },
  thPreview: { overflow: 'hidden', marginTop: 2 },
  unreadBadge: { minWidth: 19, height: 19, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), borderRadius: 9.5, marginLeft: 8, paddingHorizontal: 4 },
  threadRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 12, gap: 12 },
  threadRow2: { flexDirection: 'row', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: c('border-700'), paddingVertical: 12, gap: 12 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13, paddingVertical: 2, marginTop: 8 },
  card2: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13, paddingVertical: 2, marginTop: 8, marginBottom: 12 },
  footnote: { marginTop: 4, marginHorizontal: 2 },
  noteSvg: { flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  staleNote: { flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 12, borderWidth: 1, borderColor: a('amber-500', 0.28), paddingHorizontal: 12, paddingVertical: 10, gap: 9, marginBottom: 14 },
})
