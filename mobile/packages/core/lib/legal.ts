/**
 * The Resident App's public pages, the ones its store listing requires: privacy policy, terms of use
 * and the account-deletion page. They are served by the platform itself, with no sign-in, so their
 * address is DERIVED from the one configured server address — moving the server moves them too, with
 * no second value to keep in step (gate one-api-url).
 *
 * M24 (D-223): they are the RESIDENT'S pages and nothing else. The website no longer describes the
 * product (/support and /apps are gone), and the Guard App's policy and terms are compiled into the
 * Guard App (apps/guard/src/features/legal), so no resident can come across them.
 *
 * They open in the phone's own browser, never in a web view inside the app: a page the person can
 * see the address of, bookmark and share. If the phone cannot open it, the caller is told, and
 * shows the address in words instead of a link that does nothing.
 */

import { Linking } from 'react-native'
import { apiUrl } from './env'

export const LEGAL_PAGES = {
  privacy: '/legal/privacy',
  terms: '/legal/terms',
  deleteAccount: '/account/delete',
} as const

export type LegalPage = keyof typeof LEGAL_PAGES

/** `https://host/api/v1` → `https://host` — the web pages live beside the API, on the same server. */
export const webOrigin = (): string => apiUrl().replace(/\/api\/v\d+$/, '')

export const legalUrl = (page: LegalPage): string => `${webOrigin()}${LEGAL_PAGES[page]}`

/** The address without its scheme, for saying it in words: "app.example.com/legal/privacy". */
export const legalAddress = (page: LegalPage): string => legalUrl(page).replace(/^https?:\/\//, '')

/** Opens the page in the phone's browser. `false` when the phone would not — never throws. */
export async function openLegal(page: LegalPage): Promise<boolean> {
  try {
    await Linking.openURL(legalUrl(page))
    return true
  } catch {
    return false
  }
}

/*
 * THE COMPANY'S OWN CONTACT DETAILS — given by the client on 2026-09-21 (Q-M33).
 *
 * THE EMAIL IS THE CLIENT'S gemisecja@gmail.com (M33 §3), as the server's has been since 4 October 2026. The
 * address Q-M33 gave was a mailbox on geminisl.com, a domain with no mail server, so nothing sent there was read.
 *
 * Compiled in rather than fetched, because Help must work with no signal: this is what the person
 * reads when nothing else on the screen can load. It is the same pair of values the server prints
 * on the policy pages (config/legal.php), and `gate:contact` fails
 * if either one here is a placeholder or the two ever drift apart in shape.
 *
 * NOT a support telephone number. The client gave an email and a postal address and no phone, so
 * neither app offers one. The numbers given for panic escalation are a control-room list and are
 * not published anywhere a person could mistake them for a help line.
 *
 * NOT the estate office either. For a unit, a household, dues or access, the estate office is the
 * answer and both apps say so; this address is for the app itself and for a privacy request.
 */
export const SUPPORT_CONTACT = {
  company: 'Gemini Security',
  email: 'gemisecja@gmail.com',
  /** The postal address, as the client wrote it — one line per line of an envelope. */
  addressLines: ['Shop #20P', 'Portmore Town Centre', 'Portmore, Saint Catherine', 'Jamaica, West Indies'],
} as const

/** The postal address on one line, for a sentence rather than an envelope. */
export const supportAddress = (): string => SUPPORT_CONTACT.addressLines.join(', ')

/**
 * Opens the phone's mail app with the support address filled in. `false` when the phone has no mail
 * app — the caller then shows the address in words, exactly as a legal link does. Never throws.
 */
export async function openSupportEmail(subject?: string): Promise<boolean> {
  const query = subject ? `?subject=${encodeURIComponent(subject)}` : ''
  try {
    await Linking.openURL(`mailto:${SUPPORT_CONTACT.email}${query}`)
    return true
  } catch {
    return false
  }
}
