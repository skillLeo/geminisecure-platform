/**
 * guard-app-09 · Equipment Request. Styles are the board's own, copied from the lifted file
 * (src/lifted/GuardApp09.tsx).
 *
 * requests.store is "Queue": the request is kept on the phone first and sent when there is signal; until
 * then its row says "Pending sync" — never "Pending", which is the server's word for "with stores".
 * The board's sizes and its "Fulfilled" status are fixture-only: the server has neither.
 */

import { KeyboardAvoidingView, Platform, RefreshControl, ScrollView, StyleSheet, TextInput, View, useWindowDimensions } from 'react-native'
import { AppBar, ErrorView, Icon, Pressable, SkeletonLines, Text, a, c, sh, useFidelityReady, type ColorToken, type IconName } from '@gs/core'
import { equipmentFixture } from '@/fidelity/requests'
import { EQUIPMENT, REASON_MAX, SUBJECT_MAX } from '../requests'
import { useEquipment } from '../useRequests'

type Row = { key: string; icon: string; title: string; detail: string; note?: string | null; chip: { label: string; tone: string } }

export default function EquipmentScreen() {
  const m = useEquipment()
  const { width } = useWindowDimensions()
  useFidelityReady(true)

  // The board's grid: three across, 10 between, inside 20 either side.
  const tile = Math.floor(((width - 40 - 20) / 3) * 100) / 100
  const selected = m.fixture ? equipmentFixture.selected : m.input.item
  const quantity = m.fixture ? equipmentFixture.quantity : m.input.quantity
  const rows: Row[] = m.fixture ? [...equipmentFixture.recent] : m.rows

  return (
    <View style={s.screen}>
      <AppBar title="Equipment request" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={s.bodyPad} refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('amber-500')} />}>
          {m.staleNote ? (
            <View style={s.staleNote} role="status">
              <Icon name="offline-note" size={14} color="amber-500" style={s.noteSvg} />
              <View style={s.shrink}><Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-500">{m.staleNote}</Text></View>
            </View>
          ) : null}

          <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={{ marginTop: 2, marginLeft: 2, marginRight: 2 }}>{'Select item'}</Text>
          <View style={s.eqGrid} role="radiogroup" aria-label="Item">
            {EQUIPMENT.map((e) => {
              const on = selected === e.value
              return (
                <Pressable key={e.value} onPress={() => m.pick(e.value)} disabled={m.fixture} role="radio" aria-checked={on} aria-label={e.value} style={[s.eqItem, { width: tile }, on ? s.eqItemOn : null]}>
                  <Icon name={e.icon as IconName} size={24} color={on ? 'amber-500' : 'text-2'} style={s.eqItemSvg} />
                  <Text face="Inter-SemiBold" size={10} color="text-2" align="center">{e.value}</Text>
                </Pressable>
              )
            })}
          </View>
          {m.errors.item ? <Text face="Inter-SemiBold" size={10.5} lh={1.4} color="red-500" role="alert" style={{ marginTop: 6 }}>{m.errors.item}</Text> : null}

          {!m.fixture && m.input.item === 'Other' ? (
            <View style={s.field}>
              <Text face="Inter-SemiBold" size={11.5} color="text-2">{'What do you need?'}</Text>
              <View style={[s.inputLine, m.errors.other ? s.inputError : null]}>
                <TextInput value={m.input.other} onChangeText={m.setOther} placeholder="e.g. Raincoat, size L" placeholderTextColor={c('text-3')} maxLength={SUBJECT_MAX} aria-label="Item name" style={s.lineText} />
              </View>
              {m.errors.other ? <Text face="Inter-SemiBold" size={10.5} lh={1.4} color="red-500" role="alert" style={{ marginTop: 5 }}>{m.errors.other}</Text> : null}
            </View>
          ) : null}

          <View style={s.qtyRow}>
            <View style={s.shrink}><Text face="Inter-SemiBold" size={12.5} color="white">{'Quantity'}</Text></View>
            <View style={s.qtyCtrl}>
              <Pressable onPress={m.fewer ?? undefined} disabled={!m.fewer} role="button" aria-label={m.fewer ? 'One fewer' : 'One fewer: the least is 1'} aria-disabled={!m.fewer} hitSlop={10} style={[s.qtyBtn, { opacity: m.fewer || m.fixture ? 1 : 0.4 }]}>
                <Icon name="qty-btn" size={14} color="white" style={s.qtyBtnSvg} />
              </Pressable>
              <View style={s.qtyVal}><Text face="Inter-Bold" size={14} color="white" align="center" aria-live="polite">{String(quantity)}</Text></View>
              <Pressable onPress={m.more ?? undefined} disabled={!m.more} role="button" aria-label={m.more ? 'One more' : 'One more: the most is 50'} aria-disabled={!m.more} hitSlop={10} style={[s.qtyBtn, { opacity: m.more || m.fixture ? 1 : 0.4 }]}>
                <Icon name="qty-btn-2" size={14} color="white" style={s.qtyBtnSvg} />
              </Pressable>
            </View>
          </View>

          <View style={s.field}>
            <Text face="Inter-SemiBold" size={11.5} color="text-2">{'Reason'}</Text>
            {m.fixture ? (
              <View style={[s.input, s.inputFocus]}><View style={s.shrink}><Text face="Inter-Regular" size={13} color="text-3">{equipmentFixture.reason}</Text></View></View>
            ) : (
              <View style={[s.input, m.errors.reason ? s.inputError : null]}>
                <TextInput value={m.input.reason} onChangeText={m.setReason} placeholder="Why do you need it? e.g. torch no longer holds charge" placeholderTextColor={c('text-3')} maxLength={REASON_MAX} multiline aria-label="Reason" style={s.textArea} />
              </View>
            )}
            {m.errors.reason ? <Text face="Inter-SemiBold" size={10.5} lh={1.4} color="red-500" role="alert" style={{ marginTop: 5 }}>{m.errors.reason}</Text> : null}
          </View>

          {m.problem ? <Text face="Inter-SemiBold" size={11.5} lh={1.45} color="red-500" role="alert" style={{ marginTop: 12 }}>{m.problem}</Text> : null}
          {m.saved ? (
            <View style={s.savedNote} role="status">
              <Icon name={m.saved === 'sent' ? 'check' : 'bubble-meta'} size={14} color={m.saved === 'sent' ? 'green-400' : 'amber-500'} style={s.noteSvg} />
              <View style={s.shrink}><Text face="Inter-SemiBold" size={11} lh={1.5} color={m.saved === 'sent' ? 'green-400' : 'amber-500'}>{m.saved === 'sent' ? 'Sent: the office has your request.' : 'Saved on this phone. It has NOT reached the office yet: it is sent as soon as the connection is back.'}</Text></View>
            </View>
          ) : null}
          <Pressable onPress={m.send} disabled={m.sending || m.fixture} role="button" style={({ pressed }) => [s.btnPrimary, { opacity: m.sending ? 0.6 : pressed ? 0.9 : 1 }]}>
            <Text face="Poppins-Bold" size={14.5} color="g-navy-900">{m.sending ? 'Sending…' : 'Submit request'}</Text>
          </Pressable>

          <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={{ marginTop: 20, marginLeft: 2, marginRight: 2 }}>{'Recent requests'}</Text>
          {m.listStatus === 'loading' ? <SkeletonLines tone="dark" lines={3} style={{ marginTop: 10 }} /> : null}
          {m.listStatus === 'error' ? <ErrorView tone="dark" message={m.listError ?? 'Your requests could not be loaded.'} onRetry={m.refresh} /> : null}
          {m.listStatus === 'ready' && rows.length === 0 ? <Text face="Inter-Regular" size={11.5} lh={1.5} color="text-3" style={{ marginTop: 8, marginHorizontal: 2 }}>{'Nothing requested yet. What you ask for, and what the office decided, appears here.'}</Text> : null}
          {rows.length > 0 ? (
            <View style={s.card}>
              {rows.map((row, i) => (
                <View key={row.key} style={i === rows.length - 1 ? s.reqHistRowLast : s.reqHistRow}>
                  <View style={s.rhIcon}><Icon name={row.icon as IconName} size={15} color="text-2" style={s.rhIconSvg} /></View>
                  <View style={s.fill}>
                    <Text face="Inter-SemiBold" size={12} color="white">{row.title}</Text>
                    <Text face="Inter-Regular" size={10} color="text-3" style={{ marginTop: 1 }}>{row.detail}</Text>
                    {row.note ? <Text face="Inter-Medium" size={10.5} lh={1.45} color={row.chip.tone === 'red-500' ? 'red-500' : 'text-2'} style={{ marginTop: 3 }}>{row.note}</Text> : null}
                  </View>
                  <View style={[s.statusChip, { backgroundColor: a(row.chip.tone as ColorToken, row.chip.tone === 'navy-500' ? 0.18 : 0.15) }]}><Text face="Inter-Bold" size={9} color={row.chip.tone as ColorToken} numberOfLines={1}>{row.chip.label}</Text></View>
                </View>
              ))}
            </View>
          ) : null}
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  eqItemSvg: { width: 24, height: 24, overflow: 'hidden' },
  eqItem: { flexDirection: 'column', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 6, paddingVertical: 14, gap: 7 },
  eqItemOn: { backgroundColor: a('amber-500', 0.08), borderColor: c('amber-500') },
  eqGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 8 },
  qtyBtnSvg: { width: 14, height: 14, overflow: 'hidden' },
  qtyBtn: { width: 28, height: 28, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 8 },
  qtyVal: { minWidth: 14, flexShrink: 1 },
  qtyCtrl: { flexDirection: 'row', alignItems: 'center', flexShrink: 1, gap: 14 },
  qtyRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 10, marginTop: 18 },
  input: { minHeight: 70, flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 12, marginTop: 6 },
  inputFocus: { borderColor: c('amber-500') },
  inputError: { borderColor: c('red-500') },
  inputLine: { height: 48, flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, marginTop: 6 },
  lineText: { flex: 1, fontFamily: 'Inter-Medium', fontSize: 13.5, color: c('white'), padding: 0 },
  textArea: { flex: 1, fontFamily: 'Inter-Regular', fontSize: 13, lineHeight: 19, color: c('white'), padding: 0, minHeight: 44, textAlignVertical: 'top' },
  field: { marginTop: 15 },
  btnPrimary: { height: 52, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), borderRadius: 15, boxShadow: sh([{ x: 0, y: 10, blur: 22, spread: 0, token: 'amber-500', opacity: 0.28 }]), marginTop: 15 },
  savedNote: { flexDirection: 'row', backgroundColor: a('white', 0.04), borderRadius: 12, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 12, paddingVertical: 10, gap: 9, marginTop: 12 },
  rhIconSvg: { width: 15, height: 15, overflow: 'hidden' },
  rhIcon: { width: 32, height: 32, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 9 },
  statusChip: { flexShrink: 0, borderRadius: 20, paddingHorizontal: 9, paddingVertical: 4 },
  reqHistRow: { flexDirection: 'row', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: c('border-700'), paddingVertical: 9, gap: 11 },
  reqHistRowLast: { flexDirection: 'row', alignItems: 'center', paddingVertical: 9, gap: 11 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13, paddingVertical: 4, marginTop: 8, marginBottom: 12 },
  noteSvg: { flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  staleNote: { flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 12, borderWidth: 1, borderColor: a('amber-500', 0.28), paddingHorizontal: 12, paddingVertical: 10, gap: 9, marginBottom: 14 },
})
