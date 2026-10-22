/**
 * resident-app-33 · Privacy & data — rebuilt from the board (M42 round 2), every value from the design tokens and parts.
 *
 * The board, top to bottom: Your data (Download my data · Data retention) · Your consents (Biometric login · Location ·
 * Marketing communications, each with its pill) · Account (Delete my account) · "Read our full Privacy Policy".
 *
 * WHAT EACH DOES (usePrivacy). Data retention and the policy link open the Privacy Policy, which says how long the
 * gate, financial and report records are kept. Download my data fetches the server's PDF of everything it holds about
 * the person and opens the share sheet with it (M50 §4.3): while it is on its way the row's chevron is a spinner, and
 * what stopped it is said in the screen's own sheet. Biometric login is the app lock (a tap opens Security); Location is the phone's own
 * permission (a tap asks the phone, or opens its Settings); Marketing communications is a plain fact — the estate
 * sends none. Delete my account opens the account deletion screen.
 *
 * TWO THINGS DIFFER FROM THE BOARD, BY ORDER. The Location row keeps its live words — the user, 7 October: location
 * text says what the app does, and an incident report pins the place by itself (M42 §5 #15). And "Clear data saved on
 * this phone" stays, as the last row of Your data (the lead's order: keep clearing data) — asked first, in the kit's
 * sheet; it removes only copies of what the estate already has.
 *
 * IT DOES NOT MOVE: with the board's content the page fits a 390 × 844 phone, so it is laid out fixed.
 */

import { ActivityIndicator, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { Icon, Pressable, Text, c, useFidelityReady, type IconName } from '@gs/core'
import { AppBar, Card, IconTile, NoteSheet, SectionLabel, useNote } from '@/design/parts'
import { fill, font, icon, ink, line, radius, space, stroke } from '@/design/tokens'
import { Confirm } from '../components/SettingsParts'
import { Divider } from '../components/Rows'
import { usePrivacy, type ConsentRow } from '../useSettings'

const DELETE = 'Delete my account'
const DELETE_LINE = "Your household's financial records stay with the estate"

/** The policy link's touch area, grown to 44 points — never up into the card above. */
const POLICY_REACH = { top: space[9], bottom: space[13] }

/**
 * `.priv-row` — a 36-point tile with its 17-point icon, the title (12.5 · 600) over its line (10.5), the chevron — which
 * is a spinner, in the chevron's place and size, while the row's work is on its way.
 */
function DataRow({ glyph, title, line: sub, onPress, busy = false }: { glyph: IconName; title: string; line: string; onPress: () => void; busy?: boolean }) {
  return (
    <Pressable onPress={busy ? undefined : onPress} role="button" aria-label={`${title}. ${sub}`} aria-busy={busy} style={({ pressed }) => [s.priv, pressed && !busy ? s.pressed : null]}>
      <IconTile name={glyph} tile={36} glyph={17} />
      <View style={s.privText}>
        <Text {...font.inter600_12_5} color={ink.heading}>{title}</Text>
        <View style={s.line}><Text {...font.inter400_10_5} color={ink.secondary}>{sub}</Text></View>
      </View>
      {busy ? <ActivityIndicator size="small" color={c(ink.faint)} style={s.spinner} /> : <Icon name="chevron-right" size={icon[16]} color={ink.faint} />}
    </Pressable>
  )
}

/** `.consent-row` — the consent over its line (10), and its pill: navy "Granted", amber "Off". Ruled underneath but the last. */
function Consent({ row, last, onPress }: { row: ConsentRow; last: boolean; onPress: () => void }) {
  const look = last ? s.consent : s.consentRule
  const body = (
    <>
      <View style={s.consentText}>
        <Text {...font.inter600_12_5} color={ink.heading}>{row.title}</Text>
        <View style={s.line}><Text {...font.inter400_10} color={ink.secondary}>{row.line}</Text></View>
      </View>
      {row.status ? (
        <View style={row.granted ? s.granted : s.notGranted}>
          <Text {...font.inter700_9_5} color={row.granted ? ink.onTint : ink.onGoldTint}>{row.status}</Text>
        </View>
      ) : null}
    </>
  )
  // Nothing to ask and nowhere to open (marketing, a browser, the phone not answered yet): a fact, not a control.
  return row.tap ? (
    <Pressable onPress={onPress} role="button" aria-label={`${row.title}: ${row.status ?? ''}. ${row.tap}`} style={({ pressed }) => [look, pressed ? s.pressed : null]}>{body}</Pressable>
  ) : (
    <View style={look}>{body}</View>
  )
}

export default function PrivacyScreen() {
  const m = usePrivacy()
  const clearing = m.clearing
  useFidelityReady(true)

  // The Privacy Policy opens in the phone's browser; where it will not, the address is said in the app's own sheet.
  const note = useNote()
  const openPolicy = (title: string) => void m.openPolicy().then((trouble) => { if (trouble) note.show({ title, lines: [trouble] }) })

  return (
    <View style={s.screen}>
      <AppBar title="Privacy & data" fallback="/settings" />
      <View style={s.body}>
        <SectionLabel first>Your data</SectionLabel>
        <Card pad={13} style={s.dataCard}>
          <DataRow glyph="side-btn-2" title="Download my data" line="A copy of everything GeminiSecure holds about you" onPress={m.download.run} busy={m.download.busy} />
          <Divider inset={48} />
          <DataRow glyph="invite-note" title="Data retention" line="How long we keep gate, financial & report records" onPress={() => openPolicy('Data retention')} />
          <Divider inset={48} />
          <DataRow glyph="danger-icon" title="Clear data saved on this phone" line="Removes the saved copies. You stay signed in" onPress={clearing.ask} />
        </Card>

        <SectionLabel>Your consents</SectionLabel>
        <Card pad={13} style={s.card}>
          {m.consents.map((row, i) => (
            <Consent key={row.key} row={row} last={i === m.consents.length - 1} onPress={() => m.openPermission(row.key)} />
          ))}
        </Card>

        <SectionLabel>Account</SectionLabel>
        <Card pad={13} style={s.card}>
          <Pressable onPress={() => router.push('/settings/delete-account' as never)} role="button" aria-label={`${DELETE}. ${DELETE_LINE}`} style={({ pressed }) => [s.danger, pressed ? s.pressed : null]}>
            <IconTile name="danger-icon" tile={36} glyph={17} tint={fill.goldTint} color={ink.onGoldTint} />
            <View style={s.dangerText}>
              <Text {...font.inter700_12_5} color={ink.heading}>{DELETE}</Text>
              <View style={s.line}><Text {...font.inter400_10_5} color={ink.secondary}>{DELETE_LINE}</Text></View>
            </View>
            <Icon name="chevron-right" size={icon[16]} color={ink.faint} />
          </Pressable>
        </Card>

        <Pressable onPress={() => openPolicy('Privacy Policy')} role="link" aria-label="Read our full Privacy Policy: opens in your browser" hitSlop={POLICY_REACH} style={({ pressed }) => [s.policy, pressed ? s.pressed : null]}>
          <Icon name="privacy-note" size={icon[14]} color={ink.link} />
          <Text {...font.inter700_12} color={ink.link}>{'Read our full Privacy Policy'}</Text>
        </Pressable>
      </View>

      {/* Asked in a sheet OVER the screen; the row stays where it is. A yes closes it; a failure stays in it. */}
      <Confirm
        open={clearing.asking}
        title={clearing.warning.title}
        lines={clearing.warning.lines}
        problem={clearing.problem}
        confirmLabel="Clear saved data"
        working={clearing.working}
        onConfirm={clearing.confirm}
        onCancel={clearing.dismiss}
      />
      <NoteSheet note={note.note} onClose={note.close} />
      <NoteSheet note={m.download.problem ? { title: 'Download my data', lines: [m.download.problem] } : null} onClose={m.download.clearProblem} />
    </View>
  )
}

const consentRow = { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[10] } as const
const pill = { borderRadius: radius.chip, paddingVertical: space[4], paddingHorizontal: space[9] } as const

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  // .body-pad
  body: { flex: 1, paddingTop: space[18], paddingHorizontal: space[20], paddingBottom: space[20] },
  // .card on this board: 11 above and below, 13 at the sides, 9 under it — Your data's own 4px 14px
  card: { paddingVertical: space[11], marginBottom: space[9] },
  dataCard: { paddingVertical: space[4], paddingHorizontal: space[14], marginBottom: space[9] },
  pressed: { opacity: 0.7 },
  line: { marginTop: space[1] },
  // .priv-row · .priv-txt
  priv: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[11] },
  privText: { flex: 1, minWidth: 0 },
  // The chevron's own 16 square, so nothing in the row moves when the spinner takes its place
  spinner: { width: icon[16], height: icon[16] },
  // .consent-row · .consent-txt · .consent-status.granted / .notgranted
  consent: consentRow,
  consentRule: { ...consentRow, borderBottomWidth: stroke[1], borderBottomColor: line.hairline },
  consentText: { flex: 1, minWidth: 0 },
  granted: { ...pill, backgroundColor: fill.tint },
  notGranted: { ...pill, backgroundColor: fill.goldTint },
  // .danger-row · .danger-txt: the words have no flex of their own — they give way, they do not fill
  danger: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[4] },
  dangerText: { flexShrink: 1 },
  // .policy-link
  policy: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: space[7], paddingTop: space[6], paddingBottom: space[2] },
})
