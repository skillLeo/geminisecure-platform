/**
 * resident-app-39 · Help & support — rebuilt from the board (M42 round 2), every value from the design tokens and parts.
 *
 * The board, top to bottom: the search box ("Search help articles") · Popular topics (Visitors & gate access · Dues &
 * payments · Elections & voting · Using the panic button) · Get in touch: Report a problem with "Start a report", then
 * "Call Gemini Security", the company's address, and live chat.
 *
 * WHAT EACH DOES (useHelp). The help is ON THE PHONE (settingsLogic.ts): a topic opens its questions in place, and a
 * question its answer; typing searches every article, the matches open. Start a report writes to the company — the
 * phone's mail app, its subject the app and this phone. The address row writes to it too. Where the phone has no mail
 * app, the address is said in words.
 *
 * "CALL GEMINI SECURITY" ONLY WITH A NUMBER BEHIND IT (the user, 8 Oct 2026). The company has given none yet (Q-M33):
 * until the console saves one (Platform settings → SMS), the row is not drawn — no placeholder number, no button that
 * rings nothing — and once it is, a tap calls it. Whatever cannot be done is said in the app's own sheet over the page
 * (never the phone's grey dialog, M50 §4.3), so the page never grows past the screen.
 *
 * LIVE CHAT IS REAL (M50 §4.3): its row opens the conversation with Gemini Security's control room (/settings/chat), and
 * says how fast they typically reply once that has been measured — until then, who answers. Never a promise.
 *
 * IT DOES NOT MOVE: with the board's content the page fits a 390 × 844 phone, so it is laid out fixed. Only the topics,
 * opened or searched past the room left, scroll — inside their own card.
 */

import { Fragment } from 'react'
import { ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { Icon, Pressable, Text, c, useFidelityReady, type IconName } from '@gs/core'
import { AppBar, Card, IconTile, NoteSheet, SectionLabel, useNote, type Note } from '@/design/parts'
import { corner, fill, font, icon, ink, line, role, size, space, stroke } from '@/design/tokens'
import { Divider } from '../components/Rows'
import type { Article as HelpArticle, Topic } from '../settingsLogic'
import { useHelp, type HelpModel } from '../useSettings'

const SEARCH = 'Search help articles'

/** A contact row's touch area, grown toward 44 points. */
const ROW_REACH = { top: space[5], bottom: space[5] }

/** Whatever could not be done, said in the app's own sheet — never the phone's grey dialog (M50 §4.3). */
const sayIn = (show: (note: Note) => void) => (title: string) => (trouble: string | null) => {
  if (trouble) show({ title, lines: [trouble] })
}

/** `.search-box` — the magnifier, then the box; empty, it shows the board's own words in its grey. */
function SearchBox({ value, onChange }: { value: string; onChange: (text: string) => void }) {
  return (
    <View style={s.search}>
      <Icon name="search-box" size={icon[17]} color={ink.secondary} style={s.searchIcon} />
      <View style={s.searchText}>
        {value === '' ? (
          <View style={s.placeholder}>
            <Text {...font.inter400_13} color={ink.secondary} numberOfLines={1}>{SEARCH}</Text>
          </View>
        ) : null}
        <TextInput value={value} onChangeText={onChange} autoCorrect={false} returnKeyType="search" selectionColor={c(ink.link)} aria-label={SEARCH} style={s.input} />
      </View>
    </View>
  )
}

/** One question under an open topic; tapped, its answer. Searching, every match is open, so it is plain text. */
function Article({ m, topic, article }: { m: HelpModel; topic: Topic; article: HelpArticle }) {
  const key = `${topic.key}:${article.q}`
  const shown = m.searching || m.openArticle === key
  const body = (
    <>
      <Text {...font.inter600_12_lh1_5} color={shown ? ink.action : ink.body}>{article.q}</Text>
      {shown ? <View style={s.answer}><Text {...font.inter400_12_lh1_5} color={ink.label}>{article.a}</Text></View> : null}
    </>
  )
  return m.searching ? (
    <View style={s.article}>{body}</View>
  ) : (
    <Pressable onPress={() => m.toggleArticle(key)} role="button" aria-expanded={shown} aria-label={shown ? `${article.q} ${article.a}` : article.q} style={s.article}>{body}</Pressable>
  )
}

/** `.help-topic` — its tile, its name, the chevron (turned down while it is open), then its questions when open. */
function TopicRow({ m, topic }: { m: HelpModel; topic: Topic }) {
  const open = m.searching || m.openTopic === topic.key
  const head = (
    <>
      <IconTile name={topic.icon} />
      <View style={s.topicName}><Text {...role.rowTitleSmall} color={ink.heading}>{topic.title}</Text></View>
      <Icon name="chevron-right" size={icon[16]} color={ink.faint} style={open ? s.turned : null} />
    </>
  )
  return (
    <>
      {m.searching ? (
        <View style={s.topic}>{head}</View>
      ) : (
        <Pressable onPress={() => m.toggleTopic(topic.key)} role="button" aria-expanded={open} aria-label={topic.title} style={({ pressed }) => [s.topic, pressed ? s.pressed : null]}>{head}</Pressable>
      )}
      {open ? topic.articles.map((article) => <Article key={article.q} m={m} topic={topic} article={article} />) : null}
    </>
  )
}

/** `.contact-row` — the icon, then the words. */
function ContactRow({ glyph, text, label, link, onPress }: { glyph: IconName; text: string; label: string; link?: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} role={link ? 'link' : 'button'} aria-label={label} hitSlop={ROW_REACH} style={({ pressed }) => [s.contact, pressed ? s.pressed : null]}>
      <Icon name={glyph} size={icon[17]} color={ink.action} />
      <View style={s.contactText}><Text {...font.inter500_12_5} color={ink.heading}>{text}</Text></View>
    </Pressable>
  )
}

export default function HelpScreen() {
  const m = useHelp()
  const note = useNote()
  const say = sayIn(note.show)
  useFidelityReady(true)

  // Gemini Security's line as the console set it; a phone that cannot place the call is told the number in words.
  const callUs = () => void m.callSupport().then(say(m.contacts.call ?? 'Call Gemini Security'))

  return (
    <View style={s.screen}>
      <AppBar title="Help & support" fallback="/settings" />
      <View style={s.body}>
        <SearchBox value={m.query} onChange={m.setQuery} />

        <SectionLabel>Popular topics</SectionLabel>
        {m.topics.length > 0 ? (
          <Card pad={13} style={s.topics}>
            <ScrollView style={s.topicList} keyboardShouldPersistTaps="handled" bounces={false} overScrollMode="never" showsVerticalScrollIndicator={false}>
              {m.topics.map((topic, i) => (
                <Fragment key={topic.key}>
                  {i > 0 ? <Divider /> : null}
                  <TopicRow m={m} topic={topic} />
                </Fragment>
              ))}
            </ScrollView>
          </Card>
        ) : null}

        <SectionLabel>Get in touch</SectionLabel>
        <Card pad={13} style={s.card}>
          <View style={s.report}>
            <IconTile name="qa-tile-3" tile={40} glyph={19} tint={fill.goldTint} color={ink.onGoldTint} shape="tile" />
            <View style={s.reportText}>
              <Text {...font.inter700_13} color={ink.heading}>{'Report a problem'}</Text>
              <View style={s.reportLine}>
                <Text {...font.inter400_11_lh1_4} color={ink.secondary}>{"Something not working? Send us the details, with logs attached if you'd like."}</Text>
              </View>
            </View>
          </View>
          <Pressable onPress={() => void m.startReport().then(say('Report a problem'))} role="button" aria-label="Start a report" style={({ pressed }) => [s.outline, pressed ? s.pressed : null]}>
            <Text {...font.inter600_13} color={ink.onTint}>{'Start a report'}</Text>
          </Pressable>
        </Card>

        <Card pad={13} style={s.card}>
          {m.contacts.call ? (
            <>
              <ContactRow glyph="call-btn" text={m.contacts.call} label={m.contacts.call} onPress={callUs} />
              <Divider />
            </>
          ) : null}
          <ContactRow glyph="input-5" text={m.contacts.email} label={`Email ${m.contacts.email}`} link onPress={() => void m.emailSupport().then(say(m.contacts.email))} />
          <Divider />
          <ContactRow glyph="tab-messages" text={m.contacts.chat} label={`${m.contacts.chat}: opens the conversation`} onPress={m.openChat} />
        </Card>
      </View>
      <NoteSheet note={note.note} onClose={note.close} />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  // .body-pad — this board's is 16 above
  body: { flex: 1, paddingTop: space[16], paddingHorizontal: space[20], paddingBottom: space[20] },
  pressed: { opacity: 0.7 },
  // .card on this board: 11 under it; the topics' own padding is 3px 13px, and they give way when they outgrow the room
  card: { marginBottom: space[11] },
  topics: { paddingVertical: space[3], marginBottom: space[11], flexShrink: 1 },
  topicList: { flexGrow: 0 },

  // .search-box — the box fills its 46 points, so a tap anywhere in it types
  search: { height: size[46], flexDirection: 'row', alignItems: 'center', backgroundColor: fill.tint, borderRadius: corner[14], paddingHorizontal: space[14], marginBottom: space[16] },
  searchIcon: { marginRight: space[9] },
  searchText: { flex: 1, minWidth: 0, alignSelf: 'stretch', justifyContent: 'center' },
  // `pointerEvents` as a style, not a prop: React Native Web warns on the prop (design/parts Form.tsx).
  placeholder: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, justifyContent: 'center', pointerEvents: 'none' },
  input: { flex: 1, fontFamily: font.inter400_13.face, fontSize: font.inter400_13.size, color: c(ink.heading), padding: 0, margin: 0 },

  // .help-topic
  topic: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[11] },
  topicName: { flex: 1, minWidth: 0 },
  turned: { transform: [{ rotate: '90deg' }] },
  // A question under its topic (no board draws one open): in line with the topic's name, 44 to touch.
  article: { minHeight: size[44], justifyContent: 'center', paddingVertical: space[9], paddingLeft: space[46], paddingRight: space[4] },
  answer: { marginTop: space[5] },

  // .report-card · .report-txt · .btn-outline (this board's: 46 tall, radius 14, 13-point words)
  report: { flexDirection: 'row', alignItems: 'flex-start', gap: space[12] },
  reportText: { flexShrink: 1 },
  reportLine: { marginTop: space[2] },
  outline: { height: size[46], flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: space[9], backgroundColor: fill.surface, borderRadius: corner[14], borderWidth: stroke['1.5'], borderColor: line.outline, marginTop: space[12] },

  // .contact-row
  contact: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[9] },
  contactText: { flexShrink: 1 },
})
