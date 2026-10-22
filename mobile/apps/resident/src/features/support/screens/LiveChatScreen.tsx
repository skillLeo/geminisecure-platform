/**
 * Help & support → Live chat (no board draws it, restyle-live-chat; M50 §4.3) — drawn to belong: the design's app bar,
 * its tinted card for the line at the top, and the panic screen's own bubbles (board 06's "Live channel with dispatch"):
 * Gemini Security on the left in the tint, the resident on the right in the primary blue, each of theirs saying what is
 * true about it — Waiting for a connection, Sending…, Sent, Delivered, or Not sent with the server's words and Try again.
 *
 * WHAT EACH STATE SHOWS. While the conversation is on its way: the design's pale skeleton, never an error and never an
 * empty conversation. Read: the messages, newest at the bottom, scrolled to. Nothing written yet: one short line.
 * Could not be read with nothing kept: the kit's error with Try again. The box stays usable throughout — a message
 * written with no signal waits on the phone and goes by itself.
 *
 * Not for emergencies, and the line at the top says so, with the panic button and 119.
 */

import { useRef } from 'react'
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { ErrorView, Icon, Pressable, SkeletonLines, Text, aboveGestureBar, c, useFidelityReady } from '@gs/core'
import { AppBar } from '@/design/parts'
import { corner, fill, font, icon, ink, size, space } from '@/design/tokens'
import { CHAT_MAX, STATE_WORDS, answerFrom } from '../liveChat'
import { useLiveChat } from '../useLiveChat'

const BOX = 'Write to Gemini Security'

export default function LiveChatScreen() {
  const m = useLiveChat()
  const insets = useSafeAreaInsets()
  const list = useRef<ScrollView>(null)
  useFidelityReady(m.status !== 'loading')

  return (
    <View style={s.screen}>
      <AppBar title="Live chat" fallback="/settings/help" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
        <View style={s.intro}>
          <Text {...font.inter400_11_lh1_4} color={ink.label}>{m.intro}</Text>
        </View>

        {m.status === 'loading' ? (
          <SkeletonLines tone="light" lines={4} style={s.skeleton} />
        ) : m.status === 'error' ? (
          <ErrorView tone="light" message={m.errorMessage ?? 'The conversation could not be loaded.'} onRetry={m.refresh} style={s.skeleton} />
        ) : (
          <ScrollView
            ref={list}
            style={s.fill}
            contentContainerStyle={s.thread}
            keyboardShouldPersistTaps="handled"
            onContentSizeChange={() => list.current?.scrollToEnd({ animated: false })}
            aria-live="polite"
          >
            {m.items.length === 0 ? (
              <Text {...font.inter400_12} color={ink.secondary} align="center" style={s.empty}>{'Ask your question below.'}</Text>
            ) : null}
            {m.items.map((item) =>
              item.from === 'support' ? (
                <View key={item.key} style={s.theirs}>
                  <Text {...font.inter700_11_5} color={ink.heading}>{answerFrom(item.name)}</Text>
                  <View style={s.said}><Text {...font.inter400_12_lh1_4} color={ink.body}>{item.body}</Text></View>
                </View>
              ) : (
                <View key={item.key} style={s.mineWrap}>
                  <View style={s.mine}><Text {...font.inter400_12_lh1_4} color={ink.onDark}>{item.body}</Text></View>
                  {item.state ? (
                    <Text {...font.inter600_10} color={item.state === 'failed' ? ink.onGoldTint : ink.secondary} align="right" role={item.state === 'failed' ? 'alert' : undefined} style={s.mineState}>
                      {item.note ? `${STATE_WORDS[item.state]}: ${item.note}` : STATE_WORDS[item.state]}
                    </Text>
                  ) : null}
                  {item.opId ? (
                    <Pressable onPress={() => m.retry(item.opId as string)} role="button" aria-label="Try sending the message again" hitSlop={space[8]} style={s.retry}>
                      <Text {...font.inter700_10} color={ink.action}>{'Try again'}</Text>
                    </Pressable>
                  ) : null}
                </View>
              ),
            )}
            {m.resolved ? (
              <Text {...font.inter400_11_lh1_4} color={ink.secondary} align="center" style={s.resolved}>{'Gemini Security marked this resolved. Write again and it opens again.'}</Text>
            ) : null}
          </ScrollView>
        )}

        <View style={[s.composer, { paddingBottom: aboveGestureBar(space[12], insets.bottom) }]}>
          <View style={s.box}>
            <View style={s.boxText}>
              {m.draft === '' ? (
                <View style={s.placeholder}>
                  <Text {...font.inter400_12} color={ink.secondary}>{BOX}</Text>
                </View>
              ) : null}
              <TextInput
                value={m.draft}
                onChangeText={m.setDraft}
                maxLength={CHAT_MAX}
                multiline
                aria-label={BOX}
                style={s.input}
              />
            </View>
            <Pressable onPress={m.send} disabled={!m.canSend} role="button" aria-label="Send the message" aria-disabled={!m.canSend} accessibilityHint={m.canSend ? undefined : 'Write your message first.'} hitSlop={space[8]} style={({ pressed }) => [m.canSend ? s.send : [s.send, s.sendOff], pressed ? s.pressed : null]}>
              <Icon name="send" size={icon[13]} color={ink.onDark} />
            </Pressable>
          </View>
          {m.problem ? <Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert" style={s.problem}>{m.problem}</Text> : null}
        </View>
      </KeyboardAvoidingView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface },
  fill: { flex: 1 },
  // The line at the top, in the design's tinted card: 16 below the bar, 20 in from each side
  intro: { marginTop: space[16], marginHorizontal: space[20], backgroundColor: fill.tint, borderRadius: corner[12], paddingVertical: space[10], paddingHorizontal: space[12] },
  skeleton: { marginTop: space[16], marginHorizontal: space[20] },
  thread: { paddingTop: space[16], paddingHorizontal: space[20], paddingBottom: space[12], gap: space[10] },
  empty: { marginTop: space[20] },
  resolved: { marginTop: space[6] },
  // Board 06's bubbles: theirs in the tint on the left, the resident's in the primary blue on the right
  theirs: { width: '88%', backgroundColor: fill.tint, borderRadius: corner[12], borderTopLeftRadius: corner[3], paddingVertical: space[9], paddingHorizontal: space[11] },
  said: { marginTop: space[2] },
  mineWrap: { alignSelf: 'flex-end', maxWidth: '88%', alignItems: 'flex-end' },
  mine: { backgroundColor: fill.primary, borderRadius: corner[12], borderTopRightRadius: corner[3], paddingVertical: space[9], paddingHorizontal: space[11] },
  mineState: { marginTop: space[3] },
  retry: { marginTop: space[3] },
  // The box, as the panic screen's: the tint, radius 12, the send circle in it; it grows with what is written
  composer: { paddingTop: space[8], paddingHorizontal: space[20] },
  box: { minHeight: size[38], flexDirection: 'row', alignItems: 'flex-end', gap: space[8], backgroundColor: fill.tint, borderRadius: corner[12], paddingLeft: space[12], paddingRight: space[6], paddingVertical: space[5] },
  boxText: { flex: 1, minWidth: 0, minHeight: size[28], justifyContent: 'center' },
  placeholder: { position: 'absolute', top: 0, bottom: 0, left: 0, right: 0, justifyContent: 'center', pointerEvents: 'none' },
  input: { maxHeight: size[120], fontFamily: font.inter400_12.face, fontSize: font.inter400_12.size, color: c(ink.heading), padding: 0, margin: 0 },
  send: { width: size[28], height: size[28], borderRadius: corner.round(size[28]), alignItems: 'center', justifyContent: 'center', backgroundColor: fill.primary },
  sendOff: { opacity: 0.5 },
  pressed: { opacity: 0.7 },
  problem: { marginTop: space[8] },
})
