/**
 * The Patrol tab — board guard-app-07: the tour's progress, the next checkpoint, and the scanner.
 * plus the states no board draws: loading, error, access refused, no shift, no checkpoints set up,
 * no signal (cached tour + an honest note), a scan pending sync / confirmed / not accepted on its row.
 *
 * Layout values are the board's own, lifted (see src/lifted/GuardApp07.tsx). The lifted .appbar is
 * <AppBar>, the .statusbar is the OS's, and the navigator draws the tab bar. The lifted .card is drawn
 * as slices — one per FlatList row — so the list virtualises and still reads as the board's one card.
 * All behaviour lives in usePatrol(); this file only draws it and navigates.
 *
 * The radar panel is the board's static illustration (vector shapes, no positions) until the office places the
 * site's checkpoints on the map (M11 §6); then it is the tour's map — each placed checkpoint in its row's colour and
 * this guard's post's geofence. Neither claims a distance: the tab never asks where the guard is.
 */

import { useCallback, useState } from 'react'
import { FlatList, RefreshControl, StyleSheet, View } from 'react-native'
import { router, useFocusEffect } from 'expo-router'
import { AppBar, EmptyState, ErrorView, GuardButton, Icon, Pressable, SiteMap, SkeletonLines, Text, a, c, sh, useFidelityReady } from '@gs/core'
import { INFO } from '@/info/topics'
import { TagScanner } from '../components/TagScanner'
import type { PatrolRow } from '../patrolLogic'
import { usePatrol, type PatrolModel } from '../usePatrol'

const open = (path: string) => router.push(path as never)

function Dot({ state }: { state: PatrolRow['state'] }) {
  if (state === 'scanned') return <View style={s.cpDot}><Icon name="check" size={13} color="white" /></View>
  if (state === 'next') return <View style={s.cpDot2}><Icon name="cp-dot" size={12} color="g-navy-900" /></View>
  if (state === 'refused') return <View style={s.cpDotRefused}><Icon name="qa-tile-3" size={13} color="white" /></View>
  return <View style={s.cpDot3} />
}

function CheckpointRow({ row, first, last, onPress }: { row: PatrolRow; first: boolean; last: boolean; onPress: () => void }) {
  const muted = row.state === 'upcoming'
  return (
    <View style={[s.slice, first ? s.sliceFirst : null, last ? s.sliceLast : null]}>
      <Pressable
        onPress={onPress}
        role="button"
        aria-label={`Checkpoint ${row.position}, ${row.label}${row.detail ? `. ${row.detail}` : ''}`}
        accessibilityHint="Opens the scanner for this checkpoint"
        hitSlop={{ top: 3, bottom: 3 }}
        style={({ pressed }) => [last ? s.cpRow2 : s.cpRow, { opacity: pressed ? 0.7 : 1 }]}
      >
        <Dot state={row.state} />
        <View style={s.grow}>
          <Text face="Inter-SemiBold" size={12.5} color={muted ? 'text-3' : 'white'}>{row.label}</Text>
          {row.detail ? (
            <Text face="Inter-Regular" size={10} color={row.state === 'refused' ? 'red-500' : row.sync === 'pending' || row.sync === 'syncing' ? 'amber-500' : 'text-3'} style={{ marginTop: 1 }}>{row.detail}</Text>
          ) : null}
        </View>
      </Pressable>
    </View>
  )
}

function Header({ m }: { m: PatrolModel }) {
  return (
    <>
      {/* Persistent for as long as it is true — a banner, never a toast. */}
      {m.online ? null : (
        <Pressable onPress={() => open('/sync-queue')} role="button" aria-label="No connection. Scans are saved on this phone." accessibilityHint="Opens the sync queue" style={({ pressed }) => [s.banner, s.bannerAmber, { opacity: pressed ? 0.8 : 1 }]}>
          <Icon name="offline-note" size={17} color="amber-500" />
          <View style={s.grow}>
            <Text face="Inter-Bold" size={12} color="amber-500">{'No connection · scans are saved on this phone'}</Text>
            <Text face="Inter-Regular" size={10} color="text-2" style={{ marginTop: 1 }}>{m.staleNote ?? 'They are sent by themselves when signal returns'}</Text>
          </View>
          <Icon name="chevron-right" size={17} color="amber-500" />
        </Pressable>
      )}
      {m.refusedCount > 0 ? (
        <View style={[s.banner, s.bannerRed]} role="alert">
          <Icon name="qa-tile-3" size={17} color="red-500" />
          <View style={s.grow}>
            <Text face="Inter-Bold" size={12} color="red-500">{m.refusedCount === 1 ? '1 scan was not accepted' : `${m.refusedCount} scans were not accepted`}</Text>
            <Text face="Inter-Regular" size={10} color="text-2" style={{ marginTop: 1 }}>{"The server's reason is on the checkpoint below. Scan its tag again at the checkpoint."}</Text>
          </View>
        </View>
      ) : null}

      <View style={s.patrolHero}>
        <View style={s.phTop}>
          <View style={s.shrink}>
            <Text face="Poppins-SemiBold" size={14.5} color="white" numberOfLines={1}>{m.title}</Text>
          </View>
          {m.badge ? (
            <View style={s.phBadge}>
              <Text face="Inter-Bold" size={9} color="g-navy-900">{m.badge}</Text>
            </View>
          ) : null}
        </View>
        <View style={s.progBar} role="progressbar" aria-label={m.countLabel} aria-valuemin={0} aria-valuemax={100} aria-valuenow={m.percent}>
          <View style={[s.progBarI, { width: `${m.percent}%` }]} />
        </View>
        <View style={s.phMeta}>
          <View style={s.shrink}>
            <Text face="Inter-SemiBold" size={10.5} color="text-3">{m.countLabel}</Text>
          </View>
          <View style={s.shrink}>
            <Text face="Inter-SemiBold" size={10.5} color="text-3">{m.metaRight}</Text>
          </View>
        </View>
      </View>

      {m.map ? (
        <View style={s.mapWrap}>
          <SiteMap tone="dark" kind="plan" pins={m.map.pins} fence={m.map.fence} offline={!m.online} height={170} label={`The tour's checkpoints on the map${m.radarLabel ? `. ${m.radarLabel}` : ''}`} />
          {m.radarLabel || m.mapNote ? (
            <Text face="Inter-SemiBold" size={10.5} lh={1.45} color="text-3" style={s.mapNote}>
              {[m.radarLabel, m.mapNote].filter(Boolean).join(' · ')}
            </Text>
          ) : null}
        </View>
      ) : (
        <View style={s.radarVisual} aria-hidden={m.radarLabel === ''}>
          <View style={s.ring} />
          <View style={s.ring2} />
          <View style={s.pin} />
          {m.radarLabel ? (
            <View style={s.glabel}>
              <Text face="Inter-SemiBold" size={9.5} color="text-2">{m.radarLabel}</Text>
            </View>
          ) : null}
        </View>
      )}

      <GuardButton label="Scan checkpoint" onPress={() => m.openScanner()} style={{ marginTop: 14 }} />

      <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={s.sectionTitle}>{'Tour progress'}</Text>
    </>
  )
}

export default function PatrolScreen() {
  const m = usePatrol()
  const [focused, setFocused] = useState(true)
  const [torch, setTorch] = useState(false)
  useFidelityReady(m.status !== 'loading')

  // The camera runs only while this tab is in front: leaving it closes the scanner.
  const closeScanner = m.closeScanner
  useFocusEffect(
    useCallback(() => {
      setFocused(true)
      return () => {
        setFocused(false)
        setTorch(false)
        closeScanner()
      }
    }, [closeScanner]),
  )

  const close = () => {
    setTorch(false)
    m.closeScanner()
  }

  const ready = m.status === 'ready'
  const lastIndex = m.rows.length - 1

  return (
    <View style={s.screen}>
      {/* While the scanner dialog is open, what is under it is not there for a screen reader either. */}
      <View style={s.grow} aria-hidden={m.scanner !== null}>
        <AppBar title="Patrol" info={INFO.checkpoints} left={{ icon: 'chevron-left', label: 'Back to Home', onPress: () => router.navigate('/(tabs)' as never) }} />
        <FlatList
          data={ready ? m.rows : []}
          keyExtractor={(row) => row.key}
          renderItem={({ item, index }) => <CheckpointRow row={item} first={index === 0} last={index === lastIndex} onPress={() => m.openScanner(item.id)} />}
          contentContainerStyle={s.bodyPad}
          showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('amber-500')} />}
          ListHeaderComponent={
            ready ? (
              <Header m={m} />
            ) : m.status === 'loading' ? (
              <>
                <View style={s.patrolHero}><SkeletonLines tone="dark" lines={2} /></View>
                <View style={[s.patrolHero, { marginTop: 16 }]}><SkeletonLines tone="dark" lines={6} /></View>
              </>
            ) : m.status === 'error' ? (
              <ErrorView tone="dark" message={m.errorMessage ?? 'The checkpoints could not be loaded.'} onRetry={m.refresh} />
            ) : m.status === 'denied' ? (
              <EmptyState tone="dark" icon="rh-icon" title="Patrol is not available on this handset" body="This handset is not allowed to read this site’s checkpoints. Contact your supervisor." />
            ) : m.status === 'no_shift' ? (
              <EmptyState tone="dark" icon="tab-patrol" title="No shift, no tour" body="Checkpoints belong to the site you are rostered at. Nothing is running or rostered ahead for you right now." action={{ label: 'See open shifts', onPress: () => open('/leave') }} />
            ) : (
              <EmptyState tone="dark" icon="tab-patrol" title="No checkpoints at this site yet" body="The office has not set up a patrol tour for this site. When it does, the checkpoints appear here: pull down to check again." />
            )
          }
          ListFooterComponent={
            ready ? (
              <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center" style={s.footNote}>
                {`${m.online && m.staleNote ? `${m.staleNote}. ` : ''}Walking the tour in a different order? Press a checkpoint to scan that one.`}
              </Text>
            ) : null
          }
        />
      </View>

      {m.scanner ? (
        <TagScanner m={m.scanner} online={m.online} active={focused} torch={torch} onTorch={setTorch} onTag={m.onTag} onClose={close} onAgain={m.scanAgain} onNext={m.scanNext} />
      ) : null}
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  grow: { flex: 1 },
  shrink: { flexShrink: 1 },
  banner: { flexDirection: 'row', alignItems: 'center', borderRadius: 14, borderWidth: 1, paddingHorizontal: 13, paddingVertical: 11, gap: 10, marginBottom: 14 },
  bannerAmber: { backgroundColor: a('amber-500', 0.12), borderColor: a('amber-500', 0.32) },
  bannerRed: { backgroundColor: a('red-500', 0.1), borderColor: a('red-500', 0.3) },
  phBadge: { backgroundColor: c('amber-500'), flexShrink: 0, borderRadius: 20, paddingHorizontal: 9, paddingVertical: 4 },
  phTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 },
  progBarI: { height: '100%', backgroundColor: c('amber-500'), borderRadius: 5 },
  progBar: { height: 6, backgroundColor: c('surface-700'), overflow: 'hidden', borderRadius: 5, marginTop: 10 },
  phMeta: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 8 },
  patrolHero: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 15 },
  ring: { position: 'absolute', top: '50%', left: '50%', width: 130, height: 130, borderStyle: 'dashed', opacity: 0.4, borderRadius: 65, borderWidth: 1, borderColor: c('navy-500'), transform: [{ translateX: '-50%' }, { translateY: '-50%' }] },
  ring2: { position: 'absolute', top: '50%', left: '50%', width: 80, height: 80, borderStyle: 'dashed', opacity: 0.7, borderRadius: 40, borderWidth: 1, borderColor: c('navy-500'), transform: [{ translateX: '-50%' }, { translateY: '-50%' }] },
  pin: { position: 'absolute', top: '50%', left: '50%', width: 14, height: 14, backgroundColor: c('amber-500'), borderRadius: 7, boxShadow: sh([{ x: 0, y: 0, blur: 0, spread: 6, token: 'amber-500', opacity: 0.22 }]), transform: [{ translateX: '-50%' }, { translateY: '-50%' }] },
  glabel: { position: 'absolute', bottom: 9, left: 9, backgroundColor: a('black', 0.35), borderRadius: 20, paddingHorizontal: 8, paddingVertical: 3 },
  radarVisual: { height: 110, backgroundColor: c('surface-700'), overflow: 'hidden', borderRadius: 14, marginTop: 16 },
  mapWrap: { marginTop: 16 },
  mapNote: { marginTop: 8, marginHorizontal: 2 },
  sectionTitle: { marginTop: 18, marginLeft: 2, marginRight: 2 },
  // The lifted .card (padding 4 / 13, margin 8 / 12, 1px border-700, radius 16), cut into one slice per row.
  slice: { backgroundColor: c('surface-800'), borderLeftWidth: 1, borderRightWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13 },
  sliceFirst: { borderTopWidth: 1, borderTopLeftRadius: 16, borderTopRightRadius: 16, paddingTop: 4, marginTop: 8 },
  sliceLast: { borderBottomWidth: 1, borderBottomLeftRadius: 16, borderBottomRightRadius: 16, paddingBottom: 4, marginBottom: 12 },
  cpRow: { flexDirection: 'row', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: c('border-700'), paddingVertical: 9, gap: 11 },
  cpRow2: { flexDirection: 'row', alignItems: 'center', paddingVertical: 9, gap: 11 },
  cpDot: { width: 24, height: 24, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('navy-600'), borderRadius: 12 },
  cpDot2: { width: 24, height: 24, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), borderRadius: 12 },
  cpDot3: { width: 24, height: 24, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: c('border-600') },
  cpDotRefused: { width: 24, height: 24, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('red-500'), borderRadius: 12 },
  footNote: { paddingHorizontal: 10 },
})
