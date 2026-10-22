/**
 * THE QUESTION BEFORE ANYTHING THAT CANNOT BE UNDONE — in both apps, one component (INV-12, DESIGN §12).
 *
 * The user, 24 September: "When I click Remove, instead of showing a popup it shows at the bottom. Sometimes the
 * user doesn't feel there is any section below." The old card was drawn in the page, under the list and often off
 * the screen, with buttons that read "Remove Soft" / "Keep Soft". The lead's ruling (spec l.62): a MODAL, not
 * press-and-hold — the recognisable pattern, and the one VoiceOver, TalkBack and switch access can operate.
 *
 * WHAT IT IS. A React Native Modal: a window of its own, so it dims and blocks the tab bar too, iOS confines
 * VoiceOver to it, Android's dialog window confines TalkBack, and react-native-web traps focus and closes on
 * Escape. A bottom sheet (520 wide at most, centred on a tablet), the Foundation's 20 radius on top.
 *
 *   title      the person or thing IN FULL — "Remove Soft Dev from your household?"
 *   lines      what happens, in sentences, said BEFORE it is done
 *   children   anything the question needs (a picker)
 *   problem    the server's refusal, INSIDE the sheet (role alert); the sheet stays open
 *   blocked    why confirming cannot be pressed now (no connection) — the button is disabled and says so
 *   confirm    ABOVE Cancel, red when `destructive` (the default), labelled in full — "Remove Soft Dev". In the
 *              Resident App that red is an OUTLINE and the refusal amber (M98, 12 Oct 2026): solid red is an open
 *              panic's colour and nothing else. The Guard App's sheet is its own lane's, and is left as it was.
 *
 * THE RULES (INV-12):
 *   - the backdrop and Android's back button CANCEL; only the confirm button ever confirms, and only once
 *     (a second press before the answer is ignored — here, and again in confirmFlow);
 *   - it NEVER TRAPS ANYBODY. While `working` the confirm button is busy and the way out reads "Close" and says
 *     that closing does not stop it: Close, the backdrop and back take the sheet off the screen at once (and tell
 *     the caller, whose confirmFlow hides the question and calls nothing off). The sheet stands over the whole
 *     app — the Home tab's panic button with it — and one slow write can take most of a minute. The write
 *     carries on; a refusal brings the question back with the words, a yes simply closes it;
 *   - the app going to the background drops an unanswered question (nothing was done) — but never a write on
 *     its way, never a refusal that came back while the app was away (it is kept until the app is back), and
 *     never anything under a cover: the app lock's own Face ID prompt takes the app out of 'active' too;
 *   - its screen losing focus (a push tapped, a tab changed) drops an unanswered question; a write already on
 *     its way is left to finish, hidden, and whatever became of it is there when the screen is back;
 *   - it hides while something covers the app (overlayCover — the Resident App's lock), and comes back after;
 *   - it never opens on /panic or /duress, except the panic screen's own question (`emergencyScreen`).
 *
 * FOCUS. One frame after it shows, the screen reader is moved to the question — or to the refusal in it, when it
 * comes back with one. When it CLOSES (not when it merely hides under the lock or behind another screen): closed by
 * Cancel, focus goes back to `returnFocusTo`, the control that opened it; closed on a yes, to the first of
 * `fallbackFocusTo` still on the screen — the act has taken what the opener was about (a removed member's row) —
 * and to the opener only when no fallback is there. On the web the title is focused too, and the
 * backdrop is not a control at all, so the browser never makes it the first tab stop.
 *
 * A REFUSAL IS SPOKEN, not only drawn: VoiceOver is told the words; TalkBack reads the live region it lands in.
 *
 * CLOSED, IT RENDERS NOTHING — so no measured board changes by a pixel for having one on its screen.
 *
 * Colours are measured pairs only (the contrast gate reads apps/*, not this file): resident navy-900, slate-600,
 * amber-700 on amber-100, red-700 on white, white on navy-600; guard white, text-2, red-500 and amber-500 on
 * surface-800, white on red-700.
 */

import { useCallback, useEffect, useRef, useState, type ReactNode, type RefObject } from 'react'
import { AccessibilityInfo, AppState, Modal, Platform, ScrollView, StyleSheet, View } from 'react-native'
import { useFocusEffect, usePathname } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { alpha, color, type ColorToken } from '../theme/colors'
import { useReduceMotionPref } from './a11y'
import { GuardButton } from './guard/GuardButton'
import { isCovered, useCovered } from './overlayCover'
import { ResidentButton } from './resident/ResidentButton'
import { Text } from './Text'

export type ConfirmSheetProps = {
  open: boolean
  /** resident: the white card · guard: the Guard App's surface-800 card. */
  tone?: 'resident' | 'guard'
  /** Names the person or thing IN FULL — "Remove Soft Dev from your household?" */
  title: string
  /** The consequence, in sentences. */
  lines: readonly string[]
  /** In full — "Remove Soft Dev". */
  confirmLabel: string
  /** Default "Cancel"; "Keep the pass" / "Keep the booking" where the act is itself called "cancel". */
  cancelLabel?: string
  /** The write is on its way: confirm busy; the way out reads "Close" and only takes the sheet off the screen. */
  working: boolean
  /** The server's refusal, or why it could not be asked — shown INSIDE the sheet. */
  problem?: string | null
  /** Why confirm cannot be pressed right now: the button is disabled with this as its reason. */
  blocked?: string | null
  /** Default true: a red confirm button (the Resident App's a red outline). */
  destructive?: boolean
  /** Called once per attempt. The sheet closes only when the caller sets `open` false. */
  onConfirm: () => void
  /**
   * Cancel, the backdrop, back. ALSO called while `working`: the caller then only hides the question and lets the
   * write carry on (confirmFlow's `cancel` does exactly that). The sheet leaves the screen either way.
   */
  onCancel: () => void
  /** The control that opened the sheet: focus goes back to it on close. */
  returnFocusTo?: RefObject<View | null>
  /** Where focus goes, in order, when that control is gone by the time it closes (its row was removed). */
  fallbackFocusTo?: readonly RefObject<View | null>[]
  children?: ReactNode
  /** The panic screen's own question only: the one sheet allowed on /panic. */
  emergencyScreen?: boolean
}

const EMERGENCY_ROUTE = /^\/(panic|duress)(\/|$)/
/** While the write runs the way out cannot call it off — so it is not called "Cancel", and the sheet says so. */
export const CLOSE_WHILE_WORKING = 'Close'
export const WORKING_NOTE = 'This is being done now. Closing this does not stop it: if it does not go through, this comes back with the reason.'

type Box = { backgroundColor: string; borderColor: string }
type Ink = { card: ColorToken; border: ColorToken; title: ColorToken; body: ColorToken; problem: ColorToken; problemBox: Box; blocked: ColorToken; blockedBox: Box; scrim: string }

// Every text colour sits on a surface the contrast audit has measured it on: amber-700 on amber-100 (resident: a
// refusal is an ordinary error, amber, M98); red-500 and amber-500 on surface-800 (guard — the box is the card's own
// colour, marked by a border, because a tint under the text would be a pair nobody measured).
const INK: Record<'resident' | 'guard', Ink> = {
  resident: {
    card: 'white', border: 'navy-100', title: 'navy-900', body: 'slate-600',
    problem: 'amber-700', problemBox: { backgroundColor: color('amber-100'), borderColor: color('amber-100') },
    blocked: 'amber-700', blockedBox: { backgroundColor: color('amber-100'), borderColor: color('amber-100') },
    scrim: alpha('navy-900', 0.55),
  },
  guard: {
    card: 'surface-800', border: 'border-700', title: 'white', body: 'text-2',
    problem: 'red-500', problemBox: { backgroundColor: color('surface-800'), borderColor: alpha('red-500', 0.5) },
    blocked: 'amber-500', blockedBox: { backgroundColor: color('surface-800'), borderColor: alpha('amber-500', 0.5) },
    scrim: alpha('surface-900', 0.7),
  },
}

/** Moves focus to `node`: a phone's screen reader, or the browser's focus. Not every node can take it; never an error. */
function focusOn(node: View | null | undefined): void {
  if (!node) return
  try {
    if (Platform.OS === 'web') (node as unknown as { focus?: () => void }).focus?.()
    else AccessibilityInfo.sendAccessibilityEvent(node as Parameters<typeof AccessibilityInfo.sendAccessibilityEvent>[0], 'focus')
  } catch {
    /* nothing to move */
  }
}

/**
 * Still part of the screen? A phone's node (React Native's DOM-like element) and a browser's both say `isConnected`;
 * one that cannot say is taken to be there.
 */
const onScreen = (node: View | null | undefined): node is View => !!node && (node as unknown as { isConnected?: boolean }).isConnected !== false

export function ConfirmSheet({ open, tone = 'resident', title, lines, confirmLabel, cancelLabel = 'Cancel', working, problem = null, blocked = null, destructive = true, onConfirm, onCancel, returnFocusTo, fallbackFocusTo, children, emergencyScreen = false }: ConfirmSheetProps) {
  const insets = useSafeAreaInsets()
  const still = useReduceMotionPref()
  const covered = useCovered()
  const pathname = usePathname() ?? ''
  const [focused, setFocused] = useState(true)
  // Closed on while its write runs: off the screen at once, whatever the caller does. The question comes back
  // when the attempt is over (a refusal is shown) or goes with it (`open` false) — never is anybody held by it.
  const [detached, setDetached] = useState(false)
  if (detached && (!working || !open)) setDetached(false)
  const visible = open && !detached && focused && !covered && (emergencyScreen || !EMERGENCY_ROUTE.test(pathname))

  // What the listeners below act on is always the latest render's — they are registered once.
  const latest = useRef({ open, working, problem, onCancel, returnFocusTo, fallbackFocusTo })
  useEffect(() => {
    latest.current = { open, working, problem, onCancel, returnFocusTo, fallbackFocusTo }
  })

  /** An unanswered question is dropped; a write already on its way is never interrupted. */
  const dropIfIdle = useCallback(() => {
    const now = latest.current
    if (now.open && !now.working) now.onCancel()
  }, [])

  // Its screen losing focus — a push tapped, a tab changed, the route moved on.
  useFocusEffect(
    useCallback(() => {
      setFocused(true)
      return () => {
        setFocused(false)
        dropIfIdle()
      }
    }, [dropIfIdle]),
  )

  // The app going to the background: an unanswered question is dropped, and nothing was done. Kept: a write on its
  // way; what came back for it while the app was away (until the app is back — on an iPhone the answer can land
  // between 'inactive' and 'background'); and anything under a cover, which only the owner can lift. The app lock's
  // own Face ID or passcode prompt takes the app out of 'active' as it comes back, and must not drop a refusal.
  // A sheet that OPENS while the app is away is a refusal to a write it was closed on: nobody has seen it yet.
  useEffect(() => {
    if (!open) return
    const away = AppState.currentState === 'background' || AppState.currentState === 'inactive'
    let carrying = away
    const sub = AppState.addEventListener('change', (next) => {
      if (next === 'active') {
        carrying = false
        return
      }
      const now = latest.current
      if (!now.open) return
      if (now.working) {
        carrying = true
        return
      }
      if (carrying || isCovered()) return
      now.onCancel()
    })
    return () => sub.remove()
  }, [open])

  // One press, one attempt. The guard lets go once an attempt is over (a refusal can be pressed again).
  const pressed = useRef(false)
  useEffect(() => {
    if (!working) pressed.current = false
  })
  /** Whether a write was on its way at the last render — or has just been started by the press (FOCUS, below). */
  const wasWorking = useRef(false)
  const confirm = () => {
    if (pressed.current || working || blocked) return
    pressed.current = true
    // The write starts HERE, not at the render that shows it: an answer quick enough to land in the same render as
    // `working` would otherwise close the sheet as though it were cancelled, and send focus to a control the act removed.
    wasWorking.current = true
    onConfirm()
  }
  const cancel = () => {
    if (working) setDetached(true)
    onCancel()
  }

  /*
   * FOCUS. To the question each time it comes on screen — to the refusal in it, when it comes back with one. Back to
   * what opened it only when it CLOSES: hiding (under the lock, behind another screen) moves nobody, and a question
   * closed while out of sight moves nobody either. What opened it — and its neighbours — are remembered as it first
   * shows: a row's control is often given the ref only while its question is up, and is gone once its row is.
   */
  const titleRef = useRef<View>(null)
  const problemRef = useRef<View>(null)
  const opener = useRef<{ from: View | null; then: (View | null)[] } | null>(null)
  const wasVisible = useRef(false)
  /** The refusal the screen reader has been given — focused or announced — so it is never said twice. */
  const spoken = useRef<string | null>(null)
  useEffect(() => {
    if (!visible) return
    const now = latest.current
    if (!opener.current) opener.current = { from: now.returnFocusTo?.current ?? null, then: (now.fallbackFocusTo ?? []).map((ref) => ref.current) }
    // Back with a refusal in it (after the lock, another screen, or a write it was closed on): the refusal is the news.
    const toProblem = Platform.OS !== 'web' && !!problemRef.current
    if (toProblem) spoken.current = now.problem
    const frame = requestAnimationFrame(() => focusOn(toProblem ? problemRef.current : titleRef.current))
    return () => cancelAnimationFrame(frame)
  }, [visible])
  useEffect(() => {
    if (open || !opener.current) return
    const { from, then } = opener.current
    opener.current = null
    if (!wasVisible.current) return
    /*
     * Closed on the ANSWER (it was working a moment ago): the act was done, and what it was about — the member's row
     * and its Remove, an invite's Cancel link — is on its way off the screen, even if the list has not caught up
     * yet. So the fallbacks come first. Closed by Cancel: back to the control that opened it. The target is chosen
     * when focus is moved, from what is on the screen then.
     */
    const order = wasWorking.current ? [...then, from] : [from, ...then]
    const frame = requestAnimationFrame(() => focusOn(order.find(onScreen) ?? null))
    return () => cancelAnimationFrame(frame)
  }, [open])
  // After the two above, so they read what was on screen BEFORE this render.
  useEffect(() => {
    wasVisible.current = visible
    wasWorking.current = working
  })

  // A refusal that arrives while the sheet is up is SPOKEN. VoiceOver is told the words; on Android the note sits in
  // an assertive live region (below) that TalkBack reads when what is inside it changes. Once per refusal.
  useEffect(() => {
    if (!problem) {
      spoken.current = null
      return
    }
    if (!visible || spoken.current === problem) return
    spoken.current = problem
    if (Platform.OS === 'ios') AccessibilityInfo.announceForAccessibility(problem)
  }, [problem, visible])

  if (!visible) return null

  const ink = INK[tone]
  const guard = tone === 'guard'
  // The same words once: a refusal that is also why the button is disabled (the connection went mid-attempt).
  const note = blocked && blocked !== problem ? blocked : null
  // While the write runs, the way out cannot call it off: it is not called "Cancel" (or "Keep…"), and the note
  // above it — read before it by a screen reader — says what closing does.
  const way = working ? CLOSE_WHILE_WORKING : cancelLabel

  return (
    <Modal visible transparent animationType={still ? 'none' : 'fade'} statusBarTranslucent onRequestClose={cancel}>
      <View style={[s.scrim, { backgroundColor: ink.scrim }]}>
        {/*
          * The backdrop CANCELS — never confirms. It is NOT a control: no role, hidden from screen readers, and not a
          * Pressable, which react-native-web would make a tab stop (tabIndex 0) and its Modal would focus first.
          * It answers to the touch itself.
          */}
        <View aria-hidden onStartShouldSetResponder={() => true} onResponderRelease={cancel} testID="confirm-sheet-backdrop" style={StyleSheet.absoluteFill} />
        <View
          role="alertdialog"
          aria-modal
          aria-label={title}
          style={[s.card, { backgroundColor: color(ink.card), borderColor: color(ink.border), paddingBottom: insets.bottom + 20 }]}
        >
          <ScrollView style={s.body} contentContainerStyle={s.bodyInner} bounces={false}>
            {/* On the web the heading takes the browser's focus when the sheet shows (a tab stop it is not). */}
            <View ref={titleRef} accessible role="heading" tabIndex={Platform.OS === 'web' ? -1 : undefined}>
              <Text face="Poppins-SemiBold" size={16} lh={1.35} color={ink.title}>{title}</Text>
            </View>
            {lines.map((line, i) => (
              <Text key={`${i}-${line}`} face="Inter-Regular" size={12.5} lh={1.5} color={ink.body} style={s.line}>{line}</Text>
            ))}
            {children}
            <View testID="confirm-sheet-live" aria-live={Platform.OS === 'android' ? 'assertive' : undefined}>
              {problem ? (
                <View ref={problemRef} testID="confirm-sheet-problem" accessible role="alert" style={[s.note, ink.problemBox]}>
                  <Text face="Inter-SemiBold" size={12} lh={1.5} color={ink.problem}>{problem}</Text>
                </View>
              ) : null}
            </View>
            {note ? (
              <View testID="confirm-sheet-blocked" style={[s.note, ink.blockedBox]}>
                <Text face="Inter-SemiBold" size={12} lh={1.5} color={ink.blocked}>{note}</Text>
              </View>
            ) : null}
            {working ? <Text face="Inter-Regular" size={11.5} lh={1.5} color={ink.body} style={s.line}>{WORKING_NOTE}</Text> : null}
          </ScrollView>
          {guard ? (
            <>
              <GuardButton variant={destructive ? 'danger' : 'primary'} label={confirmLabel} onPress={confirm} loading={working} disabled={!!blocked} disabledReason={blocked ?? ''} style={s.first} />
              <GuardButton variant="outline" label={way} onPress={cancel} style={s.second} />
            </>
          ) : (
            <>
              <ResidentButton variant={destructive ? 'destructive' : 'primary'} label={confirmLabel} onPress={confirm} loading={working} disabled={!!blocked} disabledReason={blocked ?? ''} style={s.first} />
              <ResidentButton variant="outline" label={way} onPress={cancel} style={s.second} />
            </>
          )}
        </View>
      </View>
    </Modal>
  )
}

const s = StyleSheet.create({
  scrim: { flex: 1, justifyContent: 'flex-end', alignItems: 'center' },
  card: { width: '100%', maxWidth: 520, maxHeight: '92%', borderTopLeftRadius: 20, borderTopRightRadius: 20, borderWidth: 1, borderBottomWidth: 0, paddingTop: 20, paddingHorizontal: 20 },
  body: { flexGrow: 0, flexShrink: 1 },
  bodyInner: { paddingBottom: 2 },
  line: { marginTop: 8 },
  note: { borderRadius: 12, borderWidth: 1, paddingHorizontal: 12, paddingVertical: 10, marginTop: 12 },
  first: { marginTop: 18 },
  second: { marginTop: 10 },
})
