/**
 * resident-app-03 · Log In — board 01 Onboarding and Auth, rebuilt (M47 §1), with the estate dropdown where the board
 * draws its Password field.
 *
 * Top to bottom, as the board draws it: the header (back chevron, wordmark, logo disc) · "Welcome back" · "Log in to
 * access <the estate>" · Email address · Your estate (in the Password row's place: its size, fill and spacing, the tag
 * glyph left and the chevron in the eye's place) · "Trouble signing in?" (gold, right, in "Forgot password?"'s place) ·
 * Log in · or · Log in with biometrics · "Don't have an account? Sign up". Nothing else, and it fits 390 × 844.
 *
 * Deleted (M47 §1.2): the Community code field and the name under it, "Choose your estate from the list instead", the
 * no-password line, the biometrics line, "or just have a look", "Explore a demo community" and its Seaview paragraph,
 * "Create an account" as a button (it is the footer's line), and the red "YOUR EMERGENCY ALERT IS OPEN" panel.
 *
 * LOG IN WITH BIOMETRICS WORKS (M50 §3): with Face ID / fingerprint on for this phone, the phone's prompt is asked as the
 * screen opens, and the key it keeps signs the phone in — no email, no code. Not set up here: the button is disabled, and
 * the gold "Log in with your email first…" line is gone.
 */

import { StyleSheet, View } from 'react-native'
import { Pressable, Text, useFidelityReady } from '@gs/core'
import { AuthDivider, AuthField, AuthFooter, AuthFrame, AuthTitle, PrimaryButton, SecondaryButton } from '@/design/parts'
import { font, ink, space } from '@/design/tokens'
import { ChooserSheet } from '@/features/visitors/components/ChooserSheet'
import { LOG_IN_EMAIL_PLACEHOLDER, useLogInForm } from '../useAuthForms'

export default function LogInScreen() {
  const m = useLogInForm()
  useFidelityReady(true)

  return (
    <AuthFrame
      onBack={m.back}
      overlay={<ChooserSheet open={m.estates.open} title="Your estate" choices={m.estates.choices} onClose={m.estates.close} />}
    >
      <AuthTitle title="Welcome back" sub={m.subtitle} />

      <AuthField
        label="Email address"
        icon="input-5"
        value={m.email}
        onChangeText={m.setEmail}
        open={m.emailLit}
        error={m.errors.email}
        placeholder={LOG_IN_EMAIL_PLACEHOLDER}
        keyboardType="email-address"
        autoCapitalize="none"
        autoCorrect={false}
        autoComplete="email"
        textContentType="emailAddress"
        returnKeyType="go"
        onSubmitEditing={m.submit}
      />
      {m.meant ? (
        <Pressable onPress={() => m.setEmail(m.meant ?? m.email)} role="button" aria-label={`Did you mean ${m.meant}? Use it`} hitSlop={space[8]} style={s.meant}>
          <Text {...font.inter400_12_5} color={ink.body}>{'Did you mean '}<Text {...font.inter700_12_5} color={ink.action}>{m.meant}</Text>{'?'}</Text>
        </Pressable>
      ) : null}

      <AuthField label="Your estate" icon="input-4" value={m.estateName ?? ''} placeholder={m.estates.placeholder} onPress={m.estates.press} open={m.estates.open} error={m.errors.estate} />

      <View style={s.troubleRow}>
        <Pressable onPress={m.trouble} role="link" aria-label="Trouble signing in?" hitSlop={space[12]}>
          <Text {...font.inter600_12_5} color={ink.link}>{'Trouble signing in?'}</Text>
        </Pressable>
      </View>

      {m.problem ? <View style={s.problem}><Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{m.problem}</Text></View> : null}

      <PrimaryButton look="auth" label="Log in" onPress={m.submit} loading={m.busy} />

      <AuthDivider />

      {/* Works once Face ID / fingerprint is on for this phone (it also asks by itself when the screen opens); disabled
          where it is not, with no line under it — only a screen reader hears how to set it up (M50 §3.2b–c). */}
      <SecondaryButton look="auth" icon="btn-outline-2" label="Log in with biometrics" onPress={m.biometrics} disabled={!m.biometricsReady} loading={m.biometricsBusy} accessibilityHint={m.biometricsHint} />

      <AuthFooter words="Don't have an account? " link="Sign up" onPress={m.signUp} gap="wide" />
    </AuthFrame>
  )
}

const s = StyleSheet.create({
  meant: { marginTop: space['-6'], marginBottom: space[15] },
  // .row-between { margin: 2px 0 20px }: right-aligned, in "Forgot password?"'s place.
  troubleRow: { flexDirection: 'row', justifyContent: 'flex-end', marginTop: space[2], marginBottom: space[20] },
  problem: { marginBottom: space[12] },
})
