/**
 * "Your account is deleted" — the last thing the person sees, and the only screen in the app
 * that is reached WITH NO SESSION ON PURPOSE.
 *
 * It sits in the auth stack because by the time it opens there is no token, no cache and no queue
 * left on this phone: the account was deleted, and then everything the app kept here was destroyed,
 * in that order. It carries the reference the server gave, so the person has something to quote,
 * and the two dates (M18H §2.2): until when signing in brings it back, and after when it cannot.
 */

import { StyleSheet, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { Icon, LegalLinks, ResidentButton, Text, c, useFidelityReady } from '@gs/core'
import { dayMonth } from '../restore'
import { AuthShell, AuthTitle } from '../components/AuthShell'

const DAY = /^\d{4}-\d{2}-\d{2}$/

export default function DeletedScreen() {
  const { reference, until, permanent } = useLocalSearchParams<{ reference?: string; until?: string; permanent?: string }>()
  useFidelityReady(true)
  const dated = typeof until === 'string' && DAY.test(until) && typeof permanent === 'string' && DAY.test(permanent)

  return (
    <AuthShell canGoBack={false}>
      <View style={s.tick}><Icon name="check" size={30} color="navy-900" /></View>
      <AuthTitle title="Your account is deleted" sub="You are signed out on this phone, and everything the app kept here is gone." />

      {dated ? (
        <View style={s.dates}>
          <Text face="Inter-Bold" size={13.5} lh={1.5} color="navy-900">{`Changed your mind? Sign in with the same email before ${dayMonth(until)} and you can restore it: nothing will be lost.`}</Text>
          <Text face="Inter-Regular" size={12.5} lh={1.5} color="navy-800" style={{ marginTop: 6 }}>
            {`After that, only the estate office can restore it, until ${dayMonth(permanent)}. After ${dayMonth(permanent)} it cannot be undone. We have emailed you these dates.`}
          </Text>
        </View>
      ) : null}

      {reference ? (
        <View style={s.refBox}>
          <Text face="Inter-Bold" size={10.5} color="slate-600" align="center">{'YOUR REFERENCE'}</Text>
          <Text face="IBMPlexMono-Medium" size={18} ls={1.5} color="navy-900" align="center" style={{ marginTop: 6 }}>{reference}</Text>
          <Text face="Inter-Regular" size={10.5} lh={1.45} color="slate-500" align="center" style={{ marginTop: 6 }}>
            {'Keep it if you ever need to ask us about this.'}
          </Text>
        </View>
      ) : null}

      <Text face="Inter-Regular" size={12} lh={1.55} color="slate-600" style={{ marginTop: 18 }}>
        {"What your home owes, and the community's records of the gate and of your home's charges, stay with the home."}
      </Text>

      <ResidentButton label="Done" onPress={() => router.replace('/(auth)/log-in' as never)} style={{ marginTop: 22 }} />

      <LegalLinks pages={['privacy']} tone="light" style={{ marginTop: 22 }} />
    </AuthShell>
  )
}

const s = StyleSheet.create({
  tick: { width: 64, height: 64, alignSelf: 'center', alignItems: 'center', justifyContent: 'center', borderRadius: 32, backgroundColor: c('navy-100'), marginBottom: 14 },
  dates: { backgroundColor: c('navy-100'), borderRadius: 16, paddingVertical: 14, paddingHorizontal: 16, marginTop: 20 },
  refBox: { backgroundColor: c('white'), borderRadius: 16, borderWidth: 1, borderColor: c('navy-200'), paddingVertical: 16, paddingHorizontal: 18, marginTop: 14 },
})
