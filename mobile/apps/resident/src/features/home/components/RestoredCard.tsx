/**
 * "Your account is back" — said once, on the first Home after "Restore my account" (M18H §2.3; `auth.restore`'s
 * `restored`). Never under the fidelity harness: the boards have no such card.
 */

import { useState } from 'react'
import { View } from 'react-native'
import { ResidentButton, Text, c, isFidelity } from '@gs/core'
import { RESTORED, restoredNotice } from '@/features/auth/restore'

export function RestoredCard() {
  const [show, setShow] = useState(() => !isFidelity && restoredNotice.take())
  if (!show) return null

  return (
    <View role="status" style={{ backgroundColor: c('white'), borderRadius: 16, borderWidth: 1.5, borderColor: c('green-700'), paddingTop: 14, paddingHorizontal: 15, paddingBottom: 6 }}>
      <Text face="Poppins-SemiBold" size={14.5} color="navy-900">{'Welcome back'}</Text>
      <Text face="Inter-Regular" size={12.5} lh={1.5} color="navy-800" style={{ marginTop: 4 }}>{RESTORED}</Text>
      <View style={{ alignItems: 'center' }}>
        <ResidentButton variant="tertiary" label="Got it" onPress={() => setShow(false)} />
      </View>
    </View>
  )
}
