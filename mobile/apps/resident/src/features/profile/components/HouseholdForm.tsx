/**
 * The household forms' frame, and what they say after Save — Add household member (board 32, rebuilt from the board),
 * and the three no board draws, restyled in board 32's look (Add a vehicle, Add emergency contact, Add their email).
 * Every value comes from the design tokens and parts.
 *
 *   FormScreen       board 32's .appbar, then .body-pad (18 above, 20 at the sides and below) on white. The page fits a
 *                    390 × 844 phone and does not move: it scrolls only when something takes the room — the keyboard
 *                    over a low box, the largest text size — so no box and no button is ever out of reach.
 *   OutcomeScreen    what became of the write after Save. No board draws it, so it is the resident boards' success
 *                    pattern (design/parts Success): the gold tick ONLY once the estate office's server has it; "Saved
 *                    on this phone" or "Not accepted" without one. It never says "added" until the server has said so,
 *                    and its words are the outbox's own four states.
 *   EmailSuggestion  "Did you mean …? Tap to use it." under an email box (M18G §4.1) — a question, never a block.
 *
 * For the restyled forms, which keep every word they had:
 *   FieldHint        a helper line under a box, in the boards' helper type (.hint-row p); a message in its place hides it
 *   FootNote         the line above Save: what is still wrong (gold, as the Field part marks a box), or the offline note
 *                    (the success pattern's info card)
 *   OfficeHint       the boards' .hint-row: the 15-point note mark and an 11-point line, 16 under the button
 */

import type { ReactNode } from 'react'
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Icon, Pressable, Text, aboveGestureBar } from '@gs/core'
import { OUTBOX_LABEL } from '@gs/core/offline'
import { AppBar, Badge, ButtonRow, GoldTick, InfoCard, PrimaryButton, SecondaryButton } from '@/design/parts'
import { corner, fill, font, icon, ink, size, space } from '@/design/tokens'
import type { QueuedWrite, Recorded } from '../useHouseholdForms'
import { PROFILE_TAB, backToProfile } from './HouseholdParts'

export { PROFILE_TAB, backToProfile }

/* ---- the frame ------------------------------------------------------------------------------------------------ */

/** `overlay`: what opens over the page (a chooser sheet), drawn outside the scroll. */
export function FormScreen({ title, overlay, children }: { title: string; overlay?: ReactNode; children: ReactNode }) {
  const insets = useSafeAreaInsets()
  return (
    <View style={s.screen}>
      <AppBar title={title} fallback={PROFILE_TAB} />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
        {/* The board's 20 below, or the home indicator's strip where that is deeper — not both. */}
        <ScrollView style={s.fill} contentContainerStyle={[s.body, { paddingBottom: aboveGestureBar(space[20], insets.bottom) }]} bounces={false} overScrollMode="never" keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
          {children}
        </ScrollView>
      </KeyboardAvoidingView>
      {overlay}
    </View>
  )
}

/* ---- after Save ----------------------------------------------------------------------------------------------- */

export type OutcomeWords = {
  /** "Added to your household" */
  confirmedTitle: string
  /** What is true now that the server has it. */
  confirmedBody: string
  /** What did NOT happen when it was refused: "Nobody was added." */
  refusedTail: string
  /** "Add another person" — or null when another makes no sense (one contact's email). */
  another: string | null
  /** The pill once the server has it, where the outbox's own "Confirmed" would mislead ("Added · Pending verification"). */
  confirmedPill?: string
  /** Said after "Saved on this phone …" while it waits: what happens when it arrives. */
  pendingTail?: string
}

function sentenceOf(recorded: Recorded, words: OutcomeWords): string {
  const tail = words.pendingTail ? ` ${words.pendingTail}` : ''
  if (recorded.state === 'refused') return `${recorded.refusal ?? 'The estate office did not accept this.'} ${words.refusedTail} You can change it and send it again.`
  if (recorded.state === 'confirmed') return words.confirmedBody
  if (recorded.state === 'syncing') return `Saved on this phone: being sent to the estate office now.${tail}`
  return `Saved on this phone: it will be sent when you are back online. You do not need to keep this screen open.${tail}`
}

/**
 * The answer, under the bar with no way back (the write is made): the title, who or what was saved, where it stands,
 * and what that means — then `children` once the server has it (a new member's invite). Two buttons side by side,
 * the outline on the left, where both fit half a row; "Change it and send again" does not, so after a refusal the
 * two stand full width, one above the other, rather than cut a word.
 */
export function OutcomeScreen({ title, recorded, write, words, onAnother, children }: { title: string; recorded: Recorded; write: QueuedWrite; words: OutcomeWords; onAnother?: () => void; children?: ReactNode }) {
  const insets = useSafeAreaInsets()
  const refused = recorded.state === 'refused'
  const confirmed = recorded.state === 'confirmed'
  const another = words.another && onAnother && !refused ? <SecondaryButton label={words.another} onPress={onAnother} /> : null

  return (
    <View style={s.screen}>
      <AppBar title={title} back={false} fallback={PROFILE_TAB} />
      <ScrollView style={s.fill} contentContainerStyle={[s.outcome, { paddingBottom: aboveGestureBar(space[20], insets.bottom) }]} bounces={false} overScrollMode="never" showsVerticalScrollIndicator={false}>
        <View style={s.head} role="status">
          {confirmed ? <GoldTick /> : null}
          <Text {...font.poppins600_20} color={ink.heading} align="center" role="heading">{refused ? 'Not accepted' : confirmed ? words.confirmedTitle : 'Saved on this phone'}</Text>
          <View style={s.name}><Text {...font.inter500_12_5} color={ink.label} align="center">{recorded.title}</Text></View>
          <View style={s.pill}><Badge label={confirmed && words.confirmedPill ? words.confirmedPill : OUTBOX_LABEL[recorded.state]} urgent={!confirmed} /></View>
          <View style={s.line}><Text {...font.inter400_12_lh1_5} color={ink.secondary} align="center" role={refused ? 'alert' : undefined}>{sentenceOf(recorded, words)}</Text></View>
        </View>

        {confirmed ? children : null}

        <View style={s.buttons}>
          {refused ? (
            <>
              <PrimaryButton label="Change it and send again" onPress={write.changeAndResend} />
              <SecondaryButton label="Done" onPress={backToProfile} style={s.stacked} />
            </>
          ) : recorded.state === 'pending' ? (
            <>
              <ButtonRow>
                <SecondaryButton label="Done" onPress={backToProfile} />
                <PrimaryButton label="Try sending now" onPress={write.retryNow} />
              </ButtonRow>
              {another ? <View style={s.stacked}>{another}</View> : null}
            </>
          ) : (
            <ButtonRow>
              {another}
              <PrimaryButton label="Done" onPress={backToProfile} />
            </ButtonRow>
          )}
        </View>
      </ScrollView>
    </View>
  )
}

/* ---- small pieces ----------------------------------------------------------------------------------------------- */

/** A likely slip in a provider's name, offered to be put right — gold, as the boards draw what needs a look. */
export function EmailSuggestion({ suggestion, onAccept }: { suggestion: string | null; onAccept: () => void }) {
  if (!suggestion) return null
  return (
    <Pressable onPress={onAccept} role="button" aria-label={`Did you mean ${suggestion}? Tap to use it`} style={s.suggest}>
      <Text {...font.inter400_12_5_lh1_5} color={ink.heading}>{'Did you mean '}<Text face={font.inter700_12_5.face} color={ink.action}>{suggestion}</Text>{'? Tap to use it.'}</Text>
    </Pressable>
  )
}

/** Under a box drawn `last`: its helper line — unless the box has a message, which takes that place (as it always has). */
export function FieldHint({ text, error }: { text: string; error?: string | null }) {
  if (error) return null
  return <View style={s.fieldHint}><Text {...font.inter400_11_lh1_5} color={ink.secondary}>{text}</Text></View>
}

/** Above Save: the first thing still wrong (its box may be out of sight), or, with no connection, what Save will do. */
export function FootNote({ problem, online, offlineText }: { problem: string | null; online: boolean; offlineText: string }) {
  if (problem) return <View style={s.problem} role="alert"><Text {...font.inter600_12_lh1_5} color={ink.onGoldTint}>{problem}</Text></View>
  if (!online) return <View style={s.offline} role="status"><InfoCard text={offlineText} /></View>
  return null
}

/** `.hint-row` under the button: what cannot be done from the app, and who can. */
export function OfficeHint({ text }: { text: string }) {
  return (
    <View style={s.hintRow}>
      <Icon name="invite-note" size={icon[15]} color={ink.secondary} style={s.hintIcon} />
      <View style={s.hintText}><Text {...font.inter400_11_lh1_5} color={ink.secondary}>{text}</Text></View>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  fill: { flex: 1 },
  // .body-pad: 18 above, 20 at each side (the 20 below is the inset's or the board's, above)
  body: { paddingTop: space[18], paddingHorizontal: space[20] },

  // The success pattern's .receipt-wrap and its rhythm (parts/Success.tsx)
  outcome: { paddingTop: space[30], paddingHorizontal: space[24] },
  head: { alignItems: 'center' },
  name: { marginTop: space[5] },
  pill: { marginTop: space[10] },
  line: { marginTop: space[10] },
  buttons: { marginTop: space[16] },
  stacked: { marginTop: space[10] },

  suggest: { minHeight: size[40], justifyContent: 'center', backgroundColor: fill.goldTint, borderRadius: corner[12], paddingHorizontal: space[12], paddingVertical: space[8], marginTop: space[8] },

  fieldHint: { marginTop: space[6] },
  problem: { backgroundColor: fill.goldTint, borderRadius: corner[12], padding: space[12], marginTop: space[18] },
  offline: { marginTop: space[18] },
  // .hint-row: gap 9, 16 below the button, 2 in at each side; its mark 1 down
  hintRow: { flexDirection: 'row', gap: space[9], marginTop: space[16], paddingHorizontal: space[2] },
  hintIcon: { marginTop: space[1] },
  hintText: { flex: 1, minWidth: 0 },
})
