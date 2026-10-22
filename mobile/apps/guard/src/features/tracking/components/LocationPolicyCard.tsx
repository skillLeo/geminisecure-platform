/**
 * "Before your shift: how your location is shared" — on the clock-in screen, while the officer has not answered
 * the CURRENT location policy (M11 §3.2). The first shift is where every officer passes, so this is where the
 * policy is put in front of them at enrolment and again whenever its words change. Nothing is decided here: the
 * button opens the consent screen, and answering there is the officer's choice. Clocking in never waits on it.
 *
 * Drawn only when it is true: sharing switched on, and no answer to these words. Never in fidelity mode.
 */

import { StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { GuardButton, Icon, Text, a, isFidelity } from '@gs/core'
import { guardApi } from '@gs/core/api/guard'
import { useAppSelector } from '@/store'
import { consentNeeded } from '../trackingPolicy'

export function LocationPolicyCard() {
  const active = useAppSelector((s) => s.session.status === 'active')
  const status = guardApi.useTrackingStatusQuery(undefined, { skip: isFidelity || !active })
  if (isFidelity || !consentNeeded(status.data)) return null

  return (
    <View style={s.card} role="region" aria-label="Location sharing on duty">
      <Icon name="privacy-note" size={18} color="amber-500" style={{ marginTop: 1 }} />
      <View style={{ flexShrink: 1 }}>
        <Text face="Inter-Bold" size={12.5} color="amber-500">{'Before your shift: how your location is shared'}</Text>
        <Text face="Inter-Regular" size={11} lh={1.5} color="text-2" style={{ marginTop: 3 }}>
          {'While you are on duty the control room can see where you are. Read what is recorded and who sees it, then give your answer. Saying no does not stop you working.'}
        </Text>
        <GuardButton variant="outline" label="Read the location policy" onPress={() => router.push('/tracking-consent' as never)} style={{ marginTop: 10 }} />
      </View>
    </View>
  )
}

const s = StyleSheet.create({
  card: { flexDirection: 'row', gap: 10, borderRadius: 14, borderWidth: 1, padding: 13, marginTop: 14, backgroundColor: a('amber-500', 0.1), borderColor: a('amber-500', 0.32) },
})
