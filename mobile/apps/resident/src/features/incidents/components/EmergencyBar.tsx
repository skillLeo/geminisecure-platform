/**
 * The emergency way out — drawn on both incident screens, in EVERY live state, and never disabled.
 * No board draws it (board 14 has no such panel); it is assembled from the resident boards' own parts:
 * board 27's card radius, board 01's button heights and type. It is not drawn in the fidelity build.
 *
 *   full      the report form: a sentence that says where a report goes, then the two ways to get help now
 *   compact   the status screen and the "saved" panel: one line and the same two controls
 *
 * Both controls are 44 tall or more, always enabled, and depend on nothing but emergency.ts.
 */

import { StyleSheet, View } from 'react-native'
import { Icon, Pressable, Text, c } from '@gs/core'
import { useEmergency } from '../emergency'

export function EmergencyBar({ compact = false }: { compact?: boolean }) {
  const e = useEmergency()
  return (
    <View style={compact ? s.boxCompact : s.box} role="region" aria-label="In an emergency">
      <View style={s.top}>
        <Icon name="shield-alert" size={18} color="red-700" style={s.icon} />
        <View style={s.shrink}>
          <Text face="Inter-Bold" size={12.5} lh={1.4} color="navy-900">{compact ? 'Is someone in danger right now?' : 'In danger, or is a crime happening right now?'}</Text>
          <Text face="Inter-Regular" size={11.5} lh={1.5} color="slate-600" style={{ marginTop: 3 }}>
            {compact ? "Don't wait for the estate office. Send an emergency alert, or call the police." : 'This report goes to the estate office, not to emergency services. For help now, send an emergency alert or call the police.'}
          </Text>
        </View>
      </View>
      <View style={s.actions}>
        <Pressable onPress={e.openAlert} role="button" aria-label="Send an emergency alert" style={s.alertBtn}>
          <Text face="Inter-Bold" size={12.5} color="white">{'Emergency alert'}</Text>
        </Pressable>
        <Pressable onPress={e.call} role="link" aria-label={`Call the police, ${e.number}`} style={s.callBtn}>
          <Icon name="call-btn" size={15} color="red-700" />
          <Text face="Inter-Bold" size={12.5} color="red-700">{`Call ${e.number}`}</Text>
        </Pressable>
      </View>
      {e.callTrouble ? <Text face="Inter-Medium" size={11.5} lh={1.45} color="red-700" style={{ marginTop: 8 }} role="alert">{e.callTrouble}</Text> : null}
    </View>
  )
}

const s = StyleSheet.create({
  shrink: { flexShrink: 1 },
  box: { backgroundColor: c('red-100'), borderRadius: 16, padding: 14, marginBottom: 18 },
  boxCompact: { backgroundColor: c('red-100'), borderRadius: 16, padding: 14, marginTop: 18 },
  top: { flexDirection: 'row', gap: 10 },
  icon: { width: 18, height: 18, flexGrow: 0, flexShrink: 0, marginTop: 1 },
  actions: { flexDirection: 'row', gap: 10, marginTop: 12 },
  alertBtn: { flex: 1, height: 46, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('red-700'), borderRadius: 13 },
  callBtn: { flex: 1, height: 46, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, backgroundColor: c('white'), borderRadius: 13, borderWidth: 1, borderColor: c('red-700') },
})
