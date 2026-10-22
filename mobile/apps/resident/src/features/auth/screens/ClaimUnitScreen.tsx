/**
 * /(auth)/claim-unit — "which home is yours?". No board draws this screen (the boards sign a
 * resident up with an invite code that links them "automatically"; the server links nobody
 * automatically — the estate approves a claim). It is assembled from the boards' own parts: the
 * auth shell and title, the labelled field, the information strip, the primary button, and the
 * category chip of board 11 for the relationship.
 *
 * One job: say who you are and which unit. The estate does the rest, and the next screen says so.
 */

import type { ComponentProps } from 'react'
import { StyleSheet, View } from 'react-native'
import { Redirect, router } from 'expo-router'
import { Controller, type Control } from 'react-hook-form'
import { ErrorView, Pressable, ResidentButton, ResidentField, SkeletonLines, Text, c, enterApp, phoneWords } from '@gs/core'
import { useAppDispatch, useAppSelector } from '@/store'
import { AuthNote, AuthShell, AuthTitle } from '../components/AuthShell'
import { RELATIONSHIPS, RELATIONSHIP_LABEL, type ClaimForm, type Relationship } from '../claim'
import { closeSession } from '../session'
import { useClaimUnit } from '../useClaimUnit'

export default function ClaimUnitScreen() {
  const dispatch = useAppDispatch()
  const sessionStatus = useAppSelector((s) => s.session.status)
  const model = useClaimUnit((outcome) => (outcome === 'already_linked' ? enterApp('/(tabs)' as never) : router.replace('/(auth)/pending' as never)))

  const logOut = async () => {
    await closeSession(dispatch)
    router.replace('/(auth)/log-in' as never)
  }

  // Only someone who has proved an email address has anything to claim with.
  if (sessionStatus === 'signedOut') return <Redirect href={'/(auth)/log-in' as never} />
  // A claim already with the estate (or an account already active): the holding screen knows what to do.
  if (model.status === 'in_review') return <Redirect href={'/(auth)/pending' as never} />

  const cameBack = !!model.earlierRejection

  return (
    <AuthShell canGoBack={cameBack} onBack={() => router.replace('/(auth)/pending' as never)}>
      <AuthTitle
        title="Claim your home"
        sub={model.estateName ? `Tell ${model.estateName} which unit is yours. The estate office checks it before your account opens.` : 'Tell your estate which unit is yours. The estate office checks it before your account opens.'}
      />

      {model.status === 'loading' || sessionStatus === 'booting' ? (
        <SkeletonLines tone="light" lines={5} style={s.loading} />
      ) : model.status === 'denied' ? (
        <>
          <ErrorView tone="light" message={model.deniedMessage ?? 'Your estate has withdrawn this account’s access. Contact the estate office.'} />
          <ResidentButton variant="outline" label="Log out" onPress={() => void logOut()} />
        </>
      ) : (
        <>
          {model.earlierRejection ? (
            <AuthNote
              tone="warn"
              icon="privacy-note"
              text={`Your estate did not accept the claim for ${model.earlierRejection.unit}${model.earlierRejection.reason ? `: ${model.earlierRejection.reason}` : '.'} You can claim again below.`}
            />
          ) : null}

          <Field control={model.control} name="full_name" label="Full name" icon="cc-row" placeholder="e.g. Andrea Fletcher: as your estate knows you" marginTop={model.earlierRejection ? 18 : 24} autoCapitalize="words" autoComplete="name" textContentType="name" />
          <Field control={model.control} name="lot" label="Lot or unit number" icon="tab-home" placeholder="e.g. 47" hint="The number on your gate or title: 47 and Lot 47 both work." autoCapitalize="characters" />
          {/* The estate's own example (M32 F7), never the phone's locale. */}
          <Field control={model.control} name="phone" label="Phone number" icon="call-btn" placeholder={`e.g. ${phoneWords().local}`} hint="So the estate office can reach you about this claim." keyboardType="phone-pad" autoComplete="tel" textContentType="telephoneNumber" />

          <Controller
            control={model.control}
            name="relationship"
            render={({ field, fieldState }) => (
              <View style={s.group}>
                <Text face="Inter-SemiBold" size={12} ls={0.1} color="slate-600">{'Your link to this home'}</Text>
                <View style={s.chipRow} role="radiogroup" aria-label="Your link to this home">
                  {RELATIONSHIPS.map((value: Relationship) => {
                    const on = field.value === value
                    return (
                      <Pressable
                        key={value}
                        onPress={() => field.onChange(value)}
                        role="radio"
                        aria-checked={on}
                        aria-label={RELATIONSHIP_LABEL[value]}
                        hitSlop={{ top: 6, bottom: 6 }}
                        style={[s.chip, on ? s.chipOn : null]}
                      >
                        <Text face="Inter-SemiBold" size={12} color={on ? 'white' : 'navy-700'} numberOfLines={1}>{RELATIONSHIP_LABEL[value]}</Text>
                      </Pressable>
                    )
                  })}
                </View>
                {fieldState.error?.message ? <Text face="Inter-Medium" size={11.5} lh={1.45} color="amber-700" style={s.groupError} role="alert">{fieldState.error.message}</Text> : null}
              </View>
            )}
          />

          {/* M18C §3.3: genuinely extra information, under its own heading — never "optional". */}
          <Text face="Inter-SemiBold" size={13} color="navy-900" style={{ marginTop: 22 }}>{'Anything else the office should know'}</Text>
          <Field control={model.control} name="phase" label="Phase or block, if your estate has them" icon="loc-row" placeholder="e.g. Phase 2" autoCapitalize="words" marginTop={10} />

          {model.problem ? <AuthNote tone="warn" icon="privacy-note" text={model.problem} marginTop={16} /> : null}
          {!model.online ? <AuthNote tone="warn" icon="privacy-note" text="No connection. A claim goes straight to your estate, so it cannot be saved for later: you can fill this in now and send it when you are back online." marginTop={16} /> : null}

          {model.online ? (
            <ResidentButton label="Send claim to my estate" onPress={model.submit} loading={model.busy} style={s.submit} />
          ) : (
            <ResidentButton label="Send claim to my estate" onPress={model.submit} disabled disabledReason="No connection. A claim cannot be queued: connect to the internet, then send it." style={s.submit} />
          )}

          <AuthNote text="Your estate office reviews every claim by hand: matching an email or phone number never opens a gate. You will see their answer on the next screen." marginTop={18} />

          <Pressable onPress={() => void logOut()} role="button" aria-label="Log out" hitSlop={14} style={s.logOut}>
            <Text face="Inter-Regular" size={13} color="slate-600" align="center">{'Not you? '}<Text face="Inter-Bold" color="amber-700">{'Log out'}</Text></Text>
          </Pressable>
        </>
      )}
    </AuthShell>
  )
}

type TextName = 'full_name' | 'lot' | 'phase' | 'phone'
type FieldProps = Omit<ComponentProps<typeof ResidentField>, 'value' | 'onChangeText' | 'onBlur' | 'error' | 'ref'> & { control: Control<ClaimForm>; name: TextName }

/** One labelled input bound to the form: the zod sentence (or the server's 422 sentence) shows under its box. */
function Field({ control, name, ...input }: FieldProps) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState }) => (
        <ResidentField {...input} ref={field.ref} value={field.value} onChangeText={field.onChange} onBlur={field.onBlur} error={fieldState.error?.message} autoCorrect={false} returnKeyType="next" />
      )}
    />
  )
}

const s = StyleSheet.create({
  loading: { marginTop: 28 },
  group: { marginTop: 15 },
  // Board 11's category chip: navy-100 idle, navy-600 chosen.
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 8 },
  chip: { backgroundColor: c('navy-100'), borderRadius: 20, paddingHorizontal: 14, paddingVertical: 9 },
  chipOn: { backgroundColor: c('navy-600') },
  groupError: { marginTop: 5 },
  submit: { marginTop: 22 },
  logOut: { paddingTop: 6, marginTop: 20 },
})
