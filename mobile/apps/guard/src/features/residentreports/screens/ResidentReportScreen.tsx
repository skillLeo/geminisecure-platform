/**
 * What a resident says is happening NOW at this site — where the banner and the server's push
 * lead. NO BOARD DRAWS THIS SCREEN: it is built from board 23's card and rows, board 39's notice card and
 * board 08's location row, and is recorded as a decision.
 *
 * READ ONLY, AND IT SAYS SO. "Notify, but do not reassign" — the report belongs to the estate office with
 * `source = resident`. There is no control here to take it, answer it or close it, because there is no
 * endpoint that would: what the officer does about it is what their standing orders say, on the radio.
 *
 * THE STATES. The report is normally already on the phone — `sync.pull` brought it with the banner — and
 * then it is drawn at once. When it is not (the push woke a CLOSED app, and this handset has never pulled
 * it) the screen says it is looking, pulls once, and then either shows it or says plainly that it is not
 * there: older than the twelve hours the gatehouse is told about, or in while this handset was off duty.
 * With no signal it says THAT instead, and offers to look again — it never pretends and never retries in
 * a loop. There is no permission state: this screen reads the phone, not an endpoint.
 *
 * WHAT IT NEVER SHOWS. A household or a name — the server sends neither. A place, and what the resident
 * wrote, and nothing else.
 */

import { ScrollView, StyleSheet, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { AppBar, EmptyState, Icon, SkeletonLines, Text, a, c, useFidelityReady } from '@gs/core'
import { dateShort, time12 } from '@gs/core/lib'
import { useResidentReport } from '../useResidentReport'

function Row({ icon, label, value }: { icon: 'loc-row' | 'rp-icon'; label: string; value: string }) {
  return (
    <View style={s.row}>
      <View style={s.rowIcon}>
        <Icon name={icon} size={16} color="text-2" />
      </View>
      <View style={s.shrink}>
        <Text face="Inter-SemiBold" size={10} ls={0.3} color="text-3" upper>{label}</Text>
        <Text face="Inter-Regular" size={12.5} lh={1.45} color="white" style={{ marginTop: 2 }}>{value}</Text>
      </View>
    </View>
  )
}

export default function ResidentReportScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const state = useResidentReport(id)
  useFidelityReady(state.status !== 'looking')

  const home = () => (router.canGoBack() ? router.back() : router.replace('/(tabs)'))

  if (state.status !== 'ready') {
    return (
      <View style={s.screen}>
        <AppBar title="Resident report" />
        {state.status === 'looking' ? (
          <SkeletonLines tone="dark" lines={6} style={s.body} />
        ) : state.status === 'no_signal' ? (
          <EmptyState
            tone="dark"
            icon="offline-note"
            title="No signal to fetch this report"
            body="What the resident wrote is not on this phone yet, and it cannot be fetched without a connection. Move to where you have signal and open this again. If it will not wait, call the control room."
            action={{ label: 'Back to home', onPress: home }}
          />
        ) : (
          <EmptyState
            tone="dark"
            icon="qa-tile-3"
            title="This report is not on this phone"
            body="The gatehouse is told about a resident's report for twelve hours, and this one is older than that, or it came in while this handset was off duty. The estate office still has it. If it matters now, call the control room."
            action={{ label: 'Back to home', onPress: home }}
          />
        )}
      </View>
    )
  }

  const report = state.report
  const where = report.location || 'Not given'
  const at = `${dateShort(report.reportedAt)}, ${time12(report.reportedAt)}`
  const pin = report.latitude !== null && report.longitude !== null
    ? `${report.latitude.toFixed(5)}, ${report.longitude.toFixed(5)}${report.accuracyM === null ? '' : ` · to about ${report.accuracyM} m`}`
    : null

  return (
    <View style={s.screen}>
      <AppBar title="Resident report" />
      <ScrollView contentContainerStyle={s.body}>
        <View style={s.banner} role="status">
          <Icon name="shield-alert" size={19} color="amber-500" style={{ marginTop: 1 }} />
          <View style={s.shrink}>
            <Text face="Inter-Bold" size={12.5} color="amber-500">A resident says this is happening now</Text>
            <Text face="Inter-Regular" size={11} lh={1.5} color="text-2" style={{ marginTop: 3 }}>
              You are told because you are on duty at this site. Respond as your standing orders say, and tell the control room what you find.
            </Text>
          </View>
        </View>

        <View style={s.card}>
          <Text face="Inter-Bold" size={14} lh={1.35} color="white" role="heading">{report.title}</Text>
          {report.number ? <Text face="Inter-SemiBold" size={10.5} color="text-3" style={{ marginTop: 3 }}>{`Request #${report.number}`}</Text> : null}
          {report.detail ? (
            <Text face="Inter-Regular" size={12.5} lh={1.55} color="text-2" style={{ marginTop: 10 }}>{report.detail}</Text>
          ) : (
            <Text face="Inter-Regular" size={12} lh={1.5} color="text-3" style={{ marginTop: 10 }}>The resident wrote nothing more than the line above.</Text>
          )}
        </View>

        <View style={s.card}>
          <Row icon="loc-row" label="Where" value={where} />
          {pin ? <Row icon="loc-row" label="The pin they attached" value={pin} /> : null}
          <Row icon="rp-icon" label="Reported" value={at} />
        </View>

        <Text face="Inter-Regular" size={11} lh={1.5} color="text-3" style={s.note}>
          This report belongs to the estate office. It cannot be taken, answered or closed from this phone, and nothing you do here changes it.
        </Text>
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  body: { paddingTop: 16, paddingBottom: 28, paddingHorizontal: 20 },
  shrink: { flexShrink: 1, flex: 1 },
  banner: { flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 14, borderWidth: 1, borderColor: a('amber-500', 0.32), paddingHorizontal: 13, paddingVertical: 12, gap: 10 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 15, paddingVertical: 14, marginTop: 12 },
  row: { flexDirection: 'row', gap: 12, paddingVertical: 7 },
  rowIcon: { width: 34, height: 34, flexGrow: 0, flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 11 },
  note: { marginTop: 14, marginHorizontal: 2 },
})
