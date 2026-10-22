/**
 * /(auth)/restore — signing in to an account that was deleted (M18H §2.3, §2.4). No board draws it; it is the auth
 * boards' own shell, title and buttons.
 *
 * Reached only from the code screen, with what `409 account_deleted` carried held in memory (`deletedAccount`). In the
 * person's own window: "Restore my account", or "Not now". After it: only "Contact the estate office". Nothing is
 * restored until the button is pressed, and the button says exactly what it does.
 */

import { useState } from 'react'
import { Linking, StyleSheet, View } from 'react-native'
import { Redirect, router } from 'expo-router'
import { Icon, ResidentButton, Text, c, enterApp, useFidelityReady } from '@gs/core'
import { AuthNote, AuthShell } from '../components/AuthShell'
import { permissionsAsked } from '../usePermissions'
import { deletedAccount, officeLink, restoreCopy } from '../restore'
import { signInDraft, useRestoreAccount } from '../useSignIn'

const NEXT = { claim_unit: '/(auth)/claim-unit', await_approval: '/(auth)/pending', home: '/(tabs)' } as const

export default function RestoreScreen() {
  const [held, setHeld] = useState(deletedAccount.get)
  const { submit, busy } = useRestoreAccount()
  const [problem, setProblem] = useState<string | null>(null)
  useFidelityReady(true)

  // Opened cold (a reload, a deep link): there is no sign-in to restore from.
  if (!held) return <Redirect href={'/(auth)/log-in' as never} />

  const copy = restoreCopy(held)
  const office = officeLink(held)

  const notNow = () => {
    deletedAccount.clear()
    signInDraft.set({ hint: null })
    router.replace('/(auth)/log-in' as never)
  }

  const restore = async () => {
    setProblem(null)
    const outcome = await submit()
    if (outcome.ok) {
      signInDraft.set({ hint: null })
      if (outcome.next === 'home') return enterApp((permissionsAsked.get() ? NEXT.home : '/permissions') as never)
      return router.replace(NEXT[outcome.next] as never)
    }
    // The window closed while the screen was open: the office-only version, with the office's contact.
    if (outcome.officeOnly) setHeld(deletedAccount.get())
    setProblem(outcome.message)
  }

  return (
    <AuthShell onBack={notNow}>
      <View style={s.mark}><Icon name="privacy-note" size={28} color="navy-900" /></View>
      <Text face="Poppins-SemiBold" size={21} lh={1.3} color="navy-900" align="center" role="heading">{copy.title}</Text>
      <Text face="Inter-Regular" size={14} lh={1.55} color="navy-800" align="center" style={s.body}>{copy.body}</Text>

      {problem ? <AuthNote tone="warn" icon="privacy-note" text={problem} marginTop={16} /> : null}

      {copy.action === 'restore' ? (
        <>
          <ResidentButton label={copy.actionLabel} onPress={() => void restore()} loading={busy} style={{ marginTop: 24 }} />
          <ResidentButton variant="tertiary" label={copy.notNow ?? 'Not now'} onPress={notNow} disabled={busy} disabledReason="Restoring your account…" style={{ marginTop: 6 }} />
          <AuthNote text="Your home, household, balance and history come back as they were. Visitor passes that stopped when the account was deleted stay stopped: send new ones from the app." marginTop={18} />
        </>
      ) : (
        <>
          <ResidentButton
            label={copy.actionLabel}
            onPress={() => (office ? void Linking.openURL(office.url) : undefined)}
            disabled={!office}
            disabledReason="This estate has not given the app an email or a phone number for its office. Ask at the estate office in person."
            style={{ marginTop: 24 }}
          />
          {office ? <Text face="Inter-Regular" size={13} color="navy-800" align="center" selectable style={{ marginTop: 10 }}>{office.label}</Text> : null}
          <ResidentButton variant="tertiary" label="Back to sign in" onPress={notNow} style={{ marginTop: 6 }} />
        </>
      )}
    </AuthShell>
  )
}

const s = StyleSheet.create({
  mark: { width: 60, height: 60, alignSelf: 'center', alignItems: 'center', justifyContent: 'center', borderRadius: 30, backgroundColor: c('navy-100'), marginBottom: 14 },
  body: { marginTop: 8, paddingHorizontal: 4 },
})
