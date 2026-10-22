/**
 * What the four household forms share: the frame (header · keyboard · scroll), the line above the
 * button (what is still wrong, or "no connection — it will be saved on this phone"), and what is shown
 * AFTER Save — where the change stands, in the outbox's own four words. No board draws that last part;
 * it is built from the resident boards' own pieces (board 11's round badge, board 01's type and buttons),
 * the same way the maintenance request's is.
 *
 * It never says "added" until the server has said so.
 */

import type { ReactNode } from 'react'
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { Icon, ResidentAppBar, ResidentButton, Text, c, type ColorToken } from '@gs/core'
import { OUTBOX_LABEL } from '@gs/core/offline'
import type { QueuedWrite, Recorded } from '../useHouseholdForms'

export const PROFILE_TAB = '/(tabs)/profile'

export const backToProfile = () => (router.canGoBack() ? router.back() : router.replace(PROFILE_TAB as never))

export function FormFrame({ title, canGoBack = true, children }: { title: string; canGoBack?: boolean; children: ReactNode }) {
  return (
    <View style={s.screen}>
      <ResidentAppBar title={title} left={canGoBack ? 'back' : null} fallback={PROFILE_TAB} />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
        <ScrollView style={s.fill} contentContainerStyle={s.bodyPad} keyboardShouldPersistTaps="handled">
          {children}
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  )
}

/** The line above Save: the first thing still wrong (its field may be scrolled away), or the honest offline note. */
export function FootNote({ problem, online, offlineText }: { problem: string | null; online: boolean; offlineText: string }) {
  // The first thing still wrong is an ordinary problem: amber (M98; red is an open panic's colour and nothing else).
  if (problem) return <View style={s.problem} role="alert"><Text face="Inter-SemiBold" size={12} lh={1.5} color="amber-700">{problem}</Text></View>
  if (!online) return <View style={s.offline} role="status"><Text face="Inter-SemiBold" size={12} lh={1.5} color="amber-700">{offlineText}</Text></View>
  return null
}

/** `refused` is Rejected: a red OUTLINE on white, never a red fill (M98). */
const STATE_TONE: Record<Recorded['state'], { ink: ColorToken; wash: ColorToken; edge?: ColorToken }> = {
  pending: { ink: 'amber-700', wash: 'amber-100' },
  syncing: { ink: 'amber-700', wash: 'amber-100' },
  confirmed: { ink: 'green-700', wash: 'success-100' },
  refused: { ink: 'red-700', wash: 'rd-white', edge: 'rd-red-700' },
}

export type RecordedWords = {
  /** "Added to your household" */
  confirmedTitle: string
  /** What is true now that the server has it. */
  confirmedBody: string
  /** What did NOT happen when it was refused: "Nobody was added." */
  refusedTail: string
  /** "Add another person" — or null when another makes no sense (my own details). */
  another: string | null
  /**
   * The pill once the server has it, when the outbox's own "Confirmed" would mislead: a member is ADDED, and
   * still Pending until the estate office verifies them — "Confirmed" beside that reads as the office's yes.
   */
  confirmedPill?: string
  /** Said after "Saved on this phone …" while it waits: what happens when it arrives. */
  pendingTail?: string
}

export function RecordedPanel({ recorded, write, words, onAnother, children }: { recorded: Recorded; write: QueuedWrite; words: RecordedWords; onAnother?: () => void; /** Drawn under the words once the server has it. */ children?: ReactNode }) {
  const refused = recorded.state === 'refused'
  const confirmed = recorded.state === 'confirmed'
  const tone = STATE_TONE[recorded.state]
  return (
    <View style={s.recorded} role="status">
      <View style={[s.recordedBadge, { backgroundColor: c(tone.wash) }, tone.edge ? { borderWidth: 1.5, borderColor: c(tone.edge) } : null]}>
        <Icon name={refused ? 'qa-tile-3' : confirmed ? 'check' : 'recap-item'} size={30} color={tone.ink} />
      </View>
      <Text face="Poppins-SemiBold" size={18} color="navy-900" align="center" role="heading">{refused ? 'Not accepted' : confirmed ? words.confirmedTitle : 'Saved on this phone'}</Text>
      <Text face="Inter-Medium" size={12.5} lh={1.5} color="slate-600" align="center" style={{ marginTop: 6 }}>{recorded.title}</Text>

      <View style={[s.pill, { backgroundColor: c(tone.wash) }, tone.edge ? { borderWidth: 1, borderColor: c(tone.edge) } : null]}><Text face="Inter-Bold" size={10.5} color={tone.ink}>{confirmed && words.confirmedPill ? words.confirmedPill : OUTBOX_LABEL[recorded.state]}</Text></View>
      <Text face="Inter-Regular" size={12.5} lh={1.5} color={refused ? 'amber-700' : 'slate-600'} align="center" style={{ marginTop: 10 }} role={refused ? 'alert' : undefined}>
        {refused
          ? `${recorded.refusal ?? 'The estate office did not accept this.'} ${words.refusedTail} You can change it and send it again.`
          : confirmed ? words.confirmedBody
          : recorded.state === 'syncing' ? `Saved on this phone: being sent to the estate office now.${words.pendingTail ? ` ${words.pendingTail}` : ''}`
          : `Saved on this phone: it will be sent when you are back online. You do not need to keep this screen open.${words.pendingTail ? ` ${words.pendingTail}` : ''}`}
      </Text>
      {confirmed ? children : null}

      {refused ? <ResidentButton label="Change it and send again" onPress={write.changeAndResend} style={s.full} /> : null}
      {recorded.state === 'pending' ? <ResidentButton label="Try sending now" onPress={write.retryNow} style={s.full} /> : null}
      <ResidentButton variant={refused || recorded.state === 'pending' ? 'outline' : 'primary'} label="Done" onPress={backToProfile} style={refused || recorded.state === 'pending' ? s.fullTight : s.full} />
      {words.another && onAnother && !refused ? <ResidentButton variant="outline" label={words.another} onPress={onAnother} style={s.fullTight} /> : null}
    </View>
  )
}

/** The reasons a change cannot be undone from the app — said before the resident saves. */
export function OfficeNote({ text }: { text: string }) {
  return (
    <View style={s.officeNote}>
      <Icon name="invite-note" size={15} color="slate-500" style={s.officeNoteIcon} />
      <View style={s.shrink}><Text face="Inter-Regular" size={11} lh={1.5} color="slate-500">{text}</Text></View>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('white'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 18, paddingBottom: 32, paddingHorizontal: 20 },
  problem: { backgroundColor: c('amber-100'), borderRadius: 12, padding: 12, marginTop: 18 },
  offline: { backgroundColor: c('amber-100'), borderRadius: 12, padding: 12, marginTop: 18 },
  recorded: { flexDirection: 'column', alignItems: 'center', paddingTop: 24, paddingHorizontal: 4 },
  recordedBadge: { width: 74, height: 74, borderRadius: 37, alignItems: 'center', justifyContent: 'center', marginBottom: 20 },
  pill: { paddingHorizontal: 12, paddingVertical: 5, borderRadius: 20, marginTop: 14 },
  full: { width: '100%', marginTop: 24 },
  fullTight: { width: '100%', marginTop: 10 },
  officeNoteIcon: { width: 15, height: 15, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  officeNote: { flexDirection: 'row', paddingHorizontal: 2, gap: 9, marginTop: 16 },
})
