/**
 * resident-app-32 · Add household member — rebuilt from the board (M42; M47 §15, M48 §2.2), every value from the design
 * tokens and parts. All behaviour lives in useAddMember(); this file only draws it, the same way for a phone and for the
 * board's content.
 *
 * THE BOARD, TOP TO BOTTOM, AND NOTHING ELSE: the app bar (back, the title, nothing on the right) · Full name · Relationship,
 * a dropdown · Phone number · Email · "Access level": Member · Dependent · Helper · "Send an app invite", its gold switch
 * (drawn ON by the board; OFF until the resident turns it on — the user, M52 §6) · "Save household member". The board's sample values are the grey placeholders of empty boxes (fidelity/profile.ts,
 * never typed again here).
 *
 * WHERE THE BUILD DIFFERS FROM THE BOARD, ON THE LEAD'S WORD:
 *   - Email: not on the board, REQUIRED (M47 §15m) — the invite and the household's alerts go to it. The old "This
 *     person has no email — alerts go to me instead" tick is gone (§15e).
 *   - Phone: required, so the board's "(optional)" is not drawn (§15l).
 *   - The invite line reads "They'll get their own login by email" — the board's sentence with its one untrue word
 *     changed (§15.4): no SMS provider is connected, and the server emails the invite.
 *
 * NO SYSTEM DIALOG (M47 U7): a save that could not even be kept on the phone is said above the button. The switch is the
 * resident's own choice; a save with no connection sends no invite (D-158), and the answer after Save says so.
 *
 * NO "NOT SWITCHED ON YET" LINE (the user, M51-A §1, 8 Oct: "Delete the amber line"). Dependent and Helper are on from
 * the M51 server; on a server that has not opened them a tap on either simply does not move the control.
 *
 * IT DOES NOT MOVE. With the board's content and the email box the page fits a 390 × 844 phone with room to spare;
 * only the keyboard, the largest text size or a run of messages under the boxes can make it scroll.
 */

import { useState } from 'react'
import { Keyboard, StyleSheet, View } from 'react-native'
import { Controller } from 'react-hook-form'
import { EmptyState, Text, useFidelityReady } from '@gs/core'
import { Card, Field, PrimaryButton, SecondaryButton, Segment, Toggle } from '@/design/parts'
import { corner, fill, font, ink, role, space } from '@/design/tokens'
import { addMemberFixture } from '@/fidelity/profile'
import { ChooserSheet } from '@/features/visitors/components/ChooserSheet'
import { EmailSuggestion, FootNote, FormScreen, OutcomeScreen, backToProfile } from '../components/HouseholdForm'
import { EMAIL_MAX, INVITE_OFFLINE, NAME_MAX, NOT_PRIMARY_NOTE, PHONE_MAX, inviteActions, inviteLine, mmss, type InviteTone, type Member } from '../household'
import { useAddMember, type AddMemberModel } from '../useHouseholdForms'
import { useInvites, type InviteControl } from '../useInvites'

const TITLE = 'Add household member'

/** The board's own sample values, grey in empty boxes — gone at the first letter: never a value. */
const EXAMPLE = addMemberFixture

const ACCESS = 'Access level'
const INVITE = 'Send an app invite'
const INVITE_LINE = "They'll get their own login by email"

/* ---- the form's parts ---------------------------------------------------------------------------------------- */

/** `.field label` + `.role-seg` — the three levels, always drawn; below Member only once the server takes it. */
function AccessLevel({ m }: { m: AddMemberModel }) {
  return (
    <>
      <View style={s.accessLabel}><Text {...role.label} color={ink.label}>{ACCESS}</Text></View>
      <View style={s.roleSeg}>
        <Segment label={ACCESS} items={m.levels.map((level) => ({ value: level.value, label: level.label }))} value={m.level} onChange={m.chooseLevel} />
      </View>
    </>
  )
}

/** `.invite-row` — the board's words (one changed, §15.4), and the switch, off until the resident turns it on (M52 §6). */
function InviteRow({ m }: { m: AddMemberModel }) {
  return (
    <View style={s.inviteRow}>
      <View style={s.inviteText}>
        <Text {...font.inter700_12_5} color={ink.heading}>{INVITE}</Text>
        <View style={s.it2}><Text {...font.inter400_10_5} color={ink.secondary}>{INVITE_LINE}</Text></View>
      </View>
      <Toggle on={m.invite} onChange={m.toggleInvite} label={INVITE} />
    </View>
  )
}

/* ---- after Save: whether their invite went ------------------------------------------------------------------- */

const TONE: Record<InviteTone, (typeof ink)[keyof typeof ink]> = { ok: ink.body, warn: ink.onGoldTint, bad: ink.onGoldTint, quiet: ink.secondary }

/** The server's word on the invite (household.ts · inviteLine) — never "sent" on the phone's own account — and Resend. */
function InviteAnswer({ member, control }: { member: Member; control: InviteControl }) {
  const said = inviteLine(member, control.now)
  if (!said) return null
  const id = String(member.id)
  const send = inviteActions(member, control.now).send
  const problem = control.problem?.id === id ? control.problem.text : null
  const waiting = !!send && send.wait > 0
  const why = send && waiting ? `The invite was just sent. You can send it again in ${mmss(send.wait)}.` : !control.online ? INVITE_OFFLINE : undefined
  return (
    <Card style={s.answer}>
      <Text {...font.inter700_12_5} color={ink.heading}>{'Invite to the Resident App'}</Text>
      <View style={s.answerLine}><Text {...font.inter400_12_lh1_5} color={TONE[said.tone]} role={said.tone === 'bad' ? 'alert' : undefined}>{said.text}</Text></View>
      {problem ? <View style={s.answerLine}><Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{problem}</Text></View> : null}
      {send ? <SecondaryButton label={send.label} onPress={() => control.send(member)} disabled={waiting || !control.online} loading={control.working?.id === id} accessibilityHint={why} style={s.answerButton} /> : null}
      {send && waiting ? <View style={s.answerLine}><Text {...font.inter400_10_5_lh1_5} color={ink.secondary}>{'A resend waits 1 minute after the first send, then 2, then 5, so nobody’s inbox is flooded.'}</Text></View> : null}
    </Card>
  )
}

/* ---- the screen ------------------------------------------------------------------------------------------------ */

export default function AddMemberScreen() {
  const m = useAddMember()
  const [choosing, setChoosing] = useState(false)
  // This screen has no list: what the server answered here is what is drawn (`listFetchedAt` 0).
  const invites = useInvites({ shown: m.added ? [m.added] : [], listFetchedAt: 0 })
  useFidelityReady(true)

  if (m.recorded) {
    return (
      <OutcomeScreen
        title={TITLE}
        recorded={m.recorded}
        write={m}
        words={{
          confirmedTitle: 'Added to your household',
          confirmedPill: 'Added · Pending verification',
          confirmedBody: 'The estate office verifies every new member. Until it has, they show as Pending, and the gate will not act on their word.',
          refusedTail: 'Nobody was added.',
          another: 'Add another person',
          ...(m.capturedOffline && m.invite
            ? { pendingTail: "Saved on this phone while offline, so no invite goes with it. Once they're added, send it from Profile." }
            : m.invite
              ? { pendingTail: 'Their invite is emailed once it arrives, and Profile says whether it went.' }
              : null),
        }}
        onAnother={m.startAnother}
      >
        {m.added ? <InviteAnswer member={invites.withAnswer(m.added)} control={invites} /> : null}
      </OutcomeScreen>
    )
  }

  // Adding members is the primary resident's right; whoever reaches this screen another way is told who can.
  if (m.allowed === false) {
    return (
      <FormScreen title={TITLE}>
        <EmptyState tone="light" icon="privacy-note" title="Only the primary resident can add members" body={NOT_PRIMARY_NOTE} action={{ label: 'Back', onPress: backToProfile }} />
      </FormScreen>
    )
  }

  const relationships = m.relationships.map((option) => ({
    key: option.value,
    label: option.label,
    on: option.value === m.relationship,
    pick: () => {
      m.chooseRelationship(option.value)
      setChoosing(false)
    },
  }))

  return (
    <FormScreen title={TITLE} overlay={<ChooserSheet open={choosing} title="Relationship" choices={relationships} onClose={() => setChoosing(false)} />}>
      <Controller
        control={m.control}
        name="full_name"
        render={({ field }) => (
          <Field ref={field.ref} label="Full name" icon="cc-row" value={field.value} placeholder={EXAMPLE.fullName} onChangeText={field.onChange} onBlur={field.onBlur} open={m.nameLit} error={m.errors.full_name} autoCapitalize="words" autoCorrect={false} autoComplete="name" maxLength={NAME_MAX} returnKeyType="next" />
        )}
      />

      <Field
        label="Relationship"
        icon="input-6"
        value={m.relationshipLabel ?? ''}
        placeholder={EXAMPLE.board.relationshipLabel}
        onPress={() => {
          Keyboard.dismiss()
          setChoosing(true)
        }}
        open={choosing}
        chevron
        error={m.errors.relationship}
      />

      <Controller
        control={m.control}
        name="phone"
        render={({ field }) => (
          <Field ref={field.ref} label="Phone number" icon="call-btn" value={field.value} placeholder={EXAMPLE.board.phonePlaceholder} onChangeText={field.onChange} onBlur={field.onBlur} error={m.errors.phone} keyboardType="phone-pad" autoComplete="tel" maxLength={PHONE_MAX} returnKeyType="next" />
        )}
      />

      {/* The box and its "Did you mean …?" together keep the field's 15 below them. */}
      <View style={s.emailBlock}>
        <Controller
          control={m.control}
          name="email"
          render={({ field }) => (
            <Field ref={field.ref} label="Email" icon="input-5" value={field.value} placeholder={EXAMPLE.email} onChangeText={field.onChange} onBlur={field.onBlur} error={m.errors.email} keyboardType="email-address" autoCapitalize="none" autoCorrect={false} autoComplete="email" maxLength={EMAIL_MAX} returnKeyType="done" last />
          )}
        />
        <EmailSuggestion suggestion={m.emailSuggestion} onAccept={m.acceptEmailSuggestion} />
      </View>

      <AccessLevel m={m} />
      <InviteRow m={m} />

      {/* Not even kept on the phone (storage), or a demonstration session: said here, never in a dialog (U7). */}
      {m.saveError ? <View style={s.saveProblem}><FootNote problem={m.saveError} online offlineText="" /></View> : null}
      <PrimaryButton label={m.online ? 'Save household member' : 'Save on this phone'} onPress={m.send} loading={m.busy} />
    </FormScreen>
  )
}

const s = StyleSheet.create({
  // .field's 15 below the email box (and below its suggestion, when one shows)
  emailBlock: { marginBottom: space[15] },
  // A save that failed: its line sits above the button with the invite row's 18 kept under it
  saveProblem: { marginBottom: space[18] },
  // "Access level" alone in its .field: the label's 6 below collapses into the field's 15 (the board as a browser lays it out)
  accessLabel: { marginBottom: space[15] },
  // .role-seg is 18 above the invite row; the Segment part draws .seg's 20
  roleSeg: { marginBottom: space['-2'] },

  // .invite-row · .it1 · .it2
  inviteRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: space[10], backgroundColor: fill.tint, borderRadius: corner[14], paddingVertical: space[13], paddingHorizontal: space[14], marginBottom: space[18] },
  inviteText: { flex: 1, minWidth: 0 },
  it2: { marginTop: space[2] },

  // After Save: the invite's card, full width under the words
  answer: { alignSelf: 'stretch', marginTop: space[20], marginBottom: 0 },
  answerLine: { marginTop: space[6] },
  answerButton: { marginTop: space[12] },
})
