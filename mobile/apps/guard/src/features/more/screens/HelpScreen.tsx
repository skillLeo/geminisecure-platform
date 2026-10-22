/**
 * guard-app-27 · Help & Support. Styles are the board's own, copied from the lifted file (src/lifted/GuardApp27.tsx).
 *
 * The articles are on the phone, so help is there with no signal. The board's "Call Owen Grant" needs a
 * telephone number the API does not serve: the live screen writes to the control room instead, and the
 * emergency numbers are always there and never disabled.
 *
 * The company's own email and postal address (Q-M33, ruled 2026-09-21) are compiled in (SUPPORT_CONTACT),
 * so they read with no signal too. They are the LAST row on purpose: for anything about a shift, a post or
 * an incident the control room answers in seconds and a mailbox does not, and an officer in trouble must
 * not be pointed at an inbox. There is no support telephone number and none is invented.
 */

import { useState } from 'react'
import { Linking, Platform, ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { router } from 'expo-router'
import * as Application from 'expo-application'
import * as Device from 'expo-device'
import { AppBar, Icon, Pressable, SUPPORT_CONTACT, Text, c, fidelityScreenId, isFidelity, openSupportEmail, supportAddress, useFidelityReady, type IconName } from '@gs/core'
import { helpFixture } from '@/fidelity/more'
import { GuardLegalLinks } from '@/features/legal/GuardLegalLinks'
import { Divider, Heading } from '../components/Rows'
import { EMERGENCY, problemDraft, searchHelp } from '../help'

const call = (number: string) => void Linking.openURL(`tel:${number}`).catch(() => undefined)

function ContactRow({ icon, title, onPress, tone = 'white' }: { icon: IconName; title: string; onPress?: () => void; tone?: 'white' | 'red-500' }) {
  const body = (
    <>
      <Icon name={icon} size={17} color={tone === 'red-500' ? 'red-500' : 'amber-500'} style={s.contactRowSvg} />
      <View style={s.shrink}><Text face="Inter-Medium" size={12.5} color={tone}>{title}</Text></View>
    </>
  )
  return onPress ? (
    <Pressable onPress={onPress} role="button" aria-label={title} hitSlop={{ top: 8, bottom: 8 }} style={({ pressed }) => [s.contactRow, { opacity: pressed ? 0.75 : 1 }]}>{body}</Pressable>
  ) : (
    <View style={s.contactRow}>{body}</View>
  )
}

export default function HelpScreen() {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-27'
  const [query, setQuery] = useState('')
  const [openTopic, setOpenTopic] = useState<string | null>(null)
  const [openArticle, setOpenArticle] = useState<string | null>(null)
  // No dead control: a handset with no mail app is given the address in words instead of a button that does nothing.
  const [mailTrouble, setMailTrouble] = useState<string | null>(null)
  useFidelityReady(true)

  const topics = searchHelp(query)
  const searching = query.trim().length > 0
  const report = () => router.push({ pathname: '/messages/[thread]', params: { thread: 'dispatch', draft: problemDraft({ version: Application.nativeApplicationVersion ?? '0.1.0', device: Device.modelName ?? Platform.OS, os: `${Platform.OS} ${String(Platform.Version)}` }) } } as never)

  return (
    <View style={s.screen}>
      <AppBar title="Help & support" />
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={s.bodyPad}>
        <View style={s.searchBox}>
          <Icon name="search-box" size={17} color="text-3" style={s.searchBoxSvg} />
          {fixture ? (
            <View style={s.shrink}><Text face="Inter-Regular" size={13} color="text-3">{'Search help articles'}</Text></View>
          ) : (
            <TextInput value={query} onChangeText={setQuery} placeholder="Search help: e.g. clock in, patrol" placeholderTextColor={c('text-3')} autoCorrect={false} returnKeyType="search" aria-label="Search help articles" style={s.searchInput} />
          )}
        </View>

        <Heading top={16}>{searching ? 'Results' : 'Popular topics'}</Heading>
        {fixture ? (
          <View style={s.card}>
            {helpFixture.topics.map((t, i) => (
              <View key={t.title}>
                {i > 0 ? <Divider /> : null}
                <View style={s.helpTopic}>
                  <View style={s.helpIcon}><Icon name={t.icon} size={16} color="amber-500" style={s.helpIconSvg} /></View>
                  <View style={s.fill}><Text face="Inter-SemiBold" size={12.5} color="white">{t.title}</Text></View>
                  <Icon name="chevron-right" size={16} color="text-3" style={s.chevSvg} />
                </View>
              </View>
            ))}
          </View>
        ) : topics.length === 0 ? (
          <Text face="Inter-Regular" size={12} lh={1.5} color="text-3" role="status" style={{ marginTop: 10, marginHorizontal: 2 }}>{`Nothing matches "${query.trim()}". Try one word, "clock", "offline", "leave", or write to the control room below.`}</Text>
        ) : (
          <View style={s.card}>
            {topics.map((t, i) => {
              const open = searching || openTopic === t.key
              return (
                <View key={t.key}>
                  {i > 0 ? <Divider /> : null}
                  <Pressable onPress={() => setOpenTopic(open && !searching ? null : t.key)} role="button" aria-expanded={open} aria-label={t.title} style={({ pressed }) => [s.helpTopic, { opacity: pressed ? 0.75 : 1 }]}>
                    <View style={s.helpIcon}><Icon name={t.icon} size={16} color="amber-500" style={s.helpIconSvg} /></View>
                    <View style={s.fill}><Text face="Inter-SemiBold" size={12.5} color="white">{t.title}</Text></View>
                    <Icon name="chevron-right" size={16} color="text-3" style={[s.chevSvg, open ? { transform: [{ rotate: '90deg' }] } : null]} />
                  </Pressable>
                  {open ? t.articles.map((art) => {
                    const key = `${t.key}:${art.q}`
                    const shown = searching || openArticle === key
                    return (
                      <Pressable key={key} onPress={() => setOpenArticle(shown && !searching ? null : key)} role="button" aria-expanded={shown} style={s.article}>
                        <Text face="Inter-SemiBold" size={12} lh={1.45} color={shown ? 'amber-500' : 'text-2'}>{art.q}</Text>
                        {shown ? <Text face="Inter-Regular" size={12} lh={1.55} color="text-2" style={{ marginTop: 5 }}>{art.a}</Text> : null}
                      </Pressable>
                    )
                  }) : null}
                </View>
              )
            })}
          </View>
        )}

        <Heading>{'Get in touch'}</Heading>
        <View style={s.card2}>
          {fixture ? (
            helpFixture.contacts.map((row, i) => (
              <View key={row.title}>
                {i > 0 ? <Divider /> : null}
                <ContactRow icon={row.icon} title={row.title} />
              </View>
            ))
          ) : (
            <>
              <ContactRow icon="tab-messages" title="Write to the control room: 24 hours" onPress={() => router.push('/messages/dispatch' as never)} />
              <Divider />
              <ContactRow icon="qa-tile-3" title="Report a technical problem" onPress={report} />
              {EMERGENCY.map((e) => (
                <View key={e.number}>
                  <Divider />
                  <ContactRow icon="call-btn" tone="red-500" title={`Emergency: ${e.label}`} onPress={() => call(e.number)} />
                </View>
              ))}
              <Divider />
              <ContactRow
                icon="dispute-link"
                title={`Email ${SUPPORT_CONTACT.company}: ${SUPPORT_CONTACT.email}`}
                onPress={() => {
                  setMailTrouble(null)
                  void openSupportEmail('GeminiSecure Guard').then((opened) => (opened ? undefined : setMailTrouble(`This phone has no mail app set up. Write to ${SUPPORT_CONTACT.email} from any device.`)))
                }}
              />
            </>
          )}
        </View>
        {fixture || !mailTrouble ? null : <Text face="Inter-Regular" size={10.5} lh={1.5} color="amber-500" role="alert" style={{ marginHorizontal: 2, marginBottom: 10 }}>{mailTrouble}</Text>}
        {fixture ? null : <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginHorizontal: 2 }}>{`${SUPPORT_CONTACT.company}, ${supportAddress()}.`}</Text>}
        {fixture ? null : <Text face="Inter-Regular" size={10.5} lh={1.5} color="text-3" style={{ marginHorizontal: 2, marginTop: 8 }}>{'In danger, do not write: tap the shield on Home and hold the alert button, or call 119. The app never blocks a call.'}</Text>}
        {/* The app's own policy and terms, on the phone. The website carries nothing of this app (M24 §5.2). */}
        {fixture ? null : <GuardLegalLinks style={{ marginTop: 18 }} />}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  searchBoxSvg: { width: 17, height: 17, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginRight: 9 },
  searchBox: { height: 46, flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14 },
  searchInput: { flex: 1, fontFamily: 'Inter-Regular', fontSize: 13, color: c('white'), padding: 0 },
  helpIconSvg: { width: 16, height: 16, overflow: 'hidden' },
  helpIcon: { width: 34, height: 34, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 10 },
  chevSvg: { width: 16, height: 16, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  helpTopic: { flexDirection: 'row', alignItems: 'center', paddingVertical: 11, gap: 12 },
  article: { paddingVertical: 9, paddingLeft: 46, paddingRight: 4 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 13, paddingVertical: 2, marginTop: 8 },
  contactRowSvg: { width: 17, height: 17, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  contactRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 9, gap: 12 },
  card2: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14, marginTop: 8, marginBottom: 12 },
})
