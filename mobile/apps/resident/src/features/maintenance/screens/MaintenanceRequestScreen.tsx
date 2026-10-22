/**
 * resident-app-11 · New maintenance request — rebuilt from the board (M42 §5 #10), every value from the design tokens
 * and parts.
 *
 * The bar: the back chevron, "New maintenance request", the spacer. Then the board, in its order: Category and its
 * four chips, Plumbing · Electrical · Lighting · Gate, all on one line; Inside my unit / Common area; Describe the
 * problem — the gold microphone, in its rings with "Listening…" and the blue waveform while it listens; the transcript
 * card, "Transcript — tap to edit"; Photos & video — the photos themselves, then the "+"; Urgency; "Allow entry when
 * I'm not home", with nothing under it; Submit request.
 *
 * What each part says and does is the hook's (useMaintenanceRequest), the design's own moment too, in fidelity mode;
 * this file reads no mode. Not on the board, and drawn only when there is something to say: why the microphone is off
 * or has stopped (in its panel, where "Listening…" goes); the first thing to put right, or why nothing was saved (the
 * line above Submit); the sheets behind the "+" and behind a photo; and what became of the request after Submit (the
 * resident boards' success pattern). The segment and the urgency chips are drawn here at board 11's own size: the
 * shared Segment is Invite visitor's (11.5, 20 below).
 *
 * FIXED, NOT A PAGE THAT SCROLLS. The board's own content runs 40 points past 390 × 844 — its "Submit request" is cut
 * even at its own size — so (D3, 7 October) this screen's vertical gaps are tightened just enough, and nothing else:
 * no type is smaller, no element is left out.
 *   between the blocks    18 → 13 under the chips, the segment, the photos and the urgency chips; 16 → 12 under the
 *                         microphone panel and the transcript card
 *   label to its block    15 → 11 (Category, Describe the problem, Photos & video, Urgency)
 *   under the toggle      20 → 16
 * 48 points in all: the board's 40, and the 6 by which the home-indicator inset (26 on the board's phone) is larger
 * than the board's own bottom padding of 20 — Submit never sits under the home indicator — with 1.5 to spare. On a
 * smaller phone (360 × 800), with the keyboard up, or when a long description grows the card, the page scrolls.
 */

import { useRef, useState, useSyncExternalStore, type RefObject } from 'react'
import type { Levels } from '@/features/incidents/dictation'
import { Image, KeyboardAvoidingView, Platform, ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { router } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { ConfirmSheet, Icon, Pressable, Text, aboveGestureBar, c, useFidelityReady } from '@gs/core'
import { AppBar, ButtonRow, Chip, ChipRow, GoldTick, InfoCard, PrimaryButton, SecondaryButton, Toggle } from '@/design/parts'
import { corner, fill, font, icon, ink, line, radius, role, shadow, size, space, stroke } from '@/design/tokens'
import { requestFixture } from '@/fidelity/maintenance'
import { DESCRIPTION_MAX, PRIORITIES, type Area } from '../tickets'
import { useMaintenanceRequest, type MaintenanceRequestModel, type Recorded } from '../useMaintenanceRequest'

const TITLE = 'New maintenance request'
const ENTRY = "Allow entry when I'm not home"
const AREAS: readonly { value: Area; label: string }[] = [
  { value: 'unit', label: 'Inside my unit' },
  { value: 'common', label: 'Common area' },
]

/** `.transcript-box p`: Inter 13.5 at line-height 1.5 — and the card is two of its lines tall while it is empty. */
const BODY = font.inter400_13_5_lh1_5
const LINE = BODY.size * BODY.lh

/** `.wave i`: seven bars, 6 to 18 tall. A level from 0 to 1 sets a bar's height; with no level the bars lie still at 6. */
const BARS = 7
const barHeight = (level: number): number => Math.round(size[6] + Math.min(1, Math.max(0, level)) * (size[18] - size[6]))

/** `.field label` — 12 · 600 · slate-600. */
function Label({ text }: { text: string }) {
  return (
    <View style={s.label}>
      <Text {...role.label} color={ink.label}>{text}</Text>
    </View>
  )
}

/** `.chip-row` · `.cat-chip` — the shared Chip, all four on one line; on a phone too narrow for them a chip wraps, never cut. */
function Categories({ m }: { m: MaintenanceRequestModel }) {
  return (
    <View style={s.chips} role="radiogroup" aria-label="Category">
      <ChipRow wrap>
        {m.chips.map((chip) => (
          <Chip key={chip.id} label={chip.label} on={chip.on} onPress={() => m.chooseChip(chip.id)} />
        ))}
      </ChipRow>
    </View>
  )
}

/** `.seg` · `.seg-item` · `.seg-item.active`, at board 11's size. */
function Where({ m }: { m: MaintenanceRequestModel }) {
  return (
    <View style={s.seg} role="radiogroup" aria-label="Where the problem is">
      {AREAS.map((area) => {
        const on = m.area === area.value
        return (
          <Pressable key={area.value} onPress={() => m.chooseArea(area.value)} role="radio" aria-checked={on} aria-label={area.label} hitSlop={{ top: size[6], bottom: size[6] }} style={on ? s.segOn : s.segItem}>
            <Text {...font.inter700_12} color={on ? ink.onDark : ink.secondary} align="center">{area.label}</Text>
          </Pressable>
        )
      })}
    </View>
  )
}

/**
 * `.wave` — navy-500, as the board draws it (the user, 7 October: the design wins over M42 §5 #10's "gold"), each bar as
 * tall as the microphone's level when it was read (the dictation's own store,
 * ten readings a second, newest on the right). Nothing here moves by itself: no timer, no loop.
 */
function Waveform({ levels, still }: { levels: Levels; still: boolean }) {
  const values = useSyncExternalStore(levels.subscribe, levels.get, levels.get)
  return (
    <View style={s.wave} aria-hidden>
      {Array.from({ length: BARS }, (_, i) => (
        <View key={i} testID="wave-bar" style={[s.bar, { height: barHeight(still ? 0 : (values[i] ?? 0)) }]} />
      ))}
    </View>
  )
}

/** `.mic-panel`: the gold microphone; its rings, "Listening…" and the waveform only while it listens. */
function MicPanel({ m }: { m: MaintenanceRequestModel }) {
  const mic = m.mic
  return (
    <View style={s.micPanel}>
      <Pressable onPress={mic.toggle} role="button" aria-label={mic.listening ? 'Stop listening' : 'Speak your request'} aria-disabled={!mic.available} accessibilityHint={mic.available ? undefined : (mic.notice ?? undefined)} style={s.micWrap}>
        {mic.listening ? <View style={s.ringOuter} /> : null}
        {mic.listening ? <View style={s.ringInner} /> : null}
        <View style={s.micBtn}>
          <Icon name="mic-btn" size={icon[24]} color={ink.heading} />
        </View>
      </Pressable>
      {mic.listening || !mic.notice ? (
        // Kept in place, unseen, while it is not listening: nothing below moves when it starts.
        <View style={mic.listening ? null : s.unseen} aria-hidden={!mic.listening}>
          <View style={s.listening}>
            <Text {...font.inter700_11_5} color={ink.onGoldTint} align="center" role={mic.listening ? 'status' : undefined}>Listening…</Text>
          </View>
          <Waveform levels={mic.levels} still={!mic.listening} />
        </View>
      ) : (
        <View style={s.notice}>
          <Text {...font.inter400_11_5_lh1_5} color={ink.secondary} align="center" role="status">{mic.notice}</Text>
        </View>
      )}
      {mic.model ? <SecondaryButton label="Download the speech model" onPress={mic.model.download} loading={mic.model.downloading} style={s.model} /> : null}
    </View>
  )
}

/** `.transcript-box`: its label, and the words — typed, or heard — which a tap opens for editing. */
function Transcript({ m }: { m: MaintenanceRequestModel }) {
  const t = m.transcript
  const [focused, setFocused] = useState(false)
  // A browser's textarea does not grow by itself; a phone's does.
  const [height, setHeight] = useState(LINE * 2)
  return (
    <View style={[s.transcript, focused || t.attention ? s.transcriptLit : null]}>
      <View style={s.transcriptLabel}>
        <Text {...font.inter700_10_5} color={ink.secondary}>Transcript: tap to edit</Text>
      </View>
      <TextInput
        value={t.value}
        onChangeText={t.change}
        onFocus={() => {
          setFocused(true)
          t.focus()
        }}
        onBlur={() => {
          setFocused(false)
          t.blur()
        }}
        onContentSizeChange={(event) => setHeight(Math.max(LINE * 2, Math.ceil(event.nativeEvent.contentSize.height)))}
        multiline
        scrollEnabled={false}
        maxLength={DESCRIPTION_MAX}
        autoCapitalize="sentences"
        selectionColor={fill.gold}
        // Empty, it shows the board's own words in the design's placeholder grey (the user, 7 October) — gone at the first letter.
        placeholder={requestFixture.transcript}
        placeholderTextColor={c(ink.secondary)}
        aria-label="Describe the problem"
        aria-invalid={t.attention}
        style={[s.input, Platform.OS === 'web' ? { height } : null]}
      />
    </View>
  )
}

/** `.photo-row`: the photos themselves (`.photo-thumb`), then the "+" (`.photo-add`). A photo asks before it is taken back. */
function Photos({ m, addRef }: { m: MaintenanceRequestModel; addRef: RefObject<View | null> }) {
  const p = m.photos
  return (
    <View style={s.photoRow}>
      {p.thumbs.map((thumb, i) => {
        const picture = thumb.uri ? <Image testID={`photo-${i + 1}`} source={{ uri: thumb.uri }} style={s.thumbImage} resizeMode="cover" accessibilityIgnoresInvertColors /> : null
        return thumb.remove ? (
          <Pressable key={thumb.key} onPress={thumb.remove} role="button" aria-label={`Photo ${i + 1}. Remove it`} style={s.thumb}>
            {picture}
          </Pressable>
        ) : (
          <View key={thumb.key} style={s.thumb}>{picture}</View>
        )
      })}
      {p.canAdd ? (
        <Pressable ref={addRef} onPress={p.adding.ask} role="button" aria-label="Add a photo" style={s.add}>
          <Icon name="photo-add" size={icon[20]} color={ink.field} />
        </Pressable>
      ) : null}
    </View>
  )
}

/** Behind the "+": the camera or the phone's photos. Behind a photo: whether to take it back. No board draws either. */
function PhotoSheets({ m, addRef }: { m: MaintenanceRequestModel; addRef: RefObject<View | null> }) {
  const adding = m.photos.adding
  const removing = m.photos.removing
  return (
    <>
      <ConfirmSheet
        open={adding.open}
        title="Add a photo"
        lines={adding.problem ? [adding.problem] : []}
        confirmLabel="Choose from your photos"
        destructive={false}
        working={false}
        blocked={adding.blocked}
        onConfirm={adding.choose}
        onCancel={adding.cancel}
        returnFocusTo={addRef}
      >
        {adding.blocked ? null : (
          <View style={s.sheetActions}>
            <SecondaryButton label="Take a photo" onPress={adding.take} />
          </View>
        )}
      </ConfirmSheet>
      <ConfirmSheet open={removing.open} title="Remove this photo?" lines={[]} confirmLabel="Remove photo" destructive={false} working={false} onConfirm={removing.confirm} onCancel={removing.cancel} returnFocusTo={addRef} />
    </>
  )
}

/** `.urgency-row` · `.urg-chip` · `.urg-chip.active` — gold when chosen. */
function Urgency({ m }: { m: MaintenanceRequestModel }) {
  return (
    <View style={s.urgency} role="radiogroup" aria-label="Urgency">
      {PRIORITIES.map((option) => {
        const on = m.priority === option.value
        return (
          <Pressable key={option.value} onPress={() => m.choosePriority(option.value)} role="radio" aria-checked={on} aria-label={`${option.label} urgency`} hitSlop={{ top: size[5], bottom: size[5] }} style={on ? s.urgOn : s.urg}>
            <Text {...font.inter700_12} color={on ? ink.heading : ink.onTint} align="center">{option.label}</Text>
          </Pressable>
        )
      })}
    </View>
  )
}

/** `.toggle-line` and the shared switch — nothing under it (M42 §5 #10). Asked only for work inside the unit. */
function Entry({ m }: { m: MaintenanceRequestModel }) {
  if (!m.entry.shown) return null
  return (
    <View style={s.toggleLine}>
      <View style={s.shrink}>
        <Text {...font.inter600_12_5} color={ink.body}>{ENTRY}</Text>
      </View>
      <Toggle on={m.entry.on} onChange={m.entry.set} label={ENTRY} />
    </View>
  )
}

/* ---- after Submit: what was saved, and where it stands (no board draws it — the resident boards' success pattern) ---- */

const photoWord = (n: number) => `${n} photo${n === 1 ? '' : 's'}`

function photoLine(r: Recorded): string | null {
  const p = r.photos
  if (p.total === 0) return null
  if (r.state === 'refused') return `${photoWord(p.total)} not sent: the request they belong to was not accepted.`
  if (p.notSaved > 0) return `${photoWord(p.notSaved)} could not be saved on this phone and will not be sent.`
  if (p.refused > 0) return `${photoWord(p.refused)} not accepted by the estate's server. The request itself is not affected.`
  if (p.waiting > 0) return `${photoWord(p.waiting)} will follow by ${p.waiting === 1 ? 'itself' : 'themselves'} once the request has reached the office.`
  return `${photoWord(p.total)} attached.`
}

/** It never says "submitted" until the server has the request. */
function said(r: Recorded): string {
  if (r.state === 'refused') return `${r.refusal ?? 'The estate office did not accept this request.'} Nothing has been filed with the office. You can change it and send it again.`
  if (r.state === 'confirmed') return r.ticketId ? 'The estate office has it. You can follow every step from here.' : 'The estate office has it.'
  if (r.state === 'syncing') return 'Saved on this phone: being sent to the estate office now.'
  return 'Saved on this phone: it will be sent when you are back online. You do not need to keep this screen open.'
}

/**
 * SAVED ON THIS PHONE — waiting for a connection, or being sent now. Its honest mark stands in the gold tick's place and
 * keeps its shape — the two faint rings, the 62-point disc — in navy, holding the phone: nothing has reached the
 * estate office yet, and the gold tick is only for a request the office has.
 */
function SavedMark() {
  return (
    <View style={s.mark}>
      <View style={s.savedRingOuter} />
      <View style={s.savedRingInner} />
      <View style={s.savedDisc}>
        <Icon name="device-row" size={icon[30]} color={ink.action} />
      </View>
    </View>
  )
}

/**
 * …laid out as the resident boards' success pattern (design/parts Success), straight under the bar: the mark, the
 * title, the request's own title as its line, the info card in the detail card's place, two buttons side by side —
 * "Try sending now" only while it waits — and "Report another problem" under them. It says what it always said.
 */
function Saved({ r, m }: { r: Recorded; m: MaintenanceRequestModel }) {
  // Done goes to where this request can be followed, not back to a Home that has no trace of it (M98, Hassam: "I am unable to track that").
  const done = () => router.replace('/requests' as never)
  return (
    <View style={s.saved}>
      <SavedMark />
      <Text {...font.poppins600_20} color={ink.heading} align="center" role="heading">{'Saved on this phone'}</Text>
      <View style={s.outcomeLine}>
        <Text {...font.inter400_12_lh1_5} color={ink.secondary} align="center">{r.title}</Text>
      </View>
      <View style={s.outcomeInfo} role="status">
        <InfoCard text={[said(r), photoLine(r)].filter(Boolean).join(' ')} />
      </View>
      <ButtonRow style={s.outcomeButtons}>
        <SecondaryButton label="Done" onPress={done} />
        {r.state === 'pending' ? <PrimaryButton label="Try sending now" onPress={m.retryNow} /> : null}
      </ButtonRow>
      <SecondaryButton label="Report another problem" onPress={m.startAnother} style={s.another} />
    </View>
  )
}

/** The gold tick is for a request the office has; one still on this phone, or refused, is not a success. */
function Mark({ r }: { r: Recorded }) {
  if (r.state === 'confirmed') return <GoldTick />
  if (r.state !== 'refused') return <SavedMark />
  return (
    <View style={s.mark}>
      <View style={s.markRefused}>
        <Icon name="qa-tile-3" size={icon[30]} color={ink.onGoldTint} />
      </View>
    </View>
  )
}

function Outcome({ r, m }: { r: Recorded; m: MaintenanceRequestModel }) {
  // Done goes to where this request can be followed, not back to a Home that has no trace of it (M98, Hassam: "I am unable to track that").
  const done = () => router.replace('/requests' as never)
  const ticketId = r.ticketId
  const next =
    r.state === 'refused' ? { label: 'Edit and resend', onPress: m.changeAndResend }
    : r.state === 'pending' ? { label: 'Try sending now', onPress: m.retryNow }
    : r.state === 'confirmed' && ticketId ? { label: 'Follow request', onPress: () => router.replace(`/maintenance/${ticketId}` as never) }
    : null
  const title = r.state === 'refused' ? 'Not accepted' : r.state === 'confirmed' ? (r.number ? `Request #${r.number} submitted` : 'Request submitted') : 'Saved on this phone'
  return (
    <View style={s.outcome}>
      <Mark r={r} />
      <Text {...font.poppins600_20} color={ink.heading} align="center" role="heading">{title}</Text>
      <View style={s.outcomeLine}>
        <Text {...font.inter400_12_lh1_5} color={ink.secondary} align="center">{r.title}</Text>
      </View>
      <View style={s.outcomeInfo} role={r.state === 'refused' ? 'alert' : 'status'}>
        <InfoCard text={[said(r), photoLine(r)].filter(Boolean).join(' ')} />
      </View>
      <ButtonRow style={s.outcomeButtons}>
        <SecondaryButton label="Done" onPress={done} />
        {next ? <PrimaryButton label={next.label} onPress={next.onPress} /> : null}
      </ButtonRow>
      <SecondaryButton label="Report another problem" onPress={m.startAnother} style={s.another} />
    </View>
  )
}

/* ---- the screen ---------------------------------------------------------------------------------- */

export default function MaintenanceRequestScreen() {
  const m = useMaintenanceRequest()
  const insets = useSafeAreaInsets()
  const addRef = useRef<View>(null)
  useFidelityReady(true)
  // .body-pad's 20 below — or the home-indicator inset, where that is larger.
  const bottom = { paddingBottom: aboveGestureBar(space[20], insets.bottom) }

  if (m.recorded) {
    // Still on this phone: the success pattern's own page, laid out fixed. The office has it, or refused it: as before.
    const onPhone = m.recorded.state === 'pending' || m.recorded.state === 'syncing'
    return (
      <View style={s.screen}>
        <AppBar title={TITLE} back={false} />
        {onPhone ? (
          <Saved r={m.recorded} m={m} />
        ) : (
          <ScrollView style={s.fill} contentContainerStyle={[s.bodyPad, bottom]}>
            <Outcome r={m.recorded} m={m} />
          </ScrollView>
        )}
      </View>
    )
  }

  return (
    <View style={s.screen}>
      <AppBar title={TITLE} />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
        <ScrollView style={s.fill} contentContainerStyle={[s.bodyPad, bottom]} keyboardShouldPersistTaps="handled" alwaysBounceVertical={false}>
          <Label text="Category" />
          <Categories m={m} />
          <Where m={m} />
          <Label text="Describe the problem" />
          <MicPanel m={m} />
          <Transcript m={m} />
          <Label text="Photos & video" />
          <Photos m={m} addRef={addRef} />
          <Label text="Urgency" />
          <Urgency m={m} />
          <Entry m={m} />
          {m.problem ? (
            <View style={s.problem}>
              <Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{m.problem}</Text>
            </View>
          ) : null}
          <PrimaryButton label="Submit request" onPress={m.send} loading={m.busy} />
        </ScrollView>
      </KeyboardAvoidingView>
      <PhotoSheets m={m} addRef={addRef} />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  // .body-pad (its bottom is set by the screen: the board's 20, or the home-indicator inset)
  bodyPad: { paddingTop: space[16], paddingHorizontal: space[20] },
  // .field label — 15 to its block on the board, 11 here (D3)
  label: { marginBottom: space[11] },
  // .chip-row — 18 below on the board, 13 here (D3)
  chips: { marginBottom: space[13] },
  // .seg · .seg-item · .seg-item.active — 18 below on the board, 13 here (D3)
  seg: { flexDirection: 'row', backgroundColor: fill.tint, borderRadius: radius.segment, padding: space[4], marginBottom: space[13] },
  segItem: { flex: 1, borderRadius: radius.segmentItem, paddingVertical: space[9], paddingHorizontal: space[4] },
  segOn: { flex: 1, borderRadius: radius.segmentItem, paddingVertical: space[9], paddingHorizontal: space[4], backgroundColor: fill.primary, ...shadow.segmentActive },
  // .mic-panel — 16 below on the board, 12 here (D3)
  micPanel: { alignItems: 'center', backgroundColor: fill.tint, borderRadius: radius.heroCard, paddingVertical: space[20], paddingHorizontal: space[16], marginBottom: space[12] },
  // .mic-wrap · .m-ring.b · .m-ring.a · .mic-btn
  micWrap: { width: size[74], height: size[74], alignItems: 'center', justifyContent: 'center', marginBottom: space[10] },
  ringOuter: { position: 'absolute', width: size[90], height: size[90], borderRadius: corner.round(size[90]), borderWidth: stroke['1.5'], borderColor: line.focus, opacity: 0.16 },
  ringInner: { position: 'absolute', width: size[74], height: size[74], borderRadius: corner.round(size[74]), borderWidth: stroke['1.5'], borderColor: line.focus, opacity: 0.3 },
  micBtn: { width: size[54], height: size[54], borderRadius: corner.round(size[54]), alignItems: 'center', justifyContent: 'center', backgroundColor: fill.gold, zIndex: 2 },
  // .listening · .wave · .wave i
  listening: { marginBottom: space[10] },
  wave: { height: size[20], flexDirection: 'row', alignItems: 'flex-end', gap: space[3], marginBottom: space[4] },
  bar: { width: size[3], borderRadius: corner[2], backgroundColor: fill.dot },
  unseen: { opacity: 0 },
  notice: { paddingHorizontal: space[4] },
  model: { alignSelf: 'stretch', marginTop: space[12] },
  // .transcript-box · .tl · p — 16 below on the board, 12 here (D3); the gold edge while it is edited or needs a word
  transcript: { backgroundColor: fill.surface, borderRadius: radius.field, borderWidth: stroke['1.5'], borderColor: line.outline, padding: space[13], marginBottom: space[12] },
  transcriptLit: { borderColor: line.focus },
  transcriptLabel: { marginBottom: space[6] },
  input: { fontFamily: BODY.face, fontSize: BODY.size, lineHeight: LINE, color: c(ink.heading), minHeight: LINE * 2, padding: 0, margin: 0, textAlignVertical: 'top', includeFontPadding: false },
  // .photo-row · .photo-thumb · .photo-add — 18 below on the board, 13 here (D3)
  photoRow: { flexDirection: 'row', flexWrap: 'wrap', gap: space[9], marginBottom: space[13] },
  thumb: { width: size[60], height: size[60], borderRadius: radius.tile, backgroundColor: fill.divider, overflow: 'hidden' },
  thumbImage: { width: size[60], height: size[60] },
  add: { width: size[60], height: size[60], borderRadius: radius.tile, borderWidth: stroke['1.5'], borderStyle: 'dashed', borderColor: line.muted, alignItems: 'center', justifyContent: 'center' },
  // .urgency-row · .urg-chip · .urg-chip.active — 18 below on the board, 13 here (D3)
  urgency: { flexDirection: 'row', gap: space[8], marginBottom: space[13] },
  urg: { flex: 1, borderRadius: radius.tile, paddingVertical: space[10], paddingHorizontal: space[4], backgroundColor: fill.tint },
  urgOn: { flex: 1, borderRadius: radius.tile, paddingVertical: space[10], paddingHorizontal: space[4], backgroundColor: fill.gold },
  // .toggle-line — padding 2 2 20 on the board, 2 2 16 here (D3)
  toggleLine: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingTop: space[2], paddingHorizontal: space[2], paddingBottom: space[16] },
  // Not on the board: the first thing to put right, above Submit, as the shared Field says its own.
  problem: { marginHorizontal: space[2], marginBottom: space[10] },
  // After Submit (no board draws it): the success pattern's spacing.
  outcome: { alignItems: 'center', paddingTop: space[30] },
  mark: { width: size[84], height: size[84], alignItems: 'center', justifyContent: 'center', marginBottom: space[16] },
  markRefused: { width: size[62], height: size[62], borderRadius: corner.round(size[62]), alignItems: 'center', justifyContent: 'center', backgroundColor: fill.goldTint },
  outcomeLine: { marginTop: space[5] },
  outcomeInfo: { alignSelf: 'stretch', marginTop: space[20] },
  outcomeButtons: { marginTop: space[16] },
  another: { alignSelf: 'stretch', marginTop: space[10] },
  // Saved on this phone: the success pattern's page (parts/Success.tsx) — 30 under the bar, 24 at the sides — and its
  // mark the gold tick's rings and disc (parts/Success.tsx) in navy: line.active at the rings' own .14 and .28, navy-100
  saved: { alignItems: 'center', paddingTop: space[30], paddingHorizontal: space[24] },
  savedRingOuter: { position: 'absolute', width: size[100], height: size[100], borderRadius: corner.round(size[100]), borderWidth: stroke['1.5'], borderColor: line.active, opacity: 0.14 },
  savedRingInner: { position: 'absolute', width: size[84], height: size[84], borderRadius: corner.round(size[84]), borderWidth: stroke['1.5'], borderColor: line.active, opacity: 0.28 },
  savedDisc: { width: size[62], height: size[62], borderRadius: corner.round(size[62]), alignItems: 'center', justifyContent: 'center', backgroundColor: fill.tint, zIndex: 2 },
  // Inside the photo sheet (no board draws it).
  sheetActions: { marginTop: space[14] },
})
