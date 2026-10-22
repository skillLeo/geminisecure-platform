/**
 * The frame every sign-in screen shares — boards resident-app-02 · 03 · 04 (01 Onboarding and
 * Auth): the navy header with its rounded foot, the round back button, the wordmark, and the
 * white brand badge that overlaps the header's edge. Values are the boards' own.
 *
 * The boards measure from the physical screen edge: the header is 150 tall INCLUDING the 54px
 * status bar, the back button sits 60 down (54 + 6), the wordmark 64 down (54 + 10). Here the
 * real top inset takes the 54's place.
 */

import type { ReactElement, ReactNode } from 'react'
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, View, type RefreshControlProps } from 'react-native'
import { router } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { BrandMark, Gradient, Icon, Pressable, Text, a, belowStatusBar, c, sh, type GradientSpec } from '@gs/core'

const HEADER: GradientSpec = { angle: 135, stops: [{ token: 'navy-600', at: 0 }, { token: 'navy-700', at: 1 }] }

export function AuthShell({ children, onBack, canGoBack = true, refreshControl }: { children: ReactNode; onBack?: () => void; canGoBack?: boolean; /** Pull-to-refresh, for the one auth screen that shows server data (the holding screen). */ refreshControl?: ReactElement<RefreshControlProps> }) {
  const insets = useSafeAreaInsets()
  const back = onBack ?? (() => (router.canGoBack() ? router.back() : router.replace('/(auth)/log-in' as never)))

  return (
    <View style={s.screen}>
      <StatusBar style="light" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
        <ScrollView keyboardShouldPersistTaps="handled" bounces={!!refreshControl} refreshControl={refreshControl} contentContainerStyle={s.grow}>
          <View>
            <Gradient spec={HEADER} style={[s.authHeader, { height: insets.top + 96 }]}>
              {canGoBack ? (
                <Pressable onPress={back} role="button" aria-label="Back" hitSlop={6} style={[s.backBtn, { top: insets.top + belowStatusBar(6) }]}>
                  <Icon name="chevron-left" size={18} color="white" />
                </Pressable>
              ) : null}
              <View style={[s.brandLockup, { top: insets.top + 10 }]}>
                <Text face="Poppins-SemiBold" size={12.5} color="white">{'GeminiSecure'}</Text>
              </View>
            </Gradient>
            <View style={s.badge}>
              <BrandMark size={36} variant="authBadge" />
            </View>
          </View>
          <View style={[s.authBody, { paddingBottom: Math.max(28, insets.bottom + 12) }]}>{children}</View>
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  )
}

/** The centred title and one-line explanation every auth screen opens with. */
export function AuthTitle({ title, sub }: { title: string; sub: string }) {
  return (
    <>
      <Text face="Poppins-SemiBold" size={23} color="navy-900" align="center" role="heading">{title}</Text>
      <View style={s.sub}>
        <Text face="Inter-Regular" size={13.5} lh={1.55} color="slate-500" align="center">{sub}</Text>
      </View>
    </>
  )
}

/** The board's pale-navy information strip (.invite-note). `tone="warn"` is the same strip in amber. */
export function AuthNote({ text, icon = 'invite-note', tone = 'info', marginTop = 24 }: { text: string; icon?: 'invite-note' | 'privacy-note'; tone?: 'info' | 'warn'; marginTop?: number }) {
  const warn = tone === 'warn'
  return (
    <View style={[s.note, { marginTop, backgroundColor: c(warn ? 'amber-100' : 'navy-100') }]} role={warn ? 'alert' : undefined}>
      <Icon name={icon} size={16} color={warn ? 'amber-700' : 'navy-600'} style={s.noteIcon} />
      <View style={s.shrink}>
        <Text face="Inter-Regular" size={11.5} lh={1.5} color={warn ? 'amber-700' : 'navy-700'}>{text}</Text>
      </View>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('white'), overflow: 'hidden', flex: 1 },
  fill: { flex: 1 },
  grow: { flexGrow: 1 },
  shrink: { flexShrink: 1 },
  authHeader: { borderBottomRightRadius: 34, borderBottomLeftRadius: 34, paddingHorizontal: 24 },
  backBtn: { position: 'absolute', left: 24, width: 34, height: 34, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: a('white', 0.16), borderRadius: 17 },
  brandLockup: { position: 'absolute', right: 24, flexDirection: 'row', alignItems: 'center', gap: 7 },
  badge: { position: 'absolute', bottom: -30, left: '50%', width: 64, height: 64, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('white'), borderRadius: 32, boxShadow: sh([{ x: 0, y: 10, blur: 22, spread: 0, token: 'navy-800', opacity: 0.28 }]), transform: [{ translateX: '-50%' }] },
  sub: { paddingHorizontal: 6, marginTop: 6 },
  note: { flexDirection: 'row', alignItems: 'flex-start', borderRadius: 12, paddingHorizontal: 12, paddingVertical: 10, gap: 9 },
  noteIcon: { width: 16, height: 16, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  authBody: { paddingTop: 52, paddingHorizontal: 24 },
})
