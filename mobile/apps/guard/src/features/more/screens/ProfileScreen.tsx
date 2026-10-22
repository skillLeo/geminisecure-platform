/**
 * guard-app-26 · My Profile. Styles are the board's own, copied from the lifted file (src/lifted/GuardApp26.tsx).
 *
 * The board draws the HR file. What the API serves a handset of it (guards.me): who the office says
 * this is — name, service number, site, standing post — and THE GUARD'S OWN LICENCE: its number and the date it
 * runs out, with a plain warning inside 30 days and after. A job title and an employment date are not served,
 * so they are not drawn; where the office holds a licence detail the server does not have, the row says
 * "Held by the office". Nothing is invented to fill a row.
 */

import { Platform, RefreshControl, ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import * as Device from 'expo-device'
import { AppBar, GuardButton, Icon, SkeletonLines, Text, a, c, fidelityScreenId, isFidelity, useFidelityReady, useOfflineCache } from '@gs/core'
import { dateShort } from '@gs/core/lib'
import { profileFixture } from '@/fidelity/more'
import { onboarding } from '@/features/enrolment/onboardingState'
import { useAppSelector } from '@/store'
import { Divider, Heading, ListCard, NavRow } from '../components/Rows'
import { initialsOf } from '../more'
import { NO_UPCOMING } from '../upcoming'
import { useProfile } from '../useProfile'
import { useUpcomingShifts } from '../useUpcomingShifts'

const go = (path: string) => router.push(path as never)

/**
 * Amber either way — red is urgent and life-safety only (M12 §4.4, D-185). The words and the icon say which: "expires
 * in 12 days" is something to arrange, "expired" is a guard who cannot stand a post today, and the title says so.
 */
const warnTone = (_level: 'expiring' | 'expired'): 'amber-500' => 'amber-500'

const IDENTITY: Record<string, { text: string; tone: 'green-400' | 'amber-500' }> = {
  confirmed: { text: '✓ Identity checked by this phone at clock-in', tone: 'green-400' },
  unavailable: { text: 'This phone has no fingerprint or face set up: a supervisor must start each shift with their PIN', tone: 'amber-500' },
  not_confirmed: { text: 'Identity check was not confirmed at setup: clock-in will ask again', tone: 'amber-500' },
}

export default function ProfileScreen() {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-26'
  const principal = useAppSelector((s) => s.session.principal)
  const shift = useOfflineCache<{ shift: { post: { name: string } } | null }>('shift.current', undefined)
  const setup = onboarding.get()
  const m = useProfile()
  const upcoming = useUpcomingShifts(fixture)
  useFidelityReady(true)
  const refresh = () => { m.refresh(); upcoming.refresh() }

  const name = fixture ? profileFixture.name : m.name ?? principal?.name ?? 'Guard'
  const reference = m.serviceNumber ?? principal?.reference ?? null
  const rows: (readonly [string, string])[] = fixture
    ? profileFixture.assignment.map(([k, v]) => [k, v] as const)
    : [
        ['Service number', reference ?? '—'],
        ['Site', m.siteName ?? principal?.siteName ?? setup.site?.name ?? '—'],
        ...(m.postName ? ([['Primary post', m.postName]] as const) : []),
        ["Today's post", shift?.shift?.post.name ?? 'No shift on this phone yet'],
        ...(m.licence?.rows ?? []),
      ]
  const warning = fixture ? null : m.licence?.warning ?? null
  const tag = fixture ? profileFixture.tag : m.licence?.number ? `${m.licence.number} · This handset is enrolled` : `${reference ?? 'Enrolled'} · This handset is enrolled`
  const model = fixture ? profileFixture.device.model : Device.modelName ?? (Platform.OS === 'web' ? 'Web preview' : 'This phone')
  const identity = IDENTITY[setup.identity ?? ''] ?? { text: 'Identity check not set up yet', tone: 'amber-500' as const }
  const deviceLine = fixture ? profileFixture.device.line : `${identity.text}${setup.finishedAt ? ` · set up ${dateShort(setup.finishedAt)}` : ''}`

  return (
    <View style={s.screen}>
      <AppBar title="My profile" />
      <ScrollView contentContainerStyle={s.bodyPad} refreshControl={fixture ? undefined : <RefreshControl refreshing={m.refreshing} onRefresh={refresh} tintColor={c('amber-500')} />}>
        <View style={s.card}>
          <View style={s.profHero}>
            <View style={s.profAvatar}><Text face="Poppins-Bold" size={19} color="white">{fixture ? profileFixture.initials : initialsOf(name)}</Text></View>
            <View style={s.shrink}>
              <Text face="Poppins-SemiBold" size={17} color="white">{name}</Text>
              <Text face="Inter-Regular" size={11} color="text-3" style={{ marginTop: 2 }}>{fixture ? profileFixture.role : 'Security officer'}</Text>
              <View style={s.profTag}><Text face="Inter-Bold" size={9} color="amber-500">{tag}</Text></View>
            </View>
          </View>
        </View>

        {/*
          * Inside 30 days, and after: said in words first, with an icon — never by colour alone. The two
          * are told apart by their title ("expires in 12 days" / "expired"), not by red, which is kept for
          * urgent and life-safety (M12 §4.4).
          */}
        {warning ? (
          <View style={[s.warnRow, { backgroundColor: a(warnTone(warning.level), 0.1), borderColor: a(warnTone(warning.level), 0.32) }]} role="alert">
            <Icon name="qa-tile-3" size={18} color={warnTone(warning.level)} style={{ marginTop: 1 }} />
            <View style={s.shrink}>
              <Text face="Inter-Bold" size={12.5} color={warnTone(warning.level)}>{warning.title}</Text>
              <Text face="Inter-Regular" size={11} lh={1.5} color="text-2" style={{ marginTop: 3 }}>{warning.body}</Text>
            </View>
          </View>
        ) : null}

        <Heading>{'Assignment'}</Heading>
        <View style={s.card2}>
          {rows.map(([label, value]) => (
            <View key={label} style={s.infoRow2}>
              <View style={s.shrink}><Text face="Inter-SemiBold" size={11.5} color="text-3">{label}</Text></View>
              <View style={s.value}><Text face="Inter-SemiBold" size={12} color="white" align="right">{value}</Text></View>
            </View>
          ))}

          {fixture ? null : m.status === 'loading' ? (
            <SkeletonLines tone="dark" lines={2} style={{ marginTop: 8 }} />
          ) : m.status === 'forbidden' ? (
            <Text face="Inter-Regular" size={11} lh={1.5} color="amber-500" role="alert" style={{ marginTop: 8 }}>{m.errorMessage ?? 'This handset is not allowed to read your details. Contact your supervisor.'}</Text>
          ) : m.status === 'error' ? (
            <View style={{ marginTop: 8 }} role="alert">
              <Text face="Inter-Regular" size={11} lh={1.5} color="text-2">{`Your licence details could not be loaded${m.errorMessage ? `: ${m.errorMessage}` : '.'} The office still holds them.`}</Text>
              <GuardButton variant="outline" label="Try again" onPress={m.refresh} loading={m.refreshing} style={{ marginTop: 10 }} />
            </View>
          ) : null}

          {fixture || !m.staleNote ? null : <Text face="Inter-Regular" size={10.5} lh={1.5} color="amber-500" style={{ marginTop: 8 }}>{m.staleNote}</Text>}
          {fixture ? null : <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginTop: 6 }}>{'Your contract and personal details are held by the office, not on this phone. If your licence details here are wrong, tell your supervisor: only the office can correct them.'}</Text>}
        </View>

        {/* M12 §3.3: the next fourteen days of the guard's own roster. Not on the board, so not in the fixture. */}
        {fixture ? null : (
          <>
            <Heading>{'Upcoming shifts'}</Heading>
            <View style={s.card2}>
              {upcoming.status === 'loading' ? (
                <SkeletonLines tone="dark" lines={2} />
              ) : upcoming.status === 'forbidden' ? (
                <Text face="Inter-Regular" size={11} lh={1.5} color="amber-500" role="alert">{'This handset is not allowed to read your roster. Contact your supervisor.'}</Text>
              ) : upcoming.status === 'error' ? (
                <View role="alert">
                  <Text face="Inter-Regular" size={11} lh={1.5} color="text-2">{'Your upcoming shifts could not be loaded.'}</Text>
                  <GuardButton variant="outline" label="Try again" onPress={upcoming.refresh} style={{ marginTop: 10 }} />
                </View>
              ) : upcoming.rows.length === 0 ? (
                <Text face="Inter-Regular" size={11} lh={1.5} color="text-2">{NO_UPCOMING}</Text>
              ) : (
                upcoming.rows.map((row, i) => (
                  <View key={row.key} style={[s.shiftRow, i > 0 && s.shiftRowRule]}>
                    <View style={s.shiftDay}><Text face="Inter-Bold" size={11} color={row.now ? 'green-400' : 'amber-500'}>{row.day}</Text></View>
                    <View style={s.shrink}>
                      <Text face="Inter-SemiBold" size={12.5} color="white">{row.title}</Text>
                      <Text face="Inter-Regular" size={11} lh={1.4} color={row.now ? 'green-400' : 'text-2'} style={{ marginTop: 2 }}>{row.detail}</Text>
                    </View>
                  </View>
                ))
              )}
              {upcoming.staleNote ? <Text face="Inter-Regular" size={10.5} lh={1.5} color="amber-500" style={{ marginTop: 8 }}>{upcoming.staleNote}</Text> : null}
            </View>
          </>
        )}

        <Heading>{'This device'}</Heading>
        <View style={s.card2}>
          <View style={s.deviceRow}>
            <Icon name="device-row" size={22} color="amber-500" style={s.deviceRowSvg} />
            <View style={s.shrink}>
              <Text face="Inter-Bold" size={12.5} color="white">{model}</Text>
              <Text face="Inter-SemiBold" size={10.5} lh={1.4} color={fixture ? 'green-400' : identity.tone} style={{ marginTop: 2 }}>{deviceLine}</Text>
            </View>
          </View>
          {fixture ? null : <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginTop: 10 }}>{'Your fingerprint or face is checked by the phone itself. No image, template or score reaches this app or the office.'}</Text>}
        </View>

        <Heading>{'Account'}</Heading>
        <ListCard style={{ marginBottom: 12 }}>
          <NavRow icon="privacy-note" title={fixture ? 'Change PIN' : 'Sign-in & security'} onPress={() => go('/change-pin')} />
          <Divider />
          <NavRow icon="set-icon-2" title="Notification preferences" onPress={() => go('/notifications')} />
        </ListCard>
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  shrink: { flexShrink: 1 },
  value: { flexShrink: 1, maxWidth: '62%' },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  profAvatar: { width: 64, height: 64, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 32, borderWidth: 2, borderColor: c('amber-500') },
  profTag: { backgroundColor: a('amber-500', 0.12), borderRadius: 20, paddingHorizontal: 9, paddingVertical: 3, marginTop: 6, alignSelf: 'flex-start' },
  profHero: { flexDirection: 'row', alignItems: 'center', gap: 14 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14 },
  // The clock-in screen's notice card (board guard-app-38), reused: the nearest board that draws a warning.
  // Its two colours are set where it is drawn, from what the warning is.
  warnRow: { flexDirection: 'row', gap: 11, padding: 13, borderRadius: 14, borderWidth: 1, marginTop: 12 },
  infoRow2: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 8, gap: 12 },
  card2: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14, marginTop: 8 },
  deviceRowSvg: { width: 22, height: 22, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  deviceRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  shiftRow: { flexDirection: 'row', gap: 12, paddingVertical: 9 },
  shiftRowRule: { borderTopWidth: 1, borderTopColor: c('border-700') },
  shiftDay: { width: 92, flexGrow: 0, flexShrink: 0, paddingTop: 1 },
})
