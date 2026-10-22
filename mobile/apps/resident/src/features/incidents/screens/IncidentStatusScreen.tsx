/**
 * resident-app-27 · Incident #IR-0337 — one security report's status, rebuilt from the client's board, every value from
 * the design's tokens and shared parts. All behaviour lives in useIncident(); this file only draws it, the same way for
 * a phone and for the board's own content.
 *
 * THE BOARD, TOP TO BOTTOM: the slim app bar, "Incident #…" · the summary card — the warning tile, the type as a tag
 * ("Suspicious activity"), the title, "Reported Sep 4, 9:12 AM" · "Status" and the four stages · "Updates", the office's
 * entries with their times · the shield and the privacy line. Every label and line is drawn for every report; only
 * data the server has not given is left out (incidents.ts boardSteps, boardUpdates).
 *
 * A report is a `security` ticket in the estate office's queue. The board's "Received by security", "Under
 * investigation" and its privacy line are drawn as the board writes them; what of them is not so is the lead's call.
 *
 * NOT ON THE BOARD, AND KEPT TO THE LEAST THAT IS HONEST: "Is it fixed?" (FixedAnswer) once the office marks the report
 * resolved — the client's M12 §2.15 asks it of a security report as of a request; a report closed without action ends
 * its timeline at "Closed without action"; before there is a report to show, a spinner or the kit's words.
 *
 * With the board's content the page fits a 390 × 844 phone and nothing moves. Only real data longer than the board's
 * (many updates, "Is it fixed?") lets it scroll, so nothing is ever out of reach; a pull fetches the report again.
 */

import { ActivityIndicator, RefreshControl, ScrollView, StyleSheet, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, Icon, Text, aboveGestureBar, c, useFidelityReady } from '@gs/core'
import { AppBar, Badge, Card, IconTile, SectionLabel, Timeline, type TimelineStep } from '@/design/parts'
import { fill, font, icon, ink, line, radius, role, size, space, stroke } from '@/design/tokens'
import { FixedAnswer } from '@/features/maintenance/components/FixedAnswer'
import { NO_UPDATES, type BoardStep } from '../incidents'
import { useIncident, type IncidentModel } from '../useIncidents'

const PRIVACY = 'Security and the committee can see this report and your identity. It stays private from other residents.'

/** The board's three dots: done navy with a tick, now gold with its mark, not yet a white ring. Closed is where it stopped. */
const DOT: Record<BoardStep['state'], TimelineStep['state']> = { done: 'done', current: 'active', stopped: 'active', todo: 'pending' }
const SAID: Record<BoardStep['state'], string> = { done: 'done', current: 'where it is now', stopped: 'stopped here', todo: 'not yet' }

/* ---- the board's parts --------------------------------------------------------------------------------- */

/** `.status-summary` — the warning tile; the type, the title and when it was reported. */
function Summary({ m }: { m: IncidentModel }) {
  return (
    <Card>
      <View style={s.summary}>
        <IconTile name="qa-tile-3" tile={44} glyph={20} shape="tile" />
        <View style={s.summaryText}>
          {m.tag ? <View style={s.tag}><Badge label={m.tag} /></View> : null}
          <Text {...role.cardTitle} color={ink.heading}>{m.title}</Text>
          <View style={s.meta}><Text {...font.inter600_10} color={ink.faint}>{m.reportedLine}</Text></View>
        </View>
      </View>
    </Card>
  )
}

/** `.tl2` — the four stages in the shared timeline, read to a screen reader as one list of what each stage is. */
function Status({ steps }: { steps: BoardStep[] }) {
  const spoken = steps.map((step) => [step.title, SAID[step.state], step.line].filter(Boolean).join(', ')).join('. ')
  return (
    <Card>
      <View accessible aria-label={`Status. ${spoken}`}>
        <Timeline steps={steps.map((step) => ({ title: step.title, line: step.line, state: DOT[step.state], activeIcon: step.state === 'stopped' ? 'act-result' : 'cp-dot' }))} />
      </View>
    </Card>
  )
}

/** `.update-row` — the dot, the office's words and the time; a rule between rows. */
function Updates({ updates }: { updates: IncidentModel['updates'] }) {
  // Never an empty white box under the heading (M57 §8): two plain lines until the office writes something.
  if (updates.length === 0) {
    return (
      <Card>
        <View role="status">
          {NO_UPDATES.map((line) => <Text key={line} {...font.inter400_12_lh1_5} color={ink.body}>{line}</Text>)}
        </View>
      </Card>
    )
  }
  return (
    <Card>
      <View role="list" aria-label="Updates">
        {updates.map((update, i) => (
          <View key={update.key} style={i === updates.length - 1 ? s.update : [s.update, s.updateRule]} role="listitem">
            <View style={s.updateDot} />
            <View style={s.updateText}>
              <Text {...font.inter600_12_lh1_5} color={ink.heading}>{update.text}</Text>
              <View style={s.updateWhen}><Text {...font.inter600_10} color={ink.faint}>{update.when}</Text></View>
            </View>
          </View>
        ))}
      </View>
    </Card>
  )
}

/** `.privacy-note` — the shield and the board's line. */
function Privacy() {
  return (
    <View style={s.privacy}>
      <Icon name="invite-note" size={icon[15]} color={ink.secondary} style={s.privacyIcon} />
      <View style={s.privacyText}><Text {...font.inter400_10_5_lh1_5} color={ink.secondary}>{PRIVACY}</Text></View>
    </View>
  )
}

/** Before there is a report to show: the least that does not leave the screen blank. */
function NotShown({ m, id }: { m: IncidentModel; id: string | undefined }) {
  if (m.status === 'loading') return <View style={s.loading}><ActivityIndicator color={fill.primary} /></View>
  if (m.status === 'error') return <ErrorView tone="light" message={m.errorMessage ?? 'Your report could not be loaded.'} onRetry={m.refresh} />
  if (m.status === 'denied') return <EmptyState tone="light" icon="privacy-note" title="Not available for this account" body={m.errorMessage ?? 'Your account cannot see reports sent to the estate office.'} />
  return m.elsewhere && id ? (
    <EmptyState tone="light" icon="rp-icon" title="That's a maintenance request" body="It is kept with your maintenance requests, not your reports." action={{ label: 'Open it there', onPress: () => router.replace(`/maintenance/${id}` as never) }} />
  ) : (
    <EmptyState tone="light" icon="qa-tile-3" title="We can't find that report" body="It is not among your household's reports on this phone." action={{ label: 'Back to your reports', onPress: () => router.replace('/incident/new' as never) }} />
  )
}

/* ---- the screen ---------------------------------------------------------------------------------------- */

export default function IncidentStatusScreen() {
  const params = useLocalSearchParams<{ id?: string }>()
  const id = typeof params.id === 'string' ? params.id : undefined
  const m = useIncident(id)
  const insets = useSafeAreaInsets()
  useFidelityReady(true)

  return (
    <View style={s.screen}>
      <AppBar title={m.heading} fallback="/incident/new" />
      {m.incident ? (
        <ScrollView
          style={s.fill}
          contentContainerStyle={[s.body, { paddingBottom: aboveGestureBar(space[20], insets.bottom) }]}
          showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c(ink.action)} />}
        >
          <Summary m={m} />
          <SectionLabel gap="wide">Status</SectionLabel>
          <Status steps={m.steps} />
          {m.fixed ? <FixedAnswer fixed={m.fixed} what="report" /> : null}
          <SectionLabel gap="wide">Updates</SectionLabel>
          <Updates updates={m.updates} />
          <Privacy />
        </ScrollView>
      ) : (
        <NotShown m={m} id={id} />
      )}
    </View>
  )
}

const s = StyleSheet.create({
  // .screen — white
  screen: { flex: 1, backgroundColor: fill.surface },
  fill: { flex: 1 },
  // .body-pad — 18 above, 20 at the sides and below (or the home indicator's strip, where that is deeper)
  body: { paddingTop: space[18], paddingHorizontal: space[20] },
  loading: { paddingTop: space[24], alignItems: 'center' },

  // .status-summary · .status-txt; the tag is inline-block in a 16-point line, so it sits 4 down, and 5 above the
  // title (its margin-bottom); .st3 5 below the title
  summary: { flexDirection: 'row', alignItems: 'flex-start', gap: space[12] },
  summaryText: { flex: 1, minWidth: 0 },
  tag: { marginTop: space[4], marginBottom: space[5] },
  meta: { marginTop: space[5] },

  // .update-row — 10 above and below, 11 between the dot and the words, a navy-100 rule under all but the last
  update: { flexDirection: 'row', gap: space[11], paddingVertical: space[10] },
  updateRule: { borderBottomWidth: stroke[1], borderBottomColor: line.hairline },
  updateDot: { width: size[7], height: size[7], borderRadius: radius.avatar(size[7]), flexShrink: 0, marginTop: space[5], backgroundColor: fill.dot },
  updateText: { flex: 1, minWidth: 0 },
  updateWhen: { marginTop: space[3] },

  // .privacy-note — 14 below the card (its 12 and 2 more: a browser folds the two margins into one), 2 in at each side;
  // the shield 1 down
  privacy: { flexDirection: 'row', gap: space[9], marginTop: space[2], paddingHorizontal: space[2] },
  privacyIcon: { marginTop: space[1] },
  privacyText: { flex: 1, minWidth: 0 },
})
