/**
 * resident-app-19 · Security — rebuilt from the board (M42 round 2), every value from the design tokens and parts.
 *
 * The board, top to bottom: Biometric login (Face ID / fingerprint · Require biometrics for payments, each with its
 * switch) · Active sessions — each signed-in phone, its name over "This device" with the Current tag, or over "Last
 * active 2 days ago" with a gold Sign out. The board's "Change PIN" is not drawn (M50 §4.4): there is no resident PIN.
 *
 * EVERY SWITCH WORKS (M50 §3, §4). Face ID / fingerprint is the app lock AND fingerprint sign-in: on, this phone keeps a
 * key behind its own biometric prompt that signs it back in with no emailed code (auth/fingerprint.ts); off, the key
 * stops working. Require biometrics for payments makes the phone confirm its owner before a card payment above $5,000
 * (paymentsLock.ts) — on, as the board draws it. Nothing biometric is read, kept or sent: the phone's own prompt answers
 * yes or no. Whatever a switch could not do is said under the card, never in a system dialog. Active sessions is the
 * server's list of this account's phones (me.sessions); Sign out on another phone asks first, in the kit's sheet over the
 * screen, and needs a connection.
 *
 * IT DOES NOT MOVE: with the board's content the page fits a 390 × 844 phone, so it is laid out fixed. Only a list of
 * phones longer than the room left scrolls, inside its own card.
 */

import { ScrollView, StyleSheet, View } from 'react-native'
import { Pressable, Text, useFidelityReady } from '@gs/core'
import { AppBar, Card, IconTile, SectionLabel, Toggle } from '@/design/parts'
import { fill, font, ink, line, radius, space, stroke } from '@/design/tokens'
import { Confirm } from '../components/SettingsParts'
import { Divider, TRow } from '../components/Rows'
import { useSecurity, type SessionLine } from '../useSettings'

const FACE_ID = 'Face ID / fingerprint'
const FACE_ID_LINE = 'Use biometrics to log in and confirm payments'
const PAYMENTS = 'Require biometrics for payments'
const PAYMENTS_LINE = 'Re-confirm identity above $5,000'

/** A Sign out link's touch area, grown to 44 points. */
const LINK_REACH = { top: space[15], bottom: space[15], left: space[10], right: space[10] }

/** `.session-row` — the phone's tile, its name and line, then Current or Sign out; ruled underneath but the last. */
function Session({ phone, last, onSignOut }: { phone: SessionLine; last: boolean; onSignOut: (phone: SessionLine) => void }) {
  return (
    <View style={last ? s.session : s.sessionRule}>
      <IconTile name="device-row" tile={36} glyph={17} />
      <View style={s.sessionText}>
        <Text {...font.inter700_12_5} color={ink.heading}>{phone.name}</Text>
        <View style={s.sessionLine}><Text {...font.inter400_10_5} color={ink.secondary}>{phone.line}</Text></View>
      </View>
      {phone.current ? (
        <View style={s.currentTag}><Text {...font.inter700_9} color={ink.onTint}>{'Current'}</Text></View>
      ) : phone.canSignOut ? (
        <Pressable onPress={() => onSignOut(phone)} role="button" aria-label={`Sign out ${phone.name}`} hitSlop={LINK_REACH} style={({ pressed }) => (pressed ? s.pressed : null)}>
          <Text {...font.inter700_11} color={ink.link}>{'Sign out'}</Text>
        </Pressable>
      ) : null}
    </View>
  )
}

export default function SecurityScreen() {
  const m = useSecurity()
  const lock = m.lock
  useFidelityReady(true)

  // What either switch did not do, said under the card — never in a dialog (M50 §4).
  const note = lock.problem

  return (
    <View style={s.screen}>
      <AppBar title="Security" fallback="/settings" />
      <View style={s.body}>
        {/* No "Change PIN" (M50 §4.4): there is no resident PIN, so the screen opens on Biometric login. */}
        <SectionLabel>Biometric login</SectionLabel>
        <Card pad={13}>
          <TRow icon="set-icon-6" title={FACE_ID} line={FACE_ID_LINE}>
            <Toggle on={lock.on} onChange={lock.toggle} label={`${FACE_ID}. ${FACE_ID_LINE}`} />
          </TRow>
          <Divider />
          <TRow icon="pm-icon" title={PAYMENTS} line={PAYMENTS_LINE}>
            <Toggle on={m.payments.on} onChange={m.payments.toggle} label={`${PAYMENTS}. ${PAYMENTS_LINE}`} />
          </TRow>
        </Card>
        {note ? <View style={s.note}><Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{note}</Text></View> : null}

        <SectionLabel>Active sessions</SectionLabel>
        <Card pad={13} style={s.sessions}>
          <ScrollView style={s.sessionList} bounces={false} overScrollMode="never" showsVerticalScrollIndicator={false}>
            {m.sessions.map((phone, i) => (
              <Session key={String(phone.id)} phone={phone} last={i === m.sessions.length - 1} onSignOut={m.ending.ask} />
            ))}
          </ScrollView>
        </Card>
      </View>

      {/* Asked in a sheet OVER the screen: the row stays where it is, and the question can never open under the fold. */}
      <Confirm
        open={m.ending.asking}
        title={`Sign out ${m.ending.name ?? 'that phone'}?`}
        lines={[
          'It can no longer open your account: the next time anybody opens the app on it, it asks for a new sign-in code.',
          'It gets no more of your notifications.',
          'This phone stays signed in.',
        ]}
        blocked={m.ending.online ? null : 'Signing another phone out needs a connection.'}
        problem={m.ending.problem}
        confirmLabel="Sign it out"
        working={m.ending.working}
        onConfirm={m.ending.confirm}
        onCancel={m.ending.dismiss}
      />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  // .body-pad
  body: { flex: 1, paddingTop: space[18], paddingHorizontal: space[20], paddingBottom: space[20] },
  // A switch's answer, under its card: the card's own 10 below it is kept
  note: { marginTop: space['-2'], marginBottom: space[10] },
  // The phones' card gives way when the list is longer than the room left, and the list scrolls inside it.
  sessions: { flexShrink: 1 },
  sessionList: { flexGrow: 0 },
  // .session-row · .sess-txt · .current-tag
  session: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[11] },
  sessionRule: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[11], borderBottomWidth: stroke[1], borderBottomColor: line.hairline },
  sessionText: { flex: 1, minWidth: 0 },
  sessionLine: { marginTop: space[1] },
  currentTag: { backgroundColor: fill.tint, borderRadius: radius.chip, paddingVertical: space[4], paddingHorizontal: space[8] },
  pressed: { opacity: 0.7 },
})
