/**
 * resident-app-04 · Forgot Password — board 01 Onboarding and Auth, rebuilt on the sign-in parts (M50 §7) as "Trouble
 * signing in?", the link on Log in that stands in "Forgot password?"'s place (M47 §1).
 *
 * There is no password to forget: the server signs a resident in with a six-digit code sent to their email address, every
 * time. So the board's own screen asks for a fresh code — its layout and every value, its words where they are about a
 * password changed (tools/design/check-exceptions.json):
 *
 *   the header (identical to Log in) · "Trouble signing in?" · "No problem — we'll email you a new sign-in code." · the
 *   key in its navy-100 disc · Email address (the board's one field, lit) · Your estate (the server cannot send a code
 *   without it — the same dropdown as Log in) · the board's one sentence under the fields, said of a code · "Send
 *   sign-in code" · "Back to Log in".
 *
 * It ends where logging in ends — on the code screen, which is the board's "sent" ("We sent a 6-digit code to a•••@…").
 * The answer to "send me a code" is the same whether or not an account exists, and nothing here implies either.
 *
 * Deleted with the rebuild: the paragraph about spam folders and the estate office under the button, and the old kit's
 * darker button and link (the board's own navy-600 and amber-600 now, as on Log in).
 */

import { StyleSheet, View } from 'react-native'
import { Icon, Pressable, Text, useFidelityReady } from '@gs/core'
import { AuthField, AuthFooter, AuthFrame, AuthTitle, PrimaryButton } from '@/design/parts'
import { corner, fill, font, icon, ink, size, space } from '@/design/tokens'
import { ChooserSheet } from '@/features/visitors/components/ChooserSheet'
import { LOG_IN_EMAIL_PLACEHOLDER, useTroubleForm } from '../useAuthForms'

/** The board's sentence under its field, said of the code the server really sends. */
const HELPER = "We'll send a 6-digit code to the email address linked to your GeminiSecure account. It works once, for 10 minutes."

export default function ForgotScreen() {
  const m = useTroubleForm()
  useFidelityReady(true)

  return (
    <AuthFrame
      onBack={m.back}
      overlay={<ChooserSheet open={m.estates.open} title="Your estate" choices={m.estates.choices} onClose={m.estates.close} />}
    >
      <AuthTitle title="Trouble signing in?" sub="No problem: we'll email you a new sign-in code." />

      {/* .key-illus */}
      <View style={s.key} aria-hidden>
        <Icon name="key-illus" size={icon[40]} color={ink.action} />
      </View>

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
        returnKeyType="done"
        onSubmitEditing={m.submit}
      />
      {m.meant ? (
        <Pressable onPress={() => m.setEmail(m.meant ?? m.email)} role="button" aria-label={`Did you mean ${m.meant}? Use it`} hitSlop={space[8]} style={s.meant}>
          <Text {...font.inter400_12_5} color={ink.body}>{'Did you mean '}<Text {...font.inter700_12_5} color={ink.action}>{m.meant}</Text>{'?'}</Text>
        </Pressable>
      ) : null}

      <AuthField label="Your estate" icon="input-4" value={m.estateName ?? ''} placeholder={m.estates.placeholder} onPress={m.estates.press} open={m.estates.open} error={m.errors.estate} />

      {/* The board's p { margin: 2px 0 22px } — its 2 collapses into the field's 15 above it in the board. */}
      <View style={s.helper}>
        <Text {...font.inter400_12_lh1_6} color={ink.secondary}>{HELPER}</Text>
      </View>

      {m.problem ? <View style={s.problem}><Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{m.problem}</Text></View> : null}
      {m.offline ? <View style={s.problem}><Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{'No connection. Sending a code needs one: check it and try again.'}</Text></View> : null}

      <PrimaryButton look="auth" label="Send sign-in code" onPress={m.submit} loading={m.busy} />

      <AuthFooter words="Back to " link="Log in" onPress={m.logIn} gap="normal" />
    </AuthFrame>
  )
}

const s = StyleSheet.create({
  // .key-illus { margin: 2px auto 22px } — its 2 collapses into the sub's 24 above it in the board
  key: { width: size[88], height: size[88], borderRadius: corner.round(size[88]), backgroundColor: fill.tint, alignItems: 'center', justifyContent: 'center', alignSelf: 'center', marginBottom: space[22] },
  meant: { marginTop: space['-6'], marginBottom: space[15] },
  helper: { marginBottom: space[22] },
  problem: { marginBottom: space[12] },
})
