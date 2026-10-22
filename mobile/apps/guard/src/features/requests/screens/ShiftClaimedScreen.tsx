/**
 * guard-app-22 · Shift Claimed. Styles are the board's own, copied from the lifted file
 * (src/lifted/GuardApp22.tsx).
 *
 * Reached only after the SERVER accepted a claim (a claim is never queued). The board names an approver
 * and how fast he answers; the server names nobody, so the live note says only what is true: a supervisor
 * decides, and the answer arrives with the next sync.
 */

import { ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { AppBar, Icon, Pressable, Text, a, c, fidelityScreenId, isFidelity, sh, useFidelityReady } from '@gs/core'
import { claimedFixture } from '@/fidelity/requests'
import { takeLastClaim } from '../useOpenShifts'

const done = () => (router.canGoBack() ? router.back() : router.replace('/leave?tab=open' as never))

export default function ShiftClaimedScreen() {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-22'
  const claim = fixture ? null : takeLastClaim()
  useFidelityReady(true)

  const body = fixture ? claimedFixture.body : claim ? `You've asked for the ${claim.title} shift. It appears on your roster once a supervisor approves it.` : 'Your claim was sent. It appears on your roster once a supervisor approves it.'
  const rows: { icon: 'veh-row' | 'input' | 'cc-row'; text: string }[] = fixture
    ? [{ icon: 'veh-row', text: claimedFixture.post }, { icon: 'input', text: claimedFixture.when }, { icon: 'cc-row', text: 'Requires supervisor approval' }]
    : [...(claim ? [{ icon: 'veh-row' as const, text: `${claim.title} · ${claim.site}` }, { icon: 'input' as const, text: claim.when }] : []), { icon: 'cc-row', text: 'Requires supervisor approval' }]

  return (
    <View style={s.screen}>
      <AppBar title="Shift claimed" left={null} />
      <ScrollView contentContainerStyle={s.confirmWrap}>
        <View style={s.checkWrap}>
          <View style={s.cRing} />
          <View style={s.cRing2} />
          <View style={s.checkCircle}><Icon name="check" size={32} color="g-navy-900" style={s.checkCircleSvg} /></View>
        </View>
        <Text face="Poppins-SemiBold" size={20} color="white" role="heading">{'Request sent'}</Text>
        <Text face="Inter-Regular" size={12.5} lh={1.5} color="text-2" align="center" style={{ marginTop: 6 }}>{body}</Text>
        <View style={s.confirmCard}>
          {rows.map((row) => (
            <View key={row.text} style={s.ccRow}>
              <Icon name={row.icon} size={16} color="amber-500" style={s.ccRowSvg} />
              <View style={s.shrink}><Text face="Inter-Medium" size={12} color="text-2">{row.text}</Text></View>
            </View>
          ))}
        </View>
        <View style={s.pendingNote}>
          <Icon name="recap-item" size={15} color="amber-500" style={s.pendingNoteSvg} />
          <View style={s.shrink}>
            <Text face="Inter-Medium" size={10.5} lh={1.5} color="amber-500">{fixture ? claimedFixture.note : 'Until it is approved it is NOT your shift: do not report for it. The decision shows under Open shifts, and the shift joins your roster if it is approved.'}</Text>
          </View>
        </View>
        <Pressable onPress={done} role="button" style={({ pressed }) => [s.btnPrimary, { opacity: pressed ? 0.9 : 1 }]}>
          <Text face="Poppins-Bold" size={14.5} color="g-navy-900">{'Done'}</Text>
        </Pressable>
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  shrink: { flexShrink: 1 },
  cRing: { position: 'absolute', width: 106, height: 106, opacity: 0.14, borderRadius: 53, borderWidth: 1, borderColor: c('amber-500') },
  cRing2: { position: 'absolute', width: 88, height: 88, opacity: 0.28, borderRadius: 44, borderWidth: 1, borderColor: c('amber-500') },
  checkCircleSvg: { width: 32, height: 32, overflow: 'hidden' },
  checkCircle: { width: 66, height: 66, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), zIndex: 2, borderRadius: 33 },
  checkWrap: { width: 88, height: 88, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', marginBottom: 18 },
  ccRowSvg: { width: 16, height: 16, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  ccRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 6, gap: 11 },
  confirmCard: { width: '100%', backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 15, marginTop: 22, marginBottom: 14 },
  pendingNoteSvg: { width: 15, height: 15, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  pendingNote: { width: '100%', flexDirection: 'row', backgroundColor: a('amber-500', 0.08), borderRadius: 12, paddingHorizontal: 12, paddingVertical: 11, gap: 9, marginBottom: 22 },
  btnPrimary: { width: '100%', height: 52, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), borderRadius: 15, boxShadow: sh([{ x: 0, y: 10, blur: 22, spread: 0, token: 'amber-500', opacity: 0.28 }]) },
  confirmWrap: { flexDirection: 'column', alignItems: 'center', paddingTop: 36, paddingHorizontal: 24, paddingBottom: 28 },
})
