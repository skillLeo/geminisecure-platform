/**
 * guard-app-31 · Walk-up Visitor Entry — the no-pass path. Styles are the board's own, copied
 * from the lifted file (src/lifted/GuardApp31.tsx). All behaviour lives in useWalkUp().
 *
 * NO CAMERA (invariant 14): the board's photo tile and plate-camera button are drawn, inert,
 * each with its reason. The server stores no picture of a walk-up visitor.
 * A household's standing is one boolean, drawn as one fixed sentence.
 */

import { useState } from 'react'
import { ScrollView, StyleSheet, TextInput, View, type StyleProp, type TextInputProps, type ViewStyle } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { Controller, type Control } from 'react-hook-form'
import { AppBar, GuardButton, Icon, Pressable, Text, a, c, useFidelityReady, type IconName } from '@gs/core'
import { RESTRICTED_SENTENCE } from '@/features/gate/verdict'
import { PolicyChoices } from '../components/PolicyChoices'
import { RecordedPanel } from '../components/RecordedPanel'
import { ID_TYPES, useWalkUp, type WalkUpField, type WalkUpForm, type WalkUpModel } from '../useWalkUp'

/** Read out in full; the line drawn in the tile is the short form, so it is never cut off mid-sentence. */
const PHOTO_REASON = 'Not available: the estate keeps no pictures of walk-up visitors, so none is taken. Record their ID instead.'
const PHOTO_REASON_SHORT = 'No photos of walk-up visitors are kept: note their ID.'
const PLATE_CAMERA_REASON = 'Plates are typed, not photographed: this phone takes no pictures at the gate.'

function FieldError({ message }: { message: string | undefined }) {
  return message ? <Text face="Inter-Medium" size={10.5} lh={1.45} color="red-500" style={{ marginTop: 5, marginHorizontal: 2 }} role="alert">{message}</Text> : null
}

function Input({ control, name, label, icon, error, focused, onFocus, boxStyle, ...input }: {
  control: Control<WalkUpForm>
  name: WalkUpField
  label: string
  icon?: IconName
  error: string | undefined
  focused: boolean
  onFocus: (name: WalkUpField | null) => void
  boxStyle: StyleProp<ViewStyle>
} & Pick<TextInputProps, 'placeholder' | 'autoCapitalize' | 'maxLength' | 'returnKeyType' | 'autoFocus'>) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field }) => (
        <View style={[boxStyle, focused ? { borderColor: c('amber-500') } : null, error ? { borderColor: c('red-500') } : null]}>
          {icon ? <Icon name={icon} size={17} color="text-3" style={s.inputSvg} /> : null}
          <TextInput
            ref={field.ref}
            value={typeof field.value === 'string' ? field.value : ''}
            onChangeText={field.onChange}
            onFocus={() => onFocus(name)}
            onBlur={() => {
              field.onBlur()
              onFocus(null)
            }}
            placeholderTextColor={c('text-3')}
            selectionColor={c('amber-500')}
            autoCorrect={false}
            aria-label={label}
            aria-invalid={!!error}
            maxFontSizeMultiplier={1.3}
            style={s.inputText}
            {...input}
          />
        </View>
      )}
    />
  )
}

function Label({ children }: { children: string }) {
  return <Text face="Inter-SemiBold" size={11.5} color="text-2">{children}</Text>
}

/** The unit the visitor is going to: the board's settled row, or a field matched against the live register. */
function Unit({ m, focused, onFocus }: { m: WalkUpModel; focused: boolean; onFocus: (name: WalkUpField | null) => void }) {
  const u = m.unit
  if (u.settled) {
    const detail = [u.household, u.fromSearch ? 'from Search' : u.lookup === 'matched' ? 'on the register' : null].filter(Boolean).join(' · ')
    return (
      <Pressable onPress={u.edit} disabled={!!m.fixture} role="button" aria-label={`Visiting ${u.value}${u.household ? `, ${u.household}` : ''}. Change the unit`} style={({ pressed }) => [s.unitLookupRow, { opacity: pressed ? 0.8 : 1 }]}>
        <Icon name="tab-profile" size={17} color="amber-500" style={s.unitLookupRowSvg} />
        <View style={s.fill}>
          <Text face="Inter-Bold" size={12.5} color="white">{u.value}</Text>
          {detail ? <Text face="Inter-Regular" size={10.5} color="text-3">{detail}</Text> : null}
        </View>
        {m.fixture ? null : <Text face="Inter-Bold" size={11} color="amber-500">{'Change'}</Text>}
      </Pressable>
    )
  }

  return (
    <View style={s.field}>
      <Label>{'Unit visited'}</Label>
      <Input control={m.control} name="unit" label="Unit visited" icon="tab-profile" error={m.errors.unit} focused={focused} onFocus={onFocus} boxStyle={s.input2} placeholder="Lot number, e.g. Lot 47" autoCapitalize="words" maxLength={32} returnKeyType="next" />
      <FieldError message={m.errors.unit} />

      {u.lookup === 'searching' ? <Text face="Inter-Regular" size={10.5} color="text-3" style={s.hint} role="status">{'Checking the register…'}</Text> : null}
      {u.lookup === 'offline' ? <Text face="Inter-Regular" size={10.5} lh={1.45} color="text-3" style={s.hint}>{'No signal: the register cannot be checked. Type the unit exactly as it is written at the estate.'}</Text> : null}
      {u.lookup === 'none' ? (
        <View style={s.hint}>
          <Text face="Inter-Regular" size={10.5} lh={1.45} color="text-3" role="status">{'No unit on the register matches that. Check the lot number, or look the household up by name.'}</Text>
          <Pressable onPress={() => router.push('/search' as never)} role="link" aria-label="Search by name" hitSlop={14} style={{ alignSelf: 'flex-start', marginTop: 6 }}>
            <Text face="Inter-Bold" size={11.5} color="amber-500">{'Search by name'}</Text>
          </Pressable>
        </View>
      ) : null}
      {u.choices.map((choice) => (
        <Pressable key={choice.unit} onPress={() => u.choose(choice)} role="button" aria-label={`Use ${choice.unit}, ${choice.household}`} style={({ pressed }) => [s.choiceRow, { opacity: pressed ? 0.8 : 1 }]}>
          <Icon name="tab-profile" size={17} color="amber-500" style={s.unitLookupRowSvg} />
          <View style={s.fill}>
            <Text face="Inter-Bold" size={12.5} color="white">{choice.unit}</Text>
            <Text face="Inter-Regular" size={10.5} color="text-3">{`${choice.household} · on the register`}</Text>
          </View>
          <Text face="Inter-Bold" size={11} color="amber-500">{'Use'}</Text>
        </Pressable>
      ))}
    </View>
  )
}

export default function WalkUpScreen() {
  const params = useLocalSearchParams<{ unit?: string; household?: string }>()
  const m = useWalkUp({ unit: params.unit, household: params.household }, (next) => router.replace({ pathname: '/walk-up/waiting', params: next } as never))
  useFidelityReady(true)

  const [focused, setFocused] = useState<WalkUpField | null>(m.fixture ? m.fixture.focused : null)
  const [plateCameraAsked, setPlateCameraAsked] = useState(false)

  return (
    <View style={s.screen}>
      <AppBar title="New visitor: walk-up" />
      <ScrollView contentContainerStyle={s.bodyPad} keyboardShouldPersistTaps="handled" automaticallyAdjustKeyboardInsets>
        {m.recorded ? (
          <RecordedPanel recorded={m.recorded} onDone={() => router.replace('/(tabs)')} />
        ) : (
          <>
            {/* Drawn as the board draws it, and inert: no picture of a walk-up visitor is taken or kept. */}
            <View style={s.photoTile} role="button" aria-label="Visitor photo" aria-disabled accessibilityHint={PHOTO_REASON}>
              <Icon name="photo-tile" size={30} color="text-3" style={s.photoTileSvg} />
              <Text face="Inter-SemiBold" size={12} color="text-3">{m.fixture ? m.fixture.photoLabel : 'No visitor photo is taken'}</Text>
              <View style={s.photoReason}>
                {/* Two lines at most: anchored to the tile's foot, a second line (large system text) grows upward into free space. */}
                <Text face="Inter-Regular" size={10.5} color="text-3" align="center" numberOfLines={2}>{PHOTO_REASON_SHORT}</Text>
              </View>
            </View>

            <Unit m={m} focused={focused === 'unit'} onFocus={setFocused} />

            {m.unit.restricted ? (
              <View style={s.restrictedNote} role="alert">
                <Icon name="qa-tile-3" size={14} color="amber-500" style={s.noteSvg} />
                <View style={s.shrink}><Text face="Inter-SemiBold" size={10.5} lh={1.4} color="amber-500">{RESTRICTED_SENTENCE}</Text></View>
              </View>
            ) : null}

            <View style={s.field}>
              <Label>{'Visitor name'}</Label>
              <Input control={m.control} name="visitor_name" label="Visitor name" icon="cc-row" error={m.errors.visitor_name} focused={focused === 'visitor_name'} onFocus={setFocused} boxStyle={s.input2} placeholder="e.g. Jane Brown" autoCapitalize="words" maxLength={160} returnKeyType="next" />
              <FieldError message={m.errors.visitor_name} />
            </View>

            <View style={s.twoCol}>
              <View style={s.field2}>
                <Label>{'ID type'}</Label>
                <Pressable onPress={m.idType.toggle} disabled={!!m.fixture} role="button" aria-label={`ID type${m.idType.value ? `: ${m.idType.value}` : ', not chosen'}`} aria-expanded={m.idType.open} style={[s.input2, m.idType.open ? { borderColor: c('amber-500') } : null]}>
                  <Icon name="input-2" size={17} color="text-3" style={s.inputSvg} />
                  <View style={s.shrink}>
                    {m.idType.value
                      ? <Text face="Inter-Medium" size={13.5} color="white" numberOfLines={1}>{m.idType.value}</Text>
                      : <Text face="Inter-Regular" size={13.5} color="text-3" numberOfLines={1}>{'Choose'}</Text>}
                  </View>
                </Pressable>
                <FieldError message={m.errors.id_type} />
              </View>
              <View style={s.field2}>
                <Label>{'ID number'}</Label>
                <Input control={m.control} name="id_number" label="ID number" error={m.errors.id_number} focused={focused === 'id_number'} onFocus={setFocused} boxStyle={s.input2} placeholder={m.fixture ? m.fixture.idNumberPlaceholder : 'As on the ID'} autoCapitalize="characters" maxLength={40} returnKeyType="next" />
                <FieldError message={m.errors.id_number} />
              </View>
            </View>

            {m.idType.open ? (
              <View style={s.chips} role="radiogroup" aria-label="ID type">
                {ID_TYPES.map((type) => {
                  const on = m.idType.value === type
                  return (
                    <Pressable key={type} onPress={() => m.idType.choose(type)} role="radio" aria-checked={on} hitSlop={8} style={on ? s.chipOn : s.chip}>
                      <Text face="Inter-Bold" size={11.5} color={on ? 'g-navy-900' : 'text-2'}>{type}</Text>
                    </Pressable>
                  )
                })}
              </View>
            ) : null}

            <View>
              <Label>{'Purpose of visit'}</Label>
              <Input control={m.control} name="purpose" label="Purpose of visit" icon="input-3" error={m.errors.purpose} focused={focused === 'purpose'} onFocus={setFocused} boxStyle={s.input2} placeholder="e.g. Family visit, delivery" autoCapitalize="sentences" maxLength={160} returnKeyType="next" />
              <FieldError message={m.errors.purpose} />
            </View>

            <View style={s.field3}>
              <Label>{'Vehicle plate'}</Label>
              {m.noVehicle.on ? null : (
                <View style={s.plateRow}>
                  <Input control={m.control} name="vehicle_plate" label="Vehicle plate" error={m.errors.vehicle_plate} focused={focused === 'vehicle_plate'} onFocus={setFocused} boxStyle={s.input3} placeholder="e.g. PC 4471" autoCapitalize="characters" maxLength={16} returnKeyType="done" />
                  {/* Inert, as the photo tile is: aria-disabled, and a press only says why. */}
                  <Pressable onPress={() => setPlateCameraAsked(true)} role="button" aria-label="Photograph the plate" aria-disabled accessibilityHint={PLATE_CAMERA_REASON} style={s.camBtn}>
                    <Icon name="photo-tile" size={20} color="amber-500" style={s.camBtnSvg} />
                  </Pressable>
                </View>
              )}
              <FieldError message={m.errors.vehicle_plate} />
              {/* M18C §3.1: a plate, or this ticked — never neither. */}
              <Pressable onPress={m.noVehicle.toggle} disabled={!!m.fixture} role="checkbox" aria-checked={m.noVehicle.on} aria-label="Arriving on foot or by taxi: no vehicle" style={s.tickRow}>
                <View style={m.noVehicle.on ? s.tickOn : s.tick}>{m.noVehicle.on ? <Icon name="check" size={14} color="g-navy-900" /> : null}</View>
                <View style={s.shrink}><Text face="Inter-SemiBold" size={12.5} color="white">{'Arriving on foot or by taxi: no vehicle'}</Text></View>
              </Pressable>
              {plateCameraAsked ? <Text face="Inter-Regular" size={10.5} lh={1.45} color="text-3" style={s.hint} role="status">{PLATE_CAMERA_REASON}</Text> : null}
            </View>

            {m.formError ? (
              <View style={s.errorNote} role="alert">
                <Icon name="qa-tile-3" size={16} color="red-500" style={s.noteSvg} />
                <View style={s.shrink}><Text face="Inter-SemiBold" size={11} lh={1.5} color="red-500">{m.formError}</Text></View>
              </View>
            ) : null}

            {m.policyOffered ? (
              <View style={s.policyCard} role="alert">
                <View style={s.policyHead}>
                  <Icon name="offline-note" size={17} color="amber-500" style={s.noteSvg} />
                  <View style={s.shrink}>
                    <Text face="Inter-Bold" size={12.5} color="amber-500">{m.online ? 'The request did not get through' : 'No signal: the household cannot be asked'}</Text>
                    <Text face="Inter-Regular" size={11} lh={1.5} color="text-2" style={{ marginTop: 3 }}>
                      {`A request to a resident only works with a connection, and is never saved to send later. Nothing has been sent. ${m.online ? 'Try again, or apply' : 'Apply'} the estate's policy for unannounced visitors and record your decision:`}
                    </Text>
                  </View>
                </View>
                <View style={{ marginTop: 12 }}>
                  <PolicyChoices busy={m.policy.busy} saveError={m.policy.saveError} onAdmit={m.policy.admit} onDeny={m.policy.deny} />
                </View>
              </View>
            ) : null}

            {m.online ? (
              <GuardButton label={m.policyOffered ? 'Try again: send request' : 'Send request to resident'} onPress={m.send} loading={m.busy} style={{ marginTop: 15 }} />
            ) : (
              <GuardButton label="Send request to resident" onPress={m.send} disabled disabledReason="No signal: a household can only be asked while connected. Apply the estate's policy above." style={{ marginTop: 15 }} />
            )}
          </>
        )}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  photoTileSvg: { width: 30, height: 30, overflow: 'hidden' },
  photoTile: { height: 140, flexDirection: 'column', alignItems: 'center', justifyContent: 'center', borderStyle: 'dashed', backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-600'), gap: 8 },
  // Out of the flow, so the glyph and label stay exactly where the board centres them.
  photoReason: { position: 'absolute', right: 10, bottom: 12, left: 10 },
  unitLookupRowSvg: { width: 17, height: 17, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  unitLookupRow: { flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 12, gap: 11, marginTop: 16 },
  choiceRow: { flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 12, gap: 11, marginTop: 8 },
  hint: { marginTop: 6, marginHorizontal: 2 },
  inputSvg: { width: 17, height: 17, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginRight: 10 },
  inputText: { flex: 1, minWidth: 0, height: 46, fontFamily: 'Inter-Medium', fontSize: 13.5, color: c('white'), padding: 0 },
  field: { marginTop: 16 },
  input2: { height: 48, flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, marginTop: 6 },
  field2: { flex: 1, marginBottom: 15 },
  twoCol: { flexDirection: 'row', gap: 10, marginTop: 15 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 15 },
  chipOn: { backgroundColor: c('amber-500'), borderRadius: 20, borderWidth: 1, borderColor: c('amber-500'), paddingHorizontal: 14, paddingVertical: 8 },
  chip: { backgroundColor: c('surface-800'), borderRadius: 20, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 8 },
  input3: { height: 48, flex: 1, flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14 },
  camBtnSvg: { width: 20, height: 20, overflow: 'hidden' },
  camBtn: { width: 48, height: 48, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 14, borderWidth: 1, borderColor: c('border-600') },
  // M18C §3.1's tick box: the whole row the target, 48 tall at least.
  tickRow: { minHeight: 48, flexDirection: 'row', alignItems: 'center', gap: 12, marginTop: 10 },
  tick: { width: 24, height: 24, borderRadius: 6, borderWidth: 2, borderColor: c('amber-500') },
  tickOn: { width: 24, height: 24, borderRadius: 6, borderWidth: 2, borderColor: c('amber-500'), backgroundColor: c('amber-500'), alignItems: 'center', justifyContent: 'center' },
  plateRow: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 6 },
  field3: { marginTop: 15 },
  noteSvg: { flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  restrictedNote: { flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 10, borderWidth: 1, borderColor: a('amber-500', 0.25), paddingHorizontal: 10, paddingVertical: 9, gap: 8, marginTop: 8 },
  errorNote: { flexDirection: 'row', backgroundColor: a('red-500', 0.1), borderRadius: 12, borderWidth: 1, borderColor: a('red-500', 0.28), paddingHorizontal: 12, paddingVertical: 11, gap: 9, marginTop: 15 },
  policyCard: { backgroundColor: a('amber-500', 0.1), borderRadius: 14, borderWidth: 1, borderColor: a('amber-500', 0.32), padding: 13, marginTop: 15 },
  policyHead: { flexDirection: 'row', gap: 10 },
  // The board's .btn-primary (52 · radius 15 · amber glow) is GuardButton's primary.
})
