/**
 * guard-app-12 · Digital Payslip. Styles are the board's own, copied from the lifted file
 * (src/lifted/GuardApp12.tsx).
 *
 * The board splits earnings, prints statutory rates, and offers "Download PDF" and "Dispute a line",
 * and all three are now here (M8 §5.3). The server sends the whole slip — the hours and their rates,
 * basic pay, overtime and allowances, each deduction with its rate AND the figure it was taken on,
 * the employer's own contributions and the year to date — so that is what is shown, figure for
 * figure, with nothing computed on the phone.
 *
 * THE PDF IS FETCHED INTO THE APP'S OWN CACHE AND HANDED TO THE SHARE SHEET, then deleted
 * (usePayslipPdf). A payslip is still never written to shared storage; what the officer does with it
 * from the share sheet is their own decision. A question about a payslip goes to the office as a
 * message that names the period and carries no figure.
 */

import { RefreshControl, ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { AppBar, EmptyState, ErrorView, Icon, Pressable, SkeletonLines, Text, a, c, useFidelityReady, type ColorToken } from '@gs/core'
import { money } from '@gs/core/lib'
import { payslipFixture } from '@/fidelity/payslips'
import { INFO } from '@/info/topics'
import { PeriodStepper } from '../components/PeriodStepper'
import { STATUS_WORD, deduction, deductionLines, deductionRows, earningLines, employerLines, hourFigure, queryDraft, yearToDateLines } from '../payslips'
import { usePayslipPdf } from '../usePayslipPdf'
import { usePayslips } from '../usePayslips'

const Heading = ({ children, top }: { children: string; top: number }) => (
  <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={{ marginTop: top, marginLeft: 2, marginRight: 2 }}>{children}</Text>
)

const Line = ({ label, amount, tone = 'white' }: { label: string; amount: string; tone?: ColorToken }) => (
  <View style={s.payLine}>
    <View style={s.shrink}><Text face="Inter-Regular" size={12} color="text-2">{label}</Text></View>
    <View style={s.shrink}><Text face="Inter-SemiBold" size={12} color={tone}>{amount}</Text></View>
  </View>
)

export default function PayslipScreen() {
  const m = usePayslips()
  const pdf = usePayslipPdf()
  useFidelityReady(m.status !== 'loading')
  const slip = m.slip

  return (
    <View style={s.screen}>
      <AppBar title="Payslip" info={INFO.payslip} right={{ icon: m.fixture ? 'side-btn-2' : 'recap-item', label: 'My hours', onPress: () => router.push('/hours' as never) }} />
      <ScrollView contentContainerStyle={s.bodyPad} refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('amber-500')} />}>
        {m.fixture ? (
          <>
            <View style={s.periodRow}>
              <View style={s.sideBtn2}><Icon name="side-btn-3" size={14} color="white" style={s.sideBtnSvg2} /></View>
              <View style={s.shrink}><Text face="Inter-Bold" size={13} color="white">{payslipFixture.period}</Text></View>
              <View style={s.sideBtn2}><Icon name="side-btn-4" size={14} color="white" style={s.sideBtnSvg2} /></View>
            </View>
            <View style={s.hoursRow}>
              {payslipFixture.hours.map((h) => (
                <View key={h.label} style={s.hoursItem}>
                  <Text face="Inter-Bold" size={15} color="white" align="center">{h.figure}</Text>
                  <Text face="Inter-SemiBold" size={9} color="text-3" align="center" style={{ marginTop: 2 }}>{h.label}</Text>
                </View>
              ))}
            </View>
            <Heading top={16}>{'Earnings'}</Heading>
            <View style={s.card}>{payslipFixture.earnings.map((l) => <Line key={l.label} label={l.label} amount={l.amount} tone={l.tone} />)}</View>
            <Heading top={12}>{'Statutory deductions'}</Heading>
            <View style={s.card}>{payslipFixture.deductions.map((l) => <Line key={l.label} label={l.label} amount={l.amount} tone="red-500" />)}</View>
            <View style={s.card2}>
              <View style={s.payLine2}>
                <View style={s.shrink}><Text face="Inter-Bold" size={13.5} color="white">{'Net pay'}</Text></View>
                <View style={s.shrink}><Text face="Inter-SemiBold" size={17} color="amber-500">{payslipFixture.net}</Text></View>
              </View>
            </View>
            <View style={s.btnOutline}>
              <Icon name="side-btn-2" size={17} color="amber-500" style={s.btnOutlineSvg} />
              <Text face="Inter-SemiBold" size={13} color="white">{'Download PDF'}</Text>
            </View>
            <View style={s.disputeLink}>
              <Icon name="dispute-link" size={14} color="amber-500" style={s.sideBtnSvg2} />
              <View style={s.shrink}><Text face="Inter-Bold" size={12} color="amber-500">{'Dispute a line on this payslip'}</Text></View>
            </View>
          </>
        ) : (
          <>
            {m.status === 'loading' ? <SkeletonLines tone="dark" lines={8} style={{ marginTop: 12 }} /> : null}
            {m.status === 'error' ? <ErrorView tone="dark" message={m.errorMessage ?? 'Your payslips could not be loaded.'} onRetry={m.refresh} /> : null}
            {m.status === 'forbidden' ? <EmptyState tone="dark" icon="tab-card" title="Not available on this handset" body="This phone is not allowed to show payslips. Contact your supervisor." /> : null}
            {m.status === 'empty' ? <EmptyState tone="dark" icon="tab-card" title="No payslips yet" body="A payslip appears here once the office has approved the payroll run it belongs to. Your hours are recorded whether or not a payslip is ready." action={{ label: 'See my hours', onPress: () => router.push('/hours' as never) }} /> : null}

            {m.status === 'ready' && slip ? (
              <>
                {m.staleNote ? (
                  <View style={s.staleNote} role="status">
                    <Icon name="offline-note" size={14} color="amber-500" style={s.noteSvg} />
                    <View style={s.shrink}><Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-500">{m.staleNote}</Text></View>
                  </View>
                ) : null}
                <PeriodStepper label={slip.period_label} onOlder={m.older} onNewer={m.newer} olderLabel="Older payslip" newerLabel="Newer payslip" />
                <View style={s.metaRow}>
                  <Text face="Inter-SemiBold" size={10.5} color={slip.status === 'paid' ? 'green-400' : 'amber-500'}>{STATUS_WORD[slip.status]}</Text>
                  <Text face="Inter-SemiBold" size={10.5} color="text-3">{`Run ${slip.run_reference} · ${slip.currency}`}</Text>
                </View>

                {/* The hours, and what each was paid at. Absent on a slip written before they were
                    recorded — where the server sends null, the screen says so rather than drawing 0.00. */}
                {slip.hours ? (
                  <>
                    <Heading top={16}>{'Hours'}</Heading>
                    <View style={s.card}>
                      <Line label={`Regular · ${money(slip.hours.regular_rate)} an hour`} amount={hourFigure(slip.hours.regular)} />
                      <Line label={`Overtime · ${money(slip.hours.overtime_rate)} an hour`} amount={hourFigure(slip.hours.overtime)} />
                      <Text face="Inter-Regular" size={10.5} lh={1.45} color="text-3" style={{ marginTop: 4 }}>{slip.hours.overtime_note}</Text>
                    </View>
                  </>
                ) : (
                  <View style={[s.card, { marginTop: 16 }]}>
                    <Text face="Inter-Regular" size={11} lh={1.5} color="text-3">{'The hours behind this period are not on record. The figures below are the ones the office approved and paid.'}</Text>
                  </View>
                )}

                <Heading top={12}>{'Earnings'}</Heading>
                <View style={s.card}>
                  {earningLines(slip).map((l) => <Line key={l.key} label={l.label} amount={l.amount} tone={l.tone} />)}
                  {slip.earnings?.taxable_note ? <Text face="Inter-Regular" size={10.5} lh={1.45} color="text-3" style={{ marginTop: 4 }}>{slip.earnings.taxable_note}</Text> : null}
                </View>

                <Heading top={12}>{'Statutory deductions'}</Heading>
                <View style={s.card}>
                  {/* Each line carries its rate AND what it was taken on, because no two of those
                      bases are the same figure and a rate without its base cannot be checked. */}
                  {deductionRows(slip).length > 0
                    ? deductionRows(slip).map((row) => (
                        <View key={row.key} style={s.deductionRow}>
                          <View style={s.grow}>
                            <Text face="Inter-Regular" size={12} color="text-2">{row.label}</Text>
                            <Text face="Inter-Regular" size={10} lh={1.4} color="text-3" style={{ marginTop: 1 }}>
                              {row.rate && row.base ? `${row.rate} of ${row.base}` : row.note}
                            </Text>
                          </View>
                          {/* White, not red: a deduction is not an emergency, and red is urgent and life-safety only (M12 §4.4). Its minus sign says what it is. */}
                          <Text face="Inter-SemiBold" size={12} color="white">{row.amount}</Text>
                        </View>
                      ))
                    : deductionLines(slip).map((l) => <Line key={l.key} label={l.label} amount={l.amount} tone={l.tone} />)}
                  {slip.total_deductions ? (
                    <View style={s.payLine2}>
                      <View style={s.shrink}><Text face="Inter-Bold" size={12} color="white">{'Total deductions'}</Text></View>
                      <View style={s.shrink}><Text face="Inter-SemiBold" size={12} color="white">{deduction(slip.total_deductions)}</Text></View>
                    </View>
                  ) : null}
                  {slip.paye_note ? <Text face="Inter-Regular" size={10.5} lh={1.45} color="text-3" style={{ marginTop: 4 }}>{slip.paye_note}</Text> : null}
                </View>
                <View style={s.card2}>
                  <View style={s.payLine2}>
                    <View style={s.shrink}><Text face="Inter-Bold" size={13.5} color="white">{'Net pay'}</Text></View>
                    <View style={s.shrink}><Text face="Inter-SemiBold" size={17} color="amber-500">{money(slip.net)}</Text></View>
                  </View>
                </View>

                {/* What the employer pays on top, and the year so far. Both are the server's own
                    figures; neither is added up here. */}
                {employerLines(slip).length > 0 ? (
                  <>
                    <Heading top={12}>{'Paid by Gemini Security on your behalf'}</Heading>
                    <View style={s.card}>
                      {employerLines(slip).map((l) => <Line key={l.key} label={l.label} amount={l.amount} tone={l.tone} />)}
                      <Text face="Inter-Regular" size={10.5} lh={1.45} color="text-3" style={{ marginTop: 4 }}>{slip.employer_contributions?.note ?? ''}</Text>
                    </View>
                  </>
                ) : null}

                {yearToDateLines(slip).length > 0 ? (
                  <>
                    <Heading top={12}>{`Year to date · ${slip.year_to_date?.year ?? ''}`}</Heading>
                    <View style={s.card}>
                      {yearToDateLines(slip).map((l) => <Line key={l.key} label={l.label} amount={l.amount} tone={l.tone} />)}
                      <Text face="Inter-Regular" size={10.5} lh={1.45} color="text-3" style={{ marginTop: 4 }}>
                        {`${slip.year_to_date?.periods ?? 0} approved period${(slip.year_to_date?.periods ?? 0) === 1 ? '' : 's'} so far this tax year.`}
                      </Text>
                    </View>
                  </>
                ) : null}

                <Pressable onPress={() => pdf.download(slip)} disabled={pdf.state === 'working'} role="button" aria-busy={pdf.state === 'working'} style={({ pressed }) => [s.btnOutline, { opacity: pressed || pdf.state === 'working' ? 0.8 : 1 }]}>
                  <Icon name="side-btn-2" size={17} color="amber-500" style={s.btnOutlineSvg} />
                  <Text face="Inter-SemiBold" size={13} color="white">{pdf.state === 'working' ? 'Preparing your payslip…' : 'Download PDF'}</Text>
                </Pressable>
                {pdf.problem ? (
                  <View style={s.staleNote} role="alert">
                    <Icon name="offline-note" size={14} color="amber-500" style={s.noteSvg} />
                    <View style={s.shrink}><Text face="Inter-SemiBold" size={11} lh={1.5} color="amber-500">{pdf.problem}</Text></View>
                  </View>
                ) : null}

                <Pressable onPress={() => router.push('/hours' as never)} role="button" style={({ pressed }) => [s.btnOutline, { opacity: pressed ? 0.8 : 1 }]}>
                  <Icon name="recap-item" size={17} color="amber-500" style={s.btnOutlineSvg} />
                  <Text face="Inter-SemiBold" size={13} color="white">{'See my hours'}</Text>
                </Pressable>
                <Pressable onPress={() => router.push({ pathname: '/messages/[thread]', params: { thread: 'dispatch', draft: queryDraft(slip) } } as never)} role="button" hitSlop={8} style={s.disputeLink}>
                  <Icon name="dispute-link" size={14} color="amber-500" style={s.sideBtnSvg2} />
                  <View style={s.shrink}><Text face="Inter-Bold" size={12} color="amber-500">{'Ask the office about this payslip'}</Text></View>
                </Pressable>
                <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center" style={{ marginTop: 10 }}>
                  {'These are the figures the office approved. Downloading opens your phone’s share sheet so you can keep or send the PDF; the file is removed from this phone afterwards and a payslip is never saved where another app could read it.'}
                </Text>
              </>
            ) : null}
          </>
        )}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  sideBtnSvg2: { width: 14, height: 14, overflow: 'hidden' },
  sideBtn2: { width: 28, height: 28, backgroundColor: c('surface-700'), flexDirection: 'row', flexWrap: 'wrap', alignItems: 'flex-start' },
  periodRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 12 },
  metaRow: { flexDirection: 'row', justifyContent: 'space-between', gap: 8, marginTop: 10, marginHorizontal: 2 },
  hoursItem: { flex: 1, backgroundColor: c('surface-800'), borderRadius: 12, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 6, paddingVertical: 10 },
  hoursRow: { flexDirection: 'row', gap: 8, marginTop: 16 },
  payLine: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 7, gap: 12 },
  grow: { flex: 1 },
  // A deduction takes two lines: the name, and under it the rate and the figure it was taken on.
  deductionRow: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', paddingVertical: 7, gap: 12 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14, marginTop: 8 },
  payLine2: { flexDirection: 'row', justifyContent: 'space-between', paddingTop: 12, borderTopWidth: 1, borderTopColor: c('border-700'), paddingBottom: 7, marginTop: 6 },
  card2: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14, marginTop: 12 },
  btnOutlineSvg: { width: 17, height: 17, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  btnOutline: { height: 46, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 14, borderWidth: 1, borderColor: c('border-600'), gap: 9, marginTop: 12 },
  disputeLink: { flexDirection: 'row', justifyContent: 'center', paddingTop: 14, paddingBottom: 2, gap: 7 },
  noteSvg: { flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  staleNote: { flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 12, borderWidth: 1, borderColor: a('amber-500', 0.28), paddingHorizontal: 12, paddingVertical: 10, gap: 9, marginBottom: 14 },
})
