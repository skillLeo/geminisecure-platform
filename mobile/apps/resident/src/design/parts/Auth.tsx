/**
 * The sign-in boards' frame and pieces (01 Onboarding and Auth · Sign Up, Log In), for the rebuilt Log in and Create
 * account (M47 §1–§2). Every value from the token module:
 *
 *   AuthFrame     .auth-header (150 with the status bar, 135° navy-600 → navy-700, its two foot corners 34) · .back-btn
 *                 (34, white .16, 6 below the status bar) · .brand-lockup (Poppins 600 12.5 white, 10 below it) · .badge
 *                 (64 white, its .28 shadow, 30 over the header's foot) · .auth-body (52 24 28)
 *   AuthTitle     .auth-body h1 (Poppins 600 23 navy-900, 6 above the sub) · .sub (13.5 / 1.55 slate-500, 24 below)
 *   AuthField     .field label (12 · 600 · slate-600 · .1) + .input (48, navy-100, 14, 1.5 transparent; focused white with
 *                 the gold ring; its icon 18 navy-500; words 14.5). As a DROPDOWN it shows the chosen value with the
 *                 password row's own right-hand glyph place (the board's .eye: 18 navy-500, pushed right).
 *   AuthDivider   .divider (20 above and below, 12 between, a 1 navy-200 hairline either side of 11.5 · 500 "or")
 *   AuthFooter    .footer-link (13 slate-600, the last words Inter 700 amber-600; 6 above it)
 *   TermsRow      .terms-row (an 18 box, radius 5, navy-600 with its 12 tick; 11.5 / 1.55 slate-500; the documents
 *                 amber-600 600; 6 above, 22 below)
 *
 * The placeholder is drawn by us in Inter, never by the phone — so no phone falls back to its own handwriting for it
 * (M47 U8).
 */

import { forwardRef, useState, type ReactNode } from 'react'
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, TextInput, View, type TextInputProps } from 'react-native'
import { StatusBar } from 'expo-status-bar'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { BrandMark, Gradient, Icon, Pressable, Text, belowStatusBar, c, type IconName } from '@gs/core'
import { corner, fill, font, gradient, icon, ink, line, radius, role, shadow, size, space, stroke } from '../tokens'

/** The header below the status bar: the board's 150 less its 54-point status bar, which the phone's own inset replaces. */
const HEADER_BELOW_STATUS = size[150] - size[54]

export function AuthFrame({ children, onBack, overlay }: { children: ReactNode; onBack: () => void; overlay?: ReactNode }) {
  const insets = useSafeAreaInsets()

  return (
    <View style={s.screen}>
      <StatusBar style="light" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.fill}>
        <ScrollView keyboardShouldPersistTaps="handled" bounces={false} contentContainerStyle={s.grow}>
          <View>
            <Gradient spec={gradient.header} style={[s.header, { height: insets.top + HEADER_BELOW_STATUS }]}>
              {/* The board's chevron has no size of its own, so it fills the 34-point button — as the app bar's does. */}
              <Pressable onPress={onBack} role="button" aria-label="Back" hitSlop={space[6]} style={[s.back, { top: insets.top + belowStatusBar(space[6]) }]}>
                <Icon name="chevron-left" size={size[34]} color={ink.onDark} />
              </Pressable>
              <View style={[s.brand, { top: insets.top + space[10] }]}>
                <Text {...font.poppins600_12_5} color={ink.onDark}>{'GeminiSecure'}</Text>
              </View>
            </Gradient>
            <View style={s.badgeRow} aria-hidden>
              <View style={s.badge}>
                <BrandMark size={icon[36]} variant="authBadge" />
              </View>
            </View>
          </View>
          {/* The board's 28 below the last line — and never less than the phone's own gesture bar (M47 U9). */}
          <View style={[s.body, { paddingBottom: Math.max(space[28], insets.bottom + space[12]) }]}>{children}</View>
        </ScrollView>
      </KeyboardAvoidingView>
      {overlay}
    </View>
  )
}

export function AuthTitle({ title, sub }: { title: string; sub: string }) {
  return (
    <>
      <Text {...role.authTitle} color={ink.heading} align="center" role="heading">{title}</Text>
      <View style={s.sub}>
        <Text {...role.authSub} color={ink.secondary} align="center">{sub}</Text>
      </View>
    </>
  )
}

type AuthFieldProps = Omit<TextInputProps, 'style' | 'placeholderTextColor'> & {
  label: string
  icon: IconName
  error?: string | null
  /** A dropdown rather than a text box: the row shows `value` and opens a list. */
  onPress?: () => void
  open?: boolean
  /** No 15 under it: what follows sets its own distance (the board's "Forgot password?" row). */
  last?: boolean
}

export const AuthField = forwardRef<TextInput, AuthFieldProps>(function AuthField({ label, icon: glyph, error, onPress, open, last, value, placeholder, onFocus, onBlur, ...input }, ref) {
  const [focused, setFocused] = useState(false)
  const lit = open || focused
  const box = [s.input, lit ? s.inputFocused : null, error ? s.inputError : null]
  const shown = typeof value === 'string' && value !== ''

  const inner = (
    <>
      <Icon name={glyph} size={icon[18]} color={ink.field} style={s.inputIcon} />
      <View style={s.inputText}>
        {onPress ? (
          <Text {...(shown ? role.inputValue : role.placeholder)} color={shown ? ink.heading : ink.secondary} numberOfLines={1}>{shown ? value : (placeholder ?? '')}</Text>
        ) : (
          <>
            {!shown && placeholder ? (
              <View style={s.placeholder}>
                <Text {...role.placeholder} color={ink.secondary} numberOfLines={1}>{placeholder}</Text>
              </View>
            ) : null}
            <TextInput
              ref={ref}
              value={value}
              aria-label={label}
              onFocus={(e) => { setFocused(true); onFocus?.(e) }}
              onBlur={(e) => { setFocused(false); onBlur?.(e) }}
              selectionColor={c(ink.action)}
              style={s.textInput}
              {...input}
            />
          </>
        )}
      </View>
      {onPress ? <Icon name="chev" size={icon[18]} color={ink.field} style={s.trailing} /> : null}
    </>
  )

  return (
    <View style={last ? null : s.field}>
      <View style={s.label}><Text {...role.labelAuth} color={ink.label}>{label}</Text></View>
      {onPress ? (
        <Pressable onPress={onPress} role="button" aria-label={`${label}: ${shown ? value : (placeholder ?? '')}`} aria-expanded={open} style={box}>{inner}</Pressable>
      ) : (
        <View style={box}>{inner}</View>
      )}
      {error ? <View style={s.error}><Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{error}</Text></View> : null}
    </View>
  )
})

export function AuthDivider() {
  return (
    <View style={s.divider} aria-hidden>
      <View style={s.rule} />
      <Text {...font.inter500_11_5} color={ink.secondary}>{'or'}</Text>
      <View style={s.rule} />
    </View>
  )
}

/**
 * One centred line of plain words, the last ones a gold link: "Don't have an account? Sign up". Its distance from the
 * button above: none of its own (Create account), the board's 20 (Forgot Password: `normal`) or 22 (Log in: `wide`).
 */
export function AuthFooter({ words, link, onPress, gap = 'tight' }: { words: string; link: string; onPress: () => void; gap?: 'tight' | 'normal' | 'wide' }) {
  return (
    <Pressable onPress={onPress} role="link" aria-label={`${words}${link}`} hitSlop={space[12]} style={gap === 'wide' ? s.footerWide : gap === 'normal' ? s.footerNormal : s.footer}>
      <Text {...font.inter400_13} color={ink.label} align="center">
        {words}
        <Text {...font.inter700_13} color={ink.link}>{link}</Text>
      </Text>
    </Pressable>
  )
}

/**
 * The board's one sentence with its box. The BOX is the checkbox (one control a screen reader reaches); the two documents
 * are the links inside the sentence, each opening its own page — the only place they are offered (M47 §2.2 2d).
 */
export function TermsRow({ agreed, onToggle, onTerms, onPrivacy, error }: { agreed: boolean; onToggle: () => void; onTerms: () => void; onPrivacy: () => void; error?: string | null }) {
  return (
    <View style={s.termsWrap}>
      <View style={s.terms}>
        <Pressable onPress={onToggle} role="checkbox" aria-checked={agreed} aria-label="I agree to GeminiSecure's Terms of Service and Privacy Policy" hitSlop={space[12]} style={[s.box, agreed ? s.boxOn : s.boxOff, error ? s.boxError : null]}>
          {agreed ? <Icon name="check" size={icon[12]} color={ink.onDark} /> : null}
        </Pressable>
        <View style={s.termsText}>
          <Text {...font.inter400_11_5_lh1_55} color={ink.secondary} onPress={onToggle}>
            {"I agree to GeminiSecure's "}
            <Text {...font.inter600_11_5_lh1_55} color={ink.link} onPress={onTerms} role="link">{'Terms of Service'}</Text>
            {' and '}
            <Text {...font.inter600_11_5_lh1_55} color={ink.link} onPress={onPrivacy} role="link">{'Privacy Policy'}</Text>
          </Text>
        </View>
      </View>
      {error ? <View style={s.error}><Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{error}</Text></View> : null}
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface },
  fill: { flex: 1 },
  grow: { flexGrow: 1 },

  header: { borderBottomLeftRadius: radius.authHeader, borderBottomRightRadius: radius.authHeader, paddingHorizontal: space[24] },
  back: { position: 'absolute', left: space[24], width: size[34], height: size[34], borderRadius: corner.round(size[34]), backgroundColor: fill.onDarkButton, alignItems: 'center', justifyContent: 'center' },
  brand: { position: 'absolute', right: space[24], flexDirection: 'row', alignItems: 'center', gap: space[7] },
  badgeRow: { position: 'absolute', left: 0, right: 0, bottom: space['-30'], alignItems: 'center' },
  badge: { width: size[64], height: size[64], borderRadius: corner.round(size[64]), backgroundColor: fill.surface, alignItems: 'center', justifyContent: 'center', ...shadow.badge },
  body: { paddingTop: space[52], paddingHorizontal: space[24] },

  sub: { paddingHorizontal: space[6], marginTop: space[6], marginBottom: space[24] },

  field: { marginBottom: space[15] },
  label: { marginBottom: space[6] },
  input: { height: size[48], flexDirection: 'row', alignItems: 'center', backgroundColor: fill.tint, borderRadius: radius.field, borderWidth: stroke['1.5'], borderColor: 'transparent', paddingHorizontal: space[14] },
  inputFocused: { backgroundColor: fill.surface, borderColor: line.focus, ...shadow.focusRing },
  // M42 §6.3: red is for life-safety only — a field that needs attention takes the gold edge.
  inputError: { borderColor: line.focus },
  inputIcon: { marginRight: space[10] },
  inputText: { flex: 1, minWidth: 0, justifyContent: 'center' },
  placeholder: { position: 'absolute', left: 0, right: 0, pointerEvents: 'none' },
  textInput: { fontFamily: role.inputValue.face, fontSize: role.inputValue.size, color: c(ink.heading), padding: 0, margin: 0 },
  trailing: { marginLeft: 'auto' },
  error: { marginTop: space[6] },

  divider: { flexDirection: 'row', alignItems: 'center', gap: space[12], marginVertical: space[20] },
  rule: { flex: 1, height: size[1], backgroundColor: fill.divider },

  footer: { paddingTop: space[6] },
  footerNormal: { paddingTop: space[6], marginTop: space[20] },
  footerWide: { paddingTop: space[6], marginTop: space[22] },

  // .terms-row { margin: 6px 0 22px }: its 6 collapses into the field's 15 above it in the board, so it adds none here.
  termsWrap: { marginBottom: space[22] },
  terms: { flexDirection: 'row', alignItems: 'flex-start', gap: space[10] },
  box: { width: size[18], height: size[18], borderRadius: corner[5], alignItems: 'center', justifyContent: 'center', marginTop: space[1] },
  boxOn: { backgroundColor: fill.primary },
  // The board draws only the ticked box; unticked is the outline button's own edge on white.
  boxOff: { backgroundColor: fill.surface, borderWidth: stroke['1.5'], borderColor: line.outline },
  boxError: { borderColor: line.focus },
  termsText: { flex: 1, minWidth: 0 },
})
