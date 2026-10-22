/**
 * Delete my account. No board draws it; it is built from the settings kit's own parts.
 *
 * The whole screen IS the disclosure both stores require: what stops and what stays, itemised, in
 * front of the person before anything happens (M18H §2.1) — that they lose access at once, that
 * their passes stop, that signing in within the estate's first window restores it, that after the
 * second it cannot be undone, and, when the home owes money, that the debt does not go away. The
 * confirmation is TYPED, and what is still waiting to be sent is counted first.
 */

import { ScrollView, StyleSheet, View } from 'react-native'
import { ResidentAppBar, ResidentButton, ResidentField, Text, c, useFidelityReady } from '@gs/core'
import { legalAddress } from '@gs/core/lib'
import { INFO } from '@/info/topics'
import { Caption, Heading, Note } from '../components/SettingsParts'
import { ALSO_ON_THE_WEB, CONFIRMATION, DELETED_NOW, DEMONSTRATION, KEPT, type Disclosure } from '../deleteAccount'
import { useDeleteAccount } from '../useDeleteAccount'

function List({ what, tone }: { what: Disclosure; tone: 'gone' | 'kept' }) {
  return (
    <View style={[s.card, tone === 'gone' ? s.cardGone : null]}>
      {/* What is deleted needs the reader's attention: amber, never a panic's red (M98, 12 Oct 2026). */}
      <Text face="Inter-Bold" size={11.5} color={tone === 'gone' ? 'amber-700' : 'slate-600'}>{what.title.toUpperCase()}</Text>
      {what.items.map((item) => (
        <View key={item} style={s.row}>
          <View style={[s.dot, { backgroundColor: c(tone === 'gone' ? 'amber-700' : 'slate-500') }]} />
          <View style={s.fill}><Text face="Inter-Regular" size={12} lh={1.5} color="slate-600">{item}</Text></View>
        </View>
      ))}
    </View>
  )
}

export default function DeleteAccountScreen() {
  const m = useDeleteAccount()
  useFidelityReady(true)

  return (
    <View style={s.screen}>
      <ResidentAppBar title="Delete my account" fallback="/(tabs)/profile" info={INFO.deleteAccount} />
      <ScrollView contentContainerStyle={s.body}>
        <Text face="Poppins-SemiBold" size={17} color="navy-900" role="heading">{'Before you delete your account'}</Text>
        <Text face="Inter-Regular" size={12.5} lh={1.5} color="slate-600" style={{ marginTop: 6 }}>
          {'Please read what happens. You lose access at once, and your passes stop working. Some records stay with your home because they belong to it: each one is listed below.'}
        </Text>

        {/* M18H §2.1: "If they owe money, it says so and says the debt does not go away." */}
        {m.debt ? <Note tone="warn" icon="privacy-note" text={m.debt} /> : null}

        {/* A demonstration session has no account of its own, and its token cannot write. Said HERE,
          * before the word is typed — not afterwards as a token error a reviewer reads as a fault. */}
        {m.demo ? <Note tone="warn" icon="privacy-note" text={DEMONSTRATION} /> : null}

        <Heading top={16}>{'What happens'}</Heading>
        <List what={DELETED_NOW} tone="gone" />
        <List what={KEPT} tone="kept" />
        <Note icon="privacy-note" text={m.restore} />

        {m.unsent > 0 ? (
          <Note
            tone="warn"
            icon="privacy-note"
            text={`${m.unsent} ${m.unsent === 1 ? 'thing you saved on this phone has' : 'things you saved on this phone have'} not reached the estate office yet. Deleting your account deletes ${m.unsent === 1 ? 'it' : 'them'} too: ${m.unsent === 1 ? 'it' : 'they'} will never arrive. Go back and connect first if you want ${m.unsent === 1 ? 'it' : 'them'} sent.`}
          />
        ) : null}

        <Heading top={14}>{'Confirm'}</Heading>
        <ResidentField
          label={`Type ${CONFIRMATION} to confirm`}
          icon="privacy-note"
          value={m.typed}
          onChangeText={m.setTyped}
          placeholder={CONFIRMATION}
          autoCapitalize="characters"
          autoCorrect={false}
          returnKeyType="done"
          marginTop={6}
        />
        {m.problem ? <Note tone="warn" icon="privacy-note" text={m.problem} /> : null}

        <ResidentButton
          variant="destructive"
          label="Delete my account"
          onPress={m.confirm}
          loading={m.working}
          disabled={!m.ready || !m.online}
          disabledReason={!m.online ? 'Deleting your account needs a connection.' : `Type ${CONFIRMATION} above first.`}
          style={{ marginTop: 18 }}
        />
        <Caption top={12}>{`${ALSO_ON_THE_WEB} ${legalAddress('deleteAccount')}`}</Caption>
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('res-page-bg'), overflow: 'hidden', flex: 1 },
  body: { paddingHorizontal: 20, paddingTop: 16, paddingBottom: 40 },
  fill: { flex: 1, flexShrink: 1 },
  card: { backgroundColor: c('white'), borderRadius: 16, borderWidth: 1, borderColor: c('navy-200'), padding: 16, marginTop: 10 },
  cardGone: { borderColor: c('amber-100') },
  row: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, marginTop: 9 },
  dot: { width: 5, height: 5, borderRadius: 3, marginTop: 7, flexGrow: 0, flexShrink: 0 },
})
