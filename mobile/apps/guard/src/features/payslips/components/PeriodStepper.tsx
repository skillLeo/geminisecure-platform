/**
 * The boards' .period-row (guard-app-12 and -19): older ‹ label › newer. A direction with nothing in it
 * is dimmed and says so to a screen reader — it is not a dead control, it is a disabled one.
 */

import { StyleSheet, View } from 'react-native'
import { Icon, Pressable, Text, c } from '@gs/core'

type Props = { label: string; onOlder: (() => void) | null; onNewer: (() => void) | null; olderLabel: string; newerLabel: string }

export function PeriodStepper({ label, onOlder, onNewer, olderLabel, newerLabel }: Props) {
  return (
    <View style={s.periodRow}>
      <Pressable onPress={onOlder ?? undefined} disabled={!onOlder} role="button" aria-label={onOlder ? olderLabel : `${olderLabel}: there is none`} aria-disabled={!onOlder} hitSlop={10} style={[s.sideBtn2, { opacity: onOlder ? 1 : 0.35 }]}>
        <Icon name="side-btn-3" size={14} color="white" style={s.sideBtnSvg2} />
      </Pressable>
      <View style={s.shrink}><Text face="Inter-Bold" size={13} color="white" aria-live="polite">{label}</Text></View>
      <Pressable onPress={onNewer ?? undefined} disabled={!onNewer} role="button" aria-label={onNewer ? newerLabel : `${newerLabel}: there is none`} aria-disabled={!onNewer} hitSlop={10} style={[s.sideBtn2, { opacity: onNewer ? 1 : 0.35 }]}>
        <Icon name="side-btn-4" size={14} color="white" style={s.sideBtnSvg2} />
      </Pressable>
    </View>
  )
}

const s = StyleSheet.create({
  shrink: { flexShrink: 1 },
  sideBtnSvg2: { width: 14, height: 14, overflow: 'hidden' },
  // The board's own square has its chevron in the top-left corner — a slip in the board's stylesheet (its
  // .side-btn rule loses to .period-row's). On a phone it is what the board MEANT: a centred, round button.
  sideBtn2: { width: 28, height: 28, borderRadius: 14, backgroundColor: c('surface-700'), flexDirection: 'row', alignItems: 'center', justifyContent: 'center' },
  periodRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 12 },
})
