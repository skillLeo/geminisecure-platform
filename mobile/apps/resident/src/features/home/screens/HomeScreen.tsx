/**
 * resident-app-05 · Home — board 02 Home Panic and Visitors. Styles are the board's own, copied
 * from the lifted file; the hero illustration is the board's SVG, shape for shape.
 *
 * THE PANIC BUTTON (M12 §2.2). Live, it is red, at the top right of the header — clear of the greeting
 * and the estate line below it, and unlike every other control on the screen; every other screen's app
 * bar carries the same red button (ResidentAppBar), so it is reachable from anywhere. The Quick actions
 * then take the whole width, two rows of three (§2.3, quickActionLayout).
 *
 * Under the fidelity harness the board is drawn as it was: the amber button 20 above the tab bar (the
 * board's 106 − the tab bar's 86), its rings 6 and 13, and the grid kept clear of it.
 *
 * IT DOES NOT SCROLL WHEN IT FITS (M50 §6.8). The body kept the board's 108 below the Quick actions — the room the
 * board's floating button needs — on a phone, which draws no floating button: the page was taller than the screen with
 * nothing in it, and scrolling it took the Panic button off the screen. Live, the body ends 20 below the tiles, and the
 * page scrolls only when what is on it is taller than the screen (a gate request, a long notice, the largest text). And
 * THE PANIC BUTTON IS PINNED: it sits over the page rather than in it, so even a page that scrolls never moves it.
 */

import { useState } from 'react'
import { RefreshControl, ScrollView, StyleSheet, View, useWindowDimensions } from 'react-native'
import { router } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import * as Haptics from 'expo-haptics'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import Svg, { Circle, Defs, G, LinearGradient, Path, Rect, Stop } from 'react-native-svg'
import { Icon, Pressable, SkeletonLines, Text, Tour, a, c, isFidelity, sh, useEstateClockLine, useFidelityReady, type IconName } from '@gs/core'
import { flags } from '@gs/core/lib'
import { fill, ink, space } from '@/design/tokens'
import { useAccess } from '@/features/access/useAccess'
import { TOUR_CARDS, useFirstRunTour } from '@/tour/tour'
import { attentionWords, useBookingAttention } from '@/features/amenities/useAmenities'
import { MissingDetailsCard } from '../components/MissingDetailsCard'
import { MyRequestsCard } from '../components/MyRequestsCard'
import { tabEntry } from '@/features/maintenance/requestList'
import { RestoredCard } from '../components/RestoredCard'
import { homeActionsFor, type HomeActionKey } from '../homeActions'
import { useHome } from '../useHome'
import { quickActionWidth } from '../quickActionLayout'

const go = (path: string) => router.push(path as never)
const tab = (path: string) => router.navigate(path as never)

/*
 * Every quick action there is. Which of them this person sees, and in what order, is their level's
 * (homeActions.ts): a Dependent or a Helper gets their e-pass first and nothing the server would refuse.
 * Bookings sit behind a flag; with it off the tile is not a dead one: it becomes the estate's meetings —
 * ALL of them, upcoming first. ("Your community" is gone from Home: the client's one change, M42 E4.)
 */
const ACTION: Record<HomeActionKey, { label: string; icon: IconName; open: () => void }> = {
  epass: { label: 'My e-Pass', icon: 'qa-tile-4', open: () => go('/visitors/pass/e-pass') },
  invite: { label: 'Invite visitor', icon: 'qa-tile-4', open: () => go('/visitors/invite') },
  incident: { label: 'Report incident', icon: 'qa-tile-3', open: () => go(tabEntry('report').list) },
  maintenance: { label: 'Maintenance', icon: 'rp-icon', open: () => go(tabEntry('request').list) },
  dues: { label: 'Pay dues', icon: 'tab-card', open: () => tab('/(tabs)/dues') },
  amenity: { label: 'Book amenity', icon: 'qa-tile-5', open: () => go('/amenities') },
  meetings: { label: 'Meetings', icon: 'input', open: () => go('/meetings') },
  notices: { label: 'Notices', icon: 'set-icon-2', open: () => tab('/(tabs)/notices') },
}

const clock = (seconds: number) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`

function Hero({ height }: { height: number }) {
  return (
    <Svg width="100%" height={height} viewBox="0 0 397 206" preserveAspectRatio="xMidYMax slice" fill="none" style={s.heroSvg}>
      <Rect width="397" height="206" fill={c('navy-700')} stroke="none" />
      <Rect width="397" height="206" fill="url(#skyfade)" stroke="none" />
      <Defs>
        <LinearGradient x1="0" y1="0" x2="0" y2="1" id="skyfade">
          <Stop offset="0%" stopColor={c('navy-600')} stopOpacity={1} />
          <Stop offset="100%" stopColor={c('navy-800')} stopOpacity={1} />
        </LinearGradient>
        <LinearGradient x1="0" y1="0" x2="0" y2="1" id="scrim">
          <Stop offset="0%" stopColor={c('navy-900')} stopOpacity={0} />
          <Stop offset="100%" stopColor={c('navy-900')} stopOpacity={0.78} />
        </LinearGradient>
      </Defs>
      {/* The board's sun sits top right — exactly where the Panic button now sits below the status bar (M18E), which
          left a dim half-disc showing under the button on every phone (found on the emulator, M19 §10.3). The fidelity
          build keeps it, as drawn; the app does not. */}
      {isFidelity ? <Circle cx="322" cy="92" r="22" fill={c('amber-500')} stroke="none" opacity={0.85} /> : null}
      <Path d="M0 112 Q60 90 130 108 T260 104 T397 112 V206 H0 Z" fill={c('navy-800')} stroke="none" opacity={0.55} />
      <Path d="M24 196 C25 172 24 140 24 112" fill="none" stroke={c('navy-900')} strokeWidth={3.5} strokeLinecap="round" opacity={0.9} />
      <Path d="M24 112 C11 101 7 92 9 84 M24 112 C15 97 15 86 20 76 M24 112 C24 93 26 82 28 70 M24 112 C33 97 33 86 29 76 M24 112 C37 101 41 92 39 84" fill="none" stroke={c('navy-900')} strokeWidth={3} strokeLinecap="round" opacity={0.9} />
      <G opacity={0.92}>
        <Rect x="140" y="128" width="9" height="60" fill={c('navy-900')} stroke="none" />
        <Rect x="248" y="128" width="9" height="60" fill={c('navy-900')} stroke="none" />
        <Rect x="140" y="128" width="117" height="6" fill={c('navy-900')} stroke="none" />
        <Rect x="128" y="184" width="141" height="9" fill={c('navy-900')} stroke="none" />
        <Path d="M160 128 L175 101 L190 128 Z" fill={c('navy-900')} stroke="none" />
        <Path d="M206 128 L223 97 L240 128 Z" fill={c('navy-900')} stroke="none" />
        <Rect x="168" y="110" width="14" height="18" fill={c('navy-800')} stroke="none" />
        <Rect x="214" y="106" width="16" height="22" fill={c('navy-800')} stroke="none" />
      </G>
      <Rect width="397" height="206" fill="url(#scrim)" stroke="none" />
    </Svg>
  )
}

function CardProblem({ what, onRetry }: { what: string; onRetry: () => void }) {
  return (
    <Pressable onPress={onRetry} role="button" aria-label={`${what} could not be loaded. Try again`} style={s.cardPlain}>
      <Text face="Inter-SemiBold" size={12} color="slate-600">{`${what} could not be loaded.`} <Text face="Inter-Bold" color="amber-700">{'Try again'}</Text></Text>
    </Pressable>
  )
}

export default function HomeScreen() {
  const insets = useSafeAreaInsets()
  const m = useHome()
  const { access } = useAccess()
  const tour = useFirstRunTour()
  const actions = homeActionsFor(access, flags.amenityBookings || isFidelity).map((key) => ({ key, ...ACTION[key] }))
  // A booking with something to pay (M12 §2.14): a count on the "Book amenity" tile. Not read where there is no tile.
  const attention = useBookingAttention(isFidelity || !actions.some((action) => action.key === 'amenity'))
  useFidelityReady(true)

  // Measured, so three tiles fit a row on a narrow phone as well as on the board's own 397 points.
  const qaWidth = quickActionWidth(useWindowDimensions().width, isFidelity ? 'board' : 'live')

  // The board's hero is 206 tall INCLUDING its 54px status bar; the real inset takes the 54's place.
  const heroHeight = insets.top + 152
  const estateClock = useEstateClockLine()

  /*
   * THE HEADER CLEARS THE STATUS BAR AND THE CAMERA ON EVERY PHONE (M57 §2). Live, the hero is at least the board's height
   * but never less than the phone's own top inset, one spacing step, and its four lines: at the largest text it grows
   * downwards instead of sliding the greeting up under the status bar. The board's own layout stays in the fidelity build.
   *
   * THE PANIC BUTTON SITS LEVEL WITH THE DATE AND THE ESTATE TIME (M57 §1.3): the same button and the same right margin,
   * moved down into the thumb's reach. It is centred on the measured date block, which keeps the button's width clear on
   * its right and is never shorter than the button, so the button can neither cover nor push any of the four lines.
   */
  const [heroBox, setHeroBox] = useState(heroHeight)
  const [textTop, setTextTop] = useState(0)
  const [dateBand, setDateBand] = useState<{ y: number; h: number } | null>(null)
  const [panicWidth, setPanicWidth] = useState(0)
  const panicY = dateBand === null ? heroHeight - HERO_BOTTOM - PANIC_HEIGHT : textTop + dateBand.y + (dateBand.h - PANIC_HEIGHT) / 2
  const besidePanic = PANIC_RIGHT + panicWidth + space[12] - HERO_SIDE

  const panic = () => {
    void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Heavy).catch(() => undefined)
    go('/panic')
  }

  // M50 §6.8: the page moves only when what is on it is taller than the screen.
  const [viewport, setViewport] = useState(0)
  const [content, setContent] = useState(0)
  const scrolls = isFidelity || content > viewport + 1

  return (
    <View style={s.screen}>
      <StatusBar style="light" />
      <ScrollView
        contentContainerStyle={s.grow}
        scrollEnabled={scrolls}
        alwaysBounceVertical={scrolls}
        overScrollMode={scrolls ? 'auto' : 'never'}
        onLayout={(e) => setViewport(e.nativeEvent.layout.height)}
        onContentSizeChange={(_, height) => setContent(height)}
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('amber-500')} progressViewOffset={insets.top} />}
      >
        <View
          style={[s.hero, isFidelity ? { height: heroHeight } : [s.heroLive, { minHeight: heroHeight, paddingTop: insets.top + space[8] }]]}
          onLayout={isFidelity ? undefined : (e) => setHeroBox(e.nativeEvent.layout.height)}
        >
          <Hero height={isFidelity ? heroHeight : heroBox} />
          <View style={isFidelity ? s.heroText : s.heroTextLive} onLayout={isFidelity ? undefined : (e) => setTextTop(e.nativeEvent.layout.y)}>
            <Text face="Poppins-SemiBold" size={20} color="white" role="heading">{m.greeting}</Text>
            {m.place ? <Text face="Inter-SemiBold" size={12.5} color="amber-500" style={{ marginTop: 3 }}>{m.place}</Text> : null}
            <View
              style={isFidelity ? null : [s.dateBlock, { paddingRight: Math.max(0, besidePanic) }]}
              onLayout={isFidelity ? undefined : (e) => setDateBand({ y: e.nativeEvent.layout.y, h: e.nativeEvent.layout.height })}
            >
              <Text face="Inter-Regular" size={11.5} style={{ color: c('white'), marginTop: 2 }}>{m.today}</Text>
              {/* M19A §5.1 — the estate's clock, quietly, on a phone set to another zone; nothing at the estate. */}
              {estateClock ? <Text face="Inter-SemiBold" size={11.5} color="white" style={{ marginTop: 2 }}>{estateClock}</Text> : null}
            </View>
          </View>
        </View>

        <View style={isFidelity ? s.homeBody : s.homeBodyLive}>
          {/* M18H §2.3 — "Your account is back", once, after "Restore my account". */}
          <RestoredCard />
          {/* M18C §5 — an older record is asked for the phone or email it lacks: once a launch, never blocking anything. */}
          <MissingDetailsCard />
          {/* (The "This estate is on Jamaica time" card is gone: it is not in the design, M46 §5.2. The estate's clock
              line under the date says the same thing, and Language & accessibility keeps the sentence.) */}
          {/* Someone is at the gate, waiting on this household: above everything, until it is answered or times out. */}
          {m.approvals.map((p) => (
            <Pressable key={p.id} onPress={() => go(`/visitors/approval/${p.id}`)} role="button" aria-label={`${p.visitor} is at the gate. Answer now, ${clock(p.secondsLeft)} left`} style={s.gateCard}>
              <View style={s.gateIcon}><Icon name="shield-alert" size={20} color="white" /></View>
              <View style={s.fill}>
                <Text face="Inter-Bold" size={13.5} color="navy-900">{`${p.visitor} is at the gate`}</Text>
                <Text face="Inter-Regular" size={11.5} lh={1.4} color="slate-600" style={{ marginTop: 1 }}>{p.detail ? `${p.detail} · ` : ''}{`answer within ${clock(p.secondsLeft)}`}</Text>
              </View>
              <View style={[s.pillBtn, { backgroundColor: c('amber-700') }]}><Text face="Poppins-Bold" size={12} color="white" numberOfLines={1}>{'Answer'}</Text></View>
            </Pressable>
          ))}

          {!m.online ? (
            <View style={s.cardPlain} role="status">
              <Text face="Inter-SemiBold" size={12} lh={1.45} color="slate-600">
                {`No connection: showing what this phone last saw.${m.waitingToSync > 0 ? ` ${m.waitingToSync} change${m.waitingToSync === 1 ? '' : 's'} will be sent when you're back online.` : ''} The panic button still works.`}
              </Text>
            </View>
          ) : null}

          {m.balanceState === 'loading' ? <View style={s.card}><SkeletonLines tone="light" lines={2} style={s.fill} /></View> : null}
          {m.balanceState === 'error' ? <CardProblem what="Your balance" onRetry={m.refresh} /> : null}
          {m.balanceState === 'ready' && m.balance ? (
            <Pressable onPress={() => (m.balance?.opens === 'pay' ? go('/dues/pay') : tab('/(tabs)/dues'))} role="button" aria-label={`${m.balance.title}: ${m.balance.amount}. ${m.balance.detail}. ${m.balance.action}`} style={s.card}>
              <View style={s.balanceIcon}><Icon name="balance-icon" size={20} color="amber-700" /></View>
              <View style={s.fill}>
                <Text face="Inter-SemiBold" size={11} color="slate-500">{m.balance.title}</Text>
                <Text face="Inter-Bold" size={16} color="navy-900" style={{ marginTop: 1 }}>{m.balance.amount}</Text>
                {m.balance.detail ? <Text face="Inter-SemiBold" size={11} color={m.balance.tone} style={{ marginTop: 1 }}>{m.balance.detail}</Text> : null}
                {m.balance.stale ? <Text face="Inter-Regular" size={10.5} color="slate-500" style={{ marginTop: 2 }}>{m.balance.stale}</Text> : null}
              </View>
              {/* M46 §5.3, "follow the design": money owed is the board's own solid "Pay now"; "View", which the board does
                  not draw, takes the Quick action tiles' pale blue and blue. Background and text colour only. */}
              <View style={[s.pillBtn, m.balance.state === 'owing' || m.balance.state === 'overdue' ? s.pillOwed : s.pillView]}>
                <Text face="Poppins-Bold" size={12} color={m.balance.state === 'owing' || m.balance.state === 'overdue' ? 'white' : ink.action} numberOfLines={1}>{m.balance.action}</Text>
              </View>
            </Pressable>
          ) : null}

          {m.expectedState === 'hidden' ? null : (
            <View style={s.card2}>
              <View style={s.cardHead}>
                <View style={s.shrink}><Text face="Inter-Bold" size={13.5} color="navy-900">{'Expected today'}</Text></View>
                <Pressable onPress={() => tab('/(tabs)/visitors')} role="link" aria-label="See all visitors" hitSlop={14}>
                  <Text face="Inter-SemiBold" size={11.5} color="amber-700">{'See all'}</Text>
                </Pressable>
              </View>
              {m.expectedState === 'loading' ? <SkeletonLines tone="light" lines={2} style={{ marginTop: 12 }} /> : null}
              {m.expectedState === 'error' ? (
                <Pressable onPress={m.refresh} role="button" aria-label="Visitors could not be loaded. Try again" style={{ marginTop: 10 }}>
                  <Text face="Inter-Regular" size={12} color="slate-500">{'Visitors could not be loaded. '}<Text face="Inter-Bold" color="amber-700">{'Try again'}</Text></Text>
                </Pressable>
              ) : null}
              {m.expectedState === 'ready' && m.expected.length === 0 ? (
                <Pressable onPress={() => go('/visitors/invite')} role="button" aria-label="Nobody is expected today. Invite a visitor" style={{ marginTop: 10 }}>
                  <Text face="Inter-Regular" size={12} lh={1.45} color="slate-500">{'Nobody is expected today. '}<Text face="Inter-Bold" color="amber-700">{'Invite a visitor'}</Text></Text>
                </Pressable>
              ) : null}
              {m.expected.map((v, i) => (
                <Pressable key={v.id} onPress={() => go(`/visitors/pass/${v.id}`)} role="button" aria-label={`${v.name}, ${v.detail}, ${v.when}`} style={i === 0 ? s.visitorRowFirst : s.visitorRow}>
                  <View style={s.avatar}><Text face="Inter-Bold" size={11.5} color="navy-700">{v.initials}</Text></View>
                  <View style={s.fill}>
                    <Text face="Inter-SemiBold" size={12.5} color="navy-900" numberOfLines={1}>{v.name}</Text>
                    <Text face="Inter-Regular" size={10.5} color="slate-500" numberOfLines={1}>{v.detail}</Text>
                  </View>
                  <View style={s.timeChip}><Text face="Inter-Bold" size={10} color="navy-700" numberOfLines={1}>{v.when}</Text></View>
                </Pressable>
              ))}
            </View>
          )}

          {m.noticeState === 'ready' && m.notice ? (
            <Pressable onPress={() => go(`/notices/${m.notice?.id}`)} role="button" aria-label={`Notice: ${m.notice.title}`} style={s.card3}>
              <Icon name="card" size={17} color={m.notice.urgent ? 'amber-700' : 'navy-600'} style={s.noticeIcon} />
              <View style={s.shrink}>
                <Text face="Inter-Bold" size={12.5} color="navy-900">{m.notice.title}</Text>
                <Text face="Inter-Regular" size={11.5} lh={1.4} color="slate-500" numberOfLines={3} style={{ marginTop: 2 }}>{m.notice.body}</Text>
                <Text face="Inter-SemiBold" size={10} color="slate-500" style={{ marginTop: 5 }}>{m.notice.posted}</Text>
              </View>
            </Pressable>
          ) : null}
          {m.noticeState === 'loading' ? <View style={s.card3}><SkeletonLines tone="light" lines={2} style={s.fill} /></View> : null}

          {/* Where a household follows what it has sent the estate (M98): hidden until it has filed something. */}
          <MyRequestsCard />

          <Text face="Inter-Bold" size={13.5} color="navy-900" style={{ marginTop: 11 }}>{'Quick actions'}</Text>
          <View style={isFidelity ? s.qaGrid : s.qaGridLive}>
            {actions.map((action) => {
              const count = action.key === 'amenity' ? attention : 0
              return (
                <Pressable key={action.label} onPress={action.open} role="button" aria-label={count > 0 ? `${action.label}. ${attentionWords(count)}` : action.label} style={[s.qaItem, { width: qaWidth }]}>
                  <View style={[s.qaTile, isFidelity ? null : s.qaTileSolid]}>
                    <Icon name={action.icon} size={21} color={isFidelity ? 'navy-600' : 'white'} />
                    {count > 0 ? <View style={s.qaBadge}><Text face="Inter-Bold" size={10} color="navy-900">{String(count)}</Text></View> : null}
                  </View>
                  <Text face="Inter-SemiBold" size={isFidelity ? 9.5 : 11} color="navy-800" align="center">{action.label}</Text>
                </Pressable>
              )
            })}
          </View>
        </View>
      </ScrollView>

      {/* Pinned over the page, level with the date and the estate time (M57 §1.3): no scroll ever takes it away (M50 §6.8). */}
      {isFidelity ? null : (
        <Pressable onPress={panic} onLayout={(e) => setPanicWidth(e.nativeEvent.layout.width)} role="button" aria-label="Panic alert" accessibilityHint="Opens the panic alert. It works with no connection." hitSlop={6} style={({ pressed }) => [s.panicTop, { top: panicY }, pressed ? s.panicPressed : null]}>
          <Icon name="shield-alert" size={18} color="white" />
          <Text face="Poppins-Bold" size={13} color="white">{'Panic'}</Text>
        </Pressable>
      )}

      {isFidelity ? (
        <>
          <View style={s.fabRing} />
          <View style={s.fabRing2} />
          <Pressable onPress={panic} role="button" aria-label="Panic alert" accessibilityHint="Opens the panic alert. It works with no connection." style={s.fab}>
            <Icon name="shield-alert" size={24} color="navy-900" />
          </Pressable>
        </>
      ) : null}

      {/* M32 F11: the first-run tour, once — never over somebody waiting at the gate for this household's answer. */}
      {!isFidelity && tour.open && m.approvals.length === 0 ? <Tour cards={TOUR_CARDS} open onClose={tour.close} /> : null}
    </View>
  )
}

const CARD_SHADOW = sh([{ x: 0, y: 8, blur: 20, spread: 0, token: 'navy-800', opacity: 0.07 }])

/** The board's header text inset at each side, and below the last line. */
const HERO_SIDE = 22
const HERO_BOTTOM = 20

/** The live panic button: its height and its right margin, unchanged (M12 §2.2). */
const PANIC_HEIGHT = 40
const PANIC_RIGHT = 16

const s = StyleSheet.create({
  screen: { backgroundColor: c('white'), overflow: 'hidden', flex: 1 },
  grow: { flexGrow: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  heroSvg: { position: 'absolute', top: 0, left: 0, width: '100%', height: '100%', overflow: 'hidden' },
  heroText: { position: 'absolute', right: HERO_SIDE, bottom: HERO_BOTTOM, left: HERO_SIDE, zIndex: 5 },
  hero: { overflow: 'hidden' },
  // Live (M57 §2): the four lines in the page's flow, at the bottom of a header that grows rather than overlap the inset.
  heroLive: { justifyContent: 'flex-end' },
  heroTextLive: { paddingHorizontal: HERO_SIDE, paddingBottom: HERO_BOTTOM, zIndex: 5 },
  // Never shorter than the panic button beside it, so the button never reaches the lines above (M57 §1.3).
  dateBlock: { minHeight: PANIC_HEIGHT, justifyContent: 'center' },
  balanceIcon: { width: 42, height: 42, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-100'), borderRadius: 12 },
  pillBtn: { flexShrink: 0, borderRadius: 11, paddingHorizontal: 14, paddingVertical: 9 },
  // The board's .pill-btn, in its own blue (#1974D2).
  pillOwed: { backgroundColor: fill.primary },
  // The board's .qa-tile: its background (#E7F0FB), and its glyph's blue (#1974D2) for the words.
  pillView: { backgroundColor: fill.tint },
  card: { flexDirection: 'row', alignItems: 'center', backgroundColor: c('white'), borderRadius: 16, borderWidth: 1, borderColor: c('navy-100'), padding: 13, gap: 12, boxShadow: CARD_SHADOW },
  cardPlain: { backgroundColor: c('navy-100'), borderRadius: 12, paddingHorizontal: 12, paddingVertical: 10, marginBottom: 11 },
  // Someone at the gate needs an answer: urgent, so amber, and never the red that means an open panic (M98, 12 Oct 2026).
  gateCard: { flexDirection: 'row', alignItems: 'center', backgroundColor: c('amber-100'), borderRadius: 16, borderWidth: 1, borderColor: c('amber-700'), padding: 13, gap: 12, marginBottom: 11 },
  gateIcon: { width: 42, height: 42, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-700'), borderRadius: 12 },
  cardHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  avatar: { width: 34, height: 34, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('navy-100'), borderRadius: 17 },
  timeChip: { backgroundColor: c('navy-100'), flexShrink: 0, borderRadius: 20, paddingHorizontal: 9, paddingVertical: 4 },
  visitorRowFirst: { flexDirection: 'row', alignItems: 'center', paddingVertical: 7, gap: 10, marginTop: 11 },
  visitorRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 7, gap: 10 },
  card2: { backgroundColor: c('white'), borderRadius: 16, borderWidth: 1, borderColor: c('navy-100'), padding: 13, boxShadow: CARD_SHADOW, marginTop: 11 },
  noticeIcon: { width: 17, height: 17, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 2 },
  card3: { flexDirection: 'row', backgroundColor: c('white'), borderRadius: 16, borderWidth: 1, borderColor: c('navy-100'), padding: 13, gap: 11, boxShadow: CARD_SHADOW, marginTop: 11 },
  qaTile: { width: 48, height: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('navy-100'), borderRadius: 14 },
  // The user, 8 Oct 2026: every Quick action tile in the solid blue of "Pay $2,000", with a white glyph. The board
  // draws them pale; the board itself (fidelity) keeps its own, and RELEASE_NOTES lists the difference as accepted.
  qaTileSolid: { backgroundColor: fill.primary },
  // The board's three columns leave the right 72 clear for the panic button.
  // No width here: it is measured per screen (quickActionLayout.ts), because a percentage does not
  // scale the GAPS with it — which is what wrapped the third tile on a 360-point phone.
  qaItem: { flexDirection: 'column', alignItems: 'center', gap: 6 },
  qaGrid: { flexDirection: 'row', flexWrap: 'wrap', paddingRight: 72, columnGap: 9, rowGap: 9, marginTop: 10 },
  // Live: the whole width, rows centred — three tiles and two gaps fill a phone's row exactly (quickActionLayout).
  qaGridLive: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'center', columnGap: 9, rowGap: 12, marginTop: 10 },
  // Something to pay on a booking (M12 §2.14): amber with navy figures — red is kept for what is urgent.
  qaBadge: { position: 'absolute', top: -5, right: -7, minWidth: 18, height: 18, borderRadius: 9, paddingHorizontal: 5, alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), borderWidth: 2, borderColor: c('white') },
  // The live panic button (M12 §2.2): red, labelled, at the right of the header, level with the date (M57 §1.3).
  panicTop: { position: 'absolute', right: PANIC_RIGHT, zIndex: 10, flexDirection: 'row', alignItems: 'center', gap: 6, height: PANIC_HEIGHT, paddingHorizontal: 14, borderRadius: 20, backgroundColor: c('red-700'), borderWidth: 1.5, borderColor: a('white', 0.85), boxShadow: sh([{ x: 0, y: 6, blur: 14, spread: 0, token: 'red-700', opacity: 0.4 }]) },
  panicPressed: { opacity: 0.85 },
  homeBody: { paddingTop: 14, paddingBottom: 108, paddingHorizontal: 18, marginTop: -14 },
  // Live (M50 §6.8): no floating button below, so not its 108 — the board's own 20 under the Quick actions. And one
  // spacing step between the header and the first card (M57 §1.4): the board draws the card flush against the header.
  homeBodyLive: { paddingTop: space[12], paddingBottom: 20, paddingHorizontal: 18 },
  fabRing: { position: 'absolute', right: 4, bottom: 6, width: 84, height: 84, opacity: 0.16, borderRadius: 42, borderWidth: 1, borderColor: c('amber-500'), pointerEvents: 'none' },
  fabRing2: { position: 'absolute', right: 11, bottom: 13, width: 70, height: 70, opacity: 0.32, borderRadius: 35, borderWidth: 1, borderColor: c('amber-500'), pointerEvents: 'none' },
  fab: { position: 'absolute', right: 18, bottom: 20, width: 56, height: 56, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), zIndex: 24, borderRadius: 28, boxShadow: sh([{ x: 0, y: 10, blur: 22, spread: 0, token: 'amber-600', opacity: 0.45 }]) },
})
