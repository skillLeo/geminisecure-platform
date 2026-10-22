/**
 * An alert at this site, for the officer who may respond to it — where the `site_alert` push and banner lead
 * (M10 §1.3). NO BOARD DRAWS THIS SCREEN: it is built from the Duress board's timeline (guard-app-13) and
 * the resident-report screen's card and rows, and is recorded as a decision.
 *
 *   DRILL      first, before anything else, when the server's own answer says the alert is a drill.
 *   WHAT       the kind, in the push's own words, and WHERE — a lot, never a person. Who is responding is an
 *              officer's name, or "you".
 *   HOW FAR    the response so far: a step ticked only when its time is recorded, "Not recorded" when a
 *              later one is and it is not.
 *   BUTTONS    "En route" and "On scene", each drawn ONLY when the server says this handset may record it
 *              (`can_en_route` / `can_on_scene`). Otherwise the server's `reason`, as given. A tap is saved
 *              on the phone first and sent — with no signal, the moment signal returns — and what happened
 *              to it is said under the buttons, a refusal included.
 *   OLD        with no signal, the last answer with its age, and that it may be out of date.
 */

import { ScrollView, StyleSheet, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { AppBar, EmptyState, Icon, Pressable, SkeletonLines, Text, a, c, useFidelityReady } from '@gs/core'
import { DRILL_LABEL, DRILL_NOTE } from '@gs/core/safety'
import { useSiteAlert } from '../useSiteAlert'

export default function SiteAlertScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const m = useSiteAlert(id)
  useFidelityReady(m.status !== 'looking')

  const home = () => (router.canGoBack() ? router.back() : router.replace('/(tabs)'))

  if (m.status !== 'ready') {
    return (
      <View style={s.screen}>
        <AppBar title="Alert at your site" />
        {m.status === 'looking' ? (
          <SkeletonLines tone="dark" lines={6} style={s.body} />
        ) : m.status === 'no_signal' ? (
          <EmptyState
            tone="dark"
            icon="offline-note"
            title="No signal to fetch this alert"
            body="This alert is not on this phone yet, and it cannot be fetched without a connection. Radio dispatch now, and open this again where you have signal."
            action={{ label: 'Back to home', onPress: home }}
          />
        ) : m.status === 'refused' ? (
          <EmptyState
            tone="dark"
            icon="qa-tile-3"
            title={m.own ? 'This is your own alert' : 'This handset cannot follow this alert'}
            body={m.sentence}
            action={m.own ? { label: 'Open my duress alert', onPress: () => router.replace('/duress') } : { label: 'Back to home', onPress: home }}
          />
        ) : (
          <EmptyState tone="dark" icon="qa-tile-3" title="This alert cannot be opened" body="The notification did not say which alert it was. Radio dispatch if it matters now." action={{ label: 'Back to home', onPress: home }} />
        )}
      </View>
    )
  }

  return (
    <View style={s.screen}>
      <AppBar title="Alert at your site" />
      <ScrollView contentContainerStyle={s.body}>
        {m.drill ? (
          <View style={s.drill} role="alert">
            <Text face="Inter-Bold" size={15} ls={1.2} color="amber-500">{DRILL_LABEL}</Text>
            <Text face="Inter-Regular" size={11.5} lh={1.5} color="text-2" style={{ marginTop: 3 }}>{`${DRILL_NOTE} Treat it as your standing orders say for a drill.`}</Text>
          </View>
        ) : null}

        <View style={[s.card, m.drill ? null : { marginTop: 0 }]}>
          <View style={s.headRow}>
            <Icon name="shield-alert" size={20} color={m.closed ? 'text-3' : 'amber-500'} style={{ marginTop: 1 }} />
            <View style={s.shrink}>
              <Text face="Inter-Bold" size={15} lh={1.3} color="white" role="heading">{m.drill ? `${DRILL_LABEL}: ${m.title}` : m.title}</Text>
              <Text face="Inter-SemiBold" size={12.5} lh={1.4} color="amber-500" style={{ marginTop: 3 }}>{m.where}</Text>
              {m.responder ? <Text face="Inter-Regular" size={11.5} lh={1.45} color="text-2" style={{ marginTop: 5 }}>{m.responder}</Text> : null}
            </View>
          </View>
        </View>

        {m.stale ? (
          <View style={s.stale} role="status">
            <Icon name="offline-note" size={15} color="text-2" style={{ marginTop: 1 }} />
            <View style={s.shrink}><Text face="Inter-Regular" size={11} lh={1.5} color="text-2">{m.stale}</Text></View>
          </View>
        ) : null}

        <View style={s.timeline}>
          {m.steps.map((step, i) => (
            <View key={step.key} style={i === m.steps.length - 1 ? s.tRowLast : s.tRow}>
              {i === m.steps.length - 1 ? null : <View style={s.rail} />}
              <View style={step.done ? s.tDot : s.tDotOpen}>{step.done ? <Icon name="check" size={12} color="g-navy-900" /> : null}</View>
              <View style={s.shrink}>
                <Text face="Inter-SemiBold" size={12.5} color="white">{step.title}</Text>
                {step.detail ? <Text face="Inter-Regular" size={10.5} color="text-3" style={{ marginTop: 2 }}>{step.detail}</Text> : null}
              </View>
            </View>
          ))}
        </View>

        {m.enRoute || m.onScene ? (
          <View style={s.buttons}>
            {m.enRoute ? (
              <Pressable onPress={() => m.record('en_route')} disabled={m.busy !== null} role="button" aria-label="En route: record that you are on your way" aria-busy={m.busy === 'en_route'} style={s.primary}>
                <Text face="Inter-Bold" size={14} color="g-navy-900">{m.busy === 'en_route' ? 'Saving…' : 'En route: I am on my way'}</Text>
              </Pressable>
            ) : null}
            {m.onScene ? (
              <Pressable onPress={() => m.record('on_scene')} disabled={m.busy !== null} role="button" aria-label="On scene: record that you are there" aria-busy={m.busy === 'on_scene'} style={m.enRoute ? s.secondary : s.primary}>
                <Text face="Inter-Bold" size={14} color={m.enRoute ? 'amber-500' : 'g-navy-900'}>{m.busy === 'on_scene' ? 'Saving…' : 'On scene: I am there'}</Text>
              </Pressable>
            ) : null}
          </View>
        ) : null}

        {m.reason ? <Text face="Inter-Medium" size={11.5} lh={1.5} color="text-2" style={s.line}>{m.reason}</Text> : null}
        {(['en_route', 'on_scene'] as const).map((step) => {
          const line = m.acts[step]
          return line ? (
            <Text key={step} face="Inter-Medium" size={11.5} lh={1.5} color={line.tone === 'bad' ? 'red-500' : line.tone === 'ok' ? 'green-400' : 'text-2'} style={s.line} role={line.tone === 'bad' ? 'alert' : undefined}>{line.text}</Text>
          ) : null
        })}
        {m.problem ? <Text face="Inter-Medium" size={11.5} lh={1.5} color="red-500" style={s.line} role="alert">{m.problem}</Text> : null}

        <Text face="Inter-Regular" size={11} lh={1.5} color="text-3" style={s.note}>
          {m.closed ? 'This alert is closed. Nothing more can be recorded from this phone.' : 'Dispatch owns this alert and only dispatch closes it. Radio dispatch as your standing orders say: this screen is not a radio.'}
        </Text>
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  body: { paddingTop: 16, paddingBottom: 28, paddingHorizontal: 20 },
  shrink: { flexShrink: 1, flex: 1 },
  // The board's amber notice (the resident-report banner), louder: a drill must not be missed.
  drill: { backgroundColor: a('amber-500', 0.14), borderRadius: 14, borderWidth: 2, borderColor: c('amber-500'), paddingHorizontal: 14, paddingVertical: 12 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 15, paddingVertical: 14, marginTop: 12 },
  headRow: { flexDirection: 'row', gap: 11 },
  stale: { flexDirection: 'row', gap: 9, backgroundColor: c('surface-800'), borderRadius: 12, borderWidth: 1, borderColor: c('border-600'), paddingHorizontal: 12, paddingVertical: 10, marginTop: 12 },
  // The Duress board's timeline.
  rail: { position: 'absolute', top: 22, left: 10, width: 1.5, height: '100%', backgroundColor: c('border-700') },
  tDot: { width: 21, height: 21, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), zIndex: 1, borderRadius: 10.5 },
  tDotOpen: { width: 21, height: 21, flexGrow: 0, flexShrink: 0, zIndex: 1, borderRadius: 10.5, borderWidth: 1, borderColor: c('border-600') },
  tRow: { flexDirection: 'row', paddingBottom: 20, gap: 12 },
  tRowLast: { flexDirection: 'row', gap: 12 },
  timeline: { paddingTop: 18, paddingBottom: 6, paddingHorizontal: 4 },
  buttons: { gap: 10, marginTop: 14 },
  // 52 tall: found with a thumb, at a run.
  primary: { minHeight: 52, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 12, backgroundColor: c('amber-500'), paddingHorizontal: 14 },
  secondary: { minHeight: 52, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1.5, borderColor: c('amber-500'), paddingHorizontal: 14 },
  line: { marginTop: 10, marginHorizontal: 2 },
  note: { marginTop: 16, marginHorizontal: 2 },
})
