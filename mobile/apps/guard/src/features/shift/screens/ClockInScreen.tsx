/**
 * Geofenced clock-in — ONE screen, four drawn states:
 *   guard-app-02  ready                 guard-app-37  outside the geofence (Clock in disabled, with the reason)
 *   guard-app-38  mock location flagged  guard-app-39  biometric didn't match (attempt dots; 3 then the supervisor route)
 * plus the states no board draws: loading, no shift rostered, error — and, since M12 §1, finding the position
 * (with the accuracy as it settles), location not allowed, location switched off, a position too inaccurate to
 * judge, no geofence configured, no signal. Refused, the guard is told the post, how far and which way, and offered
 * a fresh fix, a call to the supervisor and the supervisor's PIN — never "you are not in the estate area".
 *
 * Layout values are the boards' own, lifted (see src/lifted/GuardApp02/37/38/39.tsx).
 * All behaviour lives in useClockIn(); this file only draws it.
 */

import { useEffect } from 'react'
import { BackHandler, RefreshControl, ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { AppBar, EmptyState, ErrorView, GuardButton, Icon, SkeletonLines, Text, a, c, isFidelity, sh, useFidelityReady } from '@gs/core'
import { LocationPolicyCard } from '@/features/tracking/components/LocationPolicyCard'
import { INFO } from '@/info/topics'
import { DuressShield } from '../components/DuressShield'
import { OFFLINE, WHY } from '../override'
import { MAX_ATTEMPTS, useClockIn, type Geo } from '../useClockIn'

const metres = (m: number) => (m < 10 ? `${Number(m.toFixed(1))}m` : `${Math.round(m)}m`)

function GeoVisual({ geo }: { geo: Geo }) {
  const outside = geo.state === 'outside'
  const known = geo.state === 'inside' || geo.state === 'outside'
  const words =
    geo.state === 'inside' ? (geo.accuracyM === null ? 'GPS fix acquired' : `GPS accuracy: ${geo.accuracyM}m`)
    : geo.state === 'checking' ? `Finding your position…${geo.accuracyM ? ` ±${geo.accuracyM}m` : ''}`
    : geo.state === 'poor_fix' ? `Only sure to ±${geo.accuracyM}m`
    : geo.state === 'no_geofence' ? 'No geofence configured'
    : geo.state === 'unavailable' ? (geo.why === 'permission' ? 'Location not allowed' : geo.why === 'services_off' ? 'Location is switched off' : geo.why === 'offline' ? 'No signal' : 'No GPS fix')
    : ''
  return (
    <View style={s.geoVisual}>
      <View style={s.ring} />
      <View style={s.ring2} />
      {known ? <View style={[s.pin, outside ? s.pinOutside : null]} /> : null}
      <View style={s.glabel}>
        {outside ? (
          <Text face="Inter-SemiBold" size={9.5} color="red-500">{geo.distanceM === undefined ? `${metres(geo.boundaryM)} from boundary` : `${metres(geo.distanceM)} from ${geo.fenceName ?? 'the post'}`}</Text>
        ) : (
          <Text face="Inter-SemiBold" size={9.5} color={geo.state === 'poor_fix' || (geo.state === 'unavailable' && geo.why !== 'offline') ? 'red-500' : 'text-2'}>{words}</Text>
        )}
      </View>
    </View>
  )
}

/** The fence named as the guard knows it: the post, or — when the post has no position of its own — the estate. */
const fenceLabel = (geo: Geo, postName: string): string =>
  'source' in geo && geo.source === 'estate' ? `the ${geo.fenceName ?? 'estate'}’s area` : ('fenceName' in geo && geo.fenceName) || postName

/** Everything said about WHERE the phone is, beyond the boards' own cards. Null when the boards' own say it. */
function whereCard(geo: Geo, postName: string): { tone: 'red-500' | 'amber-500'; title: string; body: string } | null {
  if (geo.state === 'outside' && geo.distanceM !== undefined) {
    const fence = fenceLabel(geo, postName)
    return {
      tone: 'red-500',
      title: `Outside ${fence}`,
      body: `You are ${metres(geo.distanceM)} from ${fence}: ${metres(geo.boundaryM)} outside its ${metres(geo.radiusM)} geofence.${geo.direction ? ` It is ${geo.direction} of you.` : ''} Move closer to the gate and try again.${geo.source === 'estate' ? ` (${postName} has no position of its own, so the estate’s area is used.)` : ''}${geo.offline ? ' Checked on this phone against the geofence it saved: there is no signal.' : ''}`,
    }
  }
  if (geo.state === 'poor_fix') {
    return { tone: 'red-500', title: 'Your position could not be established', body: `This phone is only sure of where it is to within ${geo.accuracyM}m: too wide to check. Step into open sky, away from walls and roofs, and try again. If you are at your post, your supervisor can start the shift with their PIN.` }
  }
  if (geo.state === 'unavailable' && geo.why === 'permission') {
    return { tone: 'amber-500', title: 'Location is not allowed for this app', body: 'Clock-in checks that you are at your post, so it needs your location. Allow location for this app in Settings, then try again. This is not the same as being away from the estate.' }
  }
  if (geo.state === 'unavailable' && geo.why === 'services_off') {
    return { tone: 'amber-500', title: 'Location is switched off on this phone', body: 'Turn on Location (GPS), then try again. Nothing is wrong with where you are standing: the phone just cannot tell.' }
  }
  if (geo.state === 'unavailable' && geo.why === 'no_fix') {
    return { tone: 'red-500', title: 'No position came from this phone', body: 'The phone gave no GPS reading in time. Step into open sky and try again. If you are at your post, your supervisor can start the shift with their PIN.' }
  }
  if (geo.state === 'no_geofence') {
    return { tone: 'amber-500', title: 'No geofence configured', body: `${postName} has no geofence configured yet, so where you are cannot be checked. You can clock in; the office is told it was not checked.` }
  }
  return null
}

function CheckLine({ ok, title, detail, first }: { ok: boolean | null; title: string; detail: string; first?: boolean }) {
  return (
    <View style={[s.geoLine, first ? { marginTop: 12 } : null]}>
      <Icon name={ok === false ? 'act-result' : 'check'} size={16} color={ok === false ? 'red-500' : ok === null ? 'text-3' : 'amber-500'} />
      <View style={{ flexShrink: 1 }}>
        <Text face="Inter-SemiBold" size={12.5} color="white">{title}</Text>
      </View>
      <View style={{ flexShrink: 1, marginLeft: 'auto' }}>
        <Text face="Inter-Medium" size={10.5} color="text-3">{detail}</Text>
      </View>
    </View>
  )
}

function NoticeCard({ tone, title, body }: { tone: 'red-500' | 'amber-500'; title: string; body: string }) {
  return (
    <View style={[s.noticeCard, { backgroundColor: a(tone, 0.1), borderColor: a(tone, tone === 'red-500' ? 0.3 : 0.32) }]} role="alert">
      <Icon name="qa-tile-3" size={19} color={tone} style={{ marginTop: 1 }} />
      <View style={{ flexShrink: 1 }}>
        <Text face="Inter-Bold" size={12.5} color={tone}>{title}</Text>
        <Text face="Inter-Regular" size={11} lh={1.5} color="text-2" style={{ marginTop: 3 }}>{body}</Text>
      </View>
    </View>
  )
}

/*
 * A WAY HOME, ALWAYS (B127). The splash and the end of setup land here, and it drew no back button: a guard between
 * shifts, with nothing rostered, or with a shift later today could not reach Home, Patrol, Messages, the sync queue
 * or the duress shield without clocking in — early, so the start was recorded wrong — and on Android, Back left
 * the app. Home is one tap away, Back goes Home when there is nowhere to go back to, and the shield is on the screen
 * in every state (D-M036: "the duress FAB is drawn in EVERY state").
 */
const goHome = () => (router.canGoBack() ? router.back() : router.replace('/(tabs)'))

export default function ClockInScreen() {
  const m = useClockIn(() => router.replace('/(tabs)'))
  useFidelityReady(m.status !== 'loading')

  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      if (router.canGoBack()) return false
      router.replace('/(tabs)')
      return true
    })
    return () => sub.remove()
  }, [])

  const failed = m.bio.failed || m.bio.locked
  // Where the phone is closes the ordinary start: outside, not established, location refused or switched off.
  const outside = m.locationBlocked
  const card = isFidelity ? null : whereCard(m.geo, m.postName)
  const geoDetail =
    m.geo.state === 'inside' ? (m.geo.margin ? 'Within your phone’s margin of error' : `${metres(m.geo.boundaryM)} from boundary`)
    : m.geo.state === 'outside' ? (m.geo.accuracyM === null ? '' : `GPS accuracy: ${m.geo.accuracyM}m`)
    : m.geo.state === 'checking' ? (m.geo.accuracyM ? `Settling: ±${m.geo.accuracyM}m` : 'Checking…')
    : m.geo.state === 'poor_fix' ? `±${m.geo.accuracyM}m, not established`
    : m.geo.state === 'no_geofence' ? 'Not configured: the office is told'
    : m.geo.why === 'permission' ? 'Location not allowed' : m.geo.why === 'services_off' ? 'Location is switched off' : m.geo.why === 'offline' ? 'No signal: checked on sync' : 'No GPS fix'
  const geoTitle = m.geo.state === 'inside' ? 'Within geofence'
    : m.geo.state === 'outside' ? 'Outside geofence'
    : m.geo.state === 'poor_fix' || (m.geo.state === 'unavailable' && m.geo.why !== 'offline') ? 'Position not established'
    : m.geo.state === 'no_geofence' ? 'No geofence'
    : 'Geofence'

  return (
    <View style={s.screen}>
      <AppBar title="Clock In" info={INFO.clockIn} left={isFidelity ? null : { icon: 'chevron-left', iconSize: 18, label: 'Home', onPress: goHome }} right={{ icon: 'tab-notices', iconSize: 18, label: 'Notifications and sound cues', onPress: () => router.push('/notifications' as never) }} />
      <ScrollView
        contentContainerStyle={s.bodyPad}
        refreshControl={<RefreshControl refreshing={m.geo.state === 'checking' && m.status === 'ready'} onRefresh={m.refresh} tintColor={c('amber-500')} />}
      >
        {m.status === 'loading' ? <SkeletonLines tone="dark" lines={6} style={{ marginTop: 12 }} /> : null}
        {/* Retry asks for the SHIFT again, not only the position (B131). */}
        {m.status === 'error' ? <ErrorView tone="dark" message={m.errorMessage ?? 'Your shift could not be loaded.'} onRetry={m.refresh} /> : null}
        {m.status === 'no_shift' ? (
          <EmptyState tone="dark" icon="tab-patrol" title="No shift to clock in to" body="Nothing is rostered for you right now. If you were asked to cover a post, claim it under Open shifts." action={{ label: 'See open shifts', onPress: () => router.push('/leave' as never) }} />
        ) : null}

        {m.status === 'ready' ? (
          <>
            <View style={s.siteHead}>
              <Text face="Inter-SemiBold" size={11} color="text-3" align="center">{m.who}</Text>
              <Text face="Poppins-SemiBold" size={16} color="white" align="center" style={{ marginTop: 3 }}>{m.where}</Text>
              <Text face="Inter-Regular" size={11.5} color="text-2" align="center" style={{ marginTop: 2 }}>{m.when}</Text>
            </View>

            {/* M11 §3.2: the location policy, until the officer has answered these words. */}
            <LocationPolicyCard />

            {/* Board 01 (ready) sets the checks 16 below the site head; board 10's three failure states draw them flush. */}
            <View style={{ marginTop: outside || m.mocked || failed ? 0 : 16 }}>
              {failed ? null : <GeoVisual geo={m.geo} />}
              <CheckLine first={!failed} ok={m.geo.state === 'inside' ? true : outside ? false : null} title={geoTitle} detail={geoDetail} />
              {m.mocked && !failed ? null : <CheckLine ok title="Device verified" detail={m.mocked ? 'Location flagged' : 'No mock location'} />}
            </View>

            {card ? (
              <NoticeCard tone={card.tone} title={card.title} body={card.body} />
            ) : m.geo.state === 'outside' ? (
              // The board's own card (guard-app-37), where the server sent no distance to name.
              <NoticeCard tone="red-500" title="Move closer to clock in" body={`You need to be within ${metres(m.geo.radiusM)} of the ${m.postName} boundary. Try standing closer to the gatehouse, or step into open sky if this seems wrong.`} />
            ) : m.mocked && !failed ? (
              <NoticeCard tone="amber-500" title="Location integrity check failed" body={`This device's location settings appear altered. You can still clock in, but it'll be flagged for ${m.supervisor ?? 'your supervisor'} to review.`} />
            ) : null}

            {/* No fingerprint or face on this phone: the sensor is not offered, because there is nothing to touch. */}
            {outside || !m.noBiometrics ? null : (
              <NoticeCard tone="amber-500" title="This phone cannot confirm it is you" body={`${WHY.no_biometrics} Your supervisor can start it with their PIN: it is recorded and reported.`} />
            )}

            {outside || m.noBiometrics ? null : (
              <View style={[s.bioWrap, { marginTop: failed ? 30 : m.mocked ? 18 : 22 }]}>
                <View style={s.bioRingWrap}>
                  <View style={s.bRing} />
                  <View style={s.bRing2} />
                  <View style={[s.bioBtn, failed ? { borderColor: c('red-500') } : null]}>
                    <Icon name={failed ? 'side-btn-7' : 'bio-btn'} size={34} color={failed ? 'red-500' : 'amber-500'} />
                  </View>
                </View>
                <Text face="Inter-Bold" size={13} color={failed ? 'red-500' : 'white'}>
                  {m.bio.locked ? 'Identity not confirmed' : failed ? "Didn't match: try again" : 'Confirm your identity'}
                </Text>
                <Text face="Inter-Regular" size={11} color="text-3" align="center" style={{ marginTop: 3 }}>
                  {/* A demonstration account may confirm with the phone's passcode: say the thing that is actually about to be asked for. */}
                  {m.passcodeIdentity
                    ? failed ? 'That was not accepted: try your passcode again' : "Touch the sensor, look at the camera, or use this phone's passcode"
                    : failed ? 'Make sure your finger fully covers the sensor' : 'Touch the sensor or look at the camera'}
                </Text>
                {failed ? (
                  <View style={s.attemptDots} role="img" aria-label={`Attempt ${Math.min(m.bio.attempt, MAX_ATTEMPTS)} of ${MAX_ATTEMPTS}`}>
                    {Array.from({ length: MAX_ATTEMPTS }, (_, i) => (
                      <View key={i} style={[s.attemptDot, { backgroundColor: c(i < m.bio.attempt ? 'red-500' : 'border-600') }]} />
                    ))}
                  </View>
                ) : null}
              </View>
            )}

            {/*
              * REFUSED FOR WHERE THE PHONE IS (M12 §1.3): a fresh fix, the settings that put location right, a call
              * to the supervisor — and below, the supervisor's PIN. With no signal a PIN cannot be checked, and the
              * screen says so rather than offering a form that could only fail.
              */}
            {outside && !isFidelity ? (
              <View style={s.recover}>
                {m.fixLocation ? <GuardButton variant="outline" label={m.geo.state === 'unavailable' && m.geo.why === 'services_off' ? 'Turn on location' : 'Open Settings'} onPress={m.fixLocation} /> : null}
                <GuardButton variant="outline" label="Try again: take a fresh fix" onPress={m.recheck} />
                <GuardButton variant="outline" label={m.supervisorPhone ? 'Call your supervisor' : 'Message the control room'} onPress={m.callSupervisor} />
                {m.online ? null : <NoticeCard tone="amber-500" title="A supervisor’s PIN needs signal" body={OFFLINE} />}
              </View>
            ) : null}

            {/*
              * The ordinary start has closed — no fingerprint or face set up, three checks that did not match, or
              * where the phone is. The supervisor route is the ONLY way on and is always offered here: never a dead
              * end, never a silent unconfirmed start.
              */}
            {m.override ? (
              <GuardButton label="Ask your supervisor to start it" onPress={() => router.push(`/clock-in-override?reason=${m.override}` as never)} style={{ marginTop: 16 }} />
            ) : m.mocked && !failed && !outside ? (
              <GuardButton variant="warning" label="Clock in: flagged for review" onPress={m.clockIn} loading={m.busy} style={{ marginTop: 16 }} />
            ) : m.canClockIn ? (
              <GuardButton label={failed ? 'Try again' : 'Clock in'} onPress={m.clockIn} loading={m.busy} style={{ marginTop: failed ? 16 : 18 }} />
            ) : (
              <GuardButton label="Clock in" onPress={m.clockIn} disabled disabledReason={m.blockedReason ?? 'No shift of yours is ready to start now. Your roster is on Home.'} style={{ marginTop: 14 }} />
            )}

            {outside ? null : failed ? (
              <View style={s.fallbackNote}>
                <Icon name="why-note" size={14} color="text-3" style={{ marginTop: 1 }} />
                <View style={{ flexShrink: 1 }}>
                  <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center">
                    {`Attempt ${Math.min(m.bio.attempt, MAX_ATTEMPTS)} of ${MAX_ATTEMPTS}. After ${MAX_ATTEMPTS} failed attempts, your supervisor starts the shift with their PIN: it is recorded and reported.`}
                  </Text>
                </View>
              </View>
            ) : m.mocked || m.override ? null : (
              // Said only while the ORDINARY start is open — that one is captured on the phone and syncs.
              // A supervisor's PIN cannot be checked without the server, so this sentence would be untrue there.
              <View style={s.offlineNote}>
                <Icon name="offline-note" size={14} color="text-3" style={{ marginTop: 1 }} />
                <View style={{ flexShrink: 1 }}>
                  <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center">
                    {m.online ? "Works even with no signal: your clock-in is saved on this device and syncs the moment you're back online." : "No signal: your clock-in will be saved on this device and sent the moment you're back online."}
                  </Text>
                </View>
              </View>
            )}

          </>
        ) : null}

        {/* A guard who has not started a shift is still a guard at a gate: the duress alert is within reach in every state (B127). */}
        {m.status === 'loading' || (isFidelity && !m.override) ? null : <DuressShield />}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: c('surface-900') },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  siteHead: { paddingTop: 6, paddingBottom: 4 },
  geoVisual: { height: 120, borderRadius: 14, backgroundColor: c('surface-700'), overflow: 'hidden' },
  ring: { position: 'absolute', top: '50%', left: '50%', width: 150, height: 150, borderWidth: 1, borderColor: c('navy-500'), borderStyle: 'dashed', borderRadius: 75, opacity: 0.5, transform: [{ translateX: '-50%' }, { translateY: '-50%' }] },
  ring2: { position: 'absolute', top: '50%', left: '50%', width: 90, height: 90, borderWidth: 1, borderColor: c('navy-500'), borderStyle: 'dashed', borderRadius: 45, opacity: 0.7, transform: [{ translateX: '-50%' }, { translateY: '-50%' }] },
  pin: { position: 'absolute', top: '50%', left: '50%', width: 16, height: 16, borderRadius: 8, backgroundColor: c('amber-500'), boxShadow: sh([{ x: 0, y: 0, blur: 0, spread: 6, token: 'amber-500', opacity: 0.25 }]), transform: [{ translateX: '-50%' }, { translateY: '-50%' }] },
  pinOutside: { top: '22%', left: '85%', backgroundColor: c('red-500'), boxShadow: sh([{ x: 0, y: 0, blur: 0, spread: 6, token: 'red-500', opacity: 0.25 }]) },
  glabel: { position: 'absolute', bottom: 9, left: 9, paddingVertical: 3, paddingHorizontal: 8, borderRadius: 20, backgroundColor: a('black', 0.35) },
  geoLine: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 6 },
  noticeCard: { flexDirection: 'row', gap: 11, padding: 13, borderRadius: 14, borderWidth: 1, marginTop: 14 },
  bioWrap: { alignItems: 'center' },
  bioRingWrap: { width: 100, height: 100, alignItems: 'center', justifyContent: 'center', marginBottom: 14 },
  bRing: { position: 'absolute', width: 118, height: 118, borderRadius: 59, borderWidth: 1, borderColor: c('amber-500'), opacity: 0.12 },
  bRing2: { position: 'absolute', width: 100, height: 100, borderRadius: 50, borderWidth: 1, borderColor: c('amber-500'), opacity: 0.25 },
  bioBtn: { width: 74, height: 74, borderRadius: 37, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: c('border-600'), backgroundColor: c('surface-700'), zIndex: 2 },
  attemptDots: { flexDirection: 'row', gap: 6, marginTop: 10 },
  attemptDot: { width: 7, height: 7, borderRadius: 3.5 },
  offlineNote: { flexDirection: 'row', justifyContent: 'center', gap: 9, paddingHorizontal: 20, marginTop: 14 },
  recover: { gap: 10, marginTop: 14 },
  fallbackNote: { flexDirection: 'row', gap: 9, paddingHorizontal: 10, marginTop: 6 },
})
