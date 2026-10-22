/**
 * Links to the Resident App's public pages, the ones its store listing requires: Privacy Policy and
 * Terms of Use, reachable WITHOUT signing in. They open in the phone's own browser. The Guard App
 * does not use this: its documents are on the phone (GuardLegalLinks, M24 §5.2).
 *
 * No dead controls: if the phone will not open a page, the link's place is taken by the page's
 * address in words, so the person can still get there.
 */

import { useState } from 'react'
import { View, type StyleProp, type ViewStyle } from 'react-native'
import { legalAddress, openLegal, type LegalPage } from '../lib/legal'
import { Pressable } from './Pressable'
import { Text } from './Text'

const LABEL: Record<LegalPage, string> = {
  privacy: 'Privacy Policy',
  terms: 'Terms of Use',
  deleteAccount: 'Delete your account',
}

const SLOP = { top: 16, bottom: 16, left: 8, right: 8 } as const

export function LegalLinks({ pages, tone, align = 'center', style }: { pages: readonly LegalPage[]; tone: 'light' | 'dark'; align?: 'center' | 'left'; style?: StyleProp<ViewStyle> }) {
  const [failed, setFailed] = useState<LegalPage | null>(null)
  const link = tone === 'dark' ? 'amber-500' : 'amber-600'
  const quiet = tone === 'dark' ? 'text-3' : 'slate-500'

  return (
    <View style={style}>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', justifyContent: align === 'center' ? 'center' : 'flex-start', columnGap: 18, rowGap: 6 }}>
        {pages.map((page) => (
          <Pressable
            key={page}
            onPress={() => {
              setFailed(null)
              void openLegal(page).then((opened) => (opened ? undefined : setFailed(page)))
            }}
            role="link"
            aria-label={`${LABEL[page]}: opens in your browser`}
            hitSlop={SLOP}
          >
            <Text face="Inter-SemiBold" size={12} color={link}>{LABEL[page]}</Text>
          </Pressable>
        ))}
      </View>
      {failed ? (
        <Text face="Inter-Regular" size={11.5} lh={1.5} color={quiet} align={align} style={{ marginTop: 8 }} role="alert">
          {`This phone could not open it. In any browser, go to ${legalAddress(failed)}`}
        </Text>
      ) : null}
    </View>
  )
}
