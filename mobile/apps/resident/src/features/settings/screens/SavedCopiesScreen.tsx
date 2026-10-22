/**
 * Privacy & data → Saved copies (M12 §2.11: "Saved copies … open what they describe"). No board draws it.
 *
 * What the app keeps on this phone so it opens with no connection — the same copies "Clear data saved on this
 * phone" removes — each named for what it is, with its size. What is waiting to be sent is NOT a copy and is not
 * here: it has its own screen, and clearing never touches it. The clear is offered here too, asked first.
 */

import { useCallback, useMemo, useState } from 'react'
import { ScrollView, StyleSheet, View } from 'react-native'
import { EmptyState, ResidentAppBar, Text, c, getStorage, sh, useFidelityReady } from '@gs/core'
import { Confirm, Divider, Heading, NavRow, Note } from '../components/SettingsParts'
import { savedCopies, sizeWords } from '../settingsLogic'
import { usePrivacy } from '../useSettings'

function readCopies() {
  const storage = getStorage()
  return savedCopies(storage.keys().map((key) => ({ key, bytes: (storage.getString(key) ?? '').length })))
}

export default function SavedCopiesScreen() {
  const m = usePrivacy()
  const clearing = m.clearing
  const [tick, setTick] = useState(0)
  // Read again after a clear, so the list shows what is left.
  const copies = useMemo(() => readCopies(), [tick, clearing.done]) // eslint-disable-line react-hooks/exhaustive-deps
  const total = copies.reduce((sum, copy) => sum + copy.bytes, 0)
  const refresh = useCallback(() => setTick((n) => n + 1), [])
  useFidelityReady(true)

  return (
    <View style={s.screen}>
      <ResidentAppBar title="Saved copies" fallback="/settings/privacy" />
      <ScrollView style={s.fill} contentContainerStyle={s.bodyPad}>
        <Text face="Inter-Regular" size={12.5} lh={1.5} color="slate-600">
          {'Copies of what the estate already has, kept on this phone so the app opens with no connection. Each is replaced with a fresh one whenever you open its screen with a connection.'}
        </Text>

        {copies.length === 0 ? (
          <EmptyState tone="light" icon="side-btn-2" title="Nothing is saved on this phone" body="Open a screen with a connection and a copy is kept for the next time you have none." />
        ) : (
          <>
            <Heading top={16}>{`On this phone · ${sizeWords(total)}`}</Heading>
            <View style={s.card}>
              {copies.map((copy, i) => (
                <View key={copy.label}>
                  {i > 0 ? <Divider inset={48} /> : null}
                  <NavRow icon="side-btn-2" title={copy.label} detail={sizeWords(copy.bytes)} tile={36} iconSize={17} vertical={11} titleSize={12.5} chevron={false} />
                </View>
              ))}
            </View>
          </>
        )}

        <View style={s.card}>
          <NavRow icon="danger-icon" tone="amber" bold title="Clear data saved on this phone" detail="Removes these copies. You stay signed in, and nothing waiting to be sent is touched" tile={36} iconSize={17} vertical={4} titleSize={12.5} onPress={clearing.ask} />
        </View>
        <Confirm open={clearing.asking} title={clearing.warning.title} lines={clearing.warning.lines} problem={clearing.problem} confirmLabel="Clear saved data" working={clearing.working} onConfirm={clearing.confirm} onCancel={() => { clearing.dismiss(); refresh() }} />
        {clearing.done ? <Note text={clearing.done} top={8} /> : null}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('white'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  card: { backgroundColor: c('white'), borderRadius: 16, borderWidth: 1, borderColor: c('navy-100'), paddingHorizontal: 14, paddingVertical: 4, boxShadow: sh([{ x: 0, y: 8, blur: 20, spread: 0, token: 'navy-800', opacity: 0.06 }]), marginTop: 10 },
  bodyPad: { paddingTop: 18, paddingBottom: 24, paddingHorizontal: 20 },
})
