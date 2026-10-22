/**
 * Clock out — board guard-app-20 (running over · shift summary · confirm before you leave · identity),
 * plus the states no board draws: loading, not clocked in, already clocked out on this phone
 * (Pending sync / Syncing / Confirmed), last clock-out Not accepted, error, 403 (explained, no retry),
 * geofence outside / not checked, no handover note yet, the note editor, identity failed / locked /
 * not available on this phone, unsynced safety-critical records (named; the clock-out goes after them, D-M164),
 * no signal, shift from the saved copy.
 *
 * Layout values are the board's own, lifted (see src/lifted/GuardApp20.tsx); the note input is
 * board 28's `.note-input`. All behaviour lives in useClockOut(); this file only draws it.
 */

import { RefreshControl, ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { router } from 'expo-router'
import { Controller } from 'react-hook-form'
import { AppBar, EmptyState, ErrorView, GuardButton, Icon, Pressable, Skeleton, SkeletonLines, Text, a, c, type, useFidelityReady, type ColorToken, type IconName } from '@gs/core'
import { OUTBOX_LABEL } from '@gs/core/offline'
import { MAX_ATTEMPTS, NOTE_MAX, useClockOut, type ClockOutModel, type Geo } from '../useClockOut'

type Tone = ColorToken

const metres = (m: number) => (m < 10 ? `${Number(m.toFixed(1))}m` : `${Math.round(m)}m`)

function SectionLabel({ children, first }: { children: string; first?: boolean }) {
  return (
    <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper role="heading" style={{ marginTop: first ? 0 : 16, marginLeft: 2, marginRight: 2 }}>
      {children}
    </Text>
  )
}

function RecapTile({ icon, value, label, loading }: { icon: IconName; value: string; label: string; loading: boolean }) {
  return (
    <View style={s.recapItem} aria-label={loading ? `${label}: loading` : `${label}: ${value}`}>
      <Icon name={icon} size={18} color="amber-500" style={s.recapItemSvg} />
      {loading ? (
        <Skeleton tone="dark" width={38} height={17} style={{ marginTop: 10, alignSelf: 'center' }} />
      ) : (
        <Text face="Inter-Bold" size={14} color="white" align="center" style={{ marginTop: 10 }}>{value}</Text>
      )}
      <Text face="Inter-SemiBold" size={8.5} color="text-3" align="center" style={{ marginTop: 2 }}>{label}</Text>
    </View>
  )
}

/** One `.co-geo` line: icon, sentence, and — only in states the board does not draw — a quiet second line. */
function ConfirmLine({ icon, tone, title, titleTone = 'white', detail }: { icon: IconName; tone: Tone; title: string; titleTone?: Tone; detail?: string | null }) {
  return (
    <>
      <Icon name={icon} size={16} color={tone} style={s.lineSvg} />
      <View style={s.lineP}>
        <Text face="Inter-SemiBold" size={12.5} color={titleTone}>{title}</Text>
        {detail ? <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginTop: 2 }}>{detail}</Text> : null}
      </View>
    </>
  )
}

function geoLine(geo: Geo, post: string): { icon: IconName; tone: Tone; title: string; detail: string | null } {
  if (geo.state === 'inside') return { icon: 'check', tone: 'amber-500', title: `Within geofence · ${post}`, detail: null }
  if (geo.state === 'outside') return { icon: 'act-result', tone: 'red-500', title: `Outside geofence · ${post}`, detail: `About ${metres(geo.boundaryM)} outside the boundary. You can still clock out: pull down to check again.` }
  if (geo.state === 'checking') return { icon: 'check', tone: 'text-3', title: `Checking geofence · ${post}`, detail: 'Finding your position…' }
  const why =
    geo.why === 'permission' ? 'Location is off for this app. You can still clock out.'
    : geo.why === 'offline' ? 'No signal, so your position cannot be checked. You can still clock out.'
    : geo.why === 'not_surveyed' ? 'This post has not been surveyed, so your distance cannot be checked.'
    : geo.why === 'unchecked' ? 'Your position could not be checked just now. You can still clock out: pull down to try again.'
    : 'No GPS fix. Pull down to try again, or clock out without it.'
  return { icon: 'dispute-link', tone: 'text-3', title: `Geofence not checked · ${post}`, detail: why }
}

function HandoverNote({ note }: { note: ClockOutModel['note'] }) {
  return (
    <>
      <Pressable
        onPress={note.toggle}
        role="button"
        aria-expanded={note.open}
        aria-label={note.added ? 'Handover note added for next shift. Edit the note' : 'Add a handover note for the next shift, if anything needs passing on'}
        hitSlop={{ top: 10, bottom: 10, left: 8, right: 8 }}
        style={({ pressed }) => [s.coGeo, { opacity: pressed ? 0.7 : 1 }]}
      >
        {note.added ? (
          <ConfirmLine icon="check" tone="amber-500" title="Handover note added for next shift" />
        ) : (
          <ConfirmLine icon="p-edit" tone="amber-500" title="Add a handover note" titleTone="amber-500" detail={note.open ? null : 'If anything needs passing on: read by whoever relieves this post.'} />
        )}
      </Pressable>
      {note.open ? (
        <View style={s.noteField}>
          <Controller
            control={note.control}
            name="handover_note"
            render={({ field }) => (
              <TextInput
                value={field.value}
                onChangeText={field.onChange}
                onBlur={field.onBlur}
                multiline
                autoFocus
                maxLength={NOTE_MAX}
                maxFontSizeMultiplier={1.3}
                placeholder="e.g. Back gate lock sticks; delivery expected for Lot 12 at 8 AM"
                placeholderTextColor={c('text-3')}
                selectionColor={c('amber-500')}
                keyboardAppearance="dark"
                aria-label="Handover note for the next shift"
                style={[s.noteInput, type('Inter-Regular', 12.5, { lineHeight: 1.5 }), { color: c('white') }]}
              />
            )}
          />
          <View style={s.noteFoot}>
            <View style={{ flexShrink: 1 }}>
              <Text face="Inter-Medium" size={10.5} color={note.error ? 'red-500' : 'text-3'} role={note.error ? 'alert' : undefined}>
                {note.error ?? `${note.length}/${NOTE_MAX}`}
              </Text>
            </View>
            <Pressable onPress={note.close} role="button" aria-label="Done writing the handover note" hitSlop={{ top: 16, bottom: 16, left: 16, right: 16 }} style={{ marginLeft: 'auto' }}>
              <Text face="Inter-Bold" size={12.5} color="amber-500">Done</Text>
            </Pressable>
          </View>
        </View>
      ) : null}
    </>
  )
}

/** A clock-out the server answered "no" to: said here, in its words — not only in the sync queue. */
function RefusedNotice({ sentence }: { sentence: string }) {
  return (
    <View style={[s.noticeCard, { marginTop: 0, marginBottom: 14 }]} role="alert">
      <Icon name="qa-tile-3" size={19} color="red-500" style={{ marginTop: 1 }} />
      <View style={{ flexShrink: 1, flexGrow: 1 }}>
        <Text face="Inter-Bold" size={12.5} color="red-500">{`${OUTBOX_LABEL.refused}: your last clock-out`}</Text>
        <Text face="Inter-Regular" size={11} lh={1.5} color="text-2" style={{ marginTop: 3 }}>{`${sentence} The record is kept in the sync queue.`}</Text>
        <GuardButton variant="outline" label="Open sync queue" onPress={() => router.push('/sync-queue' as never)} style={{ marginTop: 10 }} />
      </View>
    </View>
  )
}

export default function ClockOutScreen() {
  // The dashboard, as the clock-in does — `/` is the splash, which would replay and re-decide the route.
  const m = useClockOut(() => router.replace('/(tabs)'))
  useFidelityReady(m.status !== 'loading')

  const failed = m.bio.failed || m.bio.locked
  const noBio = m.bio.available === false && !failed
  const over = m.overMinutes >= 1
  const geo = geoLine(m.geo, m.postName)
  const capturedBody =
    m.captured === 'confirmed' ? `${OUTBOX_LABEL.confirmed}: your clock-out was received, with the time you made it.`
    : m.captured === 'syncing' ? `${OUTBOX_LABEL.syncing}: your clock-out is being sent now, with the time you made it.`
    : `${OUTBOX_LABEL.pending}: your clock-out is saved on this phone with the time you made it. ${m.clockInWaiting ? 'It is sent straight after your clock-in, which is still waiting.' : m.online ? 'It is sent as soon as the server can be reached.' : "It is sent the moment you're back online."}`

  return (
    <View style={s.screen}>
      <AppBar title="Clock out" />
      <ScrollView
        contentContainerStyle={s.bodyPad}
        keyboardShouldPersistTaps="handled"
        automaticallyAdjustKeyboardInsets
        refreshControl={<RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c('amber-500')} />}
      >
        {m.status === 'loading' ? <SkeletonLines tone="dark" lines={6} style={{ marginTop: 12 }} /> : null}
        {m.status === 'error' ? <ErrorView tone="dark" message={m.errorMessage ?? 'Your shift could not be loaded.'} onRetry={m.refresh} /> : null}
        {m.status === 'denied' ? (
          <EmptyState tone="dark" icon="side-btn-7" title="Not available on this handset" body={m.errorMessage ?? 'This handset is not allowed to read your shifts. Ask the office to check your enrolment.'} action={{ label: 'Get help from your supervisor', onPress: () => router.push('/help' as never) }} />
        ) : null}
        {m.refusal && (m.status === 'not_on_duty' || m.status === 'ready') ? <RefusedNotice sentence={m.refusal} /> : null}
        {m.status === 'not_on_duty' ? (
          <EmptyState tone="dark" icon="recap-item" title="You are not clocked in" body="There is no shift running for you right now, so there is nothing to clock out of." action={{ label: 'Back to dashboard', onPress: () => router.replace('/(tabs)') }} />
        ) : null}
        {m.status === 'clocked_out' ? (
          <EmptyState tone="dark" icon="check" title="You have clocked out" body={capturedBody} action={{ label: 'Back to dashboard', onPress: () => router.replace('/(tabs)') }} />
        ) : null}

        {m.status === 'ready' ? (
          <>
            {over ? (
              <View style={s.coLateBanner} role="status">
                <Icon name="dispute-link" size={16} color="amber-500" style={s.lineSvg} />
                <View style={s.lineP}>
                  <Text face="Inter-SemiBold" size={11} lh={1.4} color="amber-500">
                    {`This shift is running ${m.overMinutes} minute${m.overMinutes === 1 ? '' : 's'} over.${m.supervisor ? ` ${m.supervisor} has been notified automatically.` : ''}`}
                  </Text>
                </View>
              </View>
            ) : null}

            <SectionLabel first={!over}>{"Today's shift summary"}</SectionLabel>
            <View style={s.shiftRecap}>
              <RecapTile icon="recap-item" value={m.summary.onPost} label="ON POST" loading={m.summary.state === 'loading'} />
              <RecapTile icon="tab-patrol" value={m.summary.checkpoints} label="CHECKPOINTS" loading={m.summary.state === 'loading'} />
              <RecapTile icon="qa-tile-3" value={m.summary.incidents} label="INCIDENTS" loading={m.summary.state === 'loading'} />
            </View>
            {m.summary.fromCache || m.summary.state === 'unavailable' ? (
              <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginTop: 8, marginHorizontal: 2 }}>
                {m.summary.fromCache ? 'Saved on this phone when you last had signal: it may be behind.' : "Today's figures could not be loaded. Your hours are kept by the office either way."}
              </Text>
            ) : null}

            <SectionLabel>Confirm before you leave</SectionLabel>
            <View style={s.card}>
              <View style={s.coGeo}>
                <ConfirmLine {...geo} />
              </View>
              <HandoverNote note={m.note} />
            </View>

            {m.blockers.length > 0 ? (
              <View style={s.noticeCard} role="alert">
                <Icon name="qa-tile-3" size={19} color="red-500" style={{ marginTop: 1 }} />
                <View style={{ flexShrink: 1, flexGrow: 1 }}>
                  <Text face="Inter-Bold" size={12.5} color="red-500">Not sent yet: these go first</Text>
                  {m.blockers.map((label, i) => (
                    <Text key={`${i}-${label}`} face="Inter-SemiBold" size={11.5} lh={1.5} color="white" style={{ marginTop: i === 0 ? 5 : 1 }}>{`•  ${label}`}</Text>
                  ))}
                  <Text face="Inter-Regular" size={11} lh={1.5} color="text-2" style={{ marginTop: 5 }}>
                    {m.online
                      ? 'They are saved on this phone and are being sent now. You can clock out: your clock-out is sent after them, so the office has them first.'
                      : "They are saved on this phone and send the moment you're back in signal. You can clock out: your clock-out is saved and sent after them, so the office has them first."}
                  </Text>
                  <GuardButton variant="outline" label="Open sync queue" onPress={() => router.push('/sync-queue' as never)} style={{ marginTop: 10 }} />
                </View>
              </View>
            ) : null}

            <View style={s.enrolBio}>
              <View style={s.ebRingWrap}>
                <View style={s.eRing} />
                <View style={s.eRing2} />
                <View style={[s.ebBtn, failed ? { borderColor: c('red-500') } : null]}>
                  <Icon name={failed ? 'side-btn-7' : 'bio-btn'} size={36} color={failed ? 'red-500' : 'amber-500'} />
                </View>
              </View>
              <Text face="Inter-Bold" size={13} color={failed ? 'red-500' : 'white'} align="center">
                {m.bio.locked ? 'Identity not confirmed' : failed ? "Didn't match: try again" : noBio ? 'Identity check not available' : 'Confirm your identity to clock out'}
              </Text>
              {noBio ? (
                <Text face="Inter-Regular" size={11} lh={1.5} color="text-3" align="center" style={{ marginTop: 3, paddingHorizontal: 10 }}>
                  No fingerprint or face is set up on this phone, so your clock-out is recorded without an identity check.
                </Text>
              ) : null}
              {failed ? (
                <View style={s.attemptDots} role="img" aria-label={`Attempt ${Math.min(m.bio.attempt, MAX_ATTEMPTS)} of ${MAX_ATTEMPTS}`}>
                  {Array.from({ length: MAX_ATTEMPTS }, (_, i) => (
                    <View key={i} style={[s.attemptDot, { backgroundColor: c(i < m.bio.attempt ? 'red-500' : 'border-600') }]} />
                  ))}
                </View>
              ) : null}
            </View>

            {m.bio.locked ? (
              <GuardButton variant="outline" label="Get help from your supervisor" onPress={() => router.push('/help' as never)} style={{ marginTop: 18 }} />
            ) : m.canClockOut ? (
              <GuardButton label={failed ? 'Try again' : 'Clock out'} onPress={m.clockOut} loading={m.busy} style={{ marginTop: 18 }} />
            ) : (
              <GuardButton label="Clock out" onPress={m.clockOut} disabled disabledReason={m.blockedReason ?? 'You are not clocked in on a shift, so there is nothing to clock out of.'} style={{ marginTop: 18 }} />
            )}

            {failed ? (
              <View style={s.footNote}>
                <Icon name="why-note" size={14} color="text-3" style={{ marginTop: 1 }} />
                <View style={{ flexShrink: 1 }}>
                  <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center">
                    {m.bio.locked
                      ? `${MAX_ATTEMPTS} attempts did not match. Your supervisor can verify you and clock you out from the office. Nothing about your fingerprint or face leaves this phone.`
                      : `Attempt ${Math.min(m.bio.attempt, MAX_ATTEMPTS)} of ${MAX_ATTEMPTS}. After ${MAX_ATTEMPTS} failed attempts, you'll need to contact your supervisor to verify and clock you out.`}
                  </Text>
                </View>
              </View>
            ) : !m.online || m.clockInWaiting || m.shiftFromCache ? (
              <View style={s.footNote}>
                <Icon name="offline-note" size={14} color="text-3" style={{ marginTop: 1 }} />
                <View style={{ flexShrink: 1 }}>
                  <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" align="center">
                    {m.clockInWaiting
                      ? 'Your clock-in is still waiting to sync. Your clock-out is saved on this phone and sent straight after it, each with the time it happened.'
                      : !m.online
                        ? "No signal: your clock-out will be saved on this phone and sent the moment you're back online."
                        : 'This is your shift as saved on this phone: it could not be refreshed just now. Your clock-out will be saved here and sent as soon as it can be.'}
                  </Text>
                </View>
              </View>
            ) : null}
          </>
        ) : null}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: c('surface-900') },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  lineSvg: { width: 16, height: 16, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  lineP: { flexShrink: 1 },
  coLateBanner: { flexDirection: 'row', alignItems: 'center', backgroundColor: a('amber-500', 0.1), borderRadius: 14, borderWidth: 1, borderColor: a('amber-500', 0.28), paddingHorizontal: 13, paddingVertical: 11, gap: 10 },
  recapItemSvg: { width: 18, height: 18, overflow: 'hidden', alignSelf: 'center' },
  recapItem: { flex: 1, backgroundColor: c('surface-800'), borderRadius: 12, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 6, paddingVertical: 11 },
  shiftRecap: { flexDirection: 'row', gap: 8, marginTop: 8 },
  coGeo: { flexDirection: 'row', alignItems: 'center', paddingVertical: 6, gap: 10 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14, marginTop: 8 },
  noteField: { marginTop: 4, marginBottom: 6 },
  noteInput: { minHeight: 84, maxHeight: 180, backgroundColor: c('surface-700'), borderRadius: 12, borderWidth: 1, borderColor: c('border-600'), paddingHorizontal: 13, paddingVertical: 11, textAlignVertical: 'top' },
  noteFoot: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 8 },
  noticeCard: { flexDirection: 'row', gap: 11, padding: 13, borderRadius: 14, borderWidth: 1, marginTop: 14, backgroundColor: a('red-500', 0.1), borderColor: a('red-500', 0.3) },
  eRing: { position: 'absolute', width: 126, height: 126, borderStyle: 'dashed', opacity: 0.15, borderRadius: 63, borderWidth: 1, borderColor: c('amber-500') },
  eRing2: { position: 'absolute', width: 108, height: 108, borderStyle: 'dashed', opacity: 0.3, borderRadius: 54, borderWidth: 1, borderColor: c('amber-500') },
  ebBtn: { width: 80, height: 80, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), zIndex: 2, borderRadius: 40, borderWidth: 1, borderColor: c('border-600') },
  ebRingWrap: { width: 108, height: 108, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', marginBottom: 16 },
  enrolBio: { flexDirection: 'column', alignItems: 'center', marginTop: 22 },
  attemptDots: { flexDirection: 'row', gap: 6, marginTop: 10 },
  attemptDot: { width: 7, height: 7, borderRadius: 3.5 },
  footNote: { flexDirection: 'row', justifyContent: 'center', gap: 9, paddingHorizontal: 20, marginTop: 14 },
})
