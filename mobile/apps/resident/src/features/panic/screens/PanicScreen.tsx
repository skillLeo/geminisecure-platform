/**
 * resident-app-06 · "Emergency alert" — rebuilt from the client's board (M42 §5 #5), every value from the token module.
 *
 * THE BOARD, EXACTLY: the chevron down, the centred title, the circle with its shield and two rings, "Alert sent" and
 * the gold line, three steps, the white "Live channel with dispatch" card, "Share live audio", the outlined call
 * button and the note. The circle and its rings are RED — the client's one change to the board — Foundation's red-700
 * (Home's own Panic red) with its red-500, "danger on dark surfaces", for the rings. Every word in those slots is the
 * hook's `board` (panicBoard.ts): what the server recorded on a phone, the board's own sample in the fidelity build.
 * This file never asks which.
 *
 * WHAT THE BOARD DOES NOT DRAW AND THE SCREEN CANNOT DO WITHOUT — the raising, which is untouched (usePanic.ts):
 *   before the press   the circle IS the button: HELD for a second and a half, never a single tap; and, for someone who
 *                      cannot hold it, "Can't hold the button? Tap here instead" and this screen's own confirmation.
 *                      The same after an alert that will never be sent, which the gold line says.
 *   held on the phone  the board's layout with every step pending and the phone's own sentence in the card — nobody
 *                      has it yet — and, with contacts on file, a text to them from the phone's own Messages app.
 *
 * THE MESSAGE BOX IS THE LIVE CHANNEL WITH DISPATCH (M50 §4.2 #2): what the resident writes goes to the control room
 * through the queue — at once, or the moment there is a signal, never dropped — and the control room's replies appear
 * under the board's bubble, on the left; the resident's own on the right, each saying "Waiting for signal", "Sending…",
 * "Sent" or "Delivered" as it truly stands (dispatchChannel.ts). The chevron only leaves the screen — the alert is never
 * cancelled by it. The call button dials the real emergency number, in every state.
 *
 * Fixed, not a page that scrolls: with the board's content it fits 390 × 844. A longer TRUE sentence (no signal, an
 * open keyboard) may push the bottom down, and then — only then — it scrolls, so the call button is never cut off.
 */

import { useEffect, useRef, useState } from 'react'
import { Animated, Easing, Keyboard, KeyboardAvoidingView, Linking, Platform, ScrollView, StyleSheet, TextInput, View, useWindowDimensions } from 'react-native'
import { router } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import * as Haptics from 'expo-haptics'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { ConfirmSheet, Gradient, Icon, Pressable, Text, aboveGestureBar, c, useConfirmFlow, useFidelityReady, useReduceMotionPref } from '@gs/core'
import { NoteSheet, Toggle } from '@/design/parts'
import { corner, fill, font, foundation, gradient, icon, ink, inkAlpha, line, size, space, stroke } from '@/design/tokens'
import { ContactsSheet } from '../components/ContactsSheet'
import { MESSAGE_MAX, STATE_WORDS, replyFrom } from '../dispatchChannel'
import { EMERGENCY_NUMBER, HOLD_MS, usePanic } from '../usePanic'

/** Said under "Share live audio" while the microphone is recording (M57 §5.4): what is happening, and how to stop it. */
const MIC_ON = 'Your microphone is on and the control room can listen. Turn the switch off to stop.'

/** The client's red for this circle only (M42 §5 #5): the circle red-700, its rings red-500. */
const ALERT_RED = foundation.colour['red-700']
const RING_RED = foundation.colour['red-500']


/** The chevron's touch area grows to 44 downward and sideways, never up into the status bar (as the AppBar part's). */
const REACH = { top: 0, bottom: size[10], left: size[5], right: size[5] }
/** The hold's fill starts at the circle's own edge and grows to the inner ring. */
const FILL_FROM = size[76] / size[104]
const RELEASE_MS = 160

export default function PanicScreen() {
  const insets = useSafeAreaInsets()
  // A short phone (360 x 800 and the like): two of the board's vertical gaps close a little, so "Call emergency
  // services" is on screen without scrolling there too. At 390 x 844 and taller the screen is exactly the board's.
  const short = useWindowDimensions().height < 820
  const leave = () => (router.canGoBack() ? router.back() : router.replace('/(tabs)'))
  const m = usePanic(leave)
  const still = useReduceMotionPref()
  useFidelityReady(true)

  const { phase, board } = m
  // An alert exists (held on the phone, at the control room, or closed): the board's layout.
  const alertShown = phase === 'queued' || phase === 'active' || phase === 'closed'
  // No live alert: the circle is the button. While sending it is the same button, busy.
  const canRaise = phase === 'arm' || phase === 'failed'

  /* ---- hold to send: a second and a half, never a single tap ---- */
  const progress = useRef(new Animated.Value(0)).current
  const holding = useRef<Animated.CompositeAnimation | null>(null)
  const startHold = () => {
    if (!canRaise) return
    void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium).catch(() => undefined)
    holding.current = Animated.timing(progress, { toValue: 1, duration: HOLD_MS, easing: Easing.linear, useNativeDriver: true })
    holding.current.start(({ finished }) => {
      if (finished) m.fire()
    })
  }
  const endHold = () => {
    holding.current?.stop()
    Animated.timing(progress, { toValue: 0, duration: RELEASE_MS, useNativeDriver: true }).start()
  }
  useEffect(() => () => holding.current?.stop(), [])
  useEffect(() => {
    if (canRaise) progress.setValue(0)
  }, [canRaise, progress])

  /* ---- for someone who cannot hold it: this screen's own question — never blocked, offline included ---- */
  const withoutHolding = useConfirmFlow<true>({
    send: () => {
      m.fire()
      return { ok: true }
    },
  })

  /* ---- "Message dispatch…": the live channel with the control room (M50 §4.2 #2) — the words leave the box once queued ---- */
  const [draft, setDraft] = useState('')
  const channel = m.channel
  const sendToDispatch = () => {
    if (!channel.canWrite || draft.trim() === '') return
    void channel.send(draft).then((taken) => {
      if (taken) setDraft('')
    })
  }
  const thread = useRef<ScrollView>(null)

  /* ---- held with no signal: a text to the household's contacts from the phone's own Messages app (M01) ---- */
  const [contactsOpen, setContactsOpen] = useState(false)
  const openContacts = () => setContactsOpen(true)

  /* ---- fixed, unless a true sentence or the keyboard makes it longer than the phone ---- */
  const scroll = useRef<ScrollView>(null)
  const [heights, setHeights] = useState({ view: 0, content: 0 })
  const overflows = heights.view > 0 && heights.content > heights.view + 1
  const [typing, setTyping] = useState(false)
  useEffect(() => {
    if (!typing) return
    const sub = Keyboard.addListener('keyboardDidShow', () => scroll.current?.scrollToEnd({ animated: !still }))
    return () => sub.remove()
  }, [typing, still])

  const circle = (
    <>
      <View style={s.ringOuter} />
      <View style={s.ringInner} />
      {canRaise || phase === 'sending' ? (
        <Animated.View style={[s.holdFill, { opacity: progress, transform: [{ scale: still ? 1 : progress.interpolate({ inputRange: [0, 1], outputRange: [FILL_FROM, 1] }) }] }]} />
      ) : null}
      <View style={s.circle}>
        <Icon name="d-icon" size={icon[36]} color={ink.onDark} />
      </View>
    </>
  )

  return (
    <View style={s.screen}>
      <StatusBar style="light" />
      <Gradient spec={gradient.alert} style={s.fill}>
        <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
          <ScrollView
            ref={scroll}
            scrollEnabled={overflows}
            bounces={false}
            overScrollMode="never"
            keyboardShouldPersistTaps="handled"
            onLayout={(e) => {
              const view = e.nativeEvent.layout.height
              setHeights((h) => (h.view === view ? h : { ...h, view }))
            }}
            onContentSizeChange={(_, content) => setHeights((h) => (h.content === content ? h : { ...h, content }))}
            contentContainerStyle={{ paddingBottom: aboveGestureBar(0, insets.bottom) }}
          >
            {/* .panic-top */}
            <View style={[s.top, { paddingTop: insets.top + space[10] }]}>
              <Pressable onPress={leave} role="button" aria-label={phase === 'active' || phase === 'queued' ? 'Back: the alert stays active' : 'Back'} hitSlop={REACH} style={({ pressed }) => [s.side, pressed ? s.sidePressed : null]}>
                <Icon name="side-btn-5" size={size[34]} color={ink.onDark} />
              </Pressable>
              <View style={s.titleBox}>
                <Text {...font.poppins600_15} color={ink.onDark} align="center" role="heading">{'Emergency alert'}</Text>
              </View>
              <View style={s.spacer} />
            </View>

            {/* .panic-hero */}
            <View style={short ? [s.hero, s.heroShort] : s.hero}>
              {canRaise || phase === 'sending' ? (
                <Pressable
                  onPressIn={startHold}
                  onPressOut={endHold}
                  disabled={phase === 'sending'}
                  role="button"
                  aria-label={phase === 'failed' ? 'Send a new alert' : "Send an alert to your community's security officers"}
                  aria-busy={phase === 'sending'}
                  accessibilityHint={`Hold for ${HOLD_MS / 1000} seconds to send. It is never sent by a single tap.`}
                  hitSlop={space[12]}
                  style={s.iconWrap}
                >
                  {circle}
                </Pressable>
              ) : (
                <View style={s.iconWrap}>{circle}</View>
              )}
              <View style={s.status} aria-live="polite">
                <Text {...font.poppins600_22} color={ink.onDark} align="center" role="heading">{board.title}</Text>
                <Text {...font.inter600_12_5} color={ink.accent} align="center" style={s.meta}>{board.meta}</Text>
              </View>
            </View>

            {alertShown ? (
              <>
                {/* .timeline — each step ticked only by the time the server recorded for it */}
                <View style={s.timeline} aria-live="polite">
                  {board.steps.map((step, i) => {
                    const last = i === board.steps.length - 1
                    return (
                      <View key={`${i}-${step.title}`} style={last ? s.rowLast : short ? [s.row, s.rowShort] : s.row}>
                        {last ? null : <View style={s.rail} />}
                        <View style={step.done ? s.dotDone : s.dotPending}>{step.done ? <Icon name="check" size={icon[12]} color={ink.heading} /> : null}</View>
                        <View style={s.stepText}>
                          <Text {...font.inter600_13} color={ink.onDark}>{step.title}</Text>
                          {step.detail ? <Text {...font.inter400_11} style={s.stepDetail}>{step.detail}</Text> : null}
                        </View>
                      </View>
                    )
                  })}
                </View>

                {/* .live-card */}
                <View style={s.card}>
                  <Text {...font.inter700_11_5} color={ink.secondary} style={s.cardHead}>{'Live channel with dispatch'}</Text>{/* framing-ok: the client's board copy: dispatch is the estate's own security control room */}
                  {board.bubble ? (
                    <View style={s.bubble} aria-live="polite">
                      <Text {...font.inter400_12_lh1_4} color={ink.body}>
                        {board.bubble.from ? <Text {...font.inter700_12_lh1_4} color={ink.heading}>{`${board.bubble.from} `}</Text> : null}
                        {board.bubble.text}
                      </Text>
                    </View>
                  ) : null}

                  {/* The thread: the control room's replies on the left, the resident's own on the right, each with what is true about it. */}
                  {channel.items.length > 0 ? (
                    <ScrollView
                      ref={thread}
                      style={s.thread}
                      contentContainerStyle={s.threadInner}
                      nestedScrollEnabled
                      onContentSizeChange={() => thread.current?.scrollToEnd({ animated: !still })}
                      aria-live="polite"
                    >
                      {channel.items.map((item) =>
                        item.from === 'dispatch' ? (
                          <View key={item.key} style={s.bubble}>
                            <Text {...font.inter400_12_lh1_4} color={ink.body}>
                              <Text {...font.inter700_12_lh1_4} color={ink.heading}>{`${replyFrom(item.name)} `}</Text>
                              {item.body}
                            </Text>
                          </View>
                        ) : (
                          <View key={item.key} style={s.mineWrap}>
                            <View style={s.mine}>
                              <Text {...font.inter400_12_lh1_4} color={ink.onDark}>{item.body}</Text>
                            </View>
                            {item.state ? (
                              <Text {...font.inter600_10} color={item.state === 'failed' ? ink.onGoldTint : ink.secondary} align="right" role={item.state === 'failed' ? 'alert' : undefined} style={s.mineState}>
                                {item.note ? `${STATE_WORDS[item.state]}: ${item.note}` : STATE_WORDS[item.state]}
                              </Text>
                            ) : null}
                            {item.opId ? (
                              <Pressable onPress={() => channel.retry(item.opId as string)} role="button" aria-label="Try sending the message again" hitSlop={space[8]} style={s.retry}>
                                <Text {...font.inter700_10} color={ink.action}>{'Try again'}</Text>
                              </Pressable>
                            ) : null}
                          </View>
                        ),
                      )}
                    </ScrollView>
                  ) : null}

                  <View style={channel.canWrite ? s.input : [s.input, s.inputOff]}>
                    <View style={s.inputText}>
                      {draft === '' ? (
                        <View style={s.placeholder}>
                          <Text {...font.inter400_12} color={ink.secondary}>{channel.placeholder}</Text>
                        </View>
                      ) : null}
                      <TextInput
                        value={draft}
                        onChangeText={setDraft}
                        editable={channel.canWrite}
                        maxLength={MESSAGE_MAX}
                        aria-label="Message dispatch" // framing-ok: the client's board copy
                        returnKeyType="send"
                        onSubmitEditing={sendToDispatch}
                        onFocus={() => setTyping(true)}
                        onBlur={() => setTyping(false)}
                        style={s.textInput}
                      />
                    </View>
                    <Pressable onPress={sendToDispatch} disabled={!channel.canWrite} role="button" aria-label="Send the message" aria-disabled={!channel.canWrite} accessibilityHint={channel.canWrite ? undefined : 'Write your message first.'} hitSlop={space[8]} style={({ pressed }) => [s.send, pressed ? s.pressed : null]}>
                      <Icon name="send" size={icon[13]} color={ink.onDark} />
                    </Pressable>
                  </View>
                  {channel.problem ? <Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert" style={s.channelProblem}>{channel.problem}</Text> : null}
                </View>

                {/* .toggle-row — what the phone hears, to the control room, while it is on (M50 §4.2 #1): drawn as it truly stands */}
                <View style={s.toggleRow}>
                  <View style={s.micWords}>
                    <Text {...font.inter600_12_5} color={ink.onDark}>{'Share live audio'}</Text>
                    {/* M57 §5.4: while the microphone is really recording, the screen says so in words, not only the switch. */}
                    {m.audio.state === 'on' ? <Text {...font.inter500_11_5} color={ink.onDark} role="status" style={s.micLine}>{MIC_ON}</Text> : null}
                  </View>
                  <Toggle on={m.audio.on} onChange={m.audio.toggle} label="Share live audio" />
                </View>
              </>
            ) : canRaise ? (
              <Pressable
                onPress={() => withoutHolding.ask(true)}
                role="button"
                aria-label={phase === 'failed' ? "I can't hold the button. Send a new alert with a confirmation instead" : "I can't hold the button. Send with a confirmation instead"}
                hitSlop={space[8]}
                style={s.link}
              >
                <Text {...font.inter600_12_5} color={ink.onDark} align="center">
                  {"Can't hold the button? "}
                  <Text {...font.inter700_12_5} color={ink.accent}>{'Tap here instead'}</Text>
                </Text>
              </Pressable>
            ) : null}

            {/* .call-btn — the real emergency number, one tap, in every state */}
            <Pressable onPress={m.callEmergency} role="button" aria-label={`Call emergency services on ${EMERGENCY_NUMBER}`} style={({ pressed }) => [s.callBtn, pressed ? s.pressed : null]}>{/* framing-ok: dials 119 itself */}
              <Icon name="call-btn-2" size={icon[16]} color={ink.accent} />
              <Text {...font.inter600_12_5} color={ink.onDark}>{'Call emergency services'}</Text>{/* framing-ok: the client's board copy: the button dials 119 */}
            </Pressable>

            {phase === 'queued' && m.contacts.length > 0 ? (
              <Pressable onPress={openContacts} role="button" aria-label="Text my emergency contacts: choose who" style={({ pressed }) => [s.callBtn, s.second, pressed ? s.pressed : null]}>
                <Icon name="send" size={icon[16]} color={ink.accent} />
                <Text {...font.inter600_12_5} color={ink.onDark}>{`Text my emergency contacts (${m.contacts.length})`}</Text>
              </Pressable>
            ) : null}

            {alertShown ? (
              <Text {...font.inter400_10_5} align="center" style={s.note}>{'Only a responder can close this alert'}</Text>
            ) : null}
          </ScrollView>
        </KeyboardAvoidingView>
      </Gradient>

      <ContactsSheet
        visible={contactsOpen}
        contacts={m.contacts}
        emergencyNumber={EMERGENCY_NUMBER}
        onClose={() => setContactsOpen(false)}
        onText={(chosen) => {
          setContactsOpen(false)
          m.textContacts(chosen)
        }}
        onCall={m.callContact}
        onCallEmergency={m.callEmergency}
      />

      {/* Why live audio could not be on — the microphone not allowed, or the phone could not record — in the app's own sheet. */}
      <NoteSheet
        note={m.audio.problem ? { title: m.audio.problem.title, lines: [m.audio.problem.line], actions: m.audio.problem.settings ? [{ label: 'Open Settings', onPress: () => void Linking.openSettings().catch(() => undefined) }] : [] } : null}
        onClose={m.audio.clearProblem}
      />

      <ConfirmSheet
        emergencyScreen
        open={withoutHolding.open}
        title="Send an alert?"
        // "At once" only while there is a connection: offline the alert is held on this phone until it finds a signal.
        lines={m.online
          ? ['Your alert goes to your gatehouse and the control room at once.']
          : [`There is no connection: the alert is saved on this phone and sent the moment it finds any signal. Call ${EMERGENCY_NUMBER} as well.`]}
        confirmLabel="Send the alert"
        cancelLabel="No, go back"
        working={withoutHolding.working}
        problem={withoutHolding.problem}
        onConfirm={withoutHolding.confirm}
        onCancel={withoutHolding.cancel}
      />
    </View>
  )
}

const dot = { width: size[21], height: size[21], borderRadius: corner.round(size[21]), flexShrink: 0, alignItems: 'center', justifyContent: 'center', zIndex: 1 } as const

const s = StyleSheet.create({
  screen: { flex: 1, overflow: 'hidden' },
  fill: { flex: 1 },

  // .panic-top: 10 below the status bar, 20 in from each side; the side button 34 round on white .14
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: space[20] },
  side: { width: size[34], height: size[34], borderRadius: corner.round(size[34]), alignItems: 'center', justifyContent: 'center', backgroundColor: fill.onDarkButtonDim },
  sidePressed: { backgroundColor: fill.onDarkLoader },
  titleBox: { flex: 1, minWidth: 0 },
  spacer: { width: size[34] },

  // .panic-hero: 26 above, 20 below; the 104 wrap with its two rings (104 at .25, 128 at .14) and the 76 circle
  hero: { alignItems: 'center', paddingTop: space[26], paddingBottom: space[20] },
  heroShort: { paddingTop: space[16] },
  iconWrap: { width: size[104], height: size[104], alignItems: 'center', justifyContent: 'center', marginBottom: space[16] },
  ringOuter: { position: 'absolute', width: size[128], height: size[128], borderRadius: corner.round(size[128]), borderWidth: stroke['1.5'], borderColor: RING_RED, opacity: 0.14 },
  ringInner: { position: 'absolute', width: size[104], height: size[104], borderRadius: corner.round(size[104]), borderWidth: stroke['1.5'], borderColor: RING_RED, opacity: 0.25 },
  holdFill: { position: 'absolute', width: size[104], height: size[104], borderRadius: corner.round(size[104]), backgroundColor: RING_RED },
  circle: { width: size[76], height: size[76], borderRadius: corner.round(size[76]), alignItems: 'center', justifyContent: 'center', backgroundColor: ALERT_RED, zIndex: 2 },
  status: { alignItems: 'center', alignSelf: 'stretch' },
  meta: { marginTop: space[5], marginHorizontal: space[20] },

  // .timeline: 4 26 18; a row's dot 21, 12 to its words, 22 to the next; the rail 1.5 wide at white .16
  timeline: { paddingTop: space[4], paddingHorizontal: space[26], paddingBottom: space[18] },
  row: { flexDirection: 'row', gap: space[12], paddingBottom: space[22] },
  rowShort: { paddingBottom: space[18] },
  rowLast: { flexDirection: 'row', gap: space[12] },
  rail: { position: 'absolute', left: space[10], top: space[22], width: size['1.5'], height: '100%', backgroundColor: fill.onDarkButton },
  dotDone: { ...dot, backgroundColor: fill.gold },
  dotPending: { ...dot, borderWidth: stroke['1.5'], borderColor: line.focus },
  stepText: { flex: 1, minWidth: 0 },
  stepDetail: { color: inkAlpha.onDarkCaption, marginTop: space[2] },

  // .live-card: white, radius 16, 14 inside; 6 above, 14 below, 20 in from each side
  card: { marginTop: space[6], marginHorizontal: space[20], marginBottom: space[14], backgroundColor: fill.surface, borderRadius: corner[16], padding: space[14] },
  cardHead: { marginBottom: space[8] },
  bubble: { width: '88%', backgroundColor: fill.tint, borderRadius: corner[12], borderTopLeftRadius: corner[3], paddingVertical: space[9], paddingHorizontal: space[11] },
  // The thread under the board's bubble: no taller than the board's own card, so the call button stays where it is;
  // a longer conversation scrolls inside it, to the newest. The resident's own on the right, in the primary blue.
  thread: { maxHeight: size[150], marginTop: space[8] },
  threadInner: { gap: space[8] },
  mineWrap: { alignSelf: 'flex-end', maxWidth: '88%', alignItems: 'flex-end' },
  mine: { backgroundColor: fill.primary, borderRadius: corner[12], borderTopRightRadius: corner[3], paddingVertical: space[9], paddingHorizontal: space[11] },
  mineState: { marginTop: space[3] },
  retry: { marginTop: space[3] },
  inputOff: { opacity: 0.5 },
  channelProblem: { marginTop: space[8] },
  micWords: { flex: 1, paddingRight: space[12] },
  micLine: { marginTop: space[4] },
  input: { marginTop: space[10], height: size[38], flexDirection: 'row', alignItems: 'center', gap: space[8], backgroundColor: fill.tint, borderRadius: corner[12], paddingLeft: space[12], paddingRight: space[6] },
  inputText: { flex: 1, minWidth: 0, height: size[38], justifyContent: 'center' },
  placeholder: { position: 'absolute', top: 0, bottom: 0, left: 0, right: 0, justifyContent: 'center', pointerEvents: 'none' },
  textInput: { height: size[38], fontFamily: font.inter400_12.face, fontSize: font.inter400_12.size, color: c(ink.heading), padding: 0, margin: 0 },
  send: { width: size[28], height: size[28], borderRadius: corner.round(size[28]), alignItems: 'center', justifyContent: 'center', backgroundColor: fill.primary },

  // .toggle-row: white .06, radius 14, 12 × 14 inside; 20 in from each side, 14 below
  toggleRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginHorizontal: space[20], marginBottom: space[14], backgroundColor: fill.onDarkRow, borderRadius: corner[14], paddingVertical: space[12], paddingHorizontal: space[14] },

  // Before the press: the way to send for someone who cannot hold, under the circle
  link: { alignSelf: 'center', paddingVertical: space[10], paddingHorizontal: space[20], marginBottom: space[14] },

  // .call-btn: 48 tall, radius 14, a 1.5 edge at white .3, the gold phone 9 before the words
  callBtn: { height: size[48], marginHorizontal: space[20], flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: space[9], borderRadius: corner[14], borderWidth: stroke['1.5'], borderColor: line.onDarkStrong },
  second: { marginTop: space[14] },
  pressed: { opacity: 0.7 },

  // .panic-note: 10.5 at white .4, 2 above, 16 below
  note: { color: inkAlpha.onDarkMuted, marginTop: space[2], marginHorizontal: space[20], marginBottom: space[16] },
})
