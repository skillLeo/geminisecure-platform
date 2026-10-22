/**
 * "Delete my account" in the Guard App — and the honest answer to it.
 *
 * The reasoning is in closeAccount.ts: this app issues no accounts, so there is nothing here an
 * officer created and nothing this app may delete. The screen says who CAN close the account, and
 * offers the part that really is the officer's — removing the app from this phone — using the same
 * deliberate sign-out the More screen uses, so there is exactly one path and one set of warnings. It is
 * asked in a ConfirmSheet over the screen, as More asks it.
 */

import { useRef } from 'react'
import { ScrollView, StyleSheet, View } from 'react-native'
import { AppBar, ConfirmSheet, GuardButton, Text, c, isFidelity, useFidelityReady } from '@gs/core'
import { GuardLegalLinks } from '@/features/legal/GuardLegalLinks'
import { Heading, ListCard } from '../components/Rows'
import { HOW, LEGAL_LEAD, REMOVE_FROM_PHONE, TITLE, WHOSE_ACCOUNT } from '../closeAccount'
import { useSignOut } from '../useSignOut'

function Paragraphs({ lines }: { lines: readonly string[] }) {
  return (
    <>
      {lines.map((line, i) => (
        <Text key={line} face="Inter-Regular" size={12} lh={1.55} color="text-2" style={{ marginTop: i === 0 ? 0 : 10 }}>{line}</Text>
      ))}
    </>
  )
}

export default function CloseAccountScreen() {
  // Not an ordinary sign-out (M32 F3): the phone keeps that the officer removed it, so coming back takes a code.
  const out = useSignOut({ removeFromPhone: true })
  const removeButton = useRef<View>(null)
  useFidelityReady(true)

  return (
    <View style={s.screen}>
      <AppBar title="Delete my account" left="back" />
      <ScrollView contentContainerStyle={s.body}>
        <Text face="Poppins-SemiBold" size={17} color="white" role="heading">{TITLE}</Text>
        <View style={s.card}><Paragraphs lines={WHOSE_ACCOUNT} /></View>

        <Heading>{'How to close it'}</Heading>
        <ListCard style={s.plain}><Paragraphs lines={HOW} /></ListCard>

        <Heading>{'What you can do here'}</Heading>
        <View style={s.card}>
          <Text face="Inter-SemiBold" size={13} color="white">{REMOVE_FROM_PHONE.title}</Text>
          <Text face="Inter-Regular" size={12} lh={1.55} color="text-2" style={{ marginTop: 6 }}>{REMOVE_FROM_PHONE.body}</Text>

          {out.blocked ? <Text face="Inter-SemiBold" size={11.5} lh={1.5} color="red-500" style={{ marginTop: 10 }} role="alert">{out.blocked}</Text> : null}
          {!out.blocked && out.unsent > 0 ? (
            <Text face="Inter-SemiBold" size={11.5} lh={1.5} color="amber-500" style={{ marginTop: 10 }} role="alert">
              {`${out.unsent} ${out.unsent === 1 ? 'record is' : 'records are'} still waiting to be sent. Removing the app deletes ${out.unsent === 1 ? 'it' : 'them'}: ${out.unsent === 1 ? 'it' : 'they'} will never reach the control room.`}
            </Text>
          ) : null}

          <GuardButton
            ref={removeButton}
            variant="warning"
            label="Remove this app from this phone"
            onPress={out.ask}
            disabled={isFidelity || !!out.blocked}
            disabledReason={out.blocked ?? 'Not available on this screen'}
            style={{ marginTop: 14 }}
          />
        </View>

        <Text face="Inter-Regular" size={11} lh={1.5} color="text-3" style={{ marginTop: 16 }}>{LEGAL_LEAD}</Text>
        <GuardLegalLinks pages={['privacy']} align="left" style={{ marginTop: 10 }} />
      </ScrollView>

      {/* The same sign-out as More, asked in a sheet over the screen instead of a second pair of buttons in the card. */}
      <ConfirmSheet
        tone="guard"
        open={out.asking}
        title={`${REMOVE_FROM_PHONE.title}?`}
        lines={[
          REMOVE_FROM_PHONE.body,
          ...(!out.blocked && !out.online ? ['There is no signal, so the server cannot be told to end this session now. This phone is cleared either way, and the session on the server ends by itself within 30 minutes.'] : []),
        ]}
        confirmLabel={out.unsent > 0 ? `Remove, and delete ${out.unsent} unsent` : 'Remove it from this phone'}
        cancelLabel="Keep it on this phone"
        working={out.working}
        problem={out.problem}
        blocked={out.blocked}
        onConfirm={out.confirm}
        onCancel={out.dismiss}
        returnFocusTo={removeButton}
      >
        {!out.blocked && out.unsent > 0 ? (
          <Text face="Inter-SemiBold" size={12} lh={1.5} color="amber-500" style={{ marginTop: 10 }}>
            {`${out.unsent} ${out.unsent === 1 ? 'record is' : 'records are'} still waiting to be sent. Removing the app deletes ${out.unsent === 1 ? 'it' : 'them'}: ${out.unsent === 1 ? 'it' : 'they'} will never reach the control room.`}
          </Text>
        ) : null}
      </ConfirmSheet>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  body: { paddingHorizontal: 18, paddingTop: 14, paddingBottom: 40 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 16, marginTop: 10 },
  plain: { padding: 16 },
})
