/**
 * resident-app-36 · Notice — rebuilt from the client's board, every value from the design tokens and parts.
 *
 * THE BOARD, TOP TO BOTTOM: the slim app bar (the back chevron, "Notice", the "…" button) · the gold pill ("Urgent ·
 * Security") · the title · the initials and "Posted by Property Manager · Today, 8:15 AM" · the text · the file's row ·
 * under a rule, "Marked as read · seen by 212 of 450 households". Nothing else is drawn.
 *
 * What each part SAYS is the hook's (useNotices.ts useNotice) — the board's own sample in fidelity mode; this file reads
 * no mode. Live:
 *   the pill    the server sends no topic, so it is "Urgent" alone when the notice is, and a general notice has none;
 *   the file    pressed, it is fetched and handed to the phone's viewer exactly as before (useAttachments.ts), and a
 *               sentence under the row says plainly when it cannot be;
 *   the rule    "Marked as read" is the SERVER's word: until the estate has the read, the line says where it stands;
 *   "…"         More options (M50 §4.3: in the design, so it works): share the notice through the phone's share sheet,
 *               or copy its text — in the app's own sheet, never the phone's grey dialog.
 *
 * FIXED, NOT A PAGE THAT SCROLLS: with the board's notice it fits 390 × 844 with room to spare. A longer notice keeps
 * its title above and its file and read line below, and only its text scrolls, in its own area.
 */

import { ActivityIndicator, RefreshControl, ScrollView, Share, StyleSheet, View } from 'react-native'
import * as Clipboard from 'expo-clipboard'
import { router, useLocalSearchParams } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, Icon, Pressable, Text, aboveGestureBar, c, useFidelityReady } from '@gs/core'
import { AppBar, NoteSheet, useNote } from '@/design/parts'
import { fill, font, icon, ink, line, radius, role, size, space, stroke } from '@/design/tokens'
import { useNotice, type NoticeFile, type NoticeModel, type ReadLine } from '../useNotices'

const LIST = '/(tabs)/notices'

/** The notice as words someone else can read: its title, then its paragraphs. */
export const noticeText = (title: string, paragraphs: readonly string[]): string => [title, ...paragraphs].join('\n\n')

/** The file's row is drawn 40 tall (its 18-point icon and 11 above and below); 2 more above and below make it 44 to a finger. */
const FILE_REACH = { top: space[2], bottom: space[2] }

/** `.nd-tag` — one pill: 9.5 · 700 navy-900 on gold. */
function Tag({ label }: { label: string }) {
  return (
    <View style={s.tag}>
      <Text {...font.inter700_9_5} color={ink.heading}>{label}</Text>
    </View>
  )
}

/** `.nd-meta` — the initials in their navy-100 circle, then the byline and when. */
function Byline({ m }: { m: NoticeModel }) {
  return (
    <View style={s.meta}>
      <View style={s.avatar}>
        <Text {...font.inter700_9} color={ink.onTint}>{m.initials}</Text>
      </View>
      <View style={s.shrink}>
        <Text {...font.inter500_11} color={ink.secondary}>{m.meta}</Text>
      </View>
    </View>
  )
}

/** `.attach-row` — the file's name beside its page; pressed, it opens. Its sentence under it only once there is one. */
function FileRow({ file, first }: { file: NoticeFile; first: boolean }) {
  return (
    <View style={first ? s.fileFirst : s.fileNext}>
      <Pressable onPress={file.open} role="button" aria-label={file.label} aria-busy={file.busy} hitSlop={FILE_REACH} style={({ pressed }) => [s.file, pressed ? s.pressed : null]}>
        <Icon name="order-banner" size={icon[18]} color={ink.action} />
        <View style={s.grow}>
          <Text {...font.inter600_11_5} color={ink.body}>{file.name}</Text>
        </View>
      </Pressable>
      {file.note ? (
        <View style={s.fileNote}>
          <Text {...font.inter500_11_5} color={file.note.attention ? ink.onGoldTint : ink.label} role={file.note.attention ? 'alert' : 'status'}>{file.note.text}</Text>
        </View>
      ) : null}
    </View>
  )
}

/** `.seen-row` — under its rule: the server's "Marked as read · seen by …", or where the read stands until then. */
function ReadRow({ read }: { read: ReadLine }) {
  return (
    <View style={read.attention ? s.seenWrapping : s.seen} role="status">
      <Icon name={read.icon} size={icon[15]} color={read.attention ? ink.onGoldTint : ink.action} />
      <View style={s.shrink}>
        <Text {...font.inter600_11} color={read.attention ? ink.onGoldTint : ink.label}>{read.text}</Text>
      </View>
    </View>
  )
}

/** No board draws these: the least that is honest, and a way back. */
function States({ m }: { m: NoticeModel }) {
  return (
    <ScrollView style={s.grow} contentContainerStyle={s.statePad} refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c(ink.action)} />}>
      {m.status === 'loading' ? <View style={s.loading}><ActivityIndicator color={c(ink.action)} /></View> : null}
      {m.status === 'error' ? <ErrorView tone="light" message={m.errorMessage ?? 'The notice could not be loaded.'} onRetry={m.refresh} /> : null}
      {m.status === 'denied' ? <EmptyState tone="light" icon="tab-notices" title="Notices aren't open to your account" body={m.errorMessage ?? 'Your account cannot see notices.'} /> : null}
      {m.status === 'missing' ? (
        <EmptyState
          tone="light"
          icon="tab-notices"
          title="We can't find that notice"
          body={m.stale ? 'It is not among the notices saved on this phone, and that list may be out of date. Pull down to check again.' : 'It is not among the notices for your household. The estate office may have withdrawn it, or it was for another phase.'}
          action={{ label: 'Back to notices', onPress: () => router.replace(LIST as never) }}
        />
      ) : null}
    </ScrollView>
  )
}

export default function NoticeDetailScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const m = useNotice(typeof id === 'string' ? id : undefined)
  const insets = useSafeAreaInsets()
  const note = useNote()
  useFidelityReady(m.status === 'ready')

  const text = noticeText(m.title, m.paragraphs)
  const share = () => void Share.share({ title: m.title, message: text }).catch(() => note.show({ title: 'Share this notice', lines: ['The phone could not open its share sheet just now. Try again.'] }))
  const copy = () =>
    void Clipboard.setStringAsync(text)
      .then(() => note.show({ title: 'Copied', lines: ["The notice's text is on your clipboard, ready to paste."], closeLabel: 'Done' }))
      .catch(() => note.show({ title: 'Copy the text', lines: ['The phone could not copy it just now. Try again.'] }))
  const options = () => note.show({ title: 'This notice', lines: [], actions: [{ label: 'Share this notice', onPress: share }, { label: 'Copy the text', onPress: copy }] })

  if (!m.notice) {
    return (
      <View style={s.screen}>
        <AppBar title="Notice" fallback={LIST} />
        <States m={m} />
      </View>
    )
  }

  return (
    <View style={s.screen}>
      {/* "…" says what it is (M50 §6.12): "More" under it. */}
      <AppBar title="Notice" fallback={LIST} right={{ icon: 'side-btn-11', label: 'More options', caption: 'More', onPress: options }} />
      {/* The board's 20 under the last line, or the home indicator's strip where that is deeper — not both. */}
      <View style={[s.page, { paddingBottom: aboveGestureBar(space[20], insets.bottom) }]}>
        {m.tag ? <Tag label={m.tag} /> : null}
        <Text {...font.poppins600_19_lh1_35} color={ink.heading} role="heading">{m.title}</Text>
        <Byline m={m} />

        {/* .nd-body's 14 under the byline, as a space of its own: given as the scroll area's margin, react-native-web
            applies it twice once a refresh control wraps the area (Android splits it), and the text sat 14 low. */}
        <View style={s.bodyGap} />
        {/* The text: as tall as it is, and never taller than the room left — then it scrolls inside its own area. */}
        <ScrollView style={s.body} refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c(ink.action)} />}>
          {m.paragraphs.map((text, i) => (
            <View key={i} style={i === 0 ? null : s.paragraph}>
              <Text {...role.body} color={ink.body}>{text}</Text>
            </View>
          ))}
        </ScrollView>

        {m.attachments.map((file, i) => <FileRow key={file.key} file={file} first={i === 0} />)}
        {m.read ? <ReadRow read={m.read} /> : null}
      </View>
      <NoteSheet note={note.note} onClose={note.close} />
    </View>
  )
}

const seen = { flexDirection: 'row', gap: space[9], marginTop: space[16], paddingTop: space[14], borderTopWidth: stroke[1], borderTopColor: line.hairline } as const

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  grow: { flex: 1, minWidth: 0 },
  shrink: { flexShrink: 1 },
  pressed: { opacity: 0.7 },
  // .content-scroll > .body-pad, laid out fixed
  page: { flex: 1, paddingTop: space[18], paddingHorizontal: space[20] },
  statePad: { paddingTop: space[18], paddingHorizontal: space[20], paddingBottom: space[20] },
  loading: { paddingTop: space[24], alignItems: 'center' },

  // .nd-tag: an inline-block, so the browser sets it 3 below the top of its line; its margin-bottom 10 is the title's gap
  tag: { alignSelf: 'flex-start', marginTop: space[3], marginBottom: space[10], paddingVertical: space[4], paddingHorizontal: space[10], borderRadius: radius.chip, backgroundColor: fill.gold },
  // .nd-meta · .nd-avatar
  meta: { flexDirection: 'row', alignItems: 'center', gap: space[9], marginTop: space[10] },
  avatar: { width: size[26], height: size[26], borderRadius: radius.avatar(size[26]), flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.tint },
  // .nd-body, 14 under the byline; each p 11 under the one before
  bodyGap: { height: size[14] },
  body: { flexGrow: 0 },
  paragraph: { marginTop: space[11] },

  // .attach-row: the last paragraph's 11 below (it outweighs the row's own 6), and 6 between two files
  fileFirst: { marginTop: space[11] },
  fileNext: { marginTop: space[6] },
  file: { flexDirection: 'row', alignItems: 'center', gap: space[11], paddingVertical: space[11], paddingHorizontal: space[13], borderRadius: radius.tile, backgroundColor: fill.tint },
  fileNote: { marginTop: space[6], marginHorizontal: space[2] },

  // .seen-row: 16 above, a navy-100 rule, 14 under it — and a sentence that wraps keeps its icon on its first line
  seen: { ...seen, alignItems: 'center' },
  seenWrapping: { ...seen, alignItems: 'flex-start' },
})
