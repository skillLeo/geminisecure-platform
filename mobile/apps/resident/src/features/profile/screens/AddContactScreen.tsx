/**
 * An emergency contact — added from Profile's "+ Add emergency contact", or opened by tapping one to change it (M50 §5.2,
 * §5.4). A screen NO board draws, built as the user asked: "like Add household member — relationship, name, email,
 * number only". It IS Add household member (board 32) with the access level and the invite switch taken out: the same
 * app bar with nothing on the right, the same boxes, spacing and order, the same blue button.
 *
 *   Full name · Relationship (a dropdown, the same control as board 32's) · Phone number · Email · Save emergency contact
 *
 * THE RELATIONSHIP LIST IS ITS OWN (household.ts · CONTACT_RELATIONSHIPS), not the household's: a contact may be a
 * doctor, a neighbour or a friend, who does not live at the address — and is never a tenant or a helper.
 *
 * `contacts.store` / `contacts.update` take all four — required (M18C: a panic emails them at once, and the number is
 * for the control room to call). One number is one contact: a number already on the list for ANOTHER contact is said
 * under the box and nothing is sent. Both writes are queueable; the screen never says "added" or "changed" until the
 * server has said so. Remove is here, inside the contact's own screen, asked in its own sheet whose confirm is red.
 *
 * DELETED WITH M50 §5.2: the read-only summary card and the title "Add their email" (that screen is gone — a contact's
 * email is changed here, with the rest), the calendar mark on the email box (it is the envelope, as on board 32), and
 * the helper lines under the boxes.
 */

import { useRef, useState } from 'react'
import { Keyboard, StyleSheet, View } from 'react-native'
import { Controller } from 'react-hook-form'
import { useLocalSearchParams } from 'expo-router'
import { ConfirmSheet, EmptyState, phoneWords, useFidelityReady } from '@gs/core'
import { Field, PrimaryButton, SecondaryButton } from '@/design/parts'
import { space } from '@/design/tokens'
import { ChooserSheet } from '@/features/visitors/components/ChooserSheet'
import { EmailSuggestion, FootNote, FormScreen, OutcomeScreen, backToProfile } from '../components/HouseholdForm'
import { EMAIL_MAX, NAME_MAX, type Contact } from '../household'
import { removeContactSheet } from '../sheets'
import { useContactForm } from '../useHouseholdForms'
import { useRemoveHere } from '../useProfile'

const OFFLINE = 'No connection. You can still save this: it is kept on this phone and sent to the estate office when you are back online.'

export default function AddContactScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const m = useContactForm(id)
  const removal = useRemoveHere<Contact>('contact', backToProfile)
  const [choosing, setChoosing] = useState(false)
  const removeButton = useRef<View>(null)
  useFidelityReady(true)

  const editing = m.editing.item
  const title = m.editing.id ? 'Edit emergency contact' : 'Add emergency contact'

  if (m.recorded) {
    return (
      <OutcomeScreen
        title={title}
        recorded={m.recorded}
        write={m}
        words={
          m.editing.id
            ? { confirmedTitle: 'Emergency contact changed', confirmedBody: 'The estate office has their new details.', refusedTail: 'Nothing was changed.', another: null }
            // The server answers a number the household already has with that contact, and adds nothing.
            : m.alreadyListed
              ? { confirmedTitle: 'Already on your list', confirmedPill: 'Nothing added', confirmedBody: 'Already on your list: nothing was added.', refusedTail: 'No contact was added.', another: 'Add another contact' }
              : { confirmedTitle: 'Emergency contact added', confirmedBody: 'The estate office can now see who to call for your household.', refusedTail: 'No contact was added.', another: 'Add another contact' }
        }
        onAnother={m.startAnother}
      />
    )
  }

  if (m.editing.missing) {
    return (
      <FormScreen title={title}>
        <EmptyState tone="light" icon="cc-row" title="This contact is not on this phone" body="Open your profile with a connection, then try again." action={{ label: 'Back', onPress: backToProfile }} />
      </FormScreen>
    )
  }

  const relationships = m.relationships.map((option) => ({
    key: option.value,
    label: option.label,
    on: option.value === m.relationship,
    pick: () => {
      m.chooseRelationship(option.value)
      setChoosing(false)
    },
  }))
  const words = removal.target ? removeContactSheet(removal.target, m.online) : null

  return (
    <FormScreen
      title={title}
      overlay={
        <>
          <ChooserSheet open={choosing} title="Relationship" choices={relationships} onClose={() => setChoosing(false)} />
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
          <Field ref={field.ref} label="Full name" icon="cc-row" value={field.value} placeholder="Marcia Brown" onChangeText={field.onChange} onBlur={field.onBlur} error={m.errors.name} autoCapitalize="words" autoCorrect={false} autoComplete="name" maxLength={NAME_MAX} returnKeyType="next" />
        )}
      />

      <Field
        label="Relationship"
        icon="input-6"
        value={m.relationshipLabel ?? ''}
        placeholder="Sister, neighbour, doctor"
        onPress={() => {
          Keyboard.dismiss()
          setChoosing(true)
        }}
        open={choosing}
        chevron
        error={m.errors.relationship}
      />

      <Controller
        control={m.control}
        name="phone"
        render={({ field }) => (
          <Field ref={field.ref} label="Phone number" icon="call-btn" value={field.value} placeholder={phoneWords().local} onChangeText={field.onChange} onBlur={field.onBlur} error={m.errors.phone} keyboardType="phone-pad" autoComplete="tel" maxLength={21} returnKeyType="next" />
        )}
      />

      {/* The box and its "Did you mean …?" together keep the field's 15 below them, as on board 32. */}
      <View style={s.emailBlock}>
        <Controller
          control={m.control}
          name="email"
          render={({ field }) => (
            <Field ref={field.ref} label="Email" icon="input-5" value={field.value ?? ''} placeholder="marcia.brown@gmail.com" onChangeText={field.onChange} onBlur={field.onBlur} error={m.errors.email} keyboardType="email-address" autoCapitalize="none" autoCorrect={false} autoComplete="email" maxLength={EMAIL_MAX} returnKeyType="done" onSubmitEditing={m.send} last />
          )}
        />
        <EmailSuggestion suggestion={m.emailSuggestion} onAccept={m.acceptEmailSuggestion} />
      </View>

      <FootNote problem={m.saveError ?? m.firstError} online={m.online} offlineText={OFFLINE} />

      <PrimaryButton label={m.online ? 'Save emergency contact' : 'Save on this phone'} onPress={m.send} loading={m.busy} style={s.save} />
      {editing ? (
        <View ref={removeButton} style={s.remove}>
          <SecondaryButton label="Remove emergency contact" name={`Remove ${editing.name} from your emergency contacts`} onPress={() => removal.ask(editing)} />
        </View>
      ) : null}
    </FormScreen>
  )
}

const s = StyleSheet.create({
  // .field's 15 below the email box (and below its suggestion, when one shows)
  emailBlock: { marginBottom: space[15] },
  // The email block's 15 and 3 more: 18 above Save, as board 32 sets its button under the row before it
  save: { marginTop: space[3] },
  // Remove, under Save — the gap board 32's buttons keep between a pair stacked one above the other
  remove: { marginTop: space[10] },
})
