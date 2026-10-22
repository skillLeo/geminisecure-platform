/**
 * My contact details — a screen NO board draws (board 17's pencil opens it), assembled from board 32's
 * own parts. `me.update` takes three fields and no more: `full_name`, `phone`, `email`.
 *
 * WHICH ONES ARE OFFERED. The server writes `full_name` to the ACCOUNT and `phone` / `email` to the
 * resident's entry on the estate's REGISTER — and once an account is linked to a register entry, the
 * app and every server record use the register's name, which is the estate's to change. So a linked
 * resident (the normal case) is offered phone and email, and sees their name read-only with where to
 * get it corrected; an account not yet linked is offered its own name only (the server would silently
 * drop a phone or email it has nowhere to keep).
 *
 * WHAT THIS DOES NOT CHANGE, and says so: where sign-in codes are sent. That address belongs to the
 * account's sign-in, and no endpoint changes it.
 *
 * The write is queueable ("Offline: Queue"): with no connection it is saved on this phone and sent later.
 */

import { StyleSheet, View } from 'react-native'
import { Controller } from 'react-hook-form'
import { EmptyState, ErrorView, Icon, ResidentButton, ResidentField, SkeletonLines, Text, c, phoneWords, useFidelityReady } from '@gs/core'
import { FootNote, FormFrame, OfficeNote, RecordedPanel, backToProfile } from '../components/HouseholdParts'
import { EMAIL_MAX, NAME_MAX, PHONE_MAX } from '../household'
import { useEditDetails } from '../useHouseholdForms'

export default function EditDetailsScreen() {
  const m = useEditDetails()
  useFidelityReady(true)

  if (m.recorded) {
    return (
      <FormFrame title="My details" canGoBack={false}>
        <RecordedPanel
          recorded={m.recorded}
          write={m}
          words={{ confirmedTitle: 'Details updated', confirmedBody: 'The estate office now has your new details.', refusedTail: 'Nothing was changed.', another: null }}
        />
      </FormFrame>
    )
  }

  if (m.status !== 'ready') {
    return (
      <FormFrame title="My details">
        {m.status === 'loading' ? (
          <View role="progressbar" aria-label="Loading your details"><SkeletonLines tone="light" lines={4} /></View>
        ) : m.status === 'denied' ? (
          <EmptyState tone="light" icon="tab-profile" title="Your details can't be changed from this account" body={m.deniedMessage} action={{ label: 'Back to Profile', onPress: backToProfile }} />
        ) : (
          <ErrorView tone="light" message={m.errorMessage ?? 'Your details could not be loaded.'} onRetry={m.retry} />
        )}
      </FormFrame>
    )
  }

  return (
    <FormFrame title="My details">
      {m.staleNote ? (
        <View style={s.note} role="status"><Text face="Inter-SemiBold" size={11.5} lh={1.45} color="amber-700">{m.staleNote}</Text></View>
      ) : null}

      {m.mode === 'register' ? (
        <>
          <View style={s.readOnly} accessible aria-label={`Name on the estate's register: ${m.registerName ?? ''}. Only the estate office can change it.`}>
            <Icon name="cc-row" size={18} color="navy-500" style={s.readOnlyIcon} />
            <View style={s.shrink}>
              <Text face="Inter-SemiBold" size={10.5} color="slate-500">{"Name on the estate's register"}</Text>
              <Text face="Inter-Medium" size={14} color="navy-900" style={{ marginTop: 2 }}>{m.registerName}</Text>
            </View>
          </View>
          <Text face="Inter-Regular" size={11.5} lh={1.45} color="slate-500" style={s.hint}>{'The estate office keeps this name. To correct it, contact the estate office.'}</Text>

          <Controller
            control={m.control}
            name="email"
            render={({ field }) => (
              <ResidentField ref={field.ref} label="Email" icon="input-5" value={field.value} onChangeText={field.onChange} onBlur={field.onBlur} error={m.errors.email} placeholder="e.g. andrea.fletcher@gmail.com" keyboardType="email-address" autoCapitalize="none" autoCorrect={false} autoComplete="email" maxLength={EMAIL_MAX} returnKeyType="next" />
            )}
          />
          <Controller
            control={m.control}
            name="phone"
            render={({ field }) => (
              <ResidentField ref={field.ref} label="Phone number" icon="call-btn" value={field.value} onChangeText={field.onChange} onBlur={field.onBlur} error={m.errors.phone} placeholder={`e.g. ${phoneWords().local}`} keyboardType="phone-pad" autoComplete="tel" maxLength={PHONE_MAX} returnKeyType="done" onSubmitEditing={m.send} />
            )}
          />
        </>
      ) : (
        <Controller
          control={m.control}
          name="full_name"
          render={({ field }) => (
            <ResidentField ref={field.ref} label="Your name" icon="cc-row" value={field.value} onChangeText={field.onChange} onBlur={field.onBlur} error={m.errors.full_name} hint="Your phone number and email are kept on the estate's register once your home is linked to this account." placeholder="e.g. Andrea Fletcher" autoCapitalize="words" autoCorrect={false} autoComplete="name" maxLength={NAME_MAX} returnKeyType="done" onSubmitEditing={m.send} marginTop={0} />
          )}
        />
      )}

      {m.notice && !m.firstError && !m.saveError ? (
        <View style={s.notice} role="status"><Text face="Inter-SemiBold" size={12} lh={1.5} color="slate-600">{m.notice}</Text></View>
      ) : null}
      <FootNote problem={m.saveError ?? m.firstError} online={m.online} offlineText="No connection. You can still save this: it is kept on this phone and sent to the estate office when you are back online." />

      <ResidentButton label={m.online ? 'Save my details' : 'Save on this phone'} onPress={m.send} loading={m.busy} style={{ marginTop: 18 }} />

      {m.signInHint ? <OfficeNote text={`Your sign-in codes are sent to ${m.signInHint}. Changing the details here does not change that: ask the estate office if you need to sign in with a different address.`} /> : null}
    </FormFrame>
  )
}

const s = StyleSheet.create({
  shrink: { flexShrink: 1 },
  note: { backgroundColor: c('amber-100'), borderRadius: 12, padding: 12, marginBottom: 14 },
  notice: { backgroundColor: c('navy-100'), borderRadius: 12, padding: 12, marginTop: 18 },
  readOnlyIcon: { width: 18, height: 18, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginRight: 10 },
  readOnly: { minHeight: 56, flexDirection: 'row', alignItems: 'center', borderRadius: 14, borderWidth: 1, borderColor: c('navy-100'), paddingHorizontal: 14, paddingVertical: 9 },
  hint: { marginTop: 5 },
})
