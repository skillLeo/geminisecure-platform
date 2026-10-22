/**
 * guard-app-05 · Search Unit  and  guard-app-40 · Search — No Results. One screen; styles
 * are the boards' own, copied from the lifted files.
 *
 * A guard never sees money: a household's standing is one boolean, drawn as one sentence.
 */

import { FlatList, ScrollView, StyleSheet, TextInput, View } from 'react-native'
import { router } from 'expo-router'
import { AppBar, EmptyState, ErrorView, Icon, Pressable, SkeletonLines, Text, a, c, useFidelityReady } from '@gs/core'
import { RESTRICTED_SENTENCE } from '../verdict'
import { useUnitSearch, type UnitResult } from '../useUnitSearch'

const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('')

function UnitCard({ unit }: { unit: UnitResult }) {
  return (
    <View style={s.card}>
      <View style={s.unitHead}>
        <View style={s.unitBadge}>
          <Text face="Inter-Bold" size={11} color="amber-500" align="center">{unit.badge.join('\n')}</Text>
        </View>
        <View style={s.fill}>
          <Text face="Inter-Bold" size={13.5} color="white">{unit.unit}</Text>
          <Text face="Inter-Regular" size={10.5} color="text-3" style={{ marginTop: 1 }}>{unit.household}</Text>
        </View>
      </View>

      {unit.people.map((person, i) => (
        <View key={`${person.name}:${i}`} style={i === 0 ? s.occRowFirst : s.occRow}>
          <View style={s.oAvatar}>
            <Text face="Inter-Bold" size={9} color="white">{initials(person.name)}</Text>
          </View>
          <View style={s.shrink}>
            <Text face="Inter-Medium" size={11.5} color="text-2">{`${person.name} `}<Text size={10} color="text-3">{`· ${person.note}`}</Text></Text>
          </View>
        </View>
      ))}

      {unit.vehicle ? (
        <View style={s.occRow}>
          <Icon name="veh-row" size={15} color="text-3" style={s.vehIcon} />
          <View style={s.shrink}><Text face="Inter-Medium" size={11} color="text-2">{unit.vehicle}</Text></View>
        </View>
      ) : null}

      {/* A standing note the officer has to read — today, somebody the estate office has revoked (Q-M44). */}
      {unit.instruction ? (
        <View style={s.instrNote} role="alert">
          <Icon name="qa-tile-3" size={14} color="amber-500" style={s.instrIcon} />
          <View style={s.shrink}><Text face="Inter-SemiBold" size={10.5} lh={1.4} color="amber-500">{unit.instruction}</Text></View>
        </View>
      ) : null}

      {unit.restricted ? (
        <View style={s.instrNote} role="alert">
          <Icon name="qa-tile-3" size={14} color="amber-500" style={s.instrIcon} />
          <View style={s.shrink}><Text face="Inter-SemiBold" size={10.5} lh={1.4} color="amber-500">{RESTRICTED_SENTENCE}</Text></View>
        </View>
      ) : null}

      <Pressable
        onPress={() => router.push({ pathname: '/walk-up', params: { unit: unit.unit } } as never)}
        role="button"
        aria-label={`Request entry approval from ${unit.unit}`}
        hitSlop={3}
        style={({ pressed }) => [s.callBtn, { opacity: pressed ? 0.8 : 1 }]}
      >
        <Icon name="call-btn" size={15} color="white" />
        <View style={s.shrink}><Text face="Inter-Bold" size={12} color="white">{'Request entry approval'}</Text></View>
      </Pressable>
    </View>
  )
}

export default function SearchScreen() {
  const m = useUnitSearch()
  useFidelityReady(true)
  const byUnit = /\d/.test(m.query)

  const header = (
    <>
      <View style={s.searchBox}>
        <Icon name="search-box" size={18} color="text-3" style={s.searchIcon} />
        <TextInput
          value={m.query}
          onChangeText={m.setQuery}
          placeholder="Lot or name: e.g. 47, Fletcher"
          placeholderTextColor={c('text-3')}
          selectionColor={c('amber-500')}
          autoCorrect={false}
          autoCapitalize="words"
          returnKeyType="search"
          clearButtonMode="while-editing"
          aria-label="Search by lot number or name"
          maxFontSizeMultiplier={1.3}
          style={s.searchText}
        />
      </View>

      {m.phases.length === 0 ? null : (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} style={s.chipScroll} contentContainerStyle={s.chipRow} role="radiogroup" aria-label="Phase filter">
          {[null, ...m.phases].map((phase) => {
            const on = m.phase === phase
            return (
              <Pressable key={String(phase)} onPress={() => m.setPhase(phase)} role="radio" aria-checked={on} hitSlop={8} style={on ? s.fChipOn : s.fChip}>
                <Text face="Inter-Bold" size={11.5} color={on ? 'g-navy-900' : 'text-2'} numberOfLines={1}>{phase ?? 'All phases'}</Text>
              </Pressable>
            )
          })}
        </ScrollView>
      )}

      {m.status === 'results' ? (
        <Text face="Inter-Bold" size={11} ls={0.4} color="text-3" upper style={{ marginTop: 16, marginHorizontal: 2 }} role="status">
          {/* An estate of 2,000 lots matches more than a screen can hold: say which it is showing. */}
          {m.total > m.results.length ? `${m.results.length} of ${m.total} results · keep typing to narrow` : `${m.total} result${m.total === 1 ? '' : 's'}`}
        </Text>
      ) : null}
    </>
  )

  const footer =
    m.status === 'idle' ? (
      <EmptyState tone="dark" icon="search-box" title="Find a unit or a household" body="Type a lot number, or part of a resident's name. You will see who lives there and who they are expecting today." />
    ) : m.status === 'too_short' ? (
      <EmptyState tone="dark" icon="search-box" title="Keep typing" body="A name needs at least two letters. A lot number can be searched straight away." />
    ) : m.status === 'offline' ? (
      <EmptyState tone="dark" icon="sync-badge" title="Search needs a connection" body="The register is only read live, so it is never out of date. With no signal: scan the visitor's QR pass, that works offline, or use walk-up entry and follow your standing orders." action={{ label: 'Scan a pass instead', onPress: () => router.replace('/scan' as never) }} />
    ) : m.status === 'loading' ? (
      <SkeletonLines tone="dark" lines={5} style={{ marginTop: 22 }} />
    ) : m.status === 'denied' ? (
      <EmptyState tone="dark" icon="rh-icon" title="Search is not available on this handset" body="This phone is not allowed to read the register. Contact your supervisor. Scanning a pass still works." />
    ) : m.status === 'error' ? (
      <ErrorView tone="dark" message={m.errorMessage ?? 'The search could not be completed.'} onRetry={m.retry} />
    ) : m.status === 'empty' ? (
      <View style={s.emptyWrap}>
        <View style={s.emptyIcon}><Icon name="empty-icon" size={32} color="text-3" /></View>
        <Text face="Poppins-SemiBold" size={16} color="white" align="center">{byUnit ? `No unit found for "${m.query.trim()}"` : `No one found for "${m.query.trim()}"`}</Text>
        <Text face="Inter-Regular" size={12.5} lh={1.55} color="text-2" align="center" style={{ marginTop: 8 }}>
          {byUnit
            ? `${m.siteName} doesn't have a ${m.query.trim()}. Double-check the phase filter, or ask the visitor to confirm their exact unit number.`
            : `No household or resident on ${m.siteName}'s register matches that name. Check the spelling and the phase filter, or search by lot number.`}
        </Text>
        <View style={s.emptyActions}>
          <Pressable onPress={m.clear} role="button" aria-label="Clear search" style={({ pressed }) => [s.btnOutline, { opacity: pressed ? 0.7 : 1 }]}>
            <Text face="Inter-SemiBold" size={13} color="white">{'Clear search'}</Text>
          </Pressable>
          <Pressable onPress={() => router.push('/help' as never)} role="button" aria-label="Report a problem" style={({ pressed }) => [s.btnOutline, { opacity: pressed ? 0.7 : 1 }]}>
            <Text face="Inter-SemiBold" size={13} color="white">{'Report a problem'}</Text>
          </Pressable>
        </View>
      </View>
    ) : null

  return (
    <View style={s.screen}>
      <AppBar title="Search unit" />
      <FlatList
        data={m.status === 'results' ? m.results : []}
        keyExtractor={(unit) => unit.key}
        renderItem={({ item }) => <UnitCard unit={item} />}
        ListHeaderComponent={header}
        ListFooterComponent={footer}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="on-drag"
        contentContainerStyle={s.bodyPad}
      />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('surface-900'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  shrink: { flexShrink: 1 },
  bodyPad: { paddingTop: 16, paddingBottom: 20, paddingHorizontal: 20 },
  searchIcon: { width: 18, height: 18, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginRight: 10 },
  searchBox: { height: 48, flexDirection: 'row', alignItems: 'center', backgroundColor: c('surface-800'), borderRadius: 14, borderWidth: 1, borderColor: c('amber-500'), paddingHorizontal: 14 },
  searchText: { flex: 1, height: 46, fontFamily: 'Inter-Medium', fontSize: 14, color: c('white'), padding: 0 },
  chipScroll: { marginTop: 14, flexGrow: 0 },
  chipRow: { flexDirection: 'row', gap: 8 },
  fChipOn: { backgroundColor: c('amber-500'), borderRadius: 20, borderWidth: 1, borderColor: c('amber-500'), paddingHorizontal: 14, paddingVertical: 8 },
  fChip: { backgroundColor: c('surface-800'), borderRadius: 20, borderWidth: 1, borderColor: c('border-700'), paddingHorizontal: 14, paddingVertical: 8 },
  unitBadge: { width: 42, height: 42, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 12 },
  unitHead: { flexDirection: 'row', alignItems: 'center', gap: 11 },
  oAvatar: { width: 26, height: 26, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-700'), borderRadius: 13 },
  occRowFirst: { flexDirection: 'row', alignItems: 'center', paddingVertical: 5, gap: 9, marginTop: 10 },
  occRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 5, gap: 9 },
  vehIcon: { width: 15, height: 15, flexGrow: 0, flexShrink: 0, overflow: 'hidden' },
  instrIcon: { width: 14, height: 14, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  instrNote: { flexDirection: 'row', backgroundColor: a('amber-500', 0.1), borderRadius: 10, borderWidth: 1, borderColor: a('amber-500', 0.25), paddingHorizontal: 10, paddingVertical: 9, gap: 8, marginTop: 8 },
  callBtn: { height: 42, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('navy-600'), borderRadius: 12, gap: 8, marginTop: 10 },
  card: { backgroundColor: c('surface-800'), borderRadius: 16, borderWidth: 1, borderColor: c('border-700'), padding: 14, marginTop: 8, marginBottom: 11 },
  emptyIcon: { width: 76, height: 76, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('surface-800'), borderRadius: 38, borderWidth: 1, borderColor: c('border-700'), marginBottom: 18 },
  btnOutline: { height: 46, flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 14, borderWidth: 1, borderColor: c('border-600'), gap: 9 },
  emptyActions: { width: '100%', flexDirection: 'row', gap: 10, marginTop: 22 },
  emptyWrap: { flexDirection: 'column', alignItems: 'center', paddingTop: 50, paddingHorizontal: 30, marginTop: 16 },
})
