/**
 * A pet — added from Profile's "+ Add pet", or opened by tapping its pill to change it (M50 §5.3d). The card is
 * "Vehicles & pets" and the board draws a pet beside the cars, but pets did not exist in the app at all. A screen NO
 * board draws, in board 32's look (Add household member): its app bar, its boxes, its blue button.
 *
 *   Name · Kind (a dropdown, the same control as board 32's: dog, cat, bird, fish, rabbit, other) · Save pet
 *
 * `pets.store` / `pets.update`, queueable like the household's other lists: kept on this phone first, and no screen says
 * "added" until the server has said so. The same pet twice (its name in any capitals, and its kind) is one pet: said
 * under the box, nothing sent. Remove is here, inside the pet's own screen, asked in its own sheet whose confirm is red.
 */

import { useRef, useState } from 'react'
import { Keyboard, StyleSheet, View } from 'react-native'
import { Controller } from 'react-hook-form'
import { useLocalSearchParams } from 'expo-router'
import { ConfirmSheet, EmptyState, useFidelityReady } from '@gs/core'
import { Field, PrimaryButton, SecondaryButton } from '@/design/parts'
import { space } from '@/design/tokens'
import { ChooserSheet } from '@/features/visitors/components/ChooserSheet'
import { FootNote, FormScreen, OutcomeScreen, backToProfile } from '../components/HouseholdForm'
import { PET_NAME_MAX, petLine, type Pet } from '../household'
import { removePetSheet } from '../sheets'
import { usePetForm } from '../useHouseholdForms'
import { useRemoveHere } from '../useProfile'

const OFFLINE = 'No connection. You can still save this: it is kept on this phone and sent to the estate office when you are back online.'

export default function PetScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const m = usePetForm(id)
  const removal = useRemoveHere<Pet>('pet', backToProfile)
  const [choosing, setChoosing] = useState(false)
  const removeButton = useRef<View>(null)
  useFidelityReady(true)

  const editing = m.editing.item
  const title = m.editing.id ? 'Edit pet' : 'Add pet'

  if (m.recorded) {
    return (
      <OutcomeScreen
        title={title}
        recorded={m.recorded}
        write={m}
        words={
          m.editing.id
            ? { confirmedTitle: 'Pet changed', confirmedBody: "Your household's list has its new details.", refusedTail: 'Nothing was changed.', another: null }
            : m.alreadyListed
              ? { confirmedTitle: 'Already on your list', confirmedPill: 'Nothing added', confirmedBody: 'Already on your list: nothing was added.', refusedTail: 'No pet was added.', another: 'Add another pet' }
              : { confirmedTitle: 'Pet added', confirmedBody: "It is on your household's list beside your vehicles.", refusedTail: 'No pet was added.', another: 'Add another pet' }
        }
        onAnother={m.startAnother}
      />
    )
  }

  if (m.editing.missing) {
    return (
      <FormScreen title={title}>
        <EmptyState tone="light" icon="item-tag-2" title="This pet is not on this phone" body="Open your profile with a connection, then try again." action={{ label: 'Back', onPress: backToProfile }} />
      </FormScreen>
    )
  }

  const kinds = m.kinds.map((option) => ({
    key: option.value,
    label: option.label,
    on: option.value === m.kind,
    pick: () => {
      m.chooseKind(option.value)
      setChoosing(false)
    },
  }))
  const words = removal.target ? removePetSheet({ line: petLine(removal.target) }, m.online) : null

  return (
    <FormScreen
      title={title}
      overlay={
        <>
          <ChooserSheet open={choosing} title="Kind of pet" choices={kinds} onClose={() => setChoosing(false)} />
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
        </>
      }
    >
      <Controller
        control={m.control}
        name="name"
        render={({ field }) => (
          <Field ref={field.ref} label="Name" icon="item-tag-2" value={field.value} placeholder="Bella" onChangeText={field.onChange} onBlur={field.onBlur} error={m.errors.name} autoCapitalize="words" autoCorrect={false} maxLength={PET_NAME_MAX} returnKeyType="done" />
        )}
      />
      <Field
        label="Kind"
        value={m.kindLabel ?? ''}
        placeholder="Dog, cat, bird…"
        onPress={() => {
          Keyboard.dismiss()
          setChoosing(true)
        }}
        open={choosing}
        chevron
        error={m.errors.kind}
        last
      />

      <FootNote problem={m.saveError ?? m.firstError} online={m.online} offlineText={OFFLINE} />

      <PrimaryButton label={m.online ? 'Save pet' : 'Save on this phone'} onPress={m.send} loading={m.busy} style={s.save} />
      {editing ? (
        <View ref={removeButton} style={s.remove}>
          <SecondaryButton label="Remove pet" name={`Remove ${petLine(editing)} from your pets`} onPress={() => removal.ask(editing)} />
        </View>
      ) : null}
    </FormScreen>
  )
}

const s = StyleSheet.create({
  // 18 above Save, as board 32 sets its button under the row before it
  save: { marginTop: space[18] },
  // Remove, under Save — the gap board 32's buttons keep between a pair stacked one above the other
  remove: { marginTop: space[10] },
})
