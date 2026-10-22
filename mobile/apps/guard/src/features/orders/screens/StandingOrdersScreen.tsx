/**
 * Standing orders — board guard-app-11: the version banner, the orders as headed sections,
 * and the acknowledgement. Plus the states the board does not draw: loading, no shift,
 * another site's orders (403), error, offline (cached text + an honest note), an
 * acknowledgement waiting to sync, one the server refused, and "You are up to date".
 *
 * SCROLL-TO-END IS ENFORCED here and decided in the hook: the box cannot be ticked and the
 * button cannot send until the end of the text has been on screen; then the box must be
 * ticked; then the button sends. The scroll view is re-created whenever WHAT is to be
 * acknowledged changes, so a revised version is always read from its top.
 *
 * Layout values are the board's own, lifted (src/lifted/GuardApp11.tsx).
 * All behaviour lives in useStandingOrders(); this file only draws it.
 */

import { useCallback, useRef, useState } from 'react'
import { RefreshControl, ScrollView, StyleSheet, View, type LayoutChangeEvent, type NativeScrollEvent, type NativeSyntheticEvent } from 'react-native'
import { router } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { AppBar, EmptyState, ErrorView, GuardButton, Icon, Pressable, SkeletonLines, Text, a, c, useFidelityReady } from '@gs/core'
import type { OrderEntry } from '../ordersText'
import { SCROLL_FIRST, TICK_FIRST, useStandingOrders, type DrawnSection } from '../useStandingOrders'

/** How close to the very end counts as the end — a thumb in a glove does not land on the last pixel. */
const END_SLACK = 16

function Entry({ entry, first }: { entry: OrderEntry; first: boolean }) {
  return (
    <View style={first ? s.orderItem : s.orderItem2}>
      {entry.kind === 'numbered' ? (
        <Text face="Inter-SemiBold" size={12} lh={1.55} color="text-3" style={s.number}>{`${entry.number}.`}</Text>
      ) : entry.kind === 'para' ? null : (
        <View style={s.dot} />
      )}
      <View style={s.shrink}>
        <Text face="Inter-Regular" size={12} lh={1.55} color="text-2">
          {entry.kind === 'labelled' ? <Text face="Inter-SemiBold" color="white">{`${entry.label}  `}</Text> : null}
          {entry.text}
        </Text>
      </View>
    </View>
  )
}

function Section({ section, first }: { section: DrawnSection; first: boolean }) {
  return (
    <View style={first ? s.orderSec : s.orderSec2}>
      {section.heading === null ? null : <Text face="Poppins-SemiBold" size={13} color="amber-500" role="heading">{section.heading}</Text>}
      {section.caption === null ? null : <Text face="Inter-Medium" size={10} color="text-3" style={{ marginTop: 2 }}>{section.caption}</Text>}
      {section.entries.map((entry, i) => (
        <Entry key={i} entry={entry} first={i === 0} />
      ))}
    </View>
  )
}

function Note({ icon, title, detail }: { icon: 'check' | 'offline-note'; title: string; detail: string }) {
  return (
    <View style={s.ackRow}>
      <Icon name={icon} size={19} color="amber-500" style={{ marginTop: 1 }} />
      <View style={s.shrink}>
        <Text face="Inter-SemiBold" size={12.5} color="white">{title}</Text>
        <Text face="Inter-Regular" size={11.5} lh={1.5} color="text-2" style={{ marginTop: 2 }}>{detail}</Text>
      </View>
    </View>
  )
}

export default function StandingOrdersScreen() {
  const m = useStandingOrders(() => router.dismissTo('/(tabs)'))
  useFidelityReady(m.status !== 'loading')
  const insets = useSafeAreaInsets()
  const [scrollHint, setScrollHint] = useState(false)

  const needed = m.status === 'ready' && m.ack.state === 'needed'
  const scrollKey = `${m.status}:${m.versionKey}`

  // What the scroll view last reported — kept per scrollKey, so a re-created view never inherits the old one's numbers.
  const seen = useRef({ key: scrollKey, viewport: 0, content: 0, offset: 0 })
  const armed = useRef(false)
  armed.current = needed && !m.readToEnd
  const { markReadToEnd } = m

  const report = useCallback(
    (next: { viewport?: number; content?: number; offset?: number }) => {
      if (seen.current.key !== scrollKey) seen.current = { key: scrollKey, viewport: 0, content: 0, offset: 0 }
      Object.assign(seen.current, next)
      const { viewport, content, offset } = seen.current
      if (!armed.current || viewport <= 0 || content <= 0) return
      if (offset + viewport >= content - END_SLACK) {
        setScrollHint(false)
        markReadToEnd()
      }
    },
    [scrollKey, markReadToEnd],
  )

  const onScroll = (e: NativeSyntheticEvent<NativeScrollEvent>) => {
    const n = e.nativeEvent
    report({ viewport: n.layoutMeasurement.height, content: n.contentSize.height, offset: n.contentOffset.y })
  }
  const onLayout = (e: LayoutChangeEvent) => report({ viewport: e.nativeEvent.layout.height })

  const tick = () => (m.readToEnd ? m.toggleChecked() : setScrollHint(true))
  const hint = !m.readToEnd && scrollHint ? SCROLL_FIRST : m.tickMissing ? TICK_FIRST : null

  return (
    <View style={s.screen}>
      <AppBar title="Standing orders" />
      <ScrollView
        key={scrollKey}
        // Opaque, in the screen's own colour: a scroller is its own layer, and text on a see-through layer is smoothed differently.
        style={s.scroll}
        contentContainerStyle={[s.bodyPad, { paddingBottom: 20 + insets.bottom }]}
        onScroll={onScroll}
        scrollEventThrottle={32}
        onLayout={onLayout}
        onContentSizeChange={(_, height) => report({ content: height })}
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('amber-500')} />}
      >
        {m.status === 'loading' ? <SkeletonLines tone="dark" lines={8} style={{ marginTop: 12 }} /> : null}
        {m.status === 'error' ? <ErrorView tone="dark" message={m.errorMessage ?? 'The standing orders could not be loaded.'} onRetry={m.refresh} /> : null}
        {m.status === 'denied' ? (
          <EmptyState tone="dark" icon="order-banner" title="Not available on this handset" body={m.errorMessage ?? 'A handset reads the orders for its own site. If your posting has changed, ask your supervisor to check this handset.'} action={{ label: 'Back to your shift', onPress: () => router.dismissTo('/(tabs)') }} />
        ) : null}
        {m.status === 'no_shift' ? (
          <EmptyState tone="dark" icon="order-banner" title="No post, no orders to show" body="Standing orders belong to a post. When a shift is rostered to you, that post's orders appear here to read and acknowledge." action={{ label: 'Back to your shift', onPress: () => router.dismissTo('/(tabs)') }} />
        ) : null}

        {m.status === 'ready' ? (
          <>
            {m.refusal ? (
              <View style={s.refusal} role="alert">
                <Icon name="qa-tile-3" size={19} color="red-500" style={{ marginTop: 1 }} />
                <View style={s.shrink}>
                  <Text face="Inter-Bold" size={12.5} color="red-500">{m.refusal.title}</Text>
                  <Text face="Inter-Regular" size={11} lh={1.5} color="text-2" style={{ marginTop: 3 }}>{m.refusal.body}</Text>
                </View>
              </View>
            ) : null}

            {m.bannerTitle ? (
              <Pressable
                onPress={() => router.push('/orders/history' as never)}
                role="button"
                aria-label={`${m.bannerTitle}. ${m.bannerDetail}. Opens your order history`}
                style={({ pressed }) => [s.versionBanner, pressed ? { opacity: 0.85 } : null]}
              >
                <Icon name="order-banner" size={18} color="amber-500" style={s.versionBannerSvg} />
                <View style={s.shrink}>
                  <Text face="Inter-Bold" size={12} color="white">{m.bannerTitle}</Text>
                  <Text face="Inter-Regular" size={10} color="text-2" style={{ marginTop: 1 }}>{m.bannerDetail}</Text>
                </View>
              </Pressable>
            ) : (
              <EmptyState tone="dark" icon="order-banner" title="No standing orders yet" body="Nothing has been published for this post or for the company. Pull down to check again, or ask your supervisor." />
            )}

            {m.stale ? (
              <View style={s.staleNote}>
                <Icon name="offline-note" size={14} color="text-3" style={{ marginTop: 1 }} />
                <View style={s.shrink}>
                  <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3">{m.stale}</Text>
                </View>
              </View>
            ) : null}

            {m.sections.map((section, i) => (
              <Section key={section.key} section={section} first={i === 0} />
            ))}

            {!m.bannerTitle ? null : m.ack.state === 'needed' ? (
              <>
                <Pressable
                  onPress={tick}
                  role="checkbox"
                  aria-checked={m.checked}
                  aria-disabled={!m.readToEnd}
                  aria-label="I have read and understood the current standing orders for this post."
                  accessibilityHint={m.readToEnd ? undefined : SCROLL_FIRST}
                  hitSlop={{ top: 8, bottom: 8 }}
                  style={[s.ackRow, m.readToEnd ? null : { opacity: 0.5 }]}
                >
                  <View style={[s.cbox, m.checked ? s.cboxChecked : m.tickMissing ? { borderColor: c('red-500') } : null]}>
                    {m.checked ? <Icon name="check" size={12} color="g-navy-900" /> : null}
                  </View>
                  <View style={s.shrink}>
                    <Text face="Inter-Regular" size={11.5} lh={1.5} color="text-2">{'I have read and understood the current standing orders for this post.'}</Text>
                  </View>
                </Pressable>
                {hint ? (
                  <Text face="Inter-Medium" size={10.5} lh={1.5} color="red-500" role="alert" style={{ marginTop: 6, marginLeft: 29 }}>{hint}</Text>
                ) : null}
                {m.readToEnd ? (
                  <GuardButton label="Acknowledge & continue" onPress={m.acknowledge} loading={m.busy} style={{ marginTop: 16 }} />
                ) : (
                  <GuardButton label="Acknowledge & continue" onPress={m.acknowledge} disabled disabledReason={SCROLL_FIRST} style={{ marginTop: 16 }} />
                )}
              </>
            ) : m.ack.state === 'queued' ? (
              <Note icon="offline-note" title={m.ack.title} detail={m.ack.detail} />
            ) : (
              <Note icon="check" title="You are up to date" detail={m.ack.detail} />
            )}
          </>
        ) : null}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: c('surface-900') },
  scroll: { flex: 1, backgroundColor: c('surface-900') },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  shrink: { flexShrink: 1 },
  versionBanner: { flexDirection: 'row', alignItems: 'center', backgroundColor: a('amber-500', 0.1), borderRadius: 14, borderWidth: 1, borderColor: a('amber-500', 0.28), paddingHorizontal: 13, paddingVertical: 12, gap: 11 },
  versionBannerSvg: { width: 18, height: 18, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  refusal: { flexDirection: 'row', gap: 11, padding: 13, borderRadius: 14, borderWidth: 1, backgroundColor: a('red-500', 0.1), borderColor: a('red-500', 0.3), marginBottom: 14 },
  staleNote: { flexDirection: 'row', gap: 9, marginTop: 12 },
  dot: { width: 5, height: 5, flexGrow: 0, flexShrink: 0, backgroundColor: c('text-3'), borderRadius: 2.5, marginTop: 7 },
  number: { flexGrow: 0, flexShrink: 0, minWidth: 14 },
  orderItem: { flexDirection: 'row', paddingVertical: 6, gap: 9, marginTop: 8 },
  orderItem2: { flexDirection: 'row', paddingVertical: 6, gap: 9 },
  orderSec: { marginTop: 16 },
  orderSec2: { marginTop: 14 },
  cbox: { width: 19, height: 19, flexGrow: 0, flexShrink: 0, borderRadius: 6, borderWidth: 2, borderColor: c('border-600'), marginTop: 1 },
  cboxChecked: { backgroundColor: c('amber-500'), borderColor: c('amber-500'), alignItems: 'center', justifyContent: 'center' },
  ackRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, marginTop: 16 },
})
