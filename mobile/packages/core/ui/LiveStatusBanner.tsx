/**
 * THE HONESTY LINE (DESIGN §8.5, rule 7) — a screen never looks live when it is not.
 *
 * It says nothing while live is working: the socket is up, every channel is subscribed, catch-up is done,
 * the relay says it is keeping up and its pulse is under 25 s old. The SAME conditions on both apps (and on
 * the web): revision 1 checked the pulse on the guard app only, and the resident app looked live with the
 * relay dead. Otherwise, after 3 s, one slim permanent line:
 *
 *   paused    "Live updates paused · updated 2 minutes ago · Refresh"      the socket is down, or catch-up is failing
 *   delayed   "Live updates are delayed · updated 2 minutes ago · Refresh" the relay is behind, or its pulse stopped
 *   refused   "Some live updates are not available to you"           a channel this person was given is refused
 *
 * Refresh really runs a catch-up, and reconnects if the socket is down (gate:dead-controls). "updated" is the
 * last moment the phone knew it was up to date, in the SITE's zone (gate:one-clock). OFFLINE it says nothing:
 * the app's own offline line already says so, and two lines about one fact is one too many. In fidelity mode
 * it is never drawn.
 *
 *   <LiveStatusBanner tone="dark" />                       a line where it is put
 *   <LiveStatusBanner tone="light">{navigator}</LiveStatusBanner>
 *       above everything: while the line is up it takes the top safe area, and the screens below get zero — the
 *       way the resident's demonstration strip does it. With nothing to say the screens get the phone's own inset.
 *
 * WRAPPING, IT IS ONE SHAPE WHETHER OR NOT THE LINE IS UP (M13, 26 Sep). It used to hand the navigator back bare
 * while live and inside two Views while not, and React takes a different element in that place for a different
 * screen: every change of status threw the whole navigator away and built a new one, and every open screen lost
 * what was on it. While the phones never opened the socket (phone D-M167) the status never changed and nobody saw
 * it. With the socket open it changes whenever the app goes into the background, and choosing a photo does that:
 * a resident came back from the photo picker to an empty maintenance request, the photo and the words gone. Now
 * only the line comes and goes, and the inset the screens are given with it.
 */

import { useContext } from 'react'
import { StyleSheet, View } from 'react-native'
import { SafeAreaInsetsContext } from 'react-native-safe-area-context'
import { isFidelity, now } from '../fidelity'
import { sinceWords, useMinute } from '../lib/siteTime'
import { refreshLive } from '../live/client'
import { useLiveStatus, type LiveBanner } from '../live/lifecycle'
import { palette, type ColorToken } from '../theme/colors'
import { Pressable } from './Pressable'
import { Text } from './Text'
import type { Tone } from './States'

type Ink = { strip: ColorToken; words: ColorToken; action: ColorToken }

// Every pair is measured by tools/gates/contrast.mjs: amber-500 and text-2 on surface-800 (guard); amber-700 on
// amber-100 and slate-600 on navy-100 (resident). Amber means degraded, grey means "not yours" — never green.
const INK: Record<Tone, Record<Exclude<LiveBanner, 'none'>, Ink>> = {
  dark: {
    paused: { strip: 'surface-800', words: 'amber-500', action: 'amber-500' },
    delayed: { strip: 'surface-800', words: 'amber-500', action: 'amber-500' },
    refused: { strip: 'surface-800', words: 'text-2', action: 'text-2' },
  },
  light: {
    paused: { strip: 'amber-100', words: 'amber-700', action: 'amber-700' },
    delayed: { strip: 'amber-100', words: 'amber-700', action: 'amber-700' },
    refused: { strip: 'navy-100', words: 'slate-600', action: 'slate-600' },
  },
}

const HEADLINE: Record<Exclude<LiveBanner, 'none'>, string> = {
  paused: 'Live updates paused',
  delayed: 'Live updates are delayed',
  refused: 'Some live updates are not available to you',
}

export function LiveStatusBanner({ tone, children }: { tone: Tone; children?: React.ReactNode }) {
  const status = useLiveStatus()
  const insets = useContext(SafeAreaInsetsContext)
  const wraps = children !== undefined

  // The fidelity harness measures the boards bare, and `isFidelity` is fixed for the life of the app, so this
  // shape never changes under a running navigator.
  if (isFidelity) return wraps ? <>{children}</> : null

  const strip = status.banner === 'none' ? null : <Line tone={tone} banner={status.banner} updatedAt={status.updatedAt} top={wraps ? insets?.top ?? 0 : 0} />

  if (!wraps) return strip
  // The same three elements in the same places either way (see WRAPPING, above): only the line and the inset change.
  return (
    <View style={s.fill}>
      {strip}
      <SafeAreaInsetsContext.Provider value={strip !== null && insets ? { ...insets, top: 0 } : insets}>
        <View style={s.fill}>{children}</View>
      </SafeAreaInsetsContext.Provider>
    </View>
  )
}

function Line({ tone, banner, updatedAt, top }: { tone: Tone; banner: Exclude<LiveBanner, 'none'>; updatedAt: number | null; top: number }) {
  const ink = INK[tone][banner]
  // "updated 2 minutes ago" — a recent moment, in words that belong to no clock (M19A §4.1), redrawn as minutes turn.
  useMinute()
  const updated = updatedAt !== null ? sinceWords(updatedAt, now()) : ''
  const line = `${HEADLINE[banner]}${updated ? ` · updated ${updated}` : ''}`
  const canRefresh = banner !== 'refused'

  return (
    <View style={[s.strip, { backgroundColor: palette[ink.strip], paddingTop: top + 6 }]} role="status" aria-live="polite" aria-label={line}>
      <Text face="Inter-SemiBold" size={12} lh={1.35} color={ink.words} style={s.words}>
        {line}
      </Text>
      {canRefresh ? (
        <Pressable onPress={refreshLive} role="button" aria-label="Refresh live updates" hitSlop={12} style={s.refresh}>
          <Text face="Inter-Bold" size={12} color={ink.action}>
            {'Refresh'}
          </Text>
        </Pressable>
      ) : null}
    </View>
  )
}

const s = StyleSheet.create({
  fill: { flex: 1 },
  strip: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 16, paddingBottom: 6 },
  words: { flex: 1, flexShrink: 1 },
  refresh: { minHeight: 44, justifyContent: 'center', paddingLeft: 6 },
})
