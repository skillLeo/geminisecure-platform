/**
 * resident-app-14 · Report incident — rebuilt from the client's board (M42 §5 #15), every value from the design's
 * tokens and shared parts. All behaviour lives in useIncidentReport(); this file only draws it, the same way for a
 * phone and for the board's own content.
 *
 * THE BOARD, TOP TO BOTTOM: the slim app bar · four type chips · the microphone, "Listening…" and its waveform ·
 * "Transcript — tap to edit" · the photo row (a photo, a video, the add tile) · where, "Pinned from current
 * location", with a gold "Change" · "Keep my name private from other residents" · "Submit report". Nothing else is
 * drawn on the form: no emergency panel, no title field, no when or seriousness, no "Your reports" (the client
 * removed them all). With the board's content the page fits a 390 × 844 phone and nothing moves; on a smaller phone,
 * or with larger text, it scrolls — nothing is ever cut off — and Submit always has the board's 20 below it, or the
 * home-indicator strip where that is deeper (M47 §13.2). The four chips wrap onto a second row before one is ever
 * cut (§13.1). The transcript, when it runs longer than the board's, keeps its newest lines in its own box.
 *
 * DRAWN FROM REAL DATA ONLY. The waveform's bars are the microphone level the recogniser reports (dictation.ts),
 * at rest when it reports none. The location row says "Pinned from current location" only when there is a pin, and
 * names the place in words (location.ts). A video tile is drawn for a video, and the phone's picker gives photos only,
 * so on a phone it is never drawn. No system dialog anywhere (M47 U7): the photo sheets and the line above Submit say
 * what the phone's dialogs used to.
 *
 * AFTER SUBMIT (no board draws it): what happened, in the resident boards' success pattern — a gold tick only once
 * the estate office has the report; "Saved on this phone" or "Not accepted" without one.
 */

import { useRef, useState, useSyncExternalStore, type RefObject } from 'react'
import { Image, ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { router } from 'expo-router'
import { KeyboardAvoidingView } from 'react-native-keyboard-controller'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { ConfirmSheet, Icon, Pressable, Text, aboveGestureBar, c, useFidelityReady } from '@gs/core'
import { AppBar, ButtonRow, InfoCard, PrimaryButton, SecondaryButton, Success, Toggle } from '@/design/parts'
import { corner, fill, font, icon, ink, line, radius, role, size, space, stroke } from '@/design/tokens'
import { reportFixture } from '@/fidelity/incidents'
import type { Levels } from '../dictation'
import { DESCRIPTION_MAX } from '../incidents'
import { useIncidentReport, type IncidentReportModel, type MediaItem, type Recorded } from '../useIncidentReport'

const TRANSCRIPT_LABEL = 'Transcript: tap to edit'
const NAME_PRIVATE = 'Keep my name private from other residents'

/**
 * AN EMPTY TRANSCRIPT SHOWS THE BOARD'S OWN WORDS, between its quotation marks, in the design's placeholder grey (the
 * user, 7 October: "no placeholder in any input as the design") — fidelity/incidents.ts, never typed again here.
 * Grey, gone at the first word heard or typed: never a report.
 */
const TRANSCRIPT_EXAMPLE = `"${reportFixture.transcript}"`

/** A bar at rest is a dot as wide as it is; at the loudest it fills the board's 20-point wave. */
const WAVE_REST = size[3]
const WAVE_SPAN = size[20] - size[3]

/** The transcript's line, and how many of them it shows: the board's three while listening, two more when the panel is still. */
const TRANSCRIPT_LINE = font.inter400_13_lh1_5.size * font.inter400_13_lh1_5.lh
const TRANSCRIPT_LINES_LISTENING = 3
const TRANSCRIPT_LINES_STILL = 5

/** Touch areas grown to 44 points, never past their neighbours. */
const CHIP_REACH = { top: space[7], bottom: space[7] }
const LINK_REACH = { top: space[15], bottom: space[15], left: space[10], right: space[10] }

/* ---- the form's parts ---------------------------------------------------------------------------- */

/**
 * `.chip-row .cat-chip` — board 14's own chip: 11.5 · 600, 8 × 13 (the shared Chip draws 9 × 14 or 8 × 14). All four on
 * one line, as the board draws them; on a phone too narrow for them, or with larger text, a chip wraps to a second row
 * — never cut, never a sideways swipe (M47 §13.1).
 */
function Types({ m }: { m: IncidentReportModel }) {
  return (
    <View style={s.chipRow} role="radiogroup" aria-label="Kind of incident">
      {m.types.map((t) => {
        const on = t.value === m.type
        return (
          <Pressable key={t.value} onPress={() => m.chooseType(t.value)} role="radio" aria-checked={on} aria-label={t.label} hitSlop={CHIP_REACH} style={on ? s.chipOn : s.chip}>
            <Text {...role.chip} color={on ? ink.onDark : ink.onTint}>{t.label}</Text>
          </Pressable>
        )
      })}
    </View>
  )
}

/** `.wave` — seven bars, each one reading of the microphone level, newest on the right. */
function Wave({ levels }: { levels: Levels }) {
  const values = useSyncExternalStore(levels.subscribe, levels.get, levels.get)
  return (
    <View style={s.wave} aria-hidden>
      {values.map((level, i) => (
        <View key={i} style={[s.bar, { height: WAVE_REST + level * WAVE_SPAN }]} />
      ))}
    </View>
  )
}

/** `.mic-panel` — the microphone; while it listens, its rings, "Listening…" and the waveform. */
function MicPanel({ m }: { m: IncidentReportModel }) {
  const d = m.dictation
  const words = d.available ? (d.trouble ?? d.note) : d.reason
  return (
    <View style={s.micPanel}>
      <Pressable onPress={d.toggle} disabled={!d.available} role="button" aria-label={d.listening ? 'Stop listening' : 'Speak your report'} aria-disabled={!d.available} accessibilityHint={d.reason ?? undefined} style={s.micWrap}>
        {d.listening ? <View style={s.ringOuter} /> : null}
        {d.listening ? <View style={s.ringInner} /> : null}
        <View style={d.available ? s.micButton : s.micButtonOff}>
          <Icon name="mic-btn" size={icon[24]} color={d.available ? ink.heading : ink.secondary} />
        </View>
      </Pressable>
      {d.listening ? (
        <>
          <View style={s.listening}>
            <Text {...font.inter700_11_5} color={ink.onGoldTint} role="status">{'Listening…'}</Text>
          </View>
          <Wave levels={d.levels} />
        </>
      ) : null}
      {words ? (
        <View style={s.micWords}>
          <Text {...font.inter400_11_5_lh1_5} color={ink.secondary} align="center" role={d.trouble ? 'alert' : 'status'}>{words}</Text>
        </View>
      ) : null}
      {d.model ? (
        <Pressable onPress={d.model.download} disabled={d.model.downloading} role="button" aria-label="Download the speech model" aria-busy={d.model.downloading} hitSlop={LINK_REACH} style={s.micWords}>
          <Text {...font.inter700_12} color={ink.action}>{d.model.downloading ? 'Downloading…' : 'Download the speech model'}</Text>
        </Pressable>
      ) : null}
    </View>
  )
}

/** `.transcript-box` — what was heard, between the board's quotation marks; a tap edits it. */
function Transcript({ m }: { m: IncidentReportModel }) {
  const [editing, setEditing] = useState(false)
  const d = m.dictation
  const heard = d.listening && d.hearing ? `${m.transcript} ${d.hearing}`.trim() : m.transcript
  const box = [s.transcriptBox, editing || m.transcriptError ? s.transcriptLit : null]
  const room = { minHeight: TRANSCRIPT_LINE, maxHeight: TRANSCRIPT_LINE * (d.listening ? TRANSCRIPT_LINES_LISTENING : TRANSCRIPT_LINES_STILL) }

  return (
    <View style={s.transcript}>
      {editing ? (
        <View style={box}>
          <View style={s.transcriptLabel}><Text {...font.inter700_10_5} color={ink.secondary}>{TRANSCRIPT_LABEL}</Text></View>
          <TextInput
            value={m.transcript}
            onChangeText={m.setTranscript}
            onBlur={() => setEditing(false)}
            autoFocus
            multiline
            maxLength={DESCRIPTION_MAX}
            autoCapitalize="sentences"
            selectionColor={c(ink.link)}
            placeholder={TRANSCRIPT_EXAMPLE}
            placeholderTextColor={c(ink.secondary)}
            aria-label="Transcript"
            maxFontSizeMultiplier={1.3}
            style={[s.transcriptInput, room]}
          />
        </View>
      ) : (
        <Pressable onPress={() => setEditing(true)} role="button" aria-label={heard ? `Transcript: ${heard}. Tap to edit` : 'Transcript: tap to type what happened'} style={box}>
          <View style={s.transcriptLabel}><Text {...font.inter700_10_5} color={ink.secondary}>{TRANSCRIPT_LABEL}</Text></View>
          {/* Longer than the box holds: its newest lines stay in view, the earliest give way at the top. */}
          <View style={[s.transcriptWindow, room]}>
            <Text {...font.inter400_13_lh1_5} color={heard ? ink.heading : ink.secondary}>{heard ? `"${heard}"` : TRANSCRIPT_EXAMPLE}</Text>
          </View>
        </Pressable>
      )}
      {m.transcriptError ? (
        <View style={s.attention}><Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{m.transcriptError}</Text></View>
      ) : null}
    </View>
  )
}

/** `.photo-thumb` — a photo the resident added (a tap takes it back off), or the board's own tile; a video carries the play mark. */
function Thumb({ item, n, onRemove }: { item: MediaItem; n: number; onRemove: (item: MediaItem) => void }) {
  const inside = (
    <>
      {item.uri ? <Image source={{ uri: item.uri }} style={s.thumbImage} /> : null}
      {item.kind === 'video' ? <View style={s.videoShade} /> : null}
      {/* Above the shade (the board's z-index 2): a browser paints a positioned shade over an unpositioned mark. */}
      {item.kind === 'video' ? <View style={s.play}><Icon name="photo-thumb" size={icon[16]} color={ink.onDark} /></View> : null}
    </>
  )
  return item.uri ? (
    <Pressable onPress={() => onRemove(item)} role="button" aria-label={`${item.kind === 'video' ? 'Video' : 'Photo'} ${n}. Take it off this report`} style={s.thumb}>{inside}</Pressable>
  ) : (
    <View style={s.thumb} aria-label={item.kind === 'video' ? 'Video' : 'Photo'}>{inside}</View>
  )
}

/** `.photo-row`: the photos, then the add tile — which asks camera or photos in a sheet, as New maintenance request's does. */
function Media({ m, addRef }: { m: IncidentReportModel; addRef: RefObject<View | null> }) {
  const p = m.media
  return (
    <View style={s.mediaRow}>
      {p.items.map((item, i) => <Thumb key={item.key} item={item} n={i + 1} onRemove={(chosen) => p.removing.ask(chosen.key)} />)}
      {p.canAdd ? (
        <Pressable ref={addRef} onPress={p.adding.ask} role="button" aria-label="Add a photo" style={s.addTile}>
          <Icon name="photo-add" size={icon[19]} color={ink.field} />
        </Pressable>
      ) : null}
    </View>
  )
}

/** Behind the add tile: the camera or the phone's photos. Behind a photo: whether to take it off. No board draws either. */
function PhotoSheets({ m, addRef }: { m: IncidentReportModel; addRef: RefObject<View | null> }) {
  const adding = m.media.adding
  const removing = m.media.removing
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

/** `.loc-row` — where: the place, how it was found, and the gold "Change", which lets the resident say it in words. */
function Where({ m, editing, setEditing }: { m: IncidentReportModel; editing: boolean; setEditing: (on: boolean) => void }) {
  const w = m.where
  return (
    <View style={s.locRow}>
      <Icon name="loc-row" size={icon[18]} color={ink.action} />
      <View style={s.locText}>
        {editing ? (
          <TextInput
            value={w.place}
            onChangeText={w.setPlace}
            onBlur={() => setEditing(false)}
            onSubmitEditing={() => setEditing(false)}
            autoFocus
            maxLength={w.maxLength}
            returnKeyType="done"
            autoCapitalize="sentences"
            placeholder={w.fallback}
            placeholderTextColor={c(ink.secondary)}
            selectionColor={c(ink.link)}
            aria-label="Where it happened"
            maxFontSizeMultiplier={1.3}
            style={s.placeInput}
          />
        ) : (
          <Text {...font.inter600_12_5} color={ink.heading}>{w.title}</Text>
        )}
        <Text {...font.inter400_10_5} color={ink.secondary}>{w.line}</Text>
      </View>
      <Pressable onPress={() => setEditing(true)} role="button" aria-label={`Change where it happened: ${w.title}`} hitSlop={LINK_REACH}>
        <Text {...font.inter700_11} color={ink.link}>{'Change'}</Text>
      </Pressable>
    </View>
  )
}

/** `.anon-card` — the switch is on, as the board draws it. */
function NamePrivate({ m }: { m: IncidentReportModel }) {
  return (
    <View style={s.anonCard}>
      <View style={s.anonTop}>
        <View style={s.anonTitle}><Text {...font.inter700_12_5} color={ink.heading}>{NAME_PRIVATE}</Text></View>
        <Toggle on={m.keepNamePrivate} onChange={m.setKeepNamePrivate} label={NAME_PRIVATE} />
      </View>
      <Text {...font.inter400_10_5_lh1_5} color={ink.label}>{"Security and the committee will always see who filed this report. It's only hidden from other residents in shared updates."}</Text>
    </View>
  )
}

/* ---- after Submit ----------------------------------------------------------------------------------- */

const photoWord = (n: number) => `${n} photo${n === 1 ? '' : 's'}`

function photoLine(recorded: Recorded): string | null {
  const p = recorded.photos
  if (p.total === 0) return null
  if (recorded.state === 'refused') return `${photoWord(p.total)} not sent: the report they belong to was not accepted.`
  if (p.notSaved > 0) return `${photoWord(p.notSaved)} could not be saved on this phone and will not be sent.`
  if (p.refused > 0) return `${photoWord(p.refused)} not accepted by the estate's server. The report itself is not affected.`
  if (p.waiting > 0) return `${photoWord(p.waiting)} will follow once the report has reached the office.`
  return null
}

function Outcome({ recorded, m }: { recorded: Recorded; m: IncidentReportModel }) {
  const info = photoLine(recorded) ?? undefined
  // Done goes to where this report can be followed: My requests, on its Incidents tab (M98).
  const doneButton = <SecondaryButton key="done" label="Done" onPress={() => router.replace('/requests?tab=report' as never)} />

  if (recorded.state === 'confirmed') {
    const id = recorded.incidentId
    // An array, not a fragment: ButtonRow gives each child its half (Children.map does not open a fragment).
    return (
      <Success
        title={recorded.number ? `Report #${recorded.number} sent` : 'Report sent'}
        line="The estate office has it. You can follow what they do with it."
        info={info}
        buttons={[doneButton, id ? <PrimaryButton key="follow" label="View report" onPress={() => router.replace(`/incident/${id}` as never)} /> : null]}
      />
    )
  }

  const refused = recorded.state === 'refused'
  return (
    <View style={s.outcome} role="status">
      <Text {...font.poppins600_20} color={ink.heading} align="center" role="heading">{refused ? 'Not accepted' : 'Saved on this phone'}</Text>
      <View style={s.outcomeLine}>
        <Text {...font.inter400_12_lh1_5} color={ink.secondary} align="center">
          {refused
            ? `${recorded.refusal ?? 'The estate office did not accept this report.'} Nothing was filed: you can change it and send it again.`
            : recorded.state === 'syncing'
              ? 'Being sent to the estate office now.'
              : 'Nobody has seen it yet. It goes to the estate office as soon as this phone is back online: you do not need to keep this screen open.'}
        </Text>
      </View>
      {info ? <View style={s.outcomeInfo}><InfoCard text={info} /></View> : null}
      <ButtonRow style={s.outcomeButtons}>
        {doneButton}
        {/* Half a row each, one line each (parts/Buttons.tsx): the words are short enough never to be cut. */}
        {refused ? <PrimaryButton label="Edit and resend" onPress={m.changeAndResend} /> : <PrimaryButton label="Try sending now" onPress={m.retryNow} />}
      </ButtonRow>
    </View>
  )
}

/* ---- the screen ---------------------------------------------------------------------------------- */

export default function ReportIncidentScreen() {
  const m = useIncidentReport()
  const insets = useSafeAreaInsets()
  const addRef = useRef<View>(null)
  // While the place is being typed the page moves up with the keyboard — the row sits where the keyboard rises.
  const [placeEditing, setPlaceEditing] = useState(false)
  useFidelityReady(true)
  // .body-pad's 20 below Submit — or the home-indicator strip, where that is deeper (M47 §13.2).
  const bottom = { paddingBottom: aboveGestureBar(space[20], insets.bottom) }

  if (m.recorded) {
    return (
      <View style={s.screen}>
        <AppBar title="Report incident" back={false} />
        <Outcome recorded={m.recorded} m={m} />
      </View>
    )
  }

  return (
    <View style={s.screen}>
      <AppBar title="Report incident" />
      <KeyboardAvoidingView behavior="position" enabled={placeEditing} style={s.page} contentContainerStyle={s.page}>
        <ScrollView testID="report-page" style={s.page} contentContainerStyle={[s.body, bottom]} keyboardShouldPersistTaps="handled" alwaysBounceVertical={false} showsVerticalScrollIndicator={false}>
          <Types m={m} />
          <MicPanel m={m} />
          <Transcript m={m} />
          <Media m={m} addRef={addRef} />
          <Where m={m} editing={placeEditing} setEditing={setPlaceEditing} />
          <NamePrivate m={m} />
          {m.problem ? (
            <View style={s.problem}>
              <Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{m.problem}</Text>
            </View>
          ) : null}
          <PrimaryButton label="Submit report" onPress={m.send} loading={m.busy} />
        </ScrollView>
      </KeyboardAvoidingView>
      <PhotoSheets m={m} addRef={addRef} />
    </View>
  )
}

const thumbBox = { width: size[58], height: size[58], borderRadius: radius.tile, flexShrink: 0, alignItems: 'center', justifyContent: 'center', overflow: 'hidden' } as const
const micButton = { width: size[54], height: size[54], borderRadius: radius.avatar(size[54]), alignItems: 'center', justifyContent: 'center', zIndex: 2 } as const
const chip = { flexShrink: 0, borderRadius: radius.chip, paddingVertical: space[8], paddingHorizontal: space[13] } as const

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  page: { flex: 1, overflow: 'hidden' },
  // .body-pad — its bottom is set by the screen: the board's 20, or the home-indicator strip (M47 §13.2).
  body: { paddingTop: space[18], paddingHorizontal: space[20] },

  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: space[8], marginBottom: space[16] },
  chip: { ...chip, backgroundColor: fill.tint },
  chipOn: { ...chip, backgroundColor: fill.primary },

  micPanel: { alignItems: 'center', backgroundColor: fill.tint, borderRadius: radius.heroCard, paddingVertical: space[20], paddingHorizontal: space[16], marginBottom: space[16] },
  micWrap: { width: size[74], height: size[74], alignItems: 'center', justifyContent: 'center' },
  ringOuter: { position: 'absolute', width: size[90], height: size[90], borderRadius: radius.avatar(size[90]), borderWidth: stroke['1.5'], borderColor: line.focus, opacity: 0.16 },
  ringInner: { position: 'absolute', width: size[74], height: size[74], borderRadius: radius.avatar(size[74]), borderWidth: stroke['1.5'], borderColor: line.focus, opacity: 0.3 },
  micButton: { ...micButton, backgroundColor: fill.gold },
  micButtonOff: { ...micButton, backgroundColor: fill.divider },
  listening: { marginTop: space[10] },
  wave: { height: size[20], flexDirection: 'row', alignItems: 'flex-end', gap: space[3], marginTop: space[10] },
  bar: { width: size[3], borderRadius: corner[2], backgroundColor: fill.dot },
  micWords: { marginTop: space[10] },

  transcript: { marginBottom: space[16] },
  transcriptBox: { backgroundColor: fill.surface, borderRadius: corner[14], borderWidth: stroke['1.5'], borderColor: line.outline, padding: space[13] },
  transcriptLit: { borderColor: line.focus },
  transcriptLabel: { marginBottom: space[6] },
  transcriptWindow: { overflow: 'hidden', justifyContent: 'flex-end' },
  transcriptInput: { fontFamily: font.inter400_13_lh1_5.face, fontSize: font.inter400_13_lh1_5.size, lineHeight: TRANSCRIPT_LINE, color: c(ink.heading), padding: 0, margin: 0, textAlignVertical: 'top' },
  attention: { marginTop: space[6], marginHorizontal: space[2] },

  mediaRow: { flexDirection: 'row', gap: space[9], marginBottom: space[18] },
  thumb: { ...thumbBox, backgroundColor: fill.divider },
  thumbImage: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 },
  videoShade: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: fill.videoThumb },
  play: { zIndex: 2 },
  addTile: { ...thumbBox, borderWidth: stroke['1.5'], borderStyle: 'dashed', borderColor: line.muted },

  locRow: { flexDirection: 'row', alignItems: 'center', gap: space[11], backgroundColor: fill.tint, borderRadius: corner[14], paddingVertical: space[12], paddingHorizontal: space[14], marginBottom: space[16] },
  locText: { flex: 1, minWidth: 0 },
  placeInput: { fontFamily: font.inter600_12_5.face, fontSize: font.inter600_12_5.size, color: c(ink.heading), padding: 0, margin: 0 },

  anonCard: { backgroundColor: fill.tint, borderRadius: corner[14], padding: space[13], marginBottom: space[18] },
  anonTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: space[10], marginBottom: space[6] },
  anonTitle: { flexShrink: 1 },
  // Not on the board: why nothing was sent, above Submit, as New maintenance request says its own.
  problem: { marginHorizontal: space[2], marginBottom: space[10] },
  // Inside the photo sheet (no board draws it).
  sheetActions: { marginTop: space[14] },

  // After Submit, without the tick: the success pattern's own spacing (parts/Success.tsx).
  outcome: { alignItems: 'center', paddingTop: space[30], paddingHorizontal: space[24] },
  outcomeLine: { marginTop: space[5] },
  outcomeInfo: { width: '100%', marginTop: space[20] },
  outcomeButtons: { marginTop: space[16] },
})
