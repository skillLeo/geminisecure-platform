/**
 * A tab this person's level does not include — Dues, Notices, or the household's visitor passes — drawn as one
 * honest screen: what the tab is for, their access, and who to ask to change it (`access.ts`). The tab's own
 * screen is not mounted, so none of its reads is made: the server would refuse every one of them.
 *
 * The tabs themselves stay where they are, for every level: a bar that rearranges itself by level would be a
 * different app for each member of one household. Panic and the person's own e-pass are never gated.
 */

import type { ComponentType } from 'react'
import { ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { EmptyState, ResidentAppBar, c } from '@gs/core'
import { notForLevelSentence, type NOT_FOR_LEVEL } from './access'
import { useAccess } from './useAccess'

type Gated = keyof typeof NOT_FOR_LEVEL

/** `Screen` when this level includes `what`, else the one screen that says why not. */
export function withLevel(what: Gated, title: string, Screen: ComponentType, options: { epass?: boolean } = {}): ComponentType {
  function LevelGated() {
    const { access, may } = useAccess()
    if (may(what)) return <Screen />
    return (
      <View style={s.screen}>
        <ResidentAppBar title={title} left={null} />
        <ScrollView style={s.fill} contentContainerStyle={s.body}>
          <EmptyState
            tone="light"
            icon="privacy-note"
            title="Not part of your access"
            body={notForLevelSentence(access, what)}
            action={options.epass ? { label: 'Show my e-Pass', onPress: () => router.push('/visitors/pass/e-pass' as never) } : undefined}
          />
        </ScrollView>
      </View>
    )
  }
  LevelGated.displayName = `LevelGated(${what})`
  return LevelGated
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('white'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  body: { paddingTop: 18, paddingBottom: 20, paddingHorizontal: 20 },
})
