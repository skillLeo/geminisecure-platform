/**
 * The one card that asks an older record for the phone number or email it lacks (M18C §5) — see ../missingDetails.ts.
 * Never drawn under the fidelity harness (the boards have no such card), never in the way of anything else on Home.
 */

import { useState } from 'react'
import { View } from 'react-native'
import { router } from 'expo-router'
import { ResidentButton, Text, c, isFidelity } from '@gs/core'
import { residentApi } from '@gs/core/api/resident'
import { useAppSelector } from '@/store'
import { dismissMissingDetails, missingDetailsDismissed, missingDetailsPrompt } from '../missingDetails'

export function MissingDetailsCard() {
  const me = residentApi.useMeShowQuery(undefined, { skip: isFidelity })
  const who = useAppSelector((s) => s.session.principal?.id ?? null)
  const [gone, setGone] = useState(() => missingDetailsDismissed(who))
  const prompt = isFidelity || gone || missingDetailsDismissed(who) ? null : missingDetailsPrompt(me.data?.missing_details)
  if (!prompt) return null

  return (
    <View role="alert" style={{ backgroundColor: c('white'), borderRadius: 16, borderWidth: 1.5, borderColor: c('navy-600'), paddingTop: 14, paddingHorizontal: 15, paddingBottom: 6 }}>
      <Text face="Poppins-SemiBold" size={14.5} color="navy-900">{prompt.title}</Text>
      <Text face="Inter-Regular" size={12.5} lh={1.5} color="navy-800" style={{ marginTop: 4 }}>{prompt.body}</Text>
      <ResidentButton label={prompt.action} onPress={() => router.push('/household/details' as never)} style={{ marginTop: 12 }} />
      <View style={{ alignItems: 'center' }}>
        <ResidentButton
          variant="tertiary"
          label="Not now"
          onPress={() => {
            dismissMissingDetails(who)
            setGone(true)
          }}
        />
      </View>
    </View>
  )
}
