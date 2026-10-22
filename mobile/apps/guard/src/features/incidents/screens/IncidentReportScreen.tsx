/**
 * guard-app-08 · Incident Report — ONE scrolling screen with a sticky Submit; no wizard. Styles are the
 * board's own, copied from the lifted file (src/lifted/GuardApp08.tsx). All behaviour lives in
 * useIncidentReport(); this file only draws it.
 *
 * What the live screen has that the board does not draw: a severity control (the server requires one —
 * drawn as board 10's segmented control), the live character count, validation messages, and the
 * reasons beside anything this phone cannot do (dictation in Expo Go, photos without the picker).
 * What the board draws that the server does not have: a patrol link — drawn as a statement of what the
 * server really does (it links the running shift by itself), never as a switch that pretends.
 *
 * The board draws Submit at the end of the form; on a phone it is pinned under the scroll so it is one
 * thumb away however long the account is. (The fidelity build draws it where the board does.)
 * Append-only: once filed, a report is never edited or withdrawn from here.
 */

import { useRef, useState } from 'react'
import { Image, Platform, ScrollView, StyleSheet, TextInput, View, useWindowDimensions } from 'react-native'
import { router } from 'expo-router'
import { Controller } from 'react-hook-form'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { AppBar, GuardButton, Icon, Pressable, SiteMap, Text, a, aboveGestureBar, c, useFidelityReady, type ColorToken } from '@gs/core'
import { OUTBOX_LABEL } from '@gs/core/offline'
import { INFO } from '@/info/topics'
import { DETAIL_MAX, LOCATION_MAX, SEVERITIES, photoTileWidth } from '../incidents'
import { useIncidentReport, type IncidentReportModel, type Recorded } from '../useIncidentReport'

const WAVE = [7, 16, 10, 19, 9, 14, 6] as const
const DETAIL_MIN_HEIGHT = 78

function FieldError({ message }: { message: string | undefined }) {
  return message ? <Text face="Inter-Medium" size={10.5} lh={1.45} color="red-500" style={s.fieldNote} role="alert">{message}</Text> : null
}

const STATE_TONE: Record<Recorded['state'], ColorToken> = { pending: 'amber-500', syncing: 'amber-500', confirmed: 'green-400', refused: 'red-500' }

const photoWord = (n: number) => `${n} photo${n === 1 ? '' : 's'}`

/** What was recorded, and where it stands. No board draws it; it is built from board 08's own mic button and type. */
function RecordedPanel({ recorded, onFileAgain }: { recorded: Recorded; onFileAgain: () => void }) {
  const refused = recorded.state === 'refused'
  const tone = STATE_TONE[recorded.state]
  const p = recorded.photos
  const photoLine =
    p.total === 0 ? null
    : refused ? `${photoWord(p.total)} not sent: the report they belong to was not accepted.`
    : p.notSaved > 0 ? `${photoWord(p.notSaved)} could not be saved on this phone and will not be sent.`
    : p.refused > 0 ? `${photoWord(p.refused)} not accepted by the server: see the sync queue.`
    : p.waiting > 0 ? `${photoWord(p.waiting)} upload${p.waiting === 1 ? 's' : ''} by ${p.waiting === 1 ? 'itself' : 'themselves'} once the report has reached the office.`
    : `${photoWord(p.total)} uploaded.`

  return (
    <View style={s.recorded} role="status">
      <View style={[s.recordedBadge, { backgroundColor: a(tone, 0.14), borderColor: a(tone, 0.4) }]}>
        <Icon name={refused ? 'qa-tile-3' : 'check'} size={30} color={tone} />
      </View>
      <Text face="Poppins-SemiBold" size={18} color="white" align="center" role="heading">{refused ? 'Not accepted by the server' : 'Report recorded'}</Text>
      <Text face="Inter-Medium" size={12} lh={1.5} color="text-2" align="center" style={{ marginTop: 6 }}>{recorded.category}</Text>

      <View style={[s.pill, { backgroundColor: a(tone, 0.14) }]}>
        <Text face="Inter-Bold" size={10.5} color={tone}>{OUTBOX_LABEL[recorded.state]}</Text>
      </View>
      <Text face="Inter-Regular" size={12} lh={1.5} color={refused ? 'red-500' : 'text-2'} align="center" style={{ marginTop: 10 }} role={refused ? 'alert' : undefined}>
        {refused
          ? `${recorded.refusal ?? 'The server did not accept this report.'} Nothing was filed. The attempt stays in the sync queue as a record.`
          : recorded.state === 'confirmed' ? 'Filed in the incident register. The office can see it now.'
          : recorded.state === 'syncing' ? 'Saved on this phone: being sent to the office now.'
          : 'Saved on this phone. It is sent to the office by itself when signal returns.'}
      </Text>
      {photoLine ? <Text face="Inter-Regular" size={11} lh={1.5} color="text-3" align="center" style={{ marginTop: 6 }}>{photoLine}</Text> : null}

      <GuardButton label="Done" onPress={() => router.replace('/(tabs)')} style={s.full} />
      <GuardButton variant="outline" label="View my reports" onPress={() => router.replace('/reports' as never)} style={s.fullTight} />
      {refused ? <GuardButton variant="outline" label="File it again" onPress={onFileAgain} style={s.fullTight} /> : null}
    </View>
  )
}

function Severity({ m }: { m: IncidentReportModel }) {
  return (
    <View style={s.field}>
      <Text face="Inter-SemiBold" size={11.5} color="text-2">{'How serious is it?'}</Text>
      <View style={[s.seg, m.errors.severity ? { borderColor: c('red-500') } : null]} role="radiogroup" aria-label="Severity">
        {SEVERITIES.map((option) => {
          const on = m.severity === option.value
          return (
            <Pressable key={option.value} onPress={() => m.chooseSeverity(option.value)} role="radio" aria-checked={on} aria-label={`${option.label} severity`} hitSlop={{ top: 8, bottom: 8 }} style={on ? s.segItemOn : s.segItem}>
              <Text face="Inter-Bold" size={12} color={on ? 'g-navy-900' : 'text-2'} align="center">{option.label}</Text>
            </Pressable>
          )
        })}
      </View>
      <FieldError message={m.errors.severity} />
    </View>
  )
}

function MicPanel({ m }: { m: IncidentReportModel }) {
  const d = m.dictation
  return (
    <View style={s.micPanel}>
      <Pressable
        onPress={d.toggle}
        disabled={!d.available || !!m.fixture}
        role="button"
        aria-label={d.listening ? 'Stop dictating' : 'Dictate the report'}
        aria-disabled={!d.available}
        accessibilityHint={d.reason ?? undefined}
        style={s.micWrap}
      >
        {d.available ? <View style={s.mRing} /> : null}
        {d.available ? <View style={s.mRing2} /> : null}
        <View style={[s.micBtn, d.available ? null : { backgroundColor: c('surface-700') }]}>
          <Icon name="mic-btn" size={24} color={d.available ? 'g-navy-900' : 'text-3'} style={s.micBtnSvg} />
        </View>
      </Pressable>

      {d.available ? (
        <Text face="Inter-Bold" size={11.5} color="amber-500" style={d.listening ? { marginBottom: 10 } : null} role="status">{d.listening ? 'Listening…' : 'Tap to dictate'}</Text>
      ) : (
        <Text face="Inter-Medium" size={11} lh={1.5} color="text-3" align="center">{d.reason}</Text>
      )}
      {d.listening ? (
        <View style={s.wave} aria-hidden>
          {WAVE.map((height, i) => <View key={i} style={[s.waveBar, { height }]} />)}
        </View>
      ) : null}
      {/* What the phone is hearing, as it hears it (M12 §2.9); it joins the report below once it settles. */}
      {d.hearing ? <Text face="Inter-Medium" size={13} lh={1.5} color="white" align="center" style={{ marginTop: 8 }} role="status" aria-live="polite">{`“${d.hearing}”`}</Text> : null}
      {d.trouble ? <Text face="Inter-Medium" size={10.5} lh={1.45} color="red-500" align="center" style={{ marginTop: 8 }} role="alert">{d.trouble}</Text> : null}
      {d.note ? <Text face="Inter-Medium" size={10.5} lh={1.45} color="text-2" align="center" style={{ marginTop: 8 }} role="status">{d.note}</Text> : null}
      {d.model ? (
        <Pressable onPress={d.model.download} disabled={d.model.downloading} role="button" aria-label="Download the speech model" style={s.modelBtn}>
          <Text face="Inter-Bold" size={12} color="g-navy-900">{d.model.downloading ? 'Downloading…' : 'Download the speech model'}</Text>
        </Pressable>
      ) : null}
    </View>
  )
}

function Account({ m }: { m: IncidentReportModel }) {
  const [focused, setFocused] = useState(false)
  const [height, setHeight] = useState(DETAIL_MIN_HEIGHT)
  const label = m.fixture ? m.fixture.transcriptLabel : m.dictation.available ? 'Transcript: tap to edit' : 'What happened'

  return (
    <>
      <View style={[s.transcriptBox, focused ? { borderColor: c('amber-500') } : null, m.errors.detail ? { borderColor: c('red-500') } : null]}>
        <Text face="Inter-Bold" size={10.5} color="text-3">{label}</Text>
        {m.fixture ? (
          <Text face="Inter-Regular" size={13} lh={1.5} color="white" style={{ marginTop: 6 }}>{m.fixture.transcript}</Text>
        ) : (
          <Controller
            control={m.control}
            name="detail"
            render={({ field }) => (
              <TextInput
                ref={field.ref}
                value={field.value}
                onChangeText={field.onChange}
                onFocus={() => setFocused(true)}
                onBlur={() => {
                  field.onBlur()
                  setFocused(false)
                }}
                // The web's textarea does not grow by itself; a phone's does.
                onContentSizeChange={(event) => setHeight(Math.max(DETAIL_MIN_HEIGHT, Math.ceil(event.nativeEvent.contentSize.height)))}
                multiline
                scrollEnabled={false}
                maxLength={DETAIL_MAX}
                placeholder="e.g. 10:40 PM: two men climbing the wall behind Lot 63; left on foot towards the main road."
                placeholderTextColor={c('text-3')}
                selectionColor={c('amber-500')}
                autoCapitalize="sentences"
                aria-label="What happened"
                aria-invalid={!!m.errors.detail}
                maxFontSizeMultiplier={1.3}
                style={[s.detailInput, Platform.OS === 'web' ? { height } : null]}
              />
            )}
          />
        )}
      </View>
      {m.fixture ? null : (
        <Text face="Inter-Medium" size={10.5} lh={1.45} color={m.count.ok ? 'green-400' : 'text-3'} style={s.fieldNote} role="status">{m.count.label}</Text>
      )}
      <FieldError message={m.errors.detail} />
    </>
  )
}

function Photos({ m }: { m: IncidentReportModel }) {
  const p = m.photos
  // Measured, so five tiles stay on one row on a narrow phone as well as on the board's 397 points.
  const side = photoTileWidth(useWindowDimensions().width)
  const tile = { width: side, height: side }
  return (
    <>
      <View style={s.photoRow}>
        {m.fixture ? Array.from({ length: m.fixture.photoThumbs }, (_, i) => <View key={i} style={[s.photoThumb, tile]} />) : null}
        {p.items.map((photo, i) => (
          <View key={photo.uri} style={[s.photoThumb, tile]}>
            <Image source={{ uri: photo.uri }} style={s.photoImage} aria-label={`Photo ${i + 1}`} />
            <Pressable onPress={() => p.remove(photo.uri)} role="button" aria-label={`Remove photo ${i + 1} from this report`} hitSlop={14} style={s.photoRemove}>
              <Icon name="act-result" size={11} color="white" />
            </Pressable>
          </View>
        ))}
        {m.fixture || p.canAdd || !p.available ? (
          <Pressable
            onPress={() => p.add('camera')}
            disabled={!p.canAdd}
            role="button"
            aria-label="Take a photo"
            aria-disabled={!p.canAdd}
            accessibilityHint={p.reason ?? undefined}
            style={[s.photoAdd, tile]}
          >
            <Icon name="photo-add" size={19} color="text-3" style={s.photoAddSvg} />
          </Pressable>
        ) : null}
        {!m.fixture && !p.available ? (
          <View style={s.photoReason}>
            <Text face="Inter-Medium" size={10.5} lh={1.45} color="text-3">{p.reason}</Text>
          </View>
        ) : null}
      </View>
      {p.available && p.canAdd ? (
        <Pressable onPress={() => p.add('library')} role="button" aria-label="Choose photos from the gallery" hitSlop={14} style={s.galleryLink}>
          <Text face="Inter-Bold" size={11.5} color="amber-500">{'Choose from the gallery'}</Text>
        </Pressable>
      ) : null}
      {p.trouble ? <Text face="Inter-Medium" size={10.5} lh={1.45} color="red-500" style={s.fieldNote} role="alert">{p.trouble}</Text> : null}
    </>
  )
}

function LocationRow({ m }: { m: IncidentReportModel }) {
  const input = useRef<TextInput | null>(null)
  const pinned = m.fixture ? true : m.location.gps.state === 'fixed'
  return (
    <>
      <Pressable onPress={() => input.current?.focus()} disabled={!!m.fixture} role="group" aria-label="Location" style={[s.locRow, m.errors.location ? { borderColor: c('red-500') } : null]}>
        <Icon name="loc-row" size={18} color={pinned ? 'amber-500' : 'text-3'} style={s.locRowSvg} />
        <View style={s.fill}>
          {m.fixture ? (
            <Text face="Inter-SemiBold" size={12.5} color="white">{m.fixture.location.title}</Text>
          ) : (
            <Controller
              control={m.control}
              name="location"
              render={({ field }) => (
                <TextInput
                  ref={(node) => {
                    input.current = node
                    field.ref(node)
                  }}
                  value={field.value}
                  onChangeText={field.onChange}
                  onBlur={field.onBlur}
                  maxLength={LOCATION_MAX}
                  placeholder="Where it happened: e.g. back wall by Lot 63"
                  placeholderTextColor={c('text-3')}
                  selectionColor={c('amber-500')}
                  autoCapitalize="words"
                  returnKeyType="done"
                  aria-label="Where it happened"
                  aria-invalid={!!m.errors.location}
                  maxFontSizeMultiplier={1.3}
                  style={s.locInput}
                />
              )}
            />
          )}
          <Text face="Inter-Regular" size={10.5} color="text-3" role="status">{m.location.gpsLabel}</Text>
        </View>
      </Pressable>
      <FieldError message={m.errors.location} />
      {/*
        THE POSITION THE REPORT WILL CARRY, confirmed on the phone's own map before it is sent (M11 §6) — or, with
        no signal or no map on this phone, the same place in words. Nothing here reads the position again.
      */}
      {!m.fixture && m.location.gps.state === 'fixed' ? (
        <View style={s.locMap}>
          <SiteMap
            tone="dark"
            label="Where the incident is reported"
            offline={!m.online}
            height={140}
            pins={[{ id: 'incident', latitude: m.location.gps.fix.latitude, longitude: m.location.gps.fix.longitude, title: m.location.value.trim() || 'Incident position', tone: 'danger' }]}
          />
        </View>
      ) : null}
    </>
  )
}

export default function IncidentReportScreen() {
  const m = useIncidentReport()
  useFidelityReady(true)
  const insets = useSafeAreaInsets()
  const footNote = m.saveError ?? m.firstError

  if (m.recorded) {
    return (
      <View style={s.screen}>
        <AppBar title="New incident report" left={null} />
        <ScrollView contentContainerStyle={s.bodyPad}>
          <RecordedPanel recorded={m.recorded} onFileAgain={m.fileAgain} />
        </ScrollView>
      </View>
    )
  }

  const submitButton = <GuardButton label="Submit report" onPress={m.send} loading={m.busy} style={m.fixture ? { marginTop: 18 } : undefined} />

  return (
    <View style={s.screen}>
      <AppBar title="New incident report" info={INFO.incident} />
      <ScrollView style={s.fill} contentContainerStyle={s.bodyPad} keyboardShouldPersistTaps="handled" automaticallyAdjustKeyboardInsets>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={s.chipRow} keyboardShouldPersistTaps="handled" role="radiogroup" aria-label="Kind of incident">
          {m.categories.map((category) => {
            const on = m.category === category.id
            return (
              <Pressable key={category.id} onPress={() => m.chooseCategory(category.id)} disabled={!!m.fixture} role="radio" aria-checked={on} aria-label={category.label} hitSlop={{ top: 9, bottom: 9 }} style={on ? s.fChipOn : s.fChip}>
                <Text face="Inter-Bold" size={11.5} color={on ? 'g-navy-900' : 'text-2'} numberOfLines={1}>{category.label}</Text>
              </Pressable>
            )
          })}
        </ScrollView>
        <FieldError message={m.errors.category} />

        {/* The board draws no severity control; the server requires one. Drawn as board 10's segmented control. */}
        {m.fixture ? null : <Severity m={m} />}

        <MicPanel m={m} />
        <Account m={m} />
        <Photos m={m} />
        <LocationRow m={m} />

        {/* The server links the running shift by itself: a statement of that, not a switch to flip. */}
        <View style={s.linkRow}>
          <View style={s.shrink}>
            <Text face="Inter-SemiBold" size={12.5} color="white">{m.shiftLink.label}</Text>
            {m.shiftLink.detail ? <Text face="Inter-Regular" size={10.5} lh={1.45} color="text-3" style={{ marginTop: 2 }}>{m.shiftLink.detail}</Text> : null}
          </View>
          <View style={m.shiftLink.on ? s.switchOn : s.switchOff} role="switch" aria-checked={m.shiftLink.on} aria-disabled aria-label={m.shiftLink.label} accessibilityHint={m.shiftLink.detail ?? undefined}>
            <View style={m.shiftLink.on ? s.switchKnobOn : s.switchKnobOff} />
          </View>
        </View>

        {m.fixture ? submitButton : null}
      </ScrollView>

      {m.fixture ? null : (
        <View style={[s.footer, { paddingBottom: aboveGestureBar(12, insets.bottom) }]}>
          {footNote ? (
            <Text face="Inter-SemiBold" size={11} lh={1.45} color="red-500" align="center" style={s.footNote} role="alert">{footNote}</Text>
          ) : m.online ? null : (
            <Text face="Inter-Regular" size={10.5} lh={1.45} color="text-3" align="center" style={s.footNote}>{"No signal: your report is saved on this phone and sent the moment you're back online."}</Text>
          )}
          {submitButton}
        </View>
      )}
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  field: { marginTop: 16 },
  fieldNote: { marginTop: 5, marginHorizontal: 2 },
  fChipOn: { backgroundColor: c('amber-500'), borderRadius: 20, borderWidth: 1, borderColor: c('amber-500'), paddingHorizontal: 14, paddingVertical: 8 },
  fChip: { backgroundColor: c('surface-800'), borderRadius: 20, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 8 },
  chipRow: { flexDirection: 'row', gap: 8 },
  // Board 10's .seg — the board's own segmented control.
  seg: { flexDirection: 'row', backgroundColor: c('surface-800'), borderRadius: 13, borderWidth: 1, borderColor: c('border-700'), padding: 4, marginTop: 6 },
  segItemOn: { flex: 1, backgroundColor: c('amber-500'), borderRadius: 10, paddingHorizontal: 4, paddingVertical: 9 },
  segItem: { flex: 1, borderRadius: 10, paddingHorizontal: 4, paddingVertical: 9 },
  mRing: { position: 'absolute', width: 90, height: 90, opacity: 0.15, borderRadius: 45, borderWidth: 1, borderColor: c('amber-500') },
  mRing2: { position: 'absolute', width: 74, height: 74, opacity: 0.3, borderRadius: 37, borderWidth: 1, borderColor: c('amber-500') },
  micBtnSvg: { width: 24, height: 24, overflow: 'hidden' },
  micBtn: { width: 54, height: 54, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500'), zIndex: 2, borderRadius: 27 },
  micWrap: { width: 74, height: 74, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', marginBottom: 10 },
  waveBar: { width: 3, backgroundColor: c('navy-500'), borderRadius: 2 },
  wave: { height: 20, flexDirection: 'row', alignItems: 'flex-end', gap: 3 },
  micPanel: { flexDirection: 'column', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 18, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 16, paddingVertical: 20, marginTop: 16 },
  modelBtn: { marginTop: 10, minHeight: 44, paddingHorizontal: 16, borderRadius: 12, alignItems: 'center', justifyContent: 'center', backgroundColor: c('amber-500') },
  transcriptBox: { backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), padding: 13, marginTop: 16 },
  detailInput: { fontFamily: 'Inter-Regular', fontSize: 13, lineHeight: 19.5, color: c('white'), minHeight: DETAIL_MIN_HEIGHT, marginTop: 6, padding: 0, textAlignVertical: 'top' },
  photoThumb: { width: 58, height: 58, flexGrow: 0, flexShrink: 0, backgroundColor: c('surface-700'), borderRadius: 12, borderWidth: 1, borderColor: c('border-700'), overflow: 'hidden' },
  photoImage: { width: '100%', height: '100%' },
  photoRemove: { position: 'absolute', top: 3, right: 3, width: 20, height: 20, borderRadius: 10, alignItems: 'center', justifyContent: 'center', backgroundColor: a('black', 0.55) },
  photoAddSvg: { width: 19, height: 19, overflow: 'hidden' },
  photoAdd: { width: 58, height: 58, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderStyle: 'dashed', borderRadius: 12, borderWidth: 1, borderColor: c('border-600') },
  photoReason: { flex: 1, justifyContent: 'center' },
  photoRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 9, marginTop: 16 },
  galleryLink: { alignSelf: 'flex-start', marginTop: 10, marginHorizontal: 2 },
  locRowSvg: { width: 18, height: 18, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  locRow: { flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 12, gap: 11, marginTop: 18 },
  locInput: { fontFamily: 'Inter-SemiBold', fontSize: 12.5, color: c('white'), height: 22, padding: 0 },
  locMap: { marginTop: 10 },
  switchKnobOn: { position: 'absolute', top: 2.5, right: 2.5, width: 18, height: 18, backgroundColor: c('g-navy-900'), borderRadius: 9 },
  switchKnobOff: { position: 'absolute', top: 2.5, left: 2.5, width: 18, height: 18, backgroundColor: c('text-3'), borderRadius: 9 },
  switchOn: { width: 40, height: 23, flexGrow: 0, flexShrink: 0, backgroundColor: c('amber-500'), borderRadius: 20 },
  switchOff: { width: 40, height: 23, flexGrow: 0, flexShrink: 0, backgroundColor: c('surface-700'), borderRadius: 20 },
  linkRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 12, gap: 12, marginTop: 12 },
  footer: { paddingTop: 12, paddingHorizontal: 20, borderTopWidth: 1, borderTopColor: c('border-700'), backgroundColor: c('surface-900') },
  footNote: { marginBottom: 10 },
  // The recorded state: no board draws it.
  recorded: { flexDirection: 'column', alignItems: 'center', paddingTop: 24, paddingHorizontal: 4 },
  recordedBadge: { width: 74, height: 74, borderRadius: 37, borderWidth: 1, alignItems: 'center', justifyContent: 'center', marginBottom: 20 },
  pill: { paddingHorizontal: 12, paddingVertical: 5, borderRadius: 20, marginTop: 14 },
  full: { width: '100%', marginTop: 24 },
  fullTight: { width: '100%', marginTop: 10, borderColor: c('border-600') },
  // The board's .btn-primary (52 · radius 15 · amber glow) is GuardButton's primary.
})
