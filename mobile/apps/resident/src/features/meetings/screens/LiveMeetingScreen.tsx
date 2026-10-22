/**
 * resident-app-23 · Live Meeting — board 06 Amenities Live Meeting and Ballot. Styles are the board's own,
 * copied from the lifted file (src/lifted/ResidentApp23.tsx). All behaviour lives in useLiveMeeting().
 *
 * The board draws a meeting held INSIDE the app: video tiles, a head-count and a running clock, "Now
 * discussing", a live motion vote with a tally, and mute / raise-hand / camera / chat controls. The server
 * has none of it — no streaming, chat, hand-raise or live-poll endpoint — only the estate's own link
 * (`virtual_link`). So:
 *   - every one of those is drawn ONLY for the fidelity measurement, as plain views with nothing to press;
 *   - live, this screen says where the meeting stands, says so if it will be RECORDED, and has one job:
 *     "Join the meeting", which opens the estate's https link in the browser or meeting app — from half an
 *     hour before the start, while the meeting is scheduled, with a connection. Otherwise the button is
 *     greyed and the reason is written under it;
 *   - a motion is never voted on here: a vote is a ballot, and ballots have their own screens and rules.
 */

import { RefreshControl, ScrollView, StyleSheet, View, useWindowDimensions } from 'react-native'
import Svg, { Path } from 'react-native-svg'
import { router, useLocalSearchParams } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { EmptyState, ErrorView, Gradient, Icon, Pressable, ResidentButton, SkeletonLines, Text, a, aboveGestureBar, belowStatusBar, c, useFidelityReady } from '@gs/core'
import { useLiveMeeting, type LiveModel } from '../useMeetings'

type Board = NonNullable<LiveModel['board']>

/** The board's room — tiles, discussion, motion, controls. Fidelity build only: the server has nothing behind any of it. */
function BoardRoom({ board }: { board: Board }) {
  // Two tiles a row on any phone: the board's 178.5 is its 397-point screen less the 16s and the 8 between — at 390 two
  // of those no longer fit, the grid fell to one column and pushed the motion card under the controls (walk, 7 Oct).
  const { width } = useWindowDimensions()
  const tileWidth = (width - 16 * 2 - 8) / 2
  return (
    <>
      <View style={s.videoGrid}>
        {board.tiles.map((tile) => (
          <View key={tile.initials} style={[tile.speaking ? s.vtileSpeaking : s.vtile, { width: tileWidth }]}>
            <View style={s.vAvatar}>
              <Text face="Inter-Bold" size={12} color="white">{tile.initials}</Text>
            </View>
            <View style={s.vName}>
              <Text face="Inter-SemiBold" size={10} color="white">{tile.name}</Text>
            </View>
            {tile.muted ? (
              <View style={s.vMute}>
                <Icon name="v-mute" size={10} color="white" style={s.vMuteSvg} />
              </View>
            ) : null}
          </View>
        ))}
      </View>
      <View style={s.agendaBanner}>
        <Icon name="agenda-banner" size={15} color="amber-500" style={s.agendaBannerSvg} />
        <View style={s.shrink}>
          <Text face="Inter-SemiBold" size={11} color="white">{board.discussing}</Text>
        </View>
      </View>
      <View style={s.motionCard}>
        <Text face="Inter-Bold" size={9.5} color="amber-700">{board.motion.tag}</Text>
        <Text face="Inter-Bold" size={13.5} color="navy-900" style={{ marginTop: 3 }}>{board.motion.title}</Text>
        <View style={s.voteBtns}>
          {board.motion.options.map((option, i) => (
            <View key={option} style={i === 0 ? s.vbtnOn : s.vbtn}>
              <Text face="Inter-Bold" size={12} color={i === 0 ? 'white' : 'navy-700'} align="center">{option}</Text>
            </View>
          ))}
        </View>
        <View style={s.tallyRow}>
          {board.motion.tally.map((line) => (
            <View key={line} style={s.shrink}>
              <Text face="Inter-SemiBold" size={10.5} color="slate-500">{line}</Text>
            </View>
          ))}
        </View>
      </View>
    </>
  )
}

function BoardControls() {
  return (
    <Gradient spec={{ angle: 0, stops: [{ token: 'navy-900', at: 0, opacity: 0.9 }, { token: 'navy-900', at: 1, opacity: 0 }] }} style={s.meetControls}>
      <View style={s.ctrlBtnOff}>
        <Svg width={20} height={20} viewBox="0 0 24 24" fill="none" style={s.ctrlBtnSvg}>
          <Path d="M3 3l18 18M9 9v3a3 3 0 0 0 4.7 2.5M15 9.3V6a3 3 0 0 0-5.7-1.3" fill="none" stroke={a('white', 0.4)} strokeWidth={1.8} strokeLinecap="round" />
          <Path d="M5 11a7 7 0 0 0 9.7 6.4M19 11a7 7 0 0 1-1 3.6" fill="none" stroke={a('white', 0.4)} strokeWidth={1.8} strokeLinecap="round" />
        </Svg>
      </View>
      <View style={s.ctrlBtn}><Icon name="ctrl-btn" size={20} color="white" style={s.ctrlBtnSvg} /></View>
      <View style={s.ctrlBtn}><Icon name="ctrl-btn-2" size={20} color="white" style={s.ctrlBtnSvg} /></View>
      <View style={s.ctrlBtn}><Icon name="tab-messages" size={20} color="white" style={s.ctrlBtnSvg} /></View>
      <View style={s.ctrlBtnLeave}><Icon name="side-btn-7" size={20} color="navy-900" style={s.ctrlBtnSvg} /></View>
    </Gradient>
  )
}

/** What the server really has: where the meeting stands, and the estate's link. */
function Room({ m }: { m: LiveModel }) {
  const join = m.join
  return (
    <>
      {m.room ? (
        <View style={s.panel}>
          <View style={s.vAvatar}><Icon name="ctrl-btn-2" size={18} color="white" /></View>
          <Text face="Inter-Bold" size={13.5} color="white" align="center" style={{ marginTop: 12 }} role="heading">{m.room.title}</Text>
          <Text face="Inter-Regular" size={11.5} lh={1.55} align="center" style={{ color: c('white'), marginTop: 6 }}>{m.room.body}</Text>
        </View>
      ) : null}

      {/* Said BEFORE the resident joins. */}
      {m.recording ? (
        <View style={s.agendaBanner} role="note">
          <Icon name="privacy-note" size={15} color="amber-500" style={s.agendaBannerSvg} />
          <View style={s.shrink}>
            <Text face="Inter-SemiBold" size={11} lh={1.5} color="white">{m.recording}</Text>
          </View>
        </View>
      ) : null}

      <View style={s.motionCard}>
        <Text face="Inter-Bold" size={9.5} color="amber-700" role="status">{m.stateLabel}</Text>
        <Text face="Inter-Bold" size={13.5} color="navy-900" style={{ marginTop: 3 }}>{m.meeting?.title ?? ''}</Text>
        <Text face="Inter-Regular" size={11.5} lh={1.5} color="slate-600" style={{ marginTop: 4 }}>{m.stateLine}</Text>
        <Text face="Inter-SemiBold" size={10.5} lh={1.5} color="slate-500" style={{ marginTop: 4 }}>{m.where}</Text>

        {m.ended ? null : join.canJoin ? (
          <>
            <ResidentButton label="Join the meeting" onPress={m.openLink} loading={m.opening} style={{ marginTop: 12 }} />
            <Text face="Inter-Regular" size={11} lh={1.5} color="slate-500" align="center" style={{ marginTop: 8 }}>{`Opens ${join.host} outside this app.`}</Text>
          </>
        ) : (
          <>
            <ResidentButton label="Join the meeting" onPress={m.openLink} disabled disabledReason={join.reason} style={{ marginTop: 12 }} />
            <Text face="Inter-Regular" size={11} lh={1.5} color="slate-500" align="center" style={{ marginTop: 8 }}>{join.reason}</Text>
          </>
        )}
        {m.problem ? <Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-700" align="center" style={{ marginTop: 8 }} role="alert">{m.problem}</Text> : null}
      </View>

      {m.minutes ? (
        <View style={s.quiet} role="note">
          <Text face="Inter-Regular" size={11} lh={1.5} style={{ color: c('white') }}>{m.minutes}</Text>
        </View>
      ) : null}
      {m.staleNote ? (
        <View style={s.quiet} role="status">
          <Text face="Inter-Regular" size={11} lh={1.5} style={{ color: c('white') }}>{m.staleNote}</Text>
        </View>
      ) : null}
    </>
  )
}

export default function LiveMeetingScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const m = useLiveMeeting(typeof id === 'string' ? id : undefined)
  const insets = useSafeAreaInsets()
  useFidelityReady(true)

  const close = () => {
    if (m.board) return
    if (router.canGoBack()) router.back()
    else router.replace((m.status === 'missing' ? '/(tabs)' : m.lobbyRoute) as never)
  }

  return (
    <View style={s.screen}>
      <StatusBar style="light" />
      <View style={s.meetScreen}>
        {/* The board's 60 is its 54px status bar + 6. */}
        <View style={[s.meetTop, { paddingTop: insets.top + belowStatusBar(6) }]}>
          <Pressable onPress={close} role="button" aria-label="Back to the meeting details" hitSlop={6} style={({ pressed }) => [s.sideBtn, pressed ? s.pressed : null]}>
            <Icon name="side-btn-5" size={32} color="white" style={s.sideBtnSvg} />
          </Pressable>
          <View style={m.board ? s.shrink : s.meetTopInfo}>
            <Text face="Inter-Bold" size={12.5} color="white" align="center" numberOfLines={1} role="heading">{m.title}</Text>
            {m.sub ? <Text face="Inter-Bold" size={9.5} color="amber-500" align="center" numberOfLines={1} style={{ marginTop: 1 }}>{m.sub}</Text> : null}
          </View>
          {/* The board's second button (meeting notes) has nothing behind it: fidelity only. Live, a spacer keeps the title centred. */}
          {m.board ? <View style={s.sideBtn}><Icon name="side-btn-10" size={32} color="white" style={s.sideBtnSvg} /></View> : <View style={s.sideSpacer} />}
        </View>

        {m.board ? (
          <>
            <BoardRoom board={m.board} />
            <BoardControls />
          </>
        ) : (
          <ScrollView
            style={s.fill}
            contentContainerStyle={[s.liveBody, { paddingBottom: aboveGestureBar(26, insets.bottom) }]}
            refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('white')} />}
          >
            {m.status === 'loading' ? <View style={s.panel} role="progressbar" aria-label="Loading the meeting"><SkeletonLines tone="dark" lines={4} style={s.stretch} /></View> : null}
            {m.status === 'error' ? <ErrorView tone="dark" message={m.errorMessage ?? 'The meeting could not be loaded.'} onRetry={m.refresh} /> : null}
            {m.status === 'denied' ? <EmptyState tone="dark" icon="privacy-note" title="Not available for this account" body={m.errorMessage ?? 'Your account cannot see the estate’s meetings.'} /> : null}
            {m.status === 'missing' ? (
              <EmptyState
                tone="dark"
                icon="input"
                title={m.askedForNext ? 'No meeting is scheduled' : "We can't find that meeting"}
                body={m.stale ? `It is not among the meetings saved on this phone. Pull down to check again${m.online ? '' : ' once you are connected'}.` : m.askedForNext ? 'When your estate calls a meeting, it will appear here.' : 'It is not among your estate’s meetings. It may be for another phase, or older than six months.'}
                action={{ label: 'Back', onPress: close }}
              />
            ) : null}
            {m.meeting ? <Room m={m} /> : null}
          </ScrollView>
        )}
      </View>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('white'), overflow: 'hidden', flex: 1 },
  meetScreen: { width: '100%', height: '100%', backgroundColor: c('navy-900') },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  stretch: { alignSelf: 'stretch' },
  pressed: { opacity: 0.7 },
  sideBtnSvg: { overflow: 'hidden' },
  sideBtn: { width: 32, height: 32, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: a('white', 0.14), borderRadius: 16 },
  sideSpacer: { width: 32, height: 32 },
  meetTopInfo: { flex: 1, paddingHorizontal: 10 },
  meetTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 16 },
  vAvatar: { width: 38, height: 38, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('navy-600'), borderRadius: 19 },
  vName: { position: 'absolute', bottom: 8, left: 9, backgroundColor: a('black', 0.35), borderRadius: 20, paddingHorizontal: 7, paddingVertical: 3 },
  vtileSpeaking: { aspectRatio: 1.15, flexDirection: 'column', alignItems: 'center', justifyContent: 'center', backgroundColor: c('navy-800'), borderRadius: 14, borderWidth: 2, borderColor: c('amber-500') },
  vtile: { aspectRatio: 1.15, flexDirection: 'column', alignItems: 'center', justifyContent: 'center', backgroundColor: c('navy-800'), borderRadius: 14 },
  vMuteSvg: { width: 10, height: 10, overflow: 'hidden' },
  vMute: { position: 'absolute', right: 8, bottom: 8, width: 20, height: 20, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: a('black', 0.4), borderRadius: 10 },
  videoGrid: { flexDirection: 'row', flexWrap: 'wrap', paddingHorizontal: 16, paddingVertical: 14, gap: 8 },
  agendaBannerSvg: { width: 15, height: 15, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  agendaBanner: { flexDirection: 'row', alignItems: 'center', backgroundColor: a('amber-500', 0.14), borderRadius: 12, borderWidth: 1, borderColor: a('amber-500', 0.3), paddingHorizontal: 12, paddingVertical: 10, gap: 9, marginTop: 2, marginLeft: 16, marginRight: 16 },
  vbtnOn: { flex: 1, backgroundColor: c('navy-600'), borderRadius: 11, paddingHorizontal: 4, paddingVertical: 10 },
  vbtn: { flex: 1, backgroundColor: c('navy-100'), borderRadius: 11, paddingHorizontal: 4, paddingVertical: 10 },
  voteBtns: { flexDirection: 'row', gap: 8, marginTop: 11 },
  tallyRow: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 10 },
  motionCard: { backgroundColor: c('white'), borderRadius: 16, padding: 14, marginTop: 12, marginLeft: 16, marginRight: 16 },
  ctrlBtnSvg: { width: 20, height: 20, overflow: 'hidden' },
  ctrlBtnOff: { width: 48, height: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: a('white', 0.06), borderRadius: 24 },
  ctrlBtn: { width: 48, height: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: a('white', 0.14), borderRadius: 24 },
  ctrlBtnLeave: { width: 48, height: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), borderRadius: 24 },
  meetControls: { position: 'absolute', right: 0, bottom: 0, left: 0, flexDirection: 'row', justifyContent: 'center', paddingTop: 14, paddingBottom: 26, paddingHorizontal: 16, gap: 12 },
  liveBody: { paddingTop: 14 },
  panel: { alignItems: 'center', backgroundColor: c('navy-800'), borderRadius: 14, paddingHorizontal: 18, paddingVertical: 22, marginHorizontal: 16, marginBottom: 12 },
  quiet: { marginTop: 12, marginHorizontal: 18 },
})
