/**
 * A pending member's invite to the Resident App, under their row in the member sheet on the Profile tab. No board
 * draws it; it is built from the boards' own pieces — the member row's type, the Remove link. (After an add, the
 * rebuilt Add household member draws the same answer itself, from the design tokens: AddMemberScreen · InviteAnswer.)
 *
 * Every word is the server's `invite.status` (household.ts · inviteLine). The phone never says "sent" on its
 * own account, and "Resend in 1:45" is the server's timer, counted down.
 *
 * Cancel invite is asked in a ConfirmSheet over the screen (CancelInviteSheet) — never a card drawn under the list,
 * where it could open off the screen — and it names the member in full on the title.
 */

import type { Ref, RefObject } from 'react'
import { StyleSheet, View } from 'react-native'
import { ConfirmSheet, Pressable, Text, c, type ColorToken } from '@gs/core'
import { INVITE_OFFLINE, inviteActions, inviteLine, mmss, type InviteTone, type Member } from '../household'
import { cancelInviteSheet } from '../sheets'
import type { InviteControl } from '../useInvites'

// `bad` is an invite that FAILED (the mail server refused it): its words sit in a red OUTLINE, never on red (M98).
const TONE: Record<InviteTone, ColorToken> = { ok: 'green-700', warn: 'amber-700', bad: 'red-700', quiet: 'slate-500' }

/** A small word is about 14 high: 16 above and below makes a 46-point target, as "Remove" has. */
const SLOP = { top: 16, bottom: 16, left: 10, right: 10 } as const

type MemberLike = Pick<Member, 'id' | 'full_name' | 'status' | 'email' | 'invite'>

/** True when there is anything to draw under this member. */
export const hasInviteLine = (member: MemberLike, now: number): boolean => inviteLine(member, now) !== null

/** Why an invite's controls are held: another press about it is on its way (a send, or a cancel closed on). */
export const INVITE_BUSY = 'Wait: this invite is being changed now.'

/**
 * Under a member's row: what became of their invite, and — for the primary resident — Resend and Cancel. `cancelRef`
 * and `sendRef` are given to the row whose cancel is being asked: the sheet's way back, and where focus goes once
 * the invite is cancelled and its Cancel link has gone.
 */
export function InviteUnderRow({ member, control, manage, cancelRef, sendRef }: { member: MemberLike; control: InviteControl; manage: boolean; cancelRef?: Ref<View>; sendRef?: Ref<View> }) {
  const line = inviteLine(member, control.now)
  if (!line) return null
  const id = String(member.id)
  const actions = manage ? inviteActions(member, control.now) : { send: null, cancel: false }
  const busy = control.working?.id === id ? control.working.what : null
  const problem = control.problem?.id === id ? control.problem.text : null
  const waiting = !!actions.send && actions.send.wait > 0
  const sendOff = waiting || !!control.working || !control.online
  return (
    <View style={s.under}>
      <View style={line.tone === 'bad' ? s.failed : null}>
        <Text face="Inter-Medium" size={11} lh={1.45} color={TONE[line.tone]} role={line.tone === 'bad' ? 'alert' : undefined}>{line.text}</Text>
      </View>
      {actions.send || actions.cancel ? (
        <View style={s.actions}>
          {actions.send ? (
            <Pressable
              ref={sendRef}
              onPress={() => control.send(member)}
              disabled={sendOff}
              role="button"
              aria-label={`${actions.send.label} to ${member.full_name}`}
              aria-disabled={sendOff}
              aria-busy={busy === 'send'}
              accessibilityHint={waiting ? `The invite was just sent. You can send it again in ${mmss(actions.send.wait)}.` : !control.online ? INVITE_OFFLINE : control.working ? INVITE_BUSY : undefined}
              hitSlop={SLOP}
            >
              <Text face="Inter-Bold" size={11} color={sendOff ? 'slate-500' : 'navy-600'}>{busy === 'send' ? 'Sending…' : actions.send.label}</Text>
            </Pressable>
          ) : null}
          {actions.cancel ? (
            <Pressable
              ref={cancelRef}
              onPress={() => control.ask(member)}
              disabled={!!control.working}
              role="button"
              aria-label={`Cancel the invite to ${member.full_name}`}
              aria-disabled={!!control.working}
              aria-busy={busy === 'cancel'}
              accessibilityHint={control.working ? INVITE_BUSY : undefined}
              hitSlop={SLOP}
              style={[s.destructive, control.working ? s.destructiveOff : null]}
            >
              <Text face="Inter-Bold" size={11} color={control.working ? 'slate-500' : 'red-700'}>{busy === 'cancel' ? 'Cancelling…' : 'Cancel invite'}</Text>
            </Pressable>
          ) : null}
        </View>
      ) : null}
      {!control.online && manage && actions.send && !problem ? <Text face="Inter-Regular" size={10.5} lh={1.45} color="slate-500" style={s.gap}>{INVITE_OFFLINE}</Text> : null}
      {problem && control.asking?.id !== member.id ? <Text face="Inter-SemiBold" size={11} lh={1.45} color="amber-700" style={s.gap} role="alert">{problem}</Text> : null}
    </View>
  )
}

/**
 * Before an invite is cancelled: a ConfirmSheet over the screen, named in full ("Cancel Soft Dev's invite?"), said
 * before it is done, sent once and only while connected; the server's refusal stays inside it.
 */
export function CancelInviteSheet({ control, returnFocusTo, fallbackFocusTo }: { control: InviteControl; returnFocusTo?: RefObject<View | null>; fallbackFocusTo?: readonly RefObject<View | null>[] }) {
  const member = control.asking
  const words = member ? cancelInviteSheet(member, control.online) : null
  return (
    <ConfirmSheet
      open={!!words}
      title={words?.title ?? ''}
      lines={words?.lines ?? []}
      confirmLabel={words?.confirmLabel ?? ''}
      cancelLabel={words?.cancelLabel}
      working={control.working?.what === 'cancel'}
      problem={control.cancelProblem}
      blocked={words?.blocked}
      onConfirm={control.confirmCancel}
      onCancel={control.keep}
      returnFocusTo={returnFocusTo}
      fallbackFocusTo={fallbackFocusTo}
    />
  )
}

const s = StyleSheet.create({
  // In line with the name: the avatar is 34 wide, then an 11 gap.
  under: { paddingLeft: 45, paddingBottom: 8, borderBottomWidth: 1, borderBottomColor: c('navy-100') },
  actions: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 18, marginTop: 5 },
  gap: { marginTop: 4 },
  // M98: an invite that failed, and the destructive Cancel, each in a red OUTLINE; never a red fill.
  failed: { alignSelf: 'flex-start', borderWidth: 1, borderColor: c('rd-red-700'), borderRadius: 8, paddingHorizontal: 8, paddingVertical: 5, backgroundColor: c('rd-white') },
  destructive: { borderWidth: 1, borderColor: c('rd-red-700'), borderRadius: 8, paddingHorizontal: 10, paddingVertical: 4, backgroundColor: c('rd-white') },
  destructiveOff: { borderColor: c('rd-slate-300') },
})
