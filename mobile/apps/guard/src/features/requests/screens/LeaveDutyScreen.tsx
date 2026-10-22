/**
 * guard-app-10 · Leave & Duty and guard-app-21 · Open Shifts — one screen, two segments. Styles are the
 * boards' own, copied from the lifted files (src/lifted/GuardApp10.tsx · GuardApp21.tsx).
 *
 * Leave: the server's seven leave types against ONE yearly entitlement (the board's three balances are
 * fixture-only). Queued offline and said so. Open shifts: hours, never pay (the board's "+4h OT · 1.5×"
 * is fixture-only); online only, never from a cache, a claim never queued.
 */

import { useState } from 'react'
import { KeyboardAvoidingView, Platform, RefreshControl, ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { AppBar, EmptyState, ErrorView, Gradient, Icon, Pressable, SkeletonLines, Text, a, c, fidelityScreenId, isFidelity, now, sh, useFidelityReady, type GradientSpec, type IconName } from '@gs/core'
import { leaveFixture, openShiftsFixture } from '@/fidelity/requests'
import { LEAVE_TYPES, REASON_MAX, dayLabel, dayOptions, daysBetween, type OpenShiftCard, type RequestRow } from '../requests'
import { useOpenShifts } from '../useOpenShifts'
import { useLeave } from '../useRequests'

const BALANCE: GradientSpec = { angle: 135, stops: [{ token: 'navy-700', at: 0 }, { token: 'surface-900', at: 1 }] }
type Segment = 'leave' | 'open'

const Heading = ({ children, top = 16 }: { children: string; top?: number }) => (
  <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={{ marginTop: top, marginLeft: 2, marginRight: 2 }}>{children}</Text>
)

function Balance({ tiles }: { tiles: readonly { figure: string; label: string }[] }) {
  return (
    <Gradient spec={BALANCE} style={s.balanceCard}>
      <View style={s.balanceRow}>
        {tiles.map((t) => (
          <View key={t.label} style={s.fill}>
            <Text face="Poppins-Bold" size={19} color="white" align="center">{t.figure}</Text>
            <Text face="Inter-SemiBold" size={9.5} color="text-3" align="center" style={{ marginTop: 3 }}>{t.label}</Text>
          </View>
        ))}
      </View>
    </Gradient>
  )
}

function DateField({ label, value, open, onPress, error }: { label: string; value: string; open: boolean; onPress: () => void; error?: string }) {
  return (
    <View style={s.field}>
      <Text face="Inter-SemiBold" size={11.5} color="text-2">{label}</Text>
      <Pressable onPress={onPress} role="button" aria-label={`${label}: ${value}`} aria-expanded={open} style={[s.input, open ? s.inputOpen : null, error ? s.inputError : null]}>
        <Icon name="input" size={17} color="text-3" style={s.inputSvg} />
        <View style={s.shrink}><Text face="Inter-Medium" size={13.5} color="white">{value}</Text></View>
      </Pressable>
      {error ? <Text face="Inter-SemiBold" size={10.5} lh={1.4} color="red-500" role="alert" style={{ marginTop: 5 }}>{error}</Text> : null}
    </View>
  )
}

function RequestList({ rows }: { rows: RequestRow[] }) {
  return (
    <View style={s.listCard}>
      {rows.map((row, i) => (
        <View key={row.key} style={i === rows.length - 1 ? s.reqRowLast : s.reqRow}>
          <View style={s.rhIcon}><Icon name={row.icon as IconName} size={15} color="text-2" /></View>
          <View style={s.fill}>
            <Text face="Inter-SemiBold" size={12} color="white">{row.title}</Text>
            <Text face="Inter-Regular" size={10} color="text-3" style={{ marginTop: 1 }}>{row.detail}</Text>
            {row.note ? <Text face="Inter-Medium" size={10.5} lh={1.45} color={row.chip.tone === 'red-500' ? 'red-500' : 'text-2'} style={{ marginTop: 3 }}>{row.note}</Text> : null}
          </View>
          <View style={[s.statusChip, { backgroundColor: a(row.chip.tone, 0.15) }]}><Text face="Inter-Bold" size={9} color={row.chip.tone} numberOfLines={1}>{row.chip.label}</Text></View>
        </View>
      ))}
    </View>
  )
}

function ShiftCard({ card, first, claiming, problem, onClaim }: { card: OpenShiftCard; first: boolean; claiming: boolean; problem: string | null; onClaim: () => void }) {
  const claimed = card.claim === 'pending' || card.claim === 'approved'
  return (
    <View style={first ? s.card : s.card2}>
      <View style={s.spTop}>
        <View style={s.shrink}>
          <Text face="Poppins-SemiBold" size={13.5} color="white">{card.title}</Text>
          <Text face="Inter-Regular" size={10.5} color="text-3" style={{ marginTop: 2 }}>{card.site}</Text>
        </View>
        <View style={s.shrink}>
          <Text face="Inter-Bold" size={12} color="amber-500" align="right">{card.hours}</Text>
          <Text face="Inter-SemiBold" size={9} color="text-3" align="right" style={{ marginTop: 1 }}>{'LENGTH'}</Text>
        </View>
      </View>
      <View style={s.spMeta}>
        <View style={s.spMetaItem}><Icon name="input" size={14} color="text-3" style={s.spMetaItemSvg} /><View style={s.shrink}><Text face="Inter-Medium" size={11} color="text-2">{card.day}</Text></View></View>
        <View style={s.spMetaItem}><Icon name="recap-item" size={14} color="text-3" style={s.spMetaItemSvg} /><View style={s.shrink}><Text face="Inter-Medium" size={11} color="text-2">{card.window}</Text></View></View>
      </View>
      {card.restWarning ? (
        <View style={s.restWarn} role="alert">
          <Icon name="dispute-link" size={14} color="amber-500" style={s.restWarnSvg} />
          <View style={s.shrink}><Text face="Inter-SemiBold" size={10} lh={1.4} color="amber-500">{'This falls within 11 hours of another of your shifts: check your rest period before claiming.'}</Text></View>
        </View>
      ) : null}
      {problem ? <Text face="Inter-SemiBold" size={11} lh={1.45} color="red-500" role="alert" style={{ marginTop: 10 }}>{problem}</Text> : null}
      {claimed ? (
        <View style={[s.claimBtn, s.claimDone]} role="status">
          <Text face="Inter-Bold" size={12.5} color={card.claim === 'approved' ? 'green-400' : 'amber-500'}>{card.claim === 'approved' ? 'Approved: it is on your roster' : 'Claimed: waiting for a supervisor'}</Text>
        </View>
      ) : card.claim === 'declined' ? (
        <View style={[s.claimBtn, s.claimDone]} role="status"><Text face="Inter-Bold" size={12.5} color="text-3">{'Your claim was declined'}</Text></View>
      ) : (
        <Pressable onPress={onClaim} disabled={claiming} role="button" aria-label={`Claim the ${card.title} shift, ${card.day}, ${card.window}`} style={({ pressed }) => [s.claimBtn, { opacity: claiming ? 0.6 : pressed ? 0.85 : 1 }]}>
          <View style={s.shrink}><Text face="Inter-Bold" size={12.5} color="white">{claiming ? 'Claiming…' : 'Claim shift'}</Text></View>
        </Pressable>
      )}
    </View>
  )
}

export default function LeaveDutyScreen() {
  const params = useLocalSearchParams<{ tab?: string }>()
  const boardOpen = isFidelity && fidelityScreenId() === 'guard-app-21'
  const [segment, setSegment] = useState<Segment>(boardOpen || params.tab === 'open' ? 'open' : 'leave')
  const [picking, setPicking] = useState<'from' | 'to' | null>(null)
  const m = useLeave()
  const shifts = useOpenShifts(segment === 'open', () => router.push('/leave/claimed' as never))
  useFidelityReady(true)

  const days = dayOptions(now())
  const fromOptions = m.input.type === 'sick' ? dayOptions(new Date(now().getTime() - 14 * 86_400_000), 134) : days
  const span = daysBetween(m.input.from, m.input.to)

  return (
    <View style={s.screen}>
      <AppBar title="Leave & duty" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
        <ScrollView
          keyboardShouldPersistTaps="handled"
          contentContainerStyle={s.bodyPad}
          refreshControl={<RefreshControl refreshing={segment === 'leave' ? m.refreshing : shifts.refreshing} onRefresh={segment === 'leave' ? m.refresh : shifts.refresh} tintColor={c('amber-500')} />}
        >
          <View style={s.seg} role="tablist">
            {([['leave', 'Request leave'], ['open', 'Open shifts']] as const).map(([key, label]) => (
              <Pressable key={key} onPress={() => setSegment(key)} role="tab" aria-selected={segment === key} style={segment === key ? s.segItemOn : s.segItem}>
                <Text face="Inter-Bold" size={12} color={segment === key ? 'g-navy-900' : 'text-2'} align="center">{label}</Text>
              </Pressable>
            ))}
          </View>

          {segment === 'leave' ? (
            m.fixture ? (
              <>
                <Balance tiles={leaveFixture.balances} />
                <Heading>{'Leave type'}</Heading>
                <View style={s.chipRow}>
                  {leaveFixture.types.map((t, i) => (
                    <View key={t} style={i === 0 ? s.fChipOn : s.fChip}><Text face="Inter-Bold" size={11.5} color={i === 0 ? 'g-navy-900' : 'text-2'} numberOfLines={1}>{t}</Text></View>
                  ))}
                </View>
                <View style={s.twoCol}>
                  {([['Start date', leaveFixture.from], ['End date', leaveFixture.to]] as const).map(([label, value]) => (
                    <View key={label} style={s.field}>
                      <Text face="Inter-SemiBold" size={11.5} color="text-2">{label}</Text>
                      <View style={s.input}><Icon name="input" size={17} color="text-3" style={s.inputSvg} /><View style={s.shrink}><Text face="Inter-Medium" size={13.5} color="white">{value}</Text></View></View>
                    </View>
                  ))}
                </View>
                <View>
                  {/* The board's "(optional for vacation)" tail is gone here too (M18C §1): a reason is required for every kind. */}
                  <Text face="Inter-SemiBold" size={11.5} color="text-2">{'Reason'}</Text>
                  <View style={s.input2}><View style={s.shrink}><Text face="Inter-Regular" size={13} color="text-3">{leaveFixture.reason}</Text></View></View>
                </View>
                <View style={s.btnPrimary}><Text face="Poppins-Bold" size={14.5} color="g-navy-900">{'Submit request'}</Text></View>
              </>
            ) : (
              <>
                {m.staleNote ? (
                  <View style={s.staleNote} role="status">
                    <Icon name="offline-note" size={14} color="amber-500" style={s.noteSvg} />
                    <View style={s.shrink}><Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-500">{m.staleNote}</Text></View>
                  </View>
                ) : null}
                {m.leave ? (
                  <Balance tiles={[{ figure: String(m.leave.entitlement_days), label: 'DAYS A YEAR' }, { figure: String(m.leave.approved_days_this_year), label: 'APPROVED THIS YEAR' }, { figure: String(m.leave.remaining_days), label: 'REMAINING' }]} />
                ) : null}

                <Heading>{'Leave type'}</Heading>
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={s.chipRow} role="radiogroup" aria-label="Leave type">
                  {LEAVE_TYPES.map((t) => {
                    const on = m.input.type === t.value
                    return (
                      <Pressable key={t.value} onPress={() => m.setType(t.value)} role="radio" aria-checked={on} hitSlop={6} style={on ? s.fChipOn : s.fChip}>
                        <Text face="Inter-Bold" size={11.5} color={on ? 'g-navy-900' : 'text-2'} numberOfLines={1}>{t.label}</Text>
                      </Pressable>
                    )
                  })}
                </ScrollView>

                <View style={s.twoCol}>
                  <DateField label="First day" value={dayLabel(m.input.from)} open={picking === 'from'} onPress={() => setPicking(picking === 'from' ? null : 'from')} error={m.errors.from} />
                  <DateField label="Last day" value={dayLabel(m.input.to)} open={picking === 'to'} onPress={() => setPicking(picking === 'to' ? null : 'to')} error={m.errors.to} />
                </View>
                {picking ? (
                  <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={s.dayStrip} role="radiogroup" aria-label={picking === 'from' ? 'First day' : 'Last day'}>
                    {(picking === 'from' ? fromOptions : days.filter((d) => d.value >= m.input.from)).map((d) => {
                      const on = (picking === 'from' ? m.input.from : m.input.to) === d.value
                      return (
                        <Pressable key={d.value} onPress={() => { m.set(picking, d.value); setPicking(picking === 'from' ? 'to' : null) }} role="radio" aria-checked={on} hitSlop={4} style={on ? s.fChipOn : s.fChip}>
                          <Text face="Inter-Bold" size={11.5} color={on ? 'g-navy-900' : 'text-2'} numberOfLines={1}>{d.label}</Text>
                        </Pressable>
                      )
                    })}
                  </ScrollView>
                ) : null}
                <Text face="Inter-Medium" size={11} color="text-3" style={s.spanNote}>{`${span} day${span === 1 ? '' : 's'}, counting both the first and the last.`}</Text>

                <View>
                  {/* M18C: a reason for every kind of leave — the server asks for one, and a supervisor decides on it. */}
                  <Text face="Inter-SemiBold" size={11.5} color="text-2">{'Reason'}</Text>
                  <View style={[s.input2, m.errors.reason ? s.inputError : null]}>
                    <TextInput value={m.input.reason} onChangeText={(t) => m.set('reason', t)} placeholder="e.g. Family funeral in Mandeville; back Monday" placeholderTextColor={c('text-3')} maxLength={REASON_MAX} multiline aria-label="Reason" style={s.textArea} />
                  </View>
                  {m.errors.reason ? <Text face="Inter-SemiBold" size={10.5} lh={1.4} color="red-500" role="alert" style={{ marginTop: 5 }}>{m.errors.reason}</Text> : null}
                </View>

                {m.input.type === 'sick' ? (
                  <Pressable onPress={() => m.set('certificate', !m.input.certificate)} role="checkbox" aria-checked={m.input.certificate} hitSlop={6} style={s.checkRow}>
                    <View style={[s.checkBox, m.input.certificate ? s.checkBoxOn : null]}>{m.input.certificate ? <Icon name="check" size={13} color="g-navy-900" /> : null}</View>
                    <View style={s.shrink}><Text face="Inter-Medium" size={12} lh={1.45} color="text-2">{'I have a medical certificate. Only this tick is sent: show the certificate itself to your supervisor.'}</Text></View>
                  </Pressable>
                ) : null}

                {m.problem ? <Text face="Inter-SemiBold" size={11.5} lh={1.45} color="red-500" role="alert" style={{ marginTop: 12 }}>{m.problem}</Text> : null}
                {m.saved ? (
                  <View style={s.savedNote} role="status">
                    <Icon name={m.saved === 'sent' ? 'check' : 'bubble-meta'} size={14} color={m.saved === 'sent' ? 'green-400' : 'amber-500'} style={s.noteSvg} />
                    <View style={s.shrink}><Text face="Inter-SemiBold" size={11} lh={1.5} color={m.saved === 'sent' ? 'green-400' : 'amber-500'}>{m.saved === 'sent' ? 'Sent: your supervisor has it. You will see the decision below.' : 'Saved on this phone. It has NOT reached your supervisor yet: it is sent as soon as the connection is back.'}</Text></View>
                  </View>
                ) : null}
                <Pressable onPress={m.send} disabled={m.sending} role="button" style={({ pressed }) => [s.btnPrimary, { opacity: m.sending ? 0.6 : pressed ? 0.9 : 1 }]}>
                  <Text face="Poppins-Bold" size={14.5} color="g-navy-900">{m.sending ? 'Sending…' : 'Submit request'}</Text>
                </Pressable>

                <Heading top={20}>{'Your leave requests'}</Heading>
                {m.listStatus === 'loading' ? <SkeletonLines tone="dark" lines={3} style={{ marginTop: 10 }} /> : null}
                {m.listStatus === 'error' ? <ErrorView tone="dark" message={m.listError ?? 'Your requests could not be loaded.'} onRetry={m.refresh} /> : null}
                {m.listStatus === 'ready' && m.rows.length === 0 ? <Text face="Inter-Regular" size={11.5} lh={1.5} color="text-3" style={{ marginTop: 8, marginHorizontal: 2 }}>{'You have not asked for leave yet. A request and your supervisor\'s decision appear here.'}</Text> : null}
                {m.rows.length > 0 ? <RequestList rows={m.rows} /> : null}
              </>
            )
          ) : shifts.fixture ? (
            <>
              <Heading>{'Unfilled posts near you'}</Heading>
              {openShiftsFixture.map((f, i) => (
                <View key={f.id} style={i === 0 ? s.card : i === openShiftsFixture.length - 1 ? s.card3 : s.card2}>
                  <View style={s.spTop}>
                    <View style={s.shrink}>
                      <Text face="Poppins-SemiBold" size={13.5} color="white">{f.title}</Text>
                      <Text face="Inter-Regular" size={10.5} color="text-3" style={{ marginTop: 2 }}>{f.site}</Text>
                    </View>
                    <View style={s.shrink}>
                      <Text face="Inter-Bold" size={12} color="amber-500" align="right">{f.ot}</Text>
                      <Text face="Inter-SemiBold" size={9} color="text-3" align="right" style={{ marginTop: 1 }}>{f.rate}</Text>
                    </View>
                  </View>
                  <View style={s.spMeta}>
                    <View style={s.spMetaItem}><Icon name="input" size={14} color="text-3" style={s.spMetaItemSvg} /><View style={s.shrink}><Text face="Inter-Medium" size={11} color="text-2">{f.day}</Text></View></View>
                    <View style={s.spMetaItem}><Icon name="recap-item" size={14} color="text-3" style={s.spMetaItemSvg} /><View style={s.shrink}><Text face="Inter-Medium" size={11} color="text-2">{f.window}</Text></View></View>
                  </View>
                  {f.warn ? (
                    <View style={s.restWarn}>
                      <Icon name="dispute-link" size={14} color="amber-500" style={s.restWarnSvg} />
                      <View style={s.shrink}><Text face="Inter-SemiBold" size={10} lh={1.4} color="amber-500">{f.warn}</Text></View>
                    </View>
                  ) : null}
                  <View style={s.claimBtn}><View style={s.shrink}><Text face="Inter-Bold" size={12.5} color="white">{'Claim shift'}</Text></View></View>
                </View>
              ))}
            </>
          ) : (
            <>
              <Heading>{'Unfilled posts at your estate'}</Heading>
              {shifts.status === 'offline' ? <EmptyState tone="dark" icon="offline-note" title="No signal" body="Open shifts are first come, so they are only shown live, never from a saved list. Connect and pull down to see what is on offer." /> : null}
              {shifts.status === 'loading' ? <SkeletonLines tone="dark" lines={6} style={{ marginTop: 12 }} /> : null}
              {shifts.status === 'error' ? <ErrorView tone="dark" message={shifts.errorMessage ?? 'Open shifts could not be loaded.'} onRetry={shifts.refresh} /> : null}
              {shifts.status === 'forbidden' ? <EmptyState tone="dark" icon="recap-item" title="Not available on this handset" body="This phone is not allowed to see open shifts. Contact your supervisor." /> : null}
              {shifts.status === 'ready' && shifts.cards.length === 0 ? <EmptyState tone="dark" icon="recap-item" title="No open shifts" body="Nothing is unfilled at your estate in the next 14 days. Pull down to check again." /> : null}
              {shifts.status === 'ready' ? shifts.cards.map((card, i) => (
                <ShiftCard key={String(card.id)} card={card} first={i === 0} claiming={shifts.claiming === card.id} problem={shifts.problem?.id === card.id ? shifts.problem.message : null} onClaim={() => shifts.claimShift(card)} />
              )) : null}
              {shifts.status === 'ready' && shifts.cards.length > 0 ? <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={s.footnote}>{'A claim is a request: a supervisor approves it before it is on your roster. How an extra shift is paid is on your payslip, not here.'}</Text> : null}
            </>
          )}
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
  segItemOn: { flex: 1, backgroundColor: c('amber-500'), borderRadius: 10, paddingHorizontal: 4, paddingVertical: 9 },
  segItem: { flex: 1, borderRadius: 10, paddingHorizontal: 4, paddingVertical: 9 },
  seg: { flexDirection: 'row', backgroundColor: c('surface-800'), borderRadius: 13, borderWidth: 1, borderColor: c('border-700'), padding: 4 },
  balanceRow: { flexDirection: 'row', justifyContent: 'space-between' },
  balanceCard: { borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 15, marginTop: 16 },
  fChipOn: { backgroundColor: c('amber-500'), flexShrink: 1, borderRadius: 20, borderWidth: 1, borderColor: c('amber-500'), paddingHorizontal: 14, paddingVertical: 8 },
  fChip: { backgroundColor: c('surface-800'), flexShrink: 1, borderRadius: 20, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 8 },
  chipRow: { flexDirection: 'row', overflow: 'hidden', gap: 8, marginTop: 8 },
  dayStrip: { flexDirection: 'row', gap: 8, paddingBottom: 12 },
  spanNote: { marginBottom: 14, marginHorizontal: 2 },
  inputSvg: { width: 17, height: 17, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginRight: 10 },
  input: { height: 48, flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, marginTop: 6 },
  inputOpen: { borderColor: c('amber-500') },
  inputError: { borderColor: c('red-500') },
  field: { flex: 1, marginBottom: 15 },
  twoCol: { flexDirection: 'row', gap: 10, marginTop: 16 },
  input2: { minHeight: 70, flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 12, marginTop: 6 },
  textArea: { flex: 1, fontFamily: 'Inter-Regular', fontSize: 13, lineHeight: 19, color: c('white'), padding: 0, minHeight: 44, textAlignVertical: 'top' },
  checkRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, marginTop: 14 },
  checkBox: { width: 22, height: 22, flexGrow: 0, flexShrink: 0, alignItems: 'center', justifyContent: 'center', borderRadius: 6, borderWidth: 1, borderColor: c('border-600'), backgroundColor: c('surface-800') },
  checkBoxOn: { backgroundColor: c('amber-500'), borderColor: c('amber-500') },
  btnPrimary: { height: 52, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), borderRadius: 15, boxShadow: sh([{ x: 0, y: 10, blur: 22, spread: 0, token: 'amber-500', opacity: 0.28 }]), marginTop: 15 },
  savedNote: { flexDirection: 'row', backgroundColor: a('white', 0.04), borderRadius: 12, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 12, paddingVertical: 10, gap: 9, marginTop: 12 },
  listCard: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13, paddingVertical: 4, marginTop: 8, marginBottom: 12 },
  rhIcon: { width: 32, height: 32, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 9 },
  statusChip: { flexShrink: 0, borderRadius: 20, paddingHorizontal: 9, paddingVertical: 4 },
  reqRow: { flexDirection: 'row', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: c('border-700'), paddingVertical: 9, gap: 11 },
  reqRowLast: { flexDirection: 'row', alignItems: 'center', paddingVertical: 9, gap: 11 },
  spTop: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: 10 },
  spMetaItemSvg: { width: 14, height: 14, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  spMetaItem: { flexDirection: 'row', alignItems: 'center', flexShrink: 1, gap: 6 },
  spMeta: { flexDirection: 'row', gap: 14, marginTop: 10 },
  claimBtn: { height: 42, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('navy-600'), borderRadius: 12, marginTop: 10 },
  claimDone: { backgroundColor: c('surface-700') },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14, marginTop: 8 },
  card2: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14, marginTop: 11 },
  card3: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14, marginTop: 11, marginBottom: 11 },
  restWarnSvg: { width: 14, height: 14, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  restWarn: { flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 10, borderWidth: 1, borderColor: a('amber-500', 0.25), paddingHorizontal: 10, paddingVertical: 9, gap: 8, marginTop: 10 },
  footnote: { marginTop: 12, marginHorizontal: 2 },
  noteSvg: { flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  staleNote: { flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 12, borderWidth: 1, borderColor: a('amber-500', 0.28), paddingHorizontal: 12, paddingVertical: 10, gap: 9, marginTop: 14 },
})
