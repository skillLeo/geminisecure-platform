/**
 * guard-app-32 · Manual Pass Code — for when the QR will not scan. Styles are the board's
 * own, copied from the lifted file.
 *
 * The board's pad is digits plus an "ABC" key: digits are typed on the pad (big keys, a
 * gloved thumb), and ABC opens the phone's own keyboard for the letters of the prefix.
 * A typed code can only be checked online — the handset holds signature keys, not the
 * estate's pass list — and the screen says so rather than guessing (verdict.needsSignal).
 */

import { useRef, useState } from 'react'
import { ScrollView, StyleSheet, TextInput, View } from 'react-native'
import * as Haptics from 'expo-haptics'
import { AppBar, GuardButton, Icon, Pressable, Text, c, isFidelity, sh, useFidelityReady } from '@gs/core'
import { manualCodeFixture } from '@/fidelity/gate'
import { VerdictSheet } from '../components/VerdictSheet'
import { backspace, formatPassCode, isCompletePassCode } from '../passCode'
import { useGateCheck } from '../useGateCheck'

const ROWS = [['1', '2', '3'], ['4', '5', '6'], ['7', '8', '9'], ['ABC', '0', '⌫']] as const

export default function ManualCodeScreen() {
  const m = useGateCheck(isFidelity ? { verdict: null } : null)
  const input = useRef<TextInput>(null)
  const [code, setCode] = useState<string>(isFidelity ? manualCodeFixture.code : '')
  const [letters, setLetters] = useState(false)
  useFidelityReady(true)

  const complete = isCompletePassCode(code)
  const sheetOpen = m.verdict !== null || m.outcome !== null

  const press = (key: (typeof ROWS)[number][number]) => {
    void Haptics.selectionAsync().catch(() => undefined)
    if (key === 'ABC') {
      // The phone's own keyboard, for the letters. Tapping a digit key puts it away again.
      setLetters(true)
      setTimeout(() => input.current?.focus(), 0)
    } else if (key === '⌫') setCode(backspace)
    else {
      if (letters) {
        setLetters(false)
        input.current?.blur()
      }
      setCode((now) => formatPassCode(now + key))
    }
  }

  const verify = () => {
    input.current?.blur()
    setLetters(false)
    m.check(code)
  }

  return (
    <View style={s.screen}>
      <AppBar title="Enter pass code" />
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={s.codeWrap}>
        <Icon name="privacy-note" size={44} color="amber-500" style={{ marginBottom: 16 }} />
        <Text face="Poppins-SemiBold" size={19} color="white" align="center" role="heading">{"QR won't scan?"}</Text>
        <View style={s.cwSub}>
          <Text face="Inter-Regular" size={12} lh={1.5} color="text-2" align="center">{'Ask the visitor to read out the code printed under their QR pass.'}</Text>
        </View>

        <View style={s.codeInput}>
          <TextInput
            ref={input}
            value={code}
            onChangeText={(text) => setCode(formatPassCode(text))}
            onSubmitEditing={complete ? verify : undefined}
            onBlur={() => setLetters(false)}
            showSoftInputOnFocus={letters}
            autoCapitalize="characters"
            autoCorrect={false}
            autoComplete="off"
            spellCheck={false}
            maxLength={12}
            placeholder="e.g. PPV2-4471"
            placeholderTextColor={c('text-3')}
            selectionColor={c('amber-500')}
            aria-label="Pass code"
            maxFontSizeMultiplier={1.2}
            style={s.codeText}
          />
        </View>
        <Text face="Inter-Regular" size={10.5} color="text-3" align="center" style={{ marginBottom: 26 }}>{'Codes are 4 letters, a phase number, and 4 digits'}</Text>

        <View style={s.keypad}>
          {ROWS.map((row) => (
            <View key={row.join('')} style={s.keyRow}>
              {row.map((key) => {
                const plain = key === 'ABC' || key === '⌫'
                return (
                  <Pressable
                    key={key}
                    onPress={() => press(key)}
                    onLongPress={key === '⌫' ? () => setCode('') : undefined}
                    role="button"
                    aria-label={key === '⌫' ? 'Delete' : key === 'ABC' ? 'Letters keyboard' : key}
                    accessibilityHint={key === '⌫' ? 'Hold to clear the code' : undefined}
                    style={({ pressed }) => [plain ? s.keyPlain : s.key, pressed ? { backgroundColor: c('surface-700') } : null]}
                  >
                    <Text face="Poppins-SemiBold" size={17} color={plain ? (key === 'ABC' && letters ? 'amber-500' : 'text-3') : 'white'}>{key}</Text>
                  </Pressable>
                )
              })}
            </View>
          ))}
        </View>

        {complete ? (
          <GuardButton label="Verify pass" onPress={verify} loading={m.checking} style={s.full} />
        ) : (
          <GuardButton label="Verify pass" onPress={verify} disabled disabledReason="Type the whole code first: letters, the phase number, then four digits" style={s.full} />
        )}
        {!isFidelity && !m.online ? (
          <Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-500" align="center" style={{ marginTop: 12 }} role="alert">
            {'No signal: a typed code can only be checked online. Scanning the QR code works without signal.'}
          </Text>
        ) : null}
      </ScrollView>

      {sheetOpen ? <Pressable style={s.scrim} onPress={m.reset} role="button" aria-label="Close" /> : null}
      <VerdictSheet m={m} againLabel="Try again" nextLabel="Enter another code" />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  codeWrap: { flexDirection: 'column', alignItems: 'center', paddingTop: 40, paddingHorizontal: 24, paddingBottom: 40 },
  cwSub: { paddingHorizontal: 10, marginTop: 6 },
  codeInput: { width: '100%', height: 64, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('amber-500'), boxShadow: sh([{ x: 0, y: 0, blur: 0, spread: 3, token: 'amber-500', opacity: 0.15 }]), marginTop: 26, marginBottom: 8 },
  codeText: { flex: 1, height: 62, textAlign: 'center', fontFamily: 'Inter-Bold', fontSize: 22, letterSpacing: 4, color: c('white'), padding: 0 },
  keypad: { width: '100%', gap: 10, marginBottom: 22 },
  keyRow: { flexDirection: 'row', gap: 10 },
  key: { flex: 1, height: 54, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700') },
  keyPlain: { flex: 1, height: 54, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 14 },
  full: { width: '100%' },
  scrim: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: c('black'), opacity: 0.55, zIndex: 9 },
})
