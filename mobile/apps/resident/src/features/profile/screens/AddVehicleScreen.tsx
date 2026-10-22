/**
 * A vehicle — added from Profile's "+ Add vehicle", or opened by tapping its pill to change it (M50 §5.3a–c). A screen
 * NO board draws, in board 32's look (Add household member): its app bar, its boxes with their labels and grey sample
 * values, its primary button — every value from the design tokens and parts (components/HouseholdForm.tsx).
 *
 * `vehicles.store` / `vehicles.update` take a plate (letters, digits, spaces, hyphens; 16 at most), a make, a model and a
 * colour — all four required since 23 September (a guard matches the car at the gate against them). The server
 * upper-cases the plate. A plate already on the household's list — on ANOTHER vehicle — is said under the box and
 * nothing is sent. Both writes are queueable ("Offline: Queue"): with no connection the change is kept on this phone
 * and sent later, and the screen never says "registered" or "changed" until the server has said so.
 *
 * WHAT M50 §5.3 CHANGED: the plate keeps the car's mark, and make, model and colour carry none — all four had the same
 * car, which said nothing; the footer "A vehicle cannot be removed or changed from the app…" is gone, because it can;
 * Remove is here, inside the vehicle's own screen, asked in its own sheet whose confirm is red — never on the row.
 * THE PLATE'S FORMAT: the server's rule above, and nothing more. The estate has given no plate format, so none is
 * invented: "PPV 154154" and "PPV 4471" are both accepted, as the server accepts them.
 */

import { useRef } from 'react'
import { StyleSheet, View } from 'react-native'
import { Controller } from 'react-hook-form'
import { useLocalSearchParams } from 'expo-router'
import { ConfirmSheet, EmptyState, useFidelityReady } from '@gs/core'
import { Field, PrimaryButton, SecondaryButton } from '@/design/parts'
import { space } from '@/design/tokens'
import { profileFixture } from '@/fidelity/profile'
import { FieldHint, FootNote, FormScreen, OutcomeScreen, backToProfile } from '../components/HouseholdForm'
import { COLOUR_MAX, MAKE_MAX, PLATE_MAX, vehicleLine, type Vehicle } from '../household'
import { removeVehicleSheet } from '../sheets'
import { useVehicleForm } from '../useHouseholdForms'
import { useRemoveHere } from '../useProfile'

/** "PC 4471", "Honda", "CR-V", "Grey" — grey in the empty boxes, gone at the first letter: never a value. */
const EXAMPLE = profileFixture.vehicles[0]

const OFFLINE = 'No connection. You can still save this: it is kept on this phone and sent to the estate office when you are back online.'

export default function AddVehicleScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const m = useVehicleForm(id)
  const removal = useRemoveHere<Vehicle>('vehicle', backToProfile)
  const removeButton = useRef<View>(null)
  useFidelityReady(true)

  const editing = m.editing.item
  const title = m.editing.id ? 'Edit vehicle' : 'Add vehicle'

  if (m.recorded) {
    return (
      <OutcomeScreen
        title={title}
        recorded={m.recorded}
        write={m}
        words={
          m.editing.id
            ? { confirmedTitle: 'Vehicle changed', confirmedBody: "Your household's list has its new details.", refusedTail: 'Nothing was changed.', another: null }
            : { confirmedTitle: 'Vehicle registered', confirmedBody: "It is on your household's list with the estate office.", refusedTail: 'No vehicle was added.', another: 'Add another vehicle' }
        }
        onAnother={m.startAnother}
      />
    )
  }

  if (m.editing.missing) {
    return (
      <FormScreen title={title}>
        <EmptyState tone="light" icon="item-tag" title="This vehicle is not on this phone" body="Open your profile with a connection, then try again." action={{ label: 'Back', onPress: backToProfile }} />
      </FormScreen>
    )
  }

  const words = removal.target ? removeVehicleSheet({ line: vehicleLine(removal.target) }, m.online) : null

  return (
    <FormScreen
      title={title}
      overlay={
        <ConfirmSheet
          open={removal.open && !!words}
          title={words?.title ?? ''}
          lines={words?.lines ?? []}
          confirmLabel={words?.confirmLabel ?? ''}
          cancelLabel={words?.cancelLabel}
          working={removal.working}
          problem={removal.problem}
          blocked={words?.blocked ?? null}
          onConfirm={removal.confirm}
          onCancel={removal.cancel}
          returnFocusTo={removeButton}
        />
      }
    >
      <View style={s.field}>
        <Controller
          control={m.control}
          name="plate"
          render={({ field }) => (
            <Field ref={field.ref} label="Licence plate" icon="item-tag" value={field.value} placeholder={EXAMPLE.plate} onChangeText={field.onChange} onBlur={field.onBlur} error={m.errors.plate} autoCapitalize="characters" autoCorrect={false} maxLength={PLATE_MAX} returnKeyType="next" last />
          )}
        />
        <FieldHint text="Letters and numbers, as on the plate: e.g. 1234 AB" error={m.errors.plate} />
      </View>
      <Controller
        control={m.control}
        name="make"
        render={({ field }) => (
          <Field ref={field.ref} label="Make" value={field.value} placeholder={EXAMPLE.make} onChangeText={field.onChange} onBlur={field.onBlur} error={m.errors.make} autoCapitalize="words" autoCorrect={false} maxLength={MAKE_MAX} returnKeyType="next" />
        )}
      />
      <Controller
        control={m.control}
        name="model"
        render={({ field }) => (
          <Field ref={field.ref} label="Model" value={field.value} placeholder={EXAMPLE.model} onChangeText={field.onChange} onBlur={field.onBlur} error={m.errors.model} autoCapitalize="words" autoCorrect={false} maxLength={MAKE_MAX} returnKeyType="next" />
        )}
      />
      <Controller
        control={m.control}
        name="colour"
        render={({ field }) => (
          <Field ref={field.ref} label="Colour" value={field.value} placeholder={EXAMPLE.colour} onChangeText={field.onChange} onBlur={field.onBlur} error={m.errors.colour} autoCapitalize="words" maxLength={COLOUR_MAX} returnKeyType="done" onSubmitEditing={m.send} last />
        )}
      />

      <FootNote problem={m.saveError ?? m.firstError} online={m.online} offlineText={OFFLINE} />

      <PrimaryButton label={m.online ? 'Save vehicle' : 'Save on this phone'} onPress={m.send} loading={m.busy} style={s.save} />
      {editing ? (
        <View ref={removeButton} style={s.remove}>
          <SecondaryButton label="Remove vehicle" name={`Remove ${vehicleLine(editing)} from your vehicles`} onPress={() => removal.ask(editing)} />
        </View>
      ) : null}
    </FormScreen>
  )
}

const s = StyleSheet.create({
  // .field: the next box follows 15 below — here below the plate's helper line
  field: { marginBottom: space[15] },
  // 18 above Save, as board 32 sets its button under the row before it
  save: { marginTop: space[18] },
  // Remove, under Save — the gap board 32's buttons keep between a pair stacked one above the other
  remove: { marginTop: space[10] },
})
