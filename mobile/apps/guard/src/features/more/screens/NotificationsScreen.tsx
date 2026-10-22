/**
 * guard-app-36 · Notifications. Styles are the board's own, copied from the lifted file (src/lifted/GuardApp36.tsx).
 *
 * The server keeps no notification preferences, so these are THIS PHONE's — real switches over a real
 * store the notification layer reads (prefs.ts). Duress and alertness cannot be switched off. The
 * board's "SMS backup" is not drawn live: the platform has no SMS provider. "Push" is the phone's own
 * permission, shown as it really is, with the way to change it.
 *
 * THIS IS WHERE A GUARD WHO SAID NO IN SETUP FINDS OUT (setup step 4, setupPermissions.ts). When the
 * phone is not allowing alerts the screen says so at the top, in the words the app uses for the things
 * that will not arrive, and the paragraph under the card stops promising anything about a closed app.
 * Every one of those sentences lives in `notifyPermission.ts`, where a test holds them to the truth.
 *
 * AND IT IS WHERE DO NOT DISTURB IS ASKED ABOUT, IN CONTEXT. "On this phone" gains a
 * row — Android only, and only while `flags.askForDndAccess` is on — that says in plain words why a
 * work phone should let a duress alarm through a Focus setting, and opens the phone's own Do Not
 * Disturb access screen. It is drawn from what ANDROID says about the safety channel
 * (`safetyChannelDnd`), not from having asked, so it never claims a bypass this phone has not got. The
 * words and the decision are in `dndAccess.ts`. The Resident App has no equivalent and asks for none.
 *
 * AND WHETHER A PANIC RINGS THIS PHONE LIKE A CALL. One row, "Call-style alerts", says the phone's own answer in
 * a line (features/ring/readiness.ts) and opens its screen, where every part of it is ON or OFF with its fix.
 */

import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { AppState, Linking, Platform, ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { notificationPermission, safetyChannelDnd, type DndBypass } from '@gs/core/notify'
import { AppBar, Icon, Pressable, Text, a, c, fidelityScreenId, isFidelity, useFidelityReady } from '@gs/core'
import { dataLight, isExpoGo, useDataLight } from '@gs/core/lib'
import { onboarding } from '@/features/enrolment/onboardingState'
import { useCallStyle } from '@/features/ring/useCallStyle'
import { notificationsFixture } from '@/fidelity/more'
import { Heading, Switch } from '../components/Rows'
import { DND_NOT_GRANTED, DND_NO_SETTINGS_SCREEN, DND_SETTINGS_ACTION, dndRow } from '../dndAccess'
import { footerNote, offBanner, permissionRow, type Permission } from '../notifyPermission'
import { CATEGORIES, notificationPrefs, type Category } from '../prefs'

function Row({ title, detail, children, first }: { title: string; detail?: string | null; children: ReactNode; first: boolean }) {
  return (
    <>
      {first ? null : <View style={s.setDiv} />}
      <View style={s.trow}>
        <View style={s.fill}>
          <Text face="Inter-SemiBold" size={13} color="white">{title}</Text>
          {detail ? <Text face="Inter-Regular" size={10.5} lh={1.4} color="text-3" style={{ marginTop: 2 }}>{detail}</Text> : null}
        </View>
        {children}
      </View>
    </>
  )
}

export default function NotificationsScreen() {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-36'
  const [prefs, setPrefs] = useState(() => notificationPrefs.get())
  const light = useDataLight()
  const [permission, setPermission] = useState<Permission>(Platform.OS === 'web' ? 'unsupported' : 'undetermined')
  // What ANDROID says about the safety channel's Do Not Disturb bypass — read back, never assumed.
  const [dnd, setDnd] = useState<DndBypass>('unknown')
  // Shown only after the guard has been sent to the phone's settings and come back with it still off.
  const [dndNote, setDndNote] = useState<string | null>(null)
  const sentToSettings = useRef(false)
  // Whether a panic will ring this phone like a call: its own screen, More → Notifications → Call-style alerts.
  const callStyle = useCallStyle()
  useFidelityReady(true)

  const read = useCallback(async () => {
    if (Platform.OS === 'web') return
    const p = await notificationPermission()
    if (p) setPermission(p.granted ? 'granted' : p.canAskAgain ? 'undetermined' : 'denied')
    const bypass = await safetyChannelDnd('guard')
    setDnd(bypass)
    // Only after the guard has actually been sent out to the phone's settings and has come back with
    // it still off. Never on a first look: nothing has been asked for yet, so nothing has failed.
    setDndNote(sentToSettings.current && bypass === 'off' ? DND_NOT_GRANTED : null)
  }, [])
  useEffect(() => { if (!fixture) void read() }, [fixture, read])

  // Both answers are changed in the phone's own Settings, which means leaving the app: read them again
  // on the way back, or the screen would go on showing what was true before the guard went out.
  useEffect(() => {
    if (fixture) return
    const sub = AppState.addEventListener('change', (state) => { if (state === 'active') void read() })
    return () => sub.remove()
  }, [fixture, read])

  const allow = useCallback(async () => {
    if (Platform.OS === 'web') return
    if (permission === 'denied') { await Linking.openSettings().catch(() => undefined); return }
    await notificationPermission(true)
    await read()
  }, [permission, read])

  // Opens the phone's "which apps may change Do Not Disturb" screen. The app never changes it itself.
  const askDnd = useCallback(async () => {
    setDndNote(null)
    try {
      await Linking.sendIntent(DND_SETTINGS_ACTION)
      sentToSettings.current = true
    } catch {
      setDndNote(DND_NO_SETTINGS_SCREEN)
    }
  }, [])

  const dndDetail = fixture ? null : dndRow(dnd)

  const toggle = (key: Category, on: boolean) => setPrefs(notificationPrefs.set(key, on))

  // Setup put the question once (step 4). Knowing it was asked is the difference between
  // "not allowed yet" and "you said no" — and only one of those two is true.
  const everAsked = !fixture && onboarding.get().notificationsAskedAt !== null
  const row = permissionRow(permission, everAsked)
  const banner = fixture ? null : offBanner(permission)

  return (
    <View style={s.screen}>
      <AppBar title="Notifications" />
      <ScrollView contentContainerStyle={s.bodyPad}>
        {banner ? (
          <View style={s.offBanner}>
            <Icon name="qa-tile-3" size={16} color="amber-500" style={s.offBannerIcon} />
            <View style={s.fill}><Text face="Inter-Regular" size={11} lh={1.5} color="text-2">{banner}</Text></View>
          </View>
        ) : null}
        <Heading top={2}>{'By category'}</Heading>
        <View style={s.card}>
          {fixture
            ? notificationsFixture.categories.map((cat, i) => (
                <Row key={cat.title} title={cat.title} detail={cat.detail} first={i === 0}><Switch on locked={cat.locked} label={cat.title} /></Row>
              ))
            : CATEGORIES.map((cat, i) => (
                <Row key={cat.key} title={cat.title} detail={cat.detail} first={i === 0}>
                  <Switch on={prefs[cat.key]} locked={cat.locked} label={cat.title} onChange={cat.locked ? undefined : (on) => toggle(cat.key, on)} />
                </Row>
              ))}
        </View>

        <Heading top={16}>{fixture ? 'By channel' : 'On this phone'}</Heading>
        <View style={s.card2}>
          {fixture ? (
            notificationsFixture.channels.map((ch, i) => <Row key={ch.title} title={ch.title} detail={ch.detail} first={i === 0}><Switch on label={ch.title} /></Row>)
          ) : (
            <Row first title="Notifications from this app" detail={row.detail}>
              {row.control === 'on' ? (
                <Text face="Inter-Bold" size={11} color="green-400">{'On'}</Text>
              ) : row.control === 'none' ? (
                <Text face="Inter-Bold" size={11} color="text-3">{'—'}</Text>
              ) : (
                <Pressable onPress={() => void allow()} role="button" hitSlop={10} style={s.allowBtn}><Text face="Inter-Bold" size={11.5} color="g-navy-900">{row.control === 'settings' ? 'Settings' : 'Allow'}</Text></Pressable>
              )}
            </Row>
          )}
          {dndDetail ? (
            <Row first={false} title={dndDetail.title} detail={dndDetail.detail}>
              {dndDetail.control === 'on' ? (
                <Text face="Inter-Bold" size={11} color="green-400">{'On'}</Text>
              ) : (
                <Pressable onPress={() => void askDnd()} role="button" hitSlop={10} style={s.allowBtn}><Text face="Inter-Bold" size={11.5} color="g-navy-900">{'Allow'}</Text></Pressable>
              )}
            </Row>
          ) : null}
          {fixture ? null : (
            <Row first={false} title="Call-style alerts" detail={callStyle.summary?.headline ?? callStyle.note ?? 'Whether a panic rings this phone like a call.'}>
              <Pressable onPress={() => router.push('/call-style' as never)} role="button" aria-label="Check call-style alerts" hitSlop={10} style={s.allowBtn}><Text face="Inter-Bold" size={11.5} color="g-navy-900">{'Check'}</Text></Pressable>
            </Row>
          )}
          {fixture ? null : (
            <Row first={false} title="Use less mobile data" detail="Lists refresh less often and photos are sent smaller. Alerts, check-ins, gate scans and your queued records are never slowed.">
              <Switch on={light} label="Use less mobile data" onChange={(on) => dataLight.set(on)} />
            </Row>
          )}
        </View>
        {dndNote ? (
          <View style={s.offBanner}>
            <Icon name="qa-tile-3" size={16} color="amber-500" style={s.offBannerIcon} />
            <View style={s.fill}><Text face="Inter-Regular" size={11} lh={1.5} color="text-2">{dndNote}</Text></View>
          </View>
        ) : null}
        {fixture ? null : (
          <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginHorizontal: 2 }}>{footerNote(permission, isExpoGo)}</Text>
        )}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  trow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 11, gap: 12 },
  setDiv: { height: 1, backgroundColor: c('border-700') },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14, marginTop: 8 },
  card2: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14, marginTop: 8, marginBottom: 12 },
  allowBtn: { backgroundColor: c('amber-500'), borderRadius: 20, paddingHorizontal: 14, paddingVertical: 7 },
  // The "alerts are off" banner — the board's own note block (guard-app-38's notice card, at this screen's width).
  offBanner: { flexDirection: 'row', backgroundColor: a('amber-500', 0.12), borderRadius: 12, borderWidth: 1, borderColor: a('amber-500', 0.3), paddingHorizontal: 13, paddingVertical: 12, gap: 9, marginBottom: 4 },
  offBannerIcon: { width: 16, height: 16, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
})
