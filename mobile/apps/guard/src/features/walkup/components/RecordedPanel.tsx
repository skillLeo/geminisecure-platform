/**
 * What was recorded, and where it stands: Pending sync · Syncing · Confirmed · Not accepted (with
 * the server's sentence). Shown after the guard admits or denies a walk-up visitor. No board draws
 * it; it is built from board 06's ring and type. A record is never edited or withdrawn from here.
 */

import { StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { GuardButton, Icon, Text, a, c, type ColorToken } from '@gs/core'
import { OUTBOX_LABEL } from '@gs/core/offline'
import type { Recorded } from '../useGateRecord'
import { StatusRing } from './StatusRing'

const STATE_TONE: Record<Recorded['state'], ColorToken> = { pending: 'amber-500', syncing: 'amber-500', confirmed: 'green-400', refused: 'red-500' }

export function RecordedPanel({ recorded, onDone }: { recorded: Recorded; onDone: () => void }) {
  const refused = recorded.state === 'refused'
  const tone: ColorToken = refused ? 'red-500' : recorded.kind === 'admitted' ? 'green-400' : 'red-500'
  const stateTone = STATE_TONE[recorded.state]

  return (
    <View style={s.wrap} role="status">
      <StatusRing tone={tone} offset={0}>
        <Icon name={refused ? 'qa-tile-3' : recorded.kind === 'admitted' ? 'check' : 'act-result'} size={40} color={tone} />
      </StatusRing>
      <Text face="Poppins-SemiBold" size={18} color="white" align="center" role="heading">
        {refused ? 'Not accepted by the server' : recorded.kind === 'admitted' ? 'Entry recorded' : 'Denial recorded'}
      </Text>
      <Text face="Inter-Medium" size={12} lh={1.5} color="text-2" align="center" style={{ marginTop: 6 }}>{recorded.subject}</Text>

      <View style={[s.pill, { backgroundColor: a(stateTone, 0.14) }]}>
        <Text face="Inter-Bold" size={10.5} color={stateTone}>{OUTBOX_LABEL[recorded.state]}</Text>
      </View>
      <Text face="Inter-Regular" size={12} lh={1.5} color={refused ? 'red-500' : 'text-2'} align="center" style={{ marginTop: 10 }} role={refused ? 'alert' : undefined}>
        {refused
          ? `${recorded.refusal ?? 'The server did not accept this.'} Nothing was written to the gate log. The attempt stays in the sync queue as a record.`
          : recorded.state === 'confirmed' ? 'Saved to the gate log.'
          : recorded.state === 'syncing' ? 'Saved on this phone: being sent to the gate log now.'
          : 'Saved on this phone. It is sent to the gate log by itself when signal returns.'}
      </Text>

      <GuardButton label="Done" onPress={onDone} style={s.full} />
      <GuardButton variant="outline" label={refused ? 'Open sync queue' : "Today's activity"} onPress={() => router.push((refused ? '/sync-queue' : '/gate-activity') as never)} style={s.fullTight} />
    </View>
  )
}

const s = StyleSheet.create({
  // The whole width (M22, found on the emulator): in the waiting screen's centred column the panel shrank to its
  // widest line, and the buttons' 100% was taken of that — "Done" pushed to the right edge, "Today's activity" out of its box.
  wrap: { width: '100%', flexDirection: 'column', alignItems: 'center', paddingTop: 24, paddingHorizontal: 4 },
  pill: { paddingHorizontal: 12, paddingVertical: 5, borderRadius: 20, marginTop: 14 },
  full: { width: '100%', marginTop: 24 },
  fullTight: { width: '100%', marginTop: 10, borderColor: c('border-600') },
})
