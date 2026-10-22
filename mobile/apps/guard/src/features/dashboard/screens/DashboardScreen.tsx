/**
 * The Home tab — ONE screen, two drawn states:
 *   guard-app-03  on post, synced (roster card, standing-orders banner)
 *   guard-app-33  no signal: amber "Offline" badge, the PERSISTENT queued-actions banner, "all work offline"
 * plus the states no board draws: loading, error, access refused, not clocked in yet, no shift rostered,
 * on break, a clock-in still waiting to sync, queued-while-online and not-accepted sync badges.
 *
 * Layout values are the boards' own, lifted (see src/lifted/GuardApp03.tsx / GuardApp33.tsx). The lifted
 * .statusbar and .tabbar are gone: the top inset stands in for the first, the navigator draws the second —
 * so the duress button's board offsets (bottom 106 / 99 / 92) are measured from the tab bar's top edge here.
 * All behaviour lives in useDashboard(); this file only draws it and navigates.
 */

import { RefreshControl, ScrollView, StyleSheet, View, useWindowDimensions } from 'react-native'
import { router } from 'expo-router'
import * as Haptics from 'expo-haptics'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, Gradient, GuardButton, Icon, Pressable, SkeletonLines, Text, Tour, a, c, isFidelity, sh, useFidelityReady, type IconName } from '@gs/core'
import { isLive, raiseDuress, SILENT_HOLD_MS, useActiveDuress } from '@/features/duress/useDuress'
import { clearGateFlash, useGateFlash } from '@/features/gate/gateFlash'
import { TOUR_CARDS, useFirstRunTour } from '@/tour/tour'
import { quickActionWidth } from '../quickActionLayout'
import { useDashboard, type Colleague, type ShiftCard, type SyncBadge } from '../useDashboard'

/** Routes other groups are building in parallel are not in the typed-route table yet (ROUTES.md). */
const open = (path: string) => router.push(path as never)
const openTab = (path: string) => router.navigate(path as never)

const QUICK_ACTIONS: { label: string; icon: IconName; go: () => void }[] = [
  { label: 'Scan visitor', icon: 'qa-tile', go: () => open('/scan') },
  { label: 'Search unit', icon: 'qa-tile-2', go: () => open('/search') },
  { label: 'Incident', icon: 'qa-tile-3', go: () => open('/incident/new') },
  { label: 'Patrol', icon: 'tab-patrol', go: () => openTab('/(tabs)/patrol') },
  { label: 'Messages', icon: 'tab-messages', go: () => openTab('/(tabs)/messages') },
  { label: 'Payslips', icon: 'tab-card', go: () => openTab('/(tabs)/payslips') },
]

const pressedStyle = ({ pressed }: { pressed: boolean }) => ({ opacity: pressed ? 0.8 : 1 })

function SyncBadgeView({ badge }: { badge: SyncBadge }) {
  return (
    <Pressable
      onPress={() => open('/sync-queue')}
      role="button"
      aria-label={`Sync status: ${badge.label}`}
      accessibilityHint={badge.hint}
      hitSlop={{ top: 12, bottom: 12, left: 8, right: 8 }}
      style={({ pressed }) => [s.syncBadge, { backgroundColor: a(badge.tone, 0.15), borderColor: a(badge.tone, 0.3), opacity: pressed ? 0.8 : 1 }]}
    >
      {badge.icon ? <Icon name={badge.icon} size={12} color={badge.tone} /> : null}
      <View style={s.shrink}>
        <Text face="Inter-Bold" size={9.5} color={badge.tone}>{badge.label}</Text>
      </View>
    </Pressable>
  )
}

function ShiftCardView({ card }: { card: ShiftCard }) {
  const badgeInk = card.badge.tone === 'solid' ? 'g-navy-900' : card.badge.tone === 'soft' ? 'amber-500' : 'text-2'
  const badgeFill = card.badge.tone === 'solid' ? c('amber-500') : card.badge.tone === 'soft' ? a('amber-500', 0.15) : c('surface-700')

  const body = (
    <Gradient spec={{ angle: 135, stops: [{ token: 'navy-700', at: 0 }, { token: 'surface-900', at: 1 }] }} style={s.shiftCard}>
      <View style={s.scTop}>
        <View style={s.shrink}>
          <Text face="Poppins-SemiBold" size={15} color="white">{card.title}</Text>
          <Text face="Inter-Regular" size={11} color="text-2" style={{ marginTop: 2 }}>{card.site}</Text>
        </View>
        <View style={[s.shiftBadge, { backgroundColor: badgeFill }]}>
          <Text face="Inter-Bold" size={9.5} color={badgeInk}>{card.badge.label}</Text>
        </View>
      </View>
      <View style={s.scTime}>
        {card.cells.map((cell) => (
          <View key={cell.label} style={s.cell}>
            <Text face="Inter-Bold" size={13} color="white" align="center">{cell.value}</Text>
            <Text face="Inter-SemiBold" size={9.5} color="text-3" align="center" style={{ marginTop: 2 }}>{cell.label}</Text>
          </View>
        ))}
      </View>
      {card.note ? (
        <Text face="Inter-Medium" size={10} color="text-3" align="center" style={{ marginTop: 10 }}>{card.note}</Text>
      ) : null}
      {card.action === 'clock-in' ? <GuardButton label="Clock in" onPress={() => open('/clock-in')} style={{ marginTop: 14 }} /> : null}
    </Gradient>
  )

  return card.action === 'clock-out' ? (
    <Pressable onPress={() => open('/clock-out')} role="button" aria-label={`${card.title}, ${card.badge.label}`} accessibilityHint="Opens clock out and handover" style={({ pressed }) => [s.shiftCardWrap, pressedStyle({ pressed })]}>
      {body}
    </Pressable>
  ) : (
    <View style={s.shiftCardWrap}>{body}</View>
  )
}

function RosterRow({ who }: { who: Colleague }) {
  return (
    <View style={s.rosterRow}>
      <View style={s.rAvatar}>
        <Text face="Inter-Bold" size={9.5} color="white">{who.initials}</Text>
      </View>
      <View style={s.grow}>
        <Text face="Inter-SemiBold" size={11.5} color="white" numberOfLines={1}>{who.name}</Text>
      </View>
      <View style={s.shrink}>
        <Text face="Inter-SemiBold" size={10} color="text-3" numberOfLines={1}>{who.post}</Text>
      </View>
    </View>
  )
}

export default function DashboardScreen() {
  const m = useDashboard()
  const insets = useSafeAreaInsets()
  useFidelityReady(m.status !== 'loading')

  const duress = () => {
    void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Heavy).catch(() => undefined)
    open('/duress')
  }

  // THE SILENT PATH. A guard who is being watched rests a thumb on the shield for two seconds.
  // Nothing happens that anyone beside them could see or hear — no navigation, no vibration, no
  // sound, no toast, no badge — and dispatch has the alert. The one trace is for the guard alone:
  // the two faint rings around the shield are gone while an alert is active.
  const activeDuress = useActiveDuress()
  const silentDuress = () => void raiseDuress('silent')
  // M32 F11: the first-run tour, once — never while a duress alert is live. Skip is one tap on every card, and a ring or
  // an alert takes the phone to a screen the tour never stands over, so it can never stand between a guard and help.
  const tour = useFirstRunTour(isLive(activeDuress))

  // Measured, so three tiles fit a row on a narrow phone; live, centred across the whole width, as the Resident App's.
  const qaWidth = quickActionWidth(useWindowDimensions().width, isFidelity ? 'board' : 'live')
  const flash = useGateFlash()

  return (
    <View style={s.screen}>
      {/* The inset is a spacer, not a ScrollView style: react-native-web applies a ScrollView's style to the RefreshControl wrapper AND the scroller, doubling it. */}
      <View style={{ height: insets.top }} />
      <ScrollView
        contentContainerStyle={isFidelity ? s.bodyPad : s.bodyPadLive}
        showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('amber-500')} />}
      >
        <View style={s.topRow}>
          <View style={s.gAvatar} aria-hidden>
            <Text face="Inter-Bold" size={13} color="white">{m.initials}</Text>
          </View>
          <View style={s.grow}>
            <Text face="Inter-Bold" size={13.5} color="white" numberOfLines={1}>{m.name}</Text>
            <Text face="Inter-Regular" size={10.5} color="text-3" numberOfLines={1} style={{ marginTop: 1 }}>{m.subline}</Text>
          </View>
          <SyncBadgeView badge={m.sync} />
        </View>

        {/* What the gate just recorded (gateFlash.ts): an admission brings the officer back here, and this says so. */}
        {flash ? (
          <Pressable onPress={clearGateFlash} role="alert" aria-label={`${flash.title}. ${flash.detail}`} accessibilityHint="Tap to dismiss" style={({ pressed }) => [s.flash, pressedStyle({ pressed })]}>
            <View style={s.flashTick}><Icon name="check" size={15} color="g-navy-900" /></View>
            <View style={s.grow}>
              <Text face="Inter-Bold" size={13} color="white">{flash.title}</Text>
              <Text face="Inter-Regular" size={11.5} lh={1.45} color="text-2" style={{ marginTop: 2 }}>{flash.detail}</Text>
            </View>
          </Pressable>
        ) : null}

        {/* Persistent for as long as there is no signal — a banner, never a toast. */}
        {m.online ? null : (
          <Pressable onPress={() => open('/sync-queue')} role="button" aria-label={m.offlineTitle} accessibilityHint="Opens the sync queue" style={({ pressed }) => [s.offlineBanner, pressedStyle({ pressed })]}>
            <Icon name="offline-note" size={17} color="amber-500" />
            <View style={s.grow}>
              <Text face="Inter-Bold" size={12} color="amber-500">{m.offlineTitle}</Text>
              <Text face="Inter-Regular" size={10} color="text-2" style={{ marginTop: 1 }}>Everything still works: will sync automatically</Text>
            </View>
            <Icon name="chevron-right" size={17} color="amber-500" />
          </Pressable>
        )}

        {m.status === 'loading' ? (
          <View style={[s.card, { marginTop: 14, paddingVertical: 16 }]}>
            <SkeletonLines tone="dark" lines={4} />
          </View>
        ) : null}
        {m.status === 'error' ? <ErrorView tone="dark" message={m.errorMessage ?? 'Your shift could not be loaded.'} onRetry={m.refresh} /> : null}
        {m.status === 'denied' ? (
          <EmptyState tone="dark" icon="rh-icon" title="Shift details are not available" body="This handset is not allowed to read your shift. Contact your supervisor: the actions below still work." />
        ) : null}

        {m.status === 'ready' && m.card ? <ShiftCardView card={m.card} /> : null}
        {m.status === 'ready' && !m.card ? (
          <EmptyState tone="dark" icon="tab-patrol" title="No shift rostered" body="Nothing is running or rostered ahead for you. If you were asked to cover a post, claim it under Open shifts." action={{ label: 'See open shifts', onPress: () => open('/leave') }} />
        ) : null}

        {m.showColleagues ? (
          <>
            <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={s.sectionTitle}>On with you today</Text>
            <View style={s.card}>
              {m.colleagues.length ? (
                m.colleagues.map((who) => <RosterRow key={who.key} who={who} />)
              ) : (
                <Text face="Inter-Regular" size={11.5} color="text-2" style={{ paddingVertical: 7 }}>No other guards are on duty at this estate right now.</Text>
              )}
            </View>
          </>
        ) : null}

        {m.orders ? (
          <Pressable onPress={() => open('/orders')} role="button" aria-label={`${m.orders.title}. ${m.orders.body}`} style={({ pressed }) => [s.orderBanner, pressedStyle({ pressed })]}>
            <Icon name="order-banner" size={18} color="amber-500" />
            <View style={s.grow}>
              <Text face="Inter-Bold" size={12} color="white">{m.orders.title}</Text>
              <Text face="Inter-Regular" size={10} color="text-2" style={{ marginTop: 1 }}>{m.orders.body}</Text>
            </View>
            <Icon name="chevron-right" size={15} color="amber-500" />
          </Pressable>
        ) : null}

        <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={s.sectionTitle}>{m.quickActionsTitle}</Text>
        <View style={isFidelity ? s.qaGrid : s.qaGridLive}>
          {QUICK_ACTIONS.map((action) => (
            <Pressable key={action.label} onPress={action.go} role="button" aria-label={action.label} style={({ pressed }) => [s.qaItem, { width: qaWidth }, pressedStyle({ pressed })]}>
              <View style={s.qaTile}>
                <Icon name={action.icon} size={23} color="amber-500" />
              </View>
              <Text face="Inter-SemiBold" size={9.5} color="text-2" align="center">{action.label}</Text>
            </Pressable>
          ))}
        </View>
      </ScrollView>

      {/* Gone only while the alert is LIVE: back once it is closed or did not get through, which a trained guard reads as "not covered" (B124). */}
      {/* The alert button is RED (the client, 27 Sep), as the Resident App's panic button is; the board's amber in the fidelity build. */}
      {isLive(activeDuress) ? null : <View style={[s.fabRing, isFidelity ? null : s.fabRingRed]} aria-hidden />}
      {isLive(activeDuress) ? null : <View style={[s.fabRing2, isFidelity ? null : s.fabRingRed]} aria-hidden />}
      <Pressable onPress={duress} onLongPress={silentDuress} delayLongPress={SILENT_HOLD_MS} role="button" aria-label="Duress alert" accessibilityHint="Opens the duress alert" style={({ pressed }) => [s.fab, isFidelity ? null : s.fabRed, pressedStyle({ pressed })]}>
        <Icon name="shield-alert" size={24} color={isFidelity ? 'g-navy-900' : 'white'} />
      </Pressable>

      {!isFidelity && tour.open ? <Tour tone="guard" cards={TOUR_CARDS} open onClose={tour.close} /> : null}
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  grow: { flex: 1 },
  shrink: { flexShrink: 1 },
  topRow: { flexDirection: 'row', alignItems: 'center', gap: 11 },
  gAvatar: { width: 42, height: 42, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 21, borderWidth: 1, borderColor: c('border-600') },
  syncBadge: { flexDirection: 'row', alignItems: 'center', flexShrink: 1, borderRadius: 20, borderWidth: 1, paddingHorizontal: 10, paddingVertical: 5, gap: 5 },
  offlineBanner: { flexDirection: 'row', alignItems: 'center', backgroundColor: a('amber-500', 0.12), borderRadius: 14, borderWidth: 1, borderColor: a('amber-500', 0.32), paddingHorizontal: 13, paddingVertical: 11, gap: 10 },
  shiftCardWrap: { marginTop: 14 },
  shiftCard: { borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 16 },
  scTop: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: 10 },
  shiftBadge: { flexShrink: 0, borderRadius: 20, paddingHorizontal: 9, paddingVertical: 4 },
  scTime: { flexDirection: 'row', justifyContent: 'space-between', paddingTop: 12, borderTopWidth: 1, borderTopColor: c('border-700'), marginTop: 14 },
  cell: { flex: 1 },
  sectionTitle: { marginTop: 18, marginLeft: 2, marginRight: 2 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13, paddingVertical: 10, marginTop: 10 },
  rosterRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 7, gap: 10 },
  rAvatar: { width: 28, height: 28, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 14 },
  orderBanner: { flexDirection: 'row', alignItems: 'center', backgroundColor: a('amber-500', 0.1), borderRadius: 14, borderWidth: 1, borderColor: a('amber-500', 0.3), paddingHorizontal: 13, paddingVertical: 12, gap: 11, marginTop: 14 },
  qaGrid: { flexDirection: 'row', flexWrap: 'wrap', paddingRight: 70, gap: 10, marginTop: 10 },
  // Live: the whole width, rows centred — three tiles and two gaps fill a phone's row (quickActionLayout, the Resident App's way).
  qaGridLive: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'center', columnGap: 10, rowGap: 14, marginTop: 12 },
  // Live, the duress button floats over clear space at the foot of the scroll instead of over the grid's last row.
  bodyPadLive: { paddingTop: 16, paddingBottom: 104, paddingHorizontal: 20 },
  flash: { flexDirection: 'row', alignItems: 'flex-start', gap: 11, backgroundColor: a('green-400', 0.14), borderRadius: 14, borderWidth: 1, borderColor: a('green-400', 0.45), paddingHorizontal: 13, paddingVertical: 12, marginTop: 14 },
  flashTick: { width: 24, height: 24, borderRadius: 12, alignItems: 'center', justifyContent: 'center', backgroundColor: c('green-400'), marginTop: 1 },
  fabRed: { backgroundColor: c('red-700'), boxShadow: sh([{ x: 0, y: 10, blur: 22, spread: 0, token: 'red-700', opacity: 0.45 }]) },
  fabRingRed: { borderColor: c('red-500') },
  // No width here: it is measured per screen (quickActionWidth) and passed in.
  qaItem: { flexDirection: 'column', alignItems: 'center', gap: 7 },
  qaTile: { width: 56, height: 56, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-800'), borderRadius: 15, borderWidth: 1, borderColor: c('border-700') },
  // Board offsets are from the bottom of the phone, under an 86px tab bar: 92 / 99 / 106 → 6 / 13 / 20 above the navigator's bar.
  // The rings are decoration: they never take a touch meant for the content beneath them.
  fabRing: { position: 'absolute', right: 4, bottom: 6, width: 84, height: 84, opacity: 0.15, borderRadius: 42, borderWidth: 1, borderColor: c('amber-500'), pointerEvents: 'none' },
  fabRing2: { position: 'absolute', right: 11, bottom: 13, width: 70, height: 70, opacity: 0.3, borderRadius: 35, borderWidth: 1, borderColor: c('amber-500'), pointerEvents: 'none' },
  fab: { position: 'absolute', right: 18, bottom: 20, width: 56, height: 56, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), zIndex: 24, borderRadius: 28, boxShadow: sh([{ x: 0, y: 10, blur: 22, spread: 0, token: 'amber-500', opacity: 0.4 }]) },
})
