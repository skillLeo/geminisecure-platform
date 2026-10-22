/**
 * DEV ONLY — the gallery (M45 §3): every Resident screen, in every state it can draw, with the design's own content,
 * so the emulator capture loop and a person can open each one straight away.
 *
 *   phone (gallery build)  gs://gallery lists them; gs://gallery?open=resident-app-07&state=design opens one —
 *                          the capture script (tools/design/capture-native.mjs) drives it with adb and waits for the
 *                          "[gallery] ready <id>" log line before it photographs the screen
 *   web (fidelity build)   /gallery lists them; a row is a fresh page load at <route>?fidelity=<id>, the harness's own URL
 *
 * Reached only through app/gallery.tsx, which requires this file in a gallery or fidelity build and nowhere else.
 */

import { useEffect } from 'react'
import { Platform, ScrollView, StyleSheet, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Pressable, Text, enterFidelity } from '@gs/core'
import { corner, fill, font, ink, line, space, stroke } from '@/design/tokens'
import { ALL_SCREENS, DESIGN_SCREENS, EXTRA_SCREENS, GALLERY_MARKER, STATES, type GalleryScreen as Entry } from './screens'

function open(entry: Entry, state: string | null) {
  if (Platform.OS === 'web') {
    const sep = entry.route.includes('?') ? '&' : '?'
    window.location.assign(`${entry.route}${sep}fidelity=${entry.id}${state && state !== 'design' ? `&state=${state}` : ''}`)
    return
  }
  enterFidelity(entry.id, state && state !== 'design' ? state : null)
  router.replace(entry.route as never)
}

function Row({ entry }: { entry: Entry }) {
  return (
    <View style={s.row}>
      <Pressable onPress={() => open(entry, 'design')} role="button" aria-label={`Open ${entry.title}`} style={s.rowMain}>
        <Text {...font.inter600_13} color={ink.heading}>{entry.title}</Text>
        <Text {...font.inter400_10_5} color={ink.secondary}>{`${entry.id} · ${entry.route}`}</Text>
      </Pressable>
      <View style={s.states}>
        {STATES.map((state) => {
          const wired = entry.states.includes(state)
          return (
            <Pressable key={state} disabled={!wired} onPress={() => open(entry, state)} role="button" aria-label={`${entry.title}, ${state}`} style={[s.chip, wired ? s.chipOn : null]}>
              <Text {...font.inter600_10} color={wired ? ink.onTint : ink.faint}>{state}</Text>
            </Pressable>
          )
        })}
      </View>
    </View>
  )
}

export default function GalleryScreen() {
  const insets = useSafeAreaInsets()
  const { open: openId, state } = useLocalSearchParams<{ open?: string; state?: string }>()

  // A deep link names one screen: open it at once (the capture loop); with none, leave the mode and list them all.
  useEffect(() => {
    const entry = openId ? ALL_SCREENS.find((e) => e.id === openId) : undefined
    if (entry) open(entry, state ?? null)
    else enterFidelity(null)
  }, [openId, state])

  return (
    <ScrollView style={s.page} contentContainerStyle={{ paddingTop: insets.top + space[16], paddingBottom: insets.bottom + space[24], paddingHorizontal: space[20] }}>
      <Text {...font.poppins600_16_5} color={ink.heading}>{'Design gallery'}</Text>
      <Text {...font.inter400_11} color={ink.secondary} style={{ marginTop: space[4] }}>{`${GALLERY_MARKER} · dev only · ${ALL_SCREENS.length} screens · states not yet wired are grey`}</Text>
      <Text {...font.inter700_11_5_ls0_2} color={ink.secondary} style={s.section}>{`DESIGN SCREENS · ${DESIGN_SCREENS.length}`}</Text>
      {DESIGN_SCREENS.map((e) => <Row key={e.id} entry={e} />)}
      <Text {...font.inter700_11_5_ls0_2} color={ink.secondary} style={s.section}>{`NO DESIGN COUNTERPART (M43 §4) · ${EXTRA_SCREENS.length}`}</Text>
      {EXTRA_SCREENS.map((e) => <Row key={e.id} entry={e} />)}
    </ScrollView>
  )
}

const s = StyleSheet.create({
  page: { flex: 1, backgroundColor: fill.surface },
  section: { marginTop: space[22], marginBottom: space[6] },
  row: { paddingVertical: space[10], borderBottomWidth: stroke[1], borderBottomColor: line.hairline, gap: space[6] },
  rowMain: { gap: space[2] },
  states: { flexDirection: 'row', flexWrap: 'wrap', gap: space[6] },
  chip: { paddingVertical: space[3], paddingHorizontal: space[8], borderRadius: corner[20], backgroundColor: fill.surface, borderWidth: stroke[1], borderColor: line.hairline },
  chipOn: { backgroundColor: fill.tint },
})
