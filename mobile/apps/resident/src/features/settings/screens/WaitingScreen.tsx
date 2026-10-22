/**
 * What is waiting on this phone — Privacy & data → "Waiting to be sent" (the client, 26 Sep: "none of these options
 * is clickable", and a maintenance request sat at "Pending sync" behind a photo that would not send).
 *
 * No board draws it; it is built from the settings boards' own card and row. It lists every act this phone has
 * saved and the estate does not have yet — in the order they will be sent — with where each stands in the words
 * the rest of the app uses (Pending sync · Syncing · Not accepted), when it was saved, and what the last try said.
 * "Try sending now" asks straight away, whatever the backoff says. An act the estate answered NO to can be taken off
 * the list once read; nothing still waiting can be deleted from here, because deleting it would lose it.
 */

import { ScrollView, StyleSheet, View } from 'react-native'
import { ResidentAppBar, ResidentButton, Text, c, now, sh, sinceWords, useFidelityReady } from '@gs/core'
import { OUTBOX_LABEL } from '@gs/core/offline'
import { drainNow, outbox } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { Caption, Heading, Note } from '../components/SettingsParts'

// A refusal is an ordinary problem: amber (M98, 12 Oct 2026). Red is an open panic's colour and nothing else.
const TONE = { pending: 'amber-700', syncing: 'amber-700', refused: 'amber-700', confirmed: 'green-700' } as const

export default function WaitingScreen() {
  useFidelityReady(true)
  const online = useAppSelector((s) => s.network.online)
  const ops = useAppSelector((s) => s.outbox.ops)
  const waiting = ops.filter((op) => op.state === 'pending' || op.state === 'syncing')
  const refused = ops.filter((op) => op.state === 'refused')

  return (
    <View style={s.screen}>
      <ResidentAppBar title="Waiting to be sent" left="back" fallback="/settings/privacy" />
      <ScrollView style={s.fill} contentContainerStyle={s.bodyPad}>
        {waiting.length === 0 && refused.length === 0 ? (
          <Note text="Nothing is waiting. The estate has everything you have done on this phone." top={2} />
        ) : null}

        {waiting.length > 0 ? (
          <>
            <Heading top={2}>{`${waiting.length} still to send, in this order`}</Heading>
            <View style={s.card}>
              {waiting.map((op, i) => (
                <View key={op.id} style={i === waiting.length - 1 ? s.rowLast : s.row}>
                  <View style={s.fill}>
                    <Text face="Inter-SemiBold" size={12.5} color="navy-900">{op.label}</Text>
                    <Text face="Inter-Regular" size={10.5} lh={1.45} color="slate-500" style={{ marginTop: 2 }}>
                      {`Saved ${sinceWords(op.capturedAt, now())}`}
                      {op.attempts > 0 ? ` · tried ${op.attempts} ${op.attempts === 1 ? 'time' : 'times'}` : ''}
                    </Text>
                    {op.error?.message ? <Text face="Inter-Regular" size={10.5} lh={1.45} color="slate-600" style={{ marginTop: 2 }}>{`Last try: ${op.error.message}`}</Text> : null}
                  </View>
                  <Text face="Inter-Bold" size={10.5} color={TONE[op.state]}>{OUTBOX_LABEL[op.state]}</Text>
                </View>
              ))}
            </View>
            <ResidentButton
              label={online ? 'Try sending now' : 'No connection: sent when it comes back'}
              disabled={!online}
              disabledReason="This phone has no connection. Everything here is sent by itself the moment it has one."
              onPress={() => void drainNow()}
              style={s.full}
            />
            <Caption>{'Each is kept on this phone until the estate has it, and sent by itself when there is a connection. A photo that will not send waits at the back, so nothing behind it is held up.'}</Caption>
          </>
        ) : null}

        {refused.length > 0 ? (
          <>
            <Heading top={14}>{'Not accepted by the estate'}</Heading>
            <View style={s.card}>
              {refused.map((op, i) => (
                <View key={op.id} style={i === refused.length - 1 ? s.rowLast : s.row}>
                  <View style={s.fill}>
                    <Text face="Inter-SemiBold" size={12.5} color="navy-900">{op.label}</Text>
                    <Text face="Inter-Regular" size={10.5} lh={1.45} color="amber-700" style={{ marginTop: 2 }}>{op.error?.message ?? 'The estate did not accept this.'}</Text>
                  </View>
                  <ResidentButton variant="outline" label="Remove" onPress={() => void outbox.dismiss(op.id)} style={s.remove} />
                </View>
              ))}
            </View>
            <Caption>{'These reached the estate and were answered no, so they will not be sent again. Removing one takes it off this list only.'}</Caption>
          </>
        ) : null}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('white'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  bodyPad: { paddingTop: 18, paddingBottom: 24, paddingHorizontal: 20 },
  card: { backgroundColor: c('white'), borderRadius: 16, borderWidth: 1, borderColor: c('navy-100'), paddingHorizontal: 14, paddingVertical: 4, boxShadow: sh([{ x: 0, y: 8, blur: 20, spread: 0, token: 'navy-800', opacity: 0.06 }]), marginTop: 6 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 11, borderBottomWidth: 1, borderBottomColor: c('navy-100') },
  rowLast: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 11 },
  full: { alignSelf: 'stretch', marginTop: 14 },
  remove: { minWidth: 88 },
})
