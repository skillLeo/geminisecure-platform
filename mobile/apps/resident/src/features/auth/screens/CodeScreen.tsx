/**
 * /(auth)/code — the six-digit sign-in code. No board draws this screen (the boards draw a
 * password, which the server does not have); it is assembled from the auth boards' own parts:
 * the shell, the title block, the field's focused box for each digit, the primary button.
 *
 * ONE real text input sits under the six boxes, so paste, the keyboard's "code from Mail/
 * Messages" suggestion (`one-time-code`) and screen readers all work the way the phone intends.
 */

import { useEffect, useRef, useState } from 'react'
import { StyleSheet, TextInput, View } from 'react-native'
import { Redirect, router } from 'expo-router'
import { Pressable, ResidentButton, Text, c, sh } from '@gs/core'
import { goAfterSignIn } from '../afterSignIn'
import { AuthNote, AuthShell, AuthTitle } from '../components/AuthShell'
import { CODE_LENGTH, signInDraft, useRequestCode, useResendCountdown, useVerifyCode } from '../useSignIn'

/** "h•••@example.com", as the server masks an address, for a draft the server sent no hint with. */
const masked = (email: string): string => {
  const [local = '', domain = ''] = email.split('@')
  return domain ? `${local.slice(0, 1)}•••@${domain}` : email
}

export default function CodeScreen() {
  const draft = signInDraft.get()
  const input = useRef<TextInput>(null)
  const { submit, busy } = useVerifyCode()
  const { request, busy: resending } = useRequestCode()
  const [code, setCode] = useState('')
  const [problem, setProblem] = useState<{ message: string; locked: boolean } | null>(null)
  const [resendAfter, setResendAfter] = useState(draft.resendAfter)
  const [resent, setResent] = useState(false)
  const wait = useResendCountdown(resendAfter)

  const verify = async (value: string) => {
    if (busy || value.length !== CODE_LENGTH) return
    const outcome = await submit(value)
    if (outcome.ok) {
      signInDraft.set({ hint: null })
      // A held panic first, the permissions the first time, the sign-in screens cleared from the history (afterSignIn.ts).
      return goAfterSignIn(outcome.next)
    }
    // M18H: the account at this address was deleted and can still come back — the choice is the person's, on its own screen.
    if (outcome.deleted) return router.replace('/(auth)/restore' as never)
    setProblem({ message: outcome.message, locked: outcome.locked || outcome.expired })
    setCode('')
    input.current?.focus()
  }

  // The last digit is the submit: nobody should have to find a button after typing six numbers.
  useEffect(() => {
    if (code.length === CODE_LENGTH) void verify(code)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code])

  // Opened cold (a reload, a deep link): there is no address to verify a code against.
  if (!draft.email || !draft.estate) return <Redirect href={'/(auth)/log-in' as never} />

  /** Back to the address, with what was typed still in it — the estate and the email are kept in the draft. */
  const changeAddress = () => (router.canGoBack() ? router.back() : router.replace('/(auth)/log-in' as never))

  const resend = async () => {
    setProblem(null)
    // Asked the way the first request was: a sign-in is looked up again, Create account is not (M80 §2.1).
    const answer = await request({ estate: draft.estate, email: draft.email, intent: draft.intent ?? undefined })
    if (!answer.ok) return setProblem({ message: answer.message, locked: false })
    setResendAfter(signInDraft.get().resendAfter)
    setResent(true)
    setCode('')
    input.current?.focus()
  }

  return (
    <AuthShell>
      {/* M80 §2.1: headed with where the code went, masked as the server masks it. A typo is caught before this screen
          now ("We can't find that email"), and the mask is how a resident sees at once that the estate holds an old
          address for them. */}
      <AuthTitle title={`Code sent to ${draft.hint ?? masked(draft.email)}`} sub="It works once, for 10 minutes." />
      <Pressable onPress={changeAddress} role="button" aria-label="Not your email? Change it" hitSlop={10} style={s.change}>
        <Text face="Inter-Regular" size={13} color="slate-600" align="center">{'Not your email? '}<Text face="Inter-Bold" color="navy-600" style={{ textDecorationLine: 'underline' }}>{'Change it'}</Text></Text>
      </Pressable>

      <Pressable onPress={() => input.current?.focus()} accessible={false} style={s.boxes}>
        {Array.from({ length: CODE_LENGTH }, (_, i) => {
          const active = i === Math.min(code.length, CODE_LENGTH - 1) && !busy
          return (
            <View key={i} style={[s.box, active ? s.boxActive : null, problem ? s.boxError : null]}>
              <Text face="Poppins-SemiBold" size={22} color="navy-900">{code[i] ?? ''}</Text>
            </View>
          )
        })}
        <TextInput
          ref={input}
          value={code}
          onChangeText={(text) => {
            setProblem(null)
            setCode(text.replace(/\D/g, '').slice(0, CODE_LENGTH))
          }}
          keyboardType="number-pad"
          inputMode="numeric"
          autoComplete="one-time-code"
          textContentType="oneTimeCode"
          autoFocus
          maxLength={CODE_LENGTH}
          editable={!busy}
          caretHidden
          aria-label="Six-digit sign-in code"
          style={s.hidden}
        />
      </Pressable>

      {problem ? <AuthNote tone="warn" icon="privacy-note" text={problem.message} marginTop={16} /> : null}
      {resent && !problem ? <AuthNote text="A new code is on its way. The earlier one no longer works." marginTop={16} /> : null}

      <ResidentButton
        label="Verify and continue"
        onPress={() => void verify(code)}
        loading={busy}
        disabled={code.length !== CODE_LENGTH}
        disabledReason="Type all six digits of the code first"
        style={{ marginTop: 22 }}
      />

      {wait > 0 ? (
        <Text face="Inter-Regular" size={13} color="slate-500" align="center" style={s.resend} role="timer">
          {`Didn't get it? Resend in ${wait}s`}
        </Text>
      ) : (
        <Pressable onPress={() => void resend()} disabled={resending} role="button" aria-label="Resend the code" hitSlop={10} style={s.resend}>
          <Text face="Inter-Regular" size={13} color="slate-600" align="center">{"Didn't get it? "}<Text face="Inter-Bold" color="amber-700">{resending ? 'Sending…' : 'Resend'}</Text></Text>
        </Pressable>
      )}

      <AuthNote text="Look in your spam or junk folder too. The code comes from your estate's GeminiSecure address, never share it with anyone, including estate staff." marginTop={22} />
    </AuthShell>
  )
}

const s = StyleSheet.create({
  boxes: { flexDirection: 'row', justifyContent: 'space-between', gap: 8, marginTop: 26 },
  // The auth boards' input, as a single-digit cell: navy-100 idle, white with the amber ring when it is the next to fill.
  box: { flex: 1, height: 56, maxWidth: 52, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('navy-100'), borderRadius: 14, borderWidth: 1, borderColor: 'transparent' },
  boxActive: { backgroundColor: c('white'), borderColor: c('amber-500'), boxShadow: sh([{ x: 0, y: 0, blur: 0, spread: 3, token: 'amber-500', opacity: 0.18 }]) },
  // A wrong code is an ordinary error: amber, never a panic's red (M98, 12 Oct 2026).
  boxError: { borderColor: c('amber-700'), backgroundColor: c('white') },
  // Present and focusable, but never seen: the six boxes are its face.
  hidden: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, opacity: 0.01, color: 'transparent' },
  resend: { marginTop: 18 },
  address: { marginTop: 6, paddingHorizontal: 6 },
  change: { marginTop: 10, minHeight: 32, justifyContent: 'center' },
})
