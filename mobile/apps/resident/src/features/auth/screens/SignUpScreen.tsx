/**
 * resident-app-02 · Sign Up — board 01 Onboarding and Auth, rebuilt (M47 §2), with the estate dropdown where the board
 * draws its "Community invite code".
 *
 * Top to bottom: the header (identical to Log in) · "Create account" · "Join your community on GeminiSecure" · Your
 * estate (the invite code row's place) · Full name · Email address · the one terms line with its box, the two documents
 * gold inside it · Create account · "Already have an account? Log in". No password and no invite code: the account is
 * made when the six-digit code sent by email is proved, and it waits for the estate to confirm the unit.
 *
 * Deleted (M47 §2.2): the "Choose your estate below…" card, "My estate isn't listed — type a community code", the
 * no-password card, the second "Terms of Use · Privacy Policy" row, "I already have an account — log in" as a button,
 * "or just have a look", "Explore a demo community" and its paragraph, and the long name placeholder.
 */

import { StyleSheet, View } from 'react-native'
import { Pressable, Text, useFidelityReady } from '@gs/core'
import { AuthField, AuthFooter, AuthFrame, AuthTitle, PrimaryButton, TermsRow } from '@/design/parts'
import { font, ink, space } from '@/design/tokens'
import { ChooserSheet } from '@/features/visitors/components/ChooserSheet'
import { EMAIL_PLACEHOLDER, FULL_NAME_PLACEHOLDER, useSignUpForm } from '../useAuthForms'

export default function SignUpScreen() {
  const m = useSignUpForm()
  useFidelityReady(true)

  return (
    <AuthFrame
      onBack={m.back}
      overlay={<ChooserSheet open={m.estates.open} title="Your estate" choices={m.estates.choices} onClose={m.estates.close} />}
    >
      <AuthTitle title="Create account" sub="Join your community on GeminiSecure" />

      <AuthField label="Your estate" icon="input-4" value={m.estateName ?? ''} placeholder={m.estates.placeholder} onPress={m.estates.press} open={m.estates.open} error={m.errors.estate} />

      <AuthField
        ref={m.nameInput}
        label="Full name"
        icon="cc-row"
        value={m.fullName}
        onChangeText={m.setFullName}
        error={m.errors.fullName}
        placeholder={FULL_NAME_PLACEHOLDER}
        autoCapitalize="words"
        autoCorrect={false}
        autoComplete="name"
        textContentType="name"
        returnKeyType="next"
        onSubmitEditing={() => m.emailInput.current?.focus()}
      />

      <AuthField
        ref={m.emailInput}
        label="Email address"
        icon="input-5"
        value={m.email}
        onChangeText={m.setEmail}
        open={m.emailLit}
        error={m.errors.email}
        placeholder={EMAIL_PLACEHOLDER}
        keyboardType="email-address"
        autoCapitalize="none"
        autoCorrect={false}
        autoComplete="email"
        textContentType="emailAddress"
        returnKeyType="done"
      />
      {m.meant ? (
        <Pressable onPress={() => m.setEmail(m.meant ?? m.email)} role="button" aria-label={`Did you mean ${m.meant}? Use it`} hitSlop={space[8]} style={s.meant}>
          <Text {...font.inter400_12_5} color={ink.body}>{'Did you mean '}<Text {...font.inter700_12_5} color={ink.action}>{m.meant}</Text>{'?'}</Text>
        </Pressable>
      ) : null}

      <TermsRow agreed={m.agreed} onToggle={m.toggleAgreed} onTerms={m.terms} onPrivacy={m.privacy} error={m.errors.terms} />

      {m.problem ? <View style={s.problem}><Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{m.problem}</Text></View> : null}

      <PrimaryButton look="auth" label="Create account" onPress={m.submit} loading={m.busy} />

      <AuthFooter words="Already have an account? " link="Log in" onPress={m.logIn} />
    </AuthFrame>
  )
}

const s = StyleSheet.create({
  meant: { marginTop: space['-6'], marginBottom: space[15] },
  problem: { marginBottom: space[12] },
})
