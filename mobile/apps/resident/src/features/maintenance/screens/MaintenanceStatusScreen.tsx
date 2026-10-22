/**
 * resident-app-26 · Request #1042 — one maintenance request's status, rebuilt from the client's board, every value from
 * the design's tokens and shared parts. All behaviour lives in useTicket(); this file only draws it, the same way for a
 * phone and for the board's own content.
 *
 * THE BOARD, TOP TO BOTTOM: the slim app bar, "Request #1042" · the summary card — the wrench tile, the title, the
 * resident's words between quotation marks, "Submitted Sep 2 · …" · "Status" and the six stages · "Assigned vendor"
 * and the vendor's card with its call button · "Add a comment" and its box, "Message the Property Manager…", with the
 * send button.
 *
 * THE WORKING HALF (M47 §11a–b), all of it real:
 *   - "Assigned vendor": the company and its initials, "Tech: Owen Grant · today, 2:00–4:00 PM", and the round button
 *     that rings them — the technician's number, else the company's. No number, no button; nobody on the job, no card;
 *   - "Add a comment": the box takes the message and the send button sends it — a note on the request's history, where
 *     the office works the job — and under the box, in the screen's own words, whether it was sent, is waiting for
 *     signal, or was refused (never a system dialog, U7).
 * The timeline names who in the office did each step, by role ("Acknowledged · Sep 3, 8:15 AM · Property Manager").
 *
 * NOT ON THE BOARD, AND KEPT TO THE LEAST THAT IS HONEST: "Is it fixed?" (FixedAnswer) once the office marks the work
 * completed — the board draws the job in progress, and that answer is how "Closed by you" is reached; "Messages"
 * (M54 §2.2), the thread the household and the office both write into, once anybody has; a cancelled request's
 * timeline ends at "Cancelled"; before there is a request to show, a spinner or the kit's words.
 *
 * With the board's content the page fits a 390 × 844 phone and nothing moves. Only real data longer than the board's
 * (a long description, "Is it fixed?") lets it scroll, so nothing is ever out of reach; a pull fetches the request again.
 */

import { useRef, useState } from 'react'
import { ActivityIndicator, Linking, RefreshControl, ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { Redirect, router, useLocalSearchParams } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, Icon, Pressable, Text, aboveGestureBar, c, useFidelityReady } from '@gs/core'
import { normalisePhone } from '@gs/core/lib'
import { AppBar, Card, IconTile, SectionLabel, Timeline, type TimelineStep } from '@/design/parts'
import { fill, font, icon, ink, line, radius, role, shadow, size, space, stroke } from '@/design/tokens'
import { FixedAnswer } from '../components/FixedAnswer'
import type { BoardStep, MessageRow } from '../tickets'
import { COMMENT_MAX, useTicket, type CommentModel, type TicketModel } from '../useTickets'

const COMMENT_PLACEHOLDER = 'Message the Property Manager…'

/** The 36-point call button's touch area, grown to 44. */
const CALL_REACH = { top: size[4], bottom: size[4], left: size[4], right: size[4] }

/** The board's three dots: done navy with a tick, now gold with its mark, not yet a white ring. Cancelled is where it stopped. */
const DOT: Record<BoardStep['state'], TimelineStep['state']> = { done: 'done', current: 'active', stopped: 'active', todo: 'pending' }
const SAID: Record<BoardStep['state'], string> = { done: 'done', current: 'where it is now', stopped: 'stopped here', todo: 'not yet' }

/* ---- the board's parts --------------------------------------------------------------------------------- */

/** `.status-summary` — the wrench tile; the title, the resident's words and when it was submitted. */
function Summary({ m }: { m: TicketModel }) {
  return (
    <Card>
      <View style={s.summary}>
        <IconTile name="rp-icon" tile={44} glyph={20} shape="tile" />
        <View style={s.summaryText}>
          <Text {...role.cardTitle} color={ink.heading}>{m.title}</Text>
          {m.words ? <View style={s.words}><Text {...font.inter400_11} color={ink.secondary}>{m.words}</Text></View> : null}
          <View style={s.meta}><Text {...font.inter600_10} color={ink.faint}>{m.meta}</Text></View>
        </View>
      </View>
    </Card>
  )
}

/** `.tl2` — the six stages in the shared timeline, read to a screen reader as one list of what each stage is. */
function Status({ steps }: { steps: BoardStep[] }) {
  const spoken = steps.map((step) => [step.title, SAID[step.state], step.line].filter(Boolean).join(', ')).join('. ')
  return (
    <Card>
      <View accessible aria-label={`Status. ${spoken}`}>
        <Timeline steps={steps.map((step) => ({ title: step.title, line: step.line, state: DOT[step.state], activeIcon: step.state === 'stopped' ? 'act-result' : 'cp-dot' }))} />
      </View>
    </Card>
  )
}

/** `.vendor-card` — the avatar, the company and the technician's line, and the round button that rings them. */
function Vendor({ vendor }: { vendor: NonNullable<TicketModel['vendor']> }) {
  // Said in the app's one format, "+1 876 555 0148" (M50 §6.11); another country's number without its + as written.
  const phone = vendor.phone ? (normalisePhone(vendor.phone) ?? vendor.phone) : null
  return (
    <Card>
      <View style={s.vendor}>
        <View style={s.avatar}>{vendor.initials ? <Text {...font.inter700_12} color={ink.onTint}>{vendor.initials}</Text> : null}</View>
        <View style={s.vendorText}>
          {vendor.name ? <Text {...font.inter700_12_5} color={ink.heading}>{vendor.name}</Text> : null}
          {vendor.line ? <View style={vendor.name ? s.vendorLine : null}><Text {...font.inter400_10_5} color={ink.secondary}>{vendor.line}</Text></View> : null}
        </View>
        {phone ? (
          <Pressable onPress={() => void Linking.openURL(`tel:${phone.replace(/[^\d+]/g, '')}`).catch(() => undefined)} role="button" aria-label={`Call ${vendor.name ?? 'the vendor'}, ${phone}`} hitSlop={CALL_REACH} style={({ pressed }) => [s.call, pressed ? s.pressed : null]}>
            <Icon name="call-btn" size={icon[16]} color={ink.action} />
          </Pressable>
        ) : null}
      </View>
    </Card>
  )
}

/**
 * "Messages" (M54 §2.2): one thread per request, both ends. Who wrote each one (the office by its role, the household
 * by name), when, and the words. Not on the board, which draws no thread, so it is drawn only when somebody has written.
 */
function Messages({ rows }: { rows: MessageRow[] }) {
  return (
    <Card>
      {rows.map((row, i) => (
        <View key={row.key} style={i > 0 ? s.messageGap : null} accessible aria-label={`${row.from}${row.when ? `, ${row.when}` : ''}: ${row.text}`}>
          <View style={s.messageHead}>
            <Text {...font.inter700_12} color={row.office ? ink.action : ink.heading}>{row.from}</Text>
            {row.when ? <Text {...font.inter600_10} color={ink.faint}>{row.when}</Text> : null}
          </View>
          <Text {...font.inter400_12_5} color={ink.secondary}>{row.text}</Text>
        </View>
      ))}
    </Card>
  )
}

/**
 * `.comment-input` — the box, which takes the message, and its round send button, which sends it. With nothing written
 * the button puts the cursor in the box: it never does nothing.
 */
function Comment({ comment }: { comment: CommentModel }) {
  const box = useRef<TextInput>(null)
  const [focused, setFocused] = useState(false)
  const empty = comment.text.trim() === ''
  return (
    <Card>
      <View style={[s.comment, focused ? s.commentFocused : null]}>
        <View style={s.commentText}>
          {comment.text === '' ? <View style={s.placeholder}><Text {...font.inter400_12_5} color={ink.secondary} numberOfLines={1}>{COMMENT_PLACEHOLDER}</Text></View> : null}
          <TextInput
            ref={box}
            value={comment.text}
            onChangeText={comment.setText}
            aria-label={COMMENT_PLACEHOLDER}
            onFocus={() => setFocused(true)}
            onBlur={() => setFocused(false)}
            onSubmitEditing={comment.send}
            returnKeyType="send"
            maxLength={COMMENT_MAX}
            style={s.commentInput}
          />
        </View>
        <Pressable onPress={empty ? () => box.current?.focus() : comment.send} role="button" aria-label="Send the message" accessibilityHint={empty ? 'Write your message first.' : undefined} hitSlop={CALL_REACH} style={({ pressed }) => [s.send, pressed ? s.pressed : null]}>
          <Icon name="send" size={icon[14]} color={ink.onDark} />
        </Pressable>
      </View>
      {comment.state ? (
        <View style={s.commentState}>
          <Text {...font.inter500_11_5} color={comment.state.kind === 'refused' ? ink.onGoldTint : ink.secondary} role={comment.state.kind === 'refused' ? 'alert' : undefined} aria-live="polite">{comment.state.line}</Text>
        </View>
      ) : null}
    </Card>
  )
}

/** Before there is a request to show: the least that does not leave the screen blank. */
function NotShown({ m }: { m: TicketModel }) {
  if (m.status === 'loading') return <View style={s.loading}><ActivityIndicator color={fill.primary} /></View>
  if (m.status === 'error') return <ErrorView tone="light" message={m.errorMessage ?? 'Your request could not be loaded.'} onRetry={m.refresh} />
  if (m.status === 'denied') return <EmptyState tone="light" icon="privacy-note" title="Not available for this account" body={m.errorMessage ?? 'Your account cannot see maintenance requests.'} />
  const elsewhere = m.elsewhere
  // A status-change notification for a security report opens its own screen straight away (M98), not a page that says where it is.
  return elsewhere ? (
    <Redirect href={elsewhere as never} />
  ) : (
    <EmptyState tone="light" icon="rp-icon" title="We can't find that request" body="It is not among the requests on this phone." action={{ label: 'See all requests', onPress: () => router.replace('/requests' as never) }} />
  )
}

/* ---- the screen ---------------------------------------------------------------------------------------- */

export default function MaintenanceStatusScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const m = useTicket(typeof id === 'string' ? id : undefined)
  const insets = useSafeAreaInsets()
  useFidelityReady(true)

  return (
    <View style={s.screen}>
      <AppBar title={m.heading} fallback="/maintenance/new" />
      {m.ticket ? (
        <ScrollView
          style={s.fill}
          contentContainerStyle={[s.body, { paddingBottom: aboveGestureBar(space[20], insets.bottom) }]}
          showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c(ink.action)} />}
        >
          <Summary m={m} />
          <SectionLabel gap="wide">Status</SectionLabel>
          <Status steps={m.steps} />
          {m.fixed ? <FixedAnswer fixed={m.fixed} what="request" /> : null}
          {m.vendor ? (
            <>
              <SectionLabel gap="wide">Assigned vendor</SectionLabel>
              <Vendor vendor={m.vendor} />
            </>
          ) : null}
          {m.messages.length > 0 ? (
            <>
              <SectionLabel gap="wide">Messages</SectionLabel>
              <Messages rows={m.messages} />
            </>
          ) : null}
          <SectionLabel gap="wide">Add a comment</SectionLabel>
          <Comment comment={m.comment} />
        </ScrollView>
      ) : (
        <NotShown m={m} />
      )}
    </View>
  )
}

const s = StyleSheet.create({
  // .screen — white
  screen: { flex: 1, backgroundColor: fill.surface },
  fill: { flex: 1 },
  // .body-pad — 18 above, 20 at the sides and below (or the home indicator's strip, where that is deeper)
  body: { paddingTop: space[18], paddingHorizontal: space[20] },
  pressed: { opacity: 0.7 },
  loading: { paddingTop: space[24], alignItems: 'center' },

  // .status-summary · .status-txt · .st2 3 below the title · .st3 5 below
  summary: { flexDirection: 'row', alignItems: 'flex-start', gap: space[12] },
  summaryText: { flex: 1, minWidth: 0 },
  words: { marginTop: space[3] },
  meta: { marginTop: space[5] },

  // .vendor-card · .vendor-avatar · .vendor-info · .vd 1 below .vn · .vendor-call
  vendor: { flexDirection: 'row', alignItems: 'center', gap: space[12] },
  avatar: { width: size[40], height: size[40], borderRadius: radius.avatar(size[40]), flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.tint },
  vendorText: { flex: 1, minWidth: 0 },
  vendorLine: { marginTop: space[1] },
  call: { width: size[36], height: size[36], borderRadius: radius.avatar(size[36]), flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.tint },

  // .comment-input — 46 tall, 14 in on the left and 6 on the right, 4 below the card's top; .send. Focused, the gold
  // ring every field takes (M47 U6); the edge is there unfocused too, transparent, so nothing moves.
  comment: { height: size[46], flexDirection: 'row', alignItems: 'center', gap: space[9], backgroundColor: fill.tint, borderRadius: radius.field, borderWidth: stroke['1.5'], borderColor: 'transparent', paddingLeft: space[14], paddingRight: space[6], marginTop: space[4] },
  commentFocused: { backgroundColor: fill.surface, borderColor: line.focus, ...shadow.focusRing },
  commentText: { flex: 1, minWidth: 0, justifyContent: 'center' },
  // `pointerEvents` as a style, as Form.tsx keeps it: React Native Web warns on the prop.
  placeholder: { position: 'absolute', left: 0, right: 0, pointerEvents: 'none' },
  commentInput: { fontFamily: font.inter400_12_5.face, fontSize: font.inter400_12_5.size, color: c(ink.heading), padding: 0, margin: 0 },
  commentState: { marginTop: space[8] },
  send: { width: size[30], height: size[30], borderRadius: radius.avatar(size[30]), flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.primary },

  // "Messages": who and when on one line, the words under it; 12 between messages.
  messageGap: { marginTop: space[12] },
  messageHead: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', gap: space[8], marginBottom: space[4] },
})
