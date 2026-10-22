/**
 * guard-app-25 · More. Styles are the board's own, copied from the lifted file (src/lifted/GuardApp25.tsx).
 * A tab: the navigator draws the tab bar.
 *
 * Every row goes somewhere real. The board's PSRA number is not served by the API — the live line is the
 * guard's service number and estate. Sign out is deliberate: see useSignOut. It is asked in a ConfirmSheet over
 * the screen (the Guard App's tone), never a panel in the bottom row's place that could open below the fold.
 */

import { useRef, useState } from 'react'
import { ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import * as Application from 'expo-application'
import { AppBar, ConfirmSheet, Icon, Pressable, Text, Tour, c, fidelityScreenId, isFidelity, useFidelityReady } from '@gs/core'
import { moreFixture } from '@/fidelity/more'
import { useAppSelector } from '@/store'
import { TOUR_CARDS } from '@/tour/tour'
import { Divider, Heading, ListCard, NavRow } from '../components/Rows'
import { initialsOf } from '../more'
import { useSignOut } from '../useSignOut'

const go = (path: string) => router.push(path as never)

export default function MoreScreen() {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-25'
  const principal = useAppSelector((s) => s.session.principal)
  const unsent = useAppSelector((s) => s.outbox.ops.filter((op) => op.state === 'pending' || op.state === 'syncing' || op.state === 'refused').length)
  const out = useSignOut()
  const words = signOutWords(out)
  const signOutRow = useRef<View>(null)
  const [touring, setTouring] = useState(false)
  useFidelityReady(true)

  // No "times in this phone's time zone" switch any more (M19A §4.5): an officer stands at the site, so the app shows the
  // site's clock and nothing else.
  const name = fixture ? moreFixture.name : principal?.name ?? 'Guard'
  const line = fixture ? moreFixture.line : [principal?.reference, principal?.siteName].filter(Boolean).join(' · ')
  const version = fixture ? moreFixture.version : `GeminiSecure Guard · v${Application.nativeApplicationVersion ?? '0.1.0'}`

  return (
    <View style={s.screen}>
      <AppBar title="More" left={null} />
      <ScrollView contentContainerStyle={s.bodyPad}>
        <Pressable onPress={() => go('/profile')} role="button" aria-label={`${name}. My profile`} style={({ pressed }) => [s.card, { opacity: pressed ? 0.8 : 1 }]}>
          <View style={s.moreProfile}>
            <View style={s.mpAvatar}><Text face="Inter-Bold" size={15} color="white">{fixture ? moreFixture.initials : initialsOf(name)}</Text></View>
            <View style={s.fill}>
              <Text face="Poppins-SemiBold" size={14.5} color="white" numberOfLines={1}>{name}</Text>
              <Text face="Inter-Regular" size={10.5} color="text-3" numberOfLines={1} style={{ marginTop: 2 }}>{line}</Text>
            </View>
            <Icon name="chevron-right" size={16} color="text-3" style={s.chevSvg} />
          </View>
        </Pressable>

        <Heading>{'Work'}</Heading>
        <ListCard>
          <NavRow icon="input" title="Leave & duty" detail="Request leave, claim open shifts" onPress={() => go('/leave')} />
          <Divider />
          <NavRow icon="qa-tile-3" title="My reports" detail="Incident reports you've filed" onPress={() => go('/reports')} />
          <Divider />
          <NavRow icon="rh-icon" title="Equipment requests" detail="Request gear, view status" onPress={() => go('/equipment')} />
          <Divider />
          <NavRow icon="order-banner" title="Standing orders" detail="Current orders and history" onPress={() => go('/orders')} />
          {fixture ? null : (
            <>
              <Divider />
              <NavRow icon="recap-item" title="My hours" detail="Hours worked, week by week" onPress={() => go('/hours')} />
              <Divider />
              <NavRow icon="offline-note" title="Sync queue" detail={unsent > 0 ? 'Records waiting on this phone' : 'Everything has been sent'} badge={unsent > 0 ? String(unsent) : null} onPress={() => go('/sync-queue')} />
              <Divider />
              <NavRow icon="why-note" title="Alertness check-ins" detail="What they are, and your choice" onPress={() => go('/alertness')} />
              <Divider />
              <NavRow icon="privacy-note" title="Location sharing" detail="What is shared on duty, and your answer" onPress={() => go('/tracking-consent')} />
              {/* M18 §1: the two screens every officer, the review notes and the drill script name as "More → …". They
                  were reached only through the profile card or the clock-in screen's bell. */}
              <Divider />
              <NavRow icon="set-icon-2" title="Notifications" detail="What reaches this phone, and how it sounds" onPress={() => go('/notifications')} />
              <Divider />
              <NavRow icon="why-note" title="Call-style alerts" detail="A panic rings this phone like a call · test it" onPress={() => go('/call-style')} />
            </>
          )}
        </ListCard>

        <Heading>{'Support'}</Heading>
        <ListCard>
          <NavRow icon="set-icon" title="Help & support" detail={fixture ? 'FAQs, call Ops or Dispatch' : 'How the app works, the control room, 119'} onPress={() => go('/help')} />
          {fixture ? null : (
            <>
              {/* M32 F11: the first-run tour again, in place, on the guard's asking. It records nothing. */}
              <Divider />
              <NavRow icon="info" title="Show me around again" detail="The five things this app does, one card each" onPress={() => setTouring(true)} />
              <Divider />
              {/* Both stores want "delete" to be findable in the app. This app issues no accounts, so the
                  screen says who closes one — and does the part that is really the officer's. */}
              <NavRow icon="privacy-note" title="Delete my account" detail="Closing your account, and removing this app from this phone" onPress={() => go('/delete-account')} />
              {/* M24 §5.3: the app's own policy and terms, reachable from its settings. They live on the phone only. */}
              <Divider />
              <NavRow icon="privacy-note" title="Privacy Policy" detail="What the app records about you, and who sees it" onPress={() => go('/privacy-policy')} />
              <Divider />
              <NavRow icon="order-banner" title="Terms of Use" detail="The rules for using the app at work" onPress={() => go('/terms')} />
            </>
          )}
        </ListCard>

        <Pressable ref={signOutRow} onPress={out.ask} disabled={fixture} role="button" hitSlop={4} style={s.signoutRow}>
          <Text face="Inter-Bold" size={13} color="text-2" align="center">{'Sign out'}</Text>
        </Pressable>
        <Text face="Inter-Regular" size={10} color="text-3" align="center" style={{ marginTop: 16 }}>{version}</Text>
      </ScrollView>

      {/* Asked in a sheet OVER the screen — the old panel took the bottom row's place and could open below the fold. */}
      <ConfirmSheet
        tone="guard"
        open={out.asking}
        title={words.title}
        lines={words.lines}
        confirmLabel={words.confirmLabel}
        cancelLabel="Stay signed in"
        working={out.working}
        problem={out.problem}
        blocked={out.blocked}
        onConfirm={out.confirm}
        onCancel={out.dismiss}
        returnFocusTo={signOutRow}
      >
        {words.warning ? <Text face="Inter-SemiBold" size={12} lh={1.5} color="amber-500" style={{ marginTop: 10 }}>{words.warning}</Text> : null}
      </ConfirmSheet>

      {touring ? <Tour tone="guard" cards={TOUR_CARDS} open onClose={() => setTouring(false)} /> : null}
    </View>
  )
}

/** What the sign-out question says. The sentences live HERE: __tests__/signOut.test.ts reads them in this file. */
export function signOutWords(out: { blocked: string | null; unsent: number; online: boolean }): { title: string; lines: string[]; warning: string | null; confirmLabel: string } {
  // The phone stays yours (M32 F3): signing back in is your fingerprint, or a supervisor's PIN — not a new code.
  const lines = ["Everything the app keeps on this phone is removed. To sign back in on this phone you use your fingerprint, or a supervisor's PIN: no new code."]
  // Said BEFORE the tap, not claimed afterwards (M6, Q-M42): with no signal this phone cannot tell the server to end
  // the session. It is still signed out of THIS phone, and the session on the server closes by itself inside the
  // thirty-minute idle window.
  if (!out.blocked && !out.online) lines.push('There is no signal, so the server cannot be told to end this session now. This phone is signed out either way, and the session on the server ends by itself within 30 minutes.')
  return {
    title: 'Sign out of this phone?',
    lines,
    warning:
      !out.blocked && out.unsent > 0
        ? `${out.unsent} record${out.unsent === 1 ? ' has' : 's have'} not reached the server. ${out.online ? 'The app will try to send them first; anything that still cannot be sent' : 'There is no signal, so they cannot be sent now: they'} will be DELETED. Wait for a connection if you can.`
        : null,
    confirmLabel: !out.blocked && out.unsent > 0 ? 'Sign out anyway' : 'Sign out',
  }
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  mpAvatar: { width: 52, height: 52, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 26, borderWidth: 1, borderColor: c('border-600') },
  chevSvg: { width: 16, height: 16, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  moreProfile: { flexDirection: 'row', alignItems: 'center', gap: 13 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14 },
  signoutRow: { padding: 14, marginTop: 12, minHeight: 47, justifyContent: 'center' },
})
