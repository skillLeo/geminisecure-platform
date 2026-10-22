/**
 * The Guard App's Privacy Policy and Terms of Use, read on the phone (M24 §5.2, D-223). The words are compiled in
 * (guardLegal.ts), so they open with no signal and before the phone is enrolled; they are at no public address.
 */

import { ScrollView, StyleSheet, View } from 'react-native'
import { AppBar, Text, a, c, useFidelityReady } from '@gs/core'
import { DRAFT, DRAFT_NOTE, GUARD_DOCUMENTS, type GuardDocumentKey } from '../guardLegal'

export default function GuardDocumentScreen({ which }: { which: GuardDocumentKey }) {
  const doc = GUARD_DOCUMENTS[which]
  useFidelityReady(true)

  return (
    <View style={s.screen}>
      <AppBar title={doc.title} left="back" />
      <ScrollView contentContainerStyle={s.body}>
        <Text face="Poppins-SemiBold" size={17} color="white" role="heading">{doc.title}</Text>
        <Text face="Inter-Regular" size={11} color="text-3" style={{ marginTop: 4 }}>{`GeminiSecure Guard · last updated ${doc.updated}`}</Text>

        {DRAFT ? (
          <View style={s.draft} role="note">
            <Text face="Inter-SemiBold" size={11.5} lh={1.5} color="amber-500">{DRAFT_NOTE}</Text>
          </View>
        ) : null}

        {doc.intro.map((line) => (
          <Text key={line} face="Inter-Regular" size={13} lh={1.55} color="text-2" style={{ marginTop: 12 }}>{line}</Text>
        ))}

        {doc.sections.map((section) => (
          <View key={section.heading} style={s.card}>
            <Text face="Inter-Bold" size={12.5} color="white" role="heading">{section.heading}</Text>
            {section.paragraphs.map((line) => (
              <Text key={line} face="Inter-Regular" size={12.5} lh={1.55} color="text-2" style={{ marginTop: 6 }}>{line}</Text>
            ))}
          </View>
        ))}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  body: { paddingHorizontal: 18, paddingTop: 14, paddingBottom: 40 },
  draft: { borderRadius: 14, borderWidth: 1, padding: 12, marginTop: 12, backgroundColor: a('amber-500', 0.1), borderColor: a('amber-500', 0.32) },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 16, marginTop: 10 },
})
