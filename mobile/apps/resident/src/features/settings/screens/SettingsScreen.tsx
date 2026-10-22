/**
 * resident-app-18 · Settings — rebuilt from the board (M42 round 2), every value from the design tokens and parts.
 *
 * The board, top to bottom: Account (Security · Notifications · Payments & AutoPay) · Privacy (Privacy & data) ·
 * Preferences (Language & accessibility) · Support (Help & support · About GeminiSecure) · the Sign out card. Every row
 * is the shared ListRow (.set-row) with the board's own sub-line, word for word; the rule between two rows is the
 * board's, 46 in.
 *
 * Each row opens its screen. About GeminiSecure's line is this phone's real version in the board's words ("Version
 * 2.4.1"); there is no About screen, so its tap says which app and build this is — in the app's own sheet, never the
 * phone's grey dialog (M50 §4.3). Sign out asks first, in the kit's sheet over the screen, counting what has not been
 * sent yet (useSignOut).
 *
 * GOVERNANCE (the user, 8 Oct 2026): Meetings and Elections, for a Member — a resident who missed the notification had
 * no other way to a meeting or the vote. Not on the board; with it the page can outgrow a phone, so it scrolls, and only
 * when it must.
 */

import { Fragment, useRef } from 'react'
import { ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { Pressable, Text, isFidelity, useFidelityReady, type IconName } from '@gs/core'
import { AppBar, Card, ListRow, NoteSheet, SectionLabel, useNote } from '@/design/parts'
import { useRequestsEntry } from '@/features/maintenance/useAllRequests'
import { fill, font, ink, size, space } from '@/design/tokens'
import { useAccess } from '@/features/access/useAccess'
import { Confirm } from '../components/SettingsParts'
import { Divider } from '../components/Rows'
import { useSettingsHome } from '../useSettings'

type Row = { icon: IconName; title: string; line: string; onPress: () => void }

const go = (path: string) => () => router.push(path as never)

function Rows({ rows }: { rows: Row[] }) {
  return (
    <Card pad={13} style={s.rows}>
      {rows.map((row, i) => (
        <Fragment key={row.title}>
          {i > 0 ? <Divider /> : null}
          <ListRow icon={row.icon} title={row.title} line={row.line} chevron onPress={row.onPress} label={`${row.title}. ${row.line}`} />
        </Fragment>
      ))}
    </Card>
  )
}

export default function SettingsScreen() {
  const m = useSettingsHome()
  const out = m.signOut
  const signOutRow = useRef<View>(null)
  const note = useNote()
  const { may } = useAccess()
  const requestsEntry = useRequestsEntry()
  useFidelityReady(true)

  // A Member's (`access.may`); the board's own screen in the design-comparison build, which draws no such group.
  const governance: Row[] = isFidelity
    ? []
    : [
        ...(may('meetings') ? [{ icon: 'input' as IconName, title: 'Meetings', line: 'Upcoming and past meetings', onPress: go('/meetings') }] : []),
        ...(may('elections') ? [{ icon: 'tab-card' as IconName, title: 'Elections', line: 'Vote, receipts and results', onPress: go('/elections') }] : []),
      ]

  return (
    <View style={s.screen}>
      <AppBar title="Settings" fallback="/(tabs)/profile" />
      <ScrollView style={s.fill} contentContainerStyle={s.body}>
        <SectionLabel first>Account</SectionLabel>
        <Rows
          rows={[
            { icon: 'privacy-note', title: 'Security', line: 'Biometrics, active sessions', onPress: go('/settings/security') },
            { icon: 'set-icon-2', title: 'Notifications', line: 'Categories, channels, quiet hours', onPress: go('/settings/notifications') },
            { icon: 'pm-icon', title: 'Payments & AutoPay', line: 'Saved cards, AutoPay limit', onPress: go('/dues/autopay') },
          ]}
        />

        {/* Where a household follows what it has sent the estate (M98): maintenance and incident reports, open and past. */}
        {requestsEntry ? (
          <>
            <SectionLabel>Requests</SectionLabel>
            <Rows rows={[{ icon: 'rp-icon' as IconName, title: 'My requests and reports', line: 'Maintenance and incidents, open and past', onPress: go('/requests') }]} />
          </>
        ) : null}

        {governance.length > 0 ? (
          <>
            <SectionLabel>Governance</SectionLabel>
            <Rows rows={governance} />
          </>
        ) : null}

        <SectionLabel>Privacy</SectionLabel>
        <Rows rows={[{ icon: 'invite-note', title: 'Privacy & data', line: 'Download my data, consents', onPress: go('/settings/privacy') }]} />

        <SectionLabel>Preferences</SectionLabel>
        <Rows rows={[{ icon: 'set-icon-3', title: 'Language & accessibility', line: 'Text size, contrast, motion', onPress: go('/settings/language') }]} />

        <SectionLabel>Support</SectionLabel>
        <Rows
          rows={[
            { icon: 'set-icon', title: 'Help & support', line: 'Report a problem, contact us', onPress: go('/settings/help') },
            { icon: 'set-icon-4', title: 'About GeminiSecure', line: m.version, onPress: () => note.show({ title: 'About GeminiSecure', lines: [m.about], closeLabel: 'Done' }) },
          ]}
        />

        {/* Pressed, the card darkens as one piece (M50 §6.7a) — never a fade drawn through it. */}
        <Pressable ref={signOutRow} onPress={out.ask} role="button" aria-label="Sign out">
          {({ pressed }) => (
            <Card pad={13} pressed={pressed}>
              <View style={s.signOut}>
                <Text {...font.inter700_13} color={ink.onTint} align="center">{'Sign out'}</Text>
              </View>
            </Card>
          )}
        </Pressable>
      </ScrollView>

      {/* Asked in a sheet OVER the screen: the card stays where it is, and the question can never open under the fold. */}
      <Confirm
        open={out.asking}
        title="Sign out of this phone?"
        lines={out.warning.lines}
        blocked={out.blocked}
        problem={out.problem}
        confirmLabel={out.warning.confirmLabel}
        working={out.working}
        onConfirm={out.confirm}
        onCancel={out.dismiss}
        returnFocusTo={signOutRow}
      />
      <NoteSheet note={note.note} onClose={note.close} />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  fill: { flex: 1 },
  // .body-pad — grows to the screen, so the page scrolls only when Governance takes it past the phone's height
  body: { flexGrow: 1, paddingTop: space[18], paddingHorizontal: space[20], paddingBottom: space[20] },
  // .card with the board's own padding: 4px 14px around its rows
  rows: { paddingVertical: space[4], paddingHorizontal: space[14] },
  // The Sign out card's line is the page's 16-point line (the board's span sits in a div of the body's size), 19 tall.
  signOut: { minHeight: size[19], justifyContent: 'center' },
})
