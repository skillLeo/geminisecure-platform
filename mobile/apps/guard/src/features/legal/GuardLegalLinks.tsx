/**
 * The Guard App's links to its own Privacy Policy and Terms of Use. They open screens inside the app, never a web
 * page: the guard documents are at no public address (M24 §5.2, D-223), so no resident can come across them.
 */

import { View, type StyleProp, type ViewStyle } from 'react-native'
import { router } from 'expo-router'
import { Pressable, Text } from '@gs/core'
import { GUARD_DOCUMENTS, GUARD_DOCUMENT_ROUTES, type GuardDocumentKey } from './guardLegal'

const SLOP = { top: 16, bottom: 16, left: 8, right: 8 } as const

export function GuardLegalLinks({ pages = ['privacy', 'terms'], align = 'center', style }: { pages?: readonly GuardDocumentKey[]; align?: 'center' | 'left'; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={[{ flexDirection: 'row', flexWrap: 'wrap', justifyContent: align === 'center' ? 'center' : 'flex-start', columnGap: 18, rowGap: 6 }, style]}>
      {pages.map((page) => (
        <Pressable key={page} onPress={() => router.push(GUARD_DOCUMENT_ROUTES[page] as never)} role="link" aria-label={GUARD_DOCUMENTS[page].title} hitSlop={SLOP}>
          <Text face="Inter-SemiBold" size={12} color="amber-500">{GUARD_DOCUMENTS[page].title}</Text>
        </Pressable>
      ))}
    </View>
  )
}
