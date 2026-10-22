/**
 * A PHONE NUMBER AND AN EMAIL ADDRESS, READ THE WAY THE SERVER READS THEM (M18C §6, M18G §4).
 *
 * The server's `JamaicanNumber::normalise` and `EmailAddress::suggest`, in the phone, so a form can say what is wrong
 * BESIDE the field before anything is sent — and says it in the same words the server would. The server still
 * decides; this only saves a round trip and a lost minute.
 *
 *   normalisePhone   "876-555-0123", "1 876 555 0123", "+1 (876) 555-0123" → "+1 876 555 0123"; another country's number
 *                    with its "+" is kept as "+" and its digits; anything else is null. A seven-digit local number is
 *                    refused, never guessed at.
 *   phoneProblem     the sentence to show beside the field, or null.
 *   suggestEmail     "andrea@gmail.comm" → "andrea@gmail.com": a gentle "Did you mean…?", never a refusal.
 *
 * Pure: no network. (Whether a domain can receive mail at all is the server's to ask — the phone has no DNS.)
 *
 * THE EXAMPLE IS THE ESTATE'S (M32 F7). "…for example +92 301 111 6259" was written into these sentences — Pakistan's,
 * on a Jamaican estate's forms. Every example is now the estate's own country's, from its zone (`siteZone()`, the one
 * the server sends), as the server's `EstatePhone` says it, and never the phone's locale or where its holder is: a
 * North American country's number written locally, any other written with "+" (the form the server reads there), and a
 * country the table does not hold gets how to write a number and no invented example. The numbers are the ranges set
 * aside for fiction (555-01xx, Ofcom's 07700 900xxx), so no example is anybody's phone.
 */

import { siteZone } from './siteTime'

type Example = { adjective: string; local: string; international: string }

/** The server's own table (`App\Support\EstatePhone::EXAMPLES`), word for word. */
const EXAMPLES: Readonly<Record<string, Example>> = {
  JM: { adjective: 'Jamaican', local: '876 555 0123', international: '+1 876 555 0123' },
  TT: { adjective: 'Trinidad and Tobago', local: '868 555 0123', international: '+1 868 555 0123' },
  BB: { adjective: 'Barbadian', local: '246 555 0123', international: '+1 246 555 0123' },
  BS: { adjective: 'Bahamian', local: '242 555 0123', international: '+1 242 555 0123' },
  KY: { adjective: 'Caymanian', local: '345 555 0123', international: '+1 345 555 0123' },
  US: { adjective: 'US', local: '212 555 0123', international: '+1 212 555 0123' },
  CA: { adjective: 'Canadian', local: '416 555 0123', international: '+1 416 555 0123' },
  GB: { adjective: 'UK', local: '07700 900123', international: '+44 7700 900123' },
}

/** The North American Numbering Plan's countries above: a number typed without "+" is read as theirs. */
const NANP = new Set(['JM', 'TT', 'BB', 'BS', 'KY', 'US', 'CA'])

/** The platform's home, every client so far — and the answer for a zone that names no country, as on the server. */
const HOME = 'JM'

/**
 * The country that keeps each zone, as the zone database names it (what the server reads with `getLocation()`), for
 * the zones in the countries above. A zone outside them belongs to a country the table has no example for, and is said
 * as one: how to write the number, with no example made up.
 */
const ZONE_COUNTRY: Readonly<Record<string, string>> = {
  'America/Jamaica': 'JM',
  'America/Port_of_Spain': 'TT',
  'America/Barbados': 'BB',
  'America/Nassau': 'BS',
  'America/Cayman': 'KY',
  'America/New_York': 'US', 'America/Detroit': 'US', 'America/Chicago': 'US', 'America/Denver': 'US', 'America/Phoenix': 'US',
  'America/Los_Angeles': 'US', 'America/Anchorage': 'US', 'America/Boise': 'US', 'America/Indiana/Indianapolis': 'US', 'Pacific/Honolulu': 'US',
  'America/Toronto': 'CA', 'America/Vancouver': 'CA', 'America/Edmonton': 'CA', 'America/Winnipeg': 'CA', 'America/Regina': 'CA',
  'America/Halifax': 'CA', 'America/St_Johns': 'CA',
  'Europe/London': 'GB',
}

/** The estate's country from its zone; the platform's home for a zone with none ('UTC', unset). */
export function estateCountry(zone: string = siteZone()): string {
  if (zone === '' || zone === 'UTC' || zone === 'Etc/UTC') return HOME
  return ZONE_COUNTRY[zone] ?? 'XX'
}

export type PhoneWords = {
  /** What a phone box shows before anything is typed: the number as it is read here. */
  local: string
  international: string
  /** "Example: 876 555 0123." — the end of every "Enter a phone number" sentence. */
  example: string
  /** A number that is not one. */
  invalid: string
  /** Ten digits or more with no "+": a number from somewhere else, typed without its country code. */
  abroad: string
}

/** What this estate's phone fields say (M32 F7), the server's sentences for the same estate. */
export function phoneWords(zone: string = siteZone()): PhoneWords {
  const country = estateCountry(zone)
  const example = EXAMPLES[country]

  if (example === undefined) {
    const sentence = 'That does not look like a phone number. Write it with + and the country code first.'
    return { local: '+…', international: '+…', example: 'Write it with + and the country code first.', invalid: sentence, abroad: sentence }
  }

  const nanp = NANP.has(country)
  const local = nanp ? example.local : example.international

  return {
    local,
    international: example.international,
    example: `Example: ${local}.`,
    invalid: nanp ? `That does not look like a phone number. Example: ${example.local}.` : `That does not look like a phone number. Write it with + and the country code: for example ${example.international}.`,
    abroad: nanp
      ? `That does not look like a ${example.adjective} number. For a number in another country, start with + and the country code: for example ${example.international}.`
      : `That does not look like a phone number. Write it with + and the country code: for example ${example.international}.`,
  }
}

export function normalisePhone(typed: string | null | undefined): string | null {
  const raw = (typed ?? '').trim()
  if (raw === '') return null
  const explicit = raw.startsWith('+') || raw.startsWith('00')
  let digits = raw.replace(/\D+/g, '')
  if (digits === '') return null

  if (explicit) {
    if (!raw.startsWith('+') && digits.startsWith('00')) digits = digits.slice(2)
    if (digits.length < 8 || digits.length > 15) return null
    if (digits.length === 11 && digits.startsWith('1')) return nanp(digits.slice(1))
    return digits.startsWith('1') ? null : `+${digits}`
  }

  if (digits.length === 10) return nanp(digits)
  if (digits.length === 11 && digits.startsWith('1')) return nanp(digits.slice(1))
  return null
}

/** NXX-NXX-XXXX, N being 2–9: "+1 876 555 0123". */
function nanp(national: string): string | null {
  return /^[2-9]\d\d[2-9]\d{6}$/.test(national) ? `+1 ${national.slice(0, 3)} ${national.slice(3, 6)} ${national.slice(6)}` : null
}

/** What to say beside a phone field, or null when the number is one the server will take — in this estate's words. */
export function phoneProblem(typed: string | null | undefined, zone: string = siteZone()): string | null {
  const raw = (typed ?? '').trim()
  if (raw === '') return null
  if (normalisePhone(raw) !== null) return null
  const words = phoneWords(zone)
  return raw.replace(/\D+/g, '').length >= 10 && !raw.startsWith('+') ? words.abroad : words.invalid
}

const PROVIDERS = [
  'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'live.com', 'aol.com', 'msn.com',
  'yahoo.co.uk', 'hotmail.co.uk', 'protonmail.com', 'proton.me', 'me.com', 'mail.com', 'flowja.com', 'cwjamaica.com',
]

const KNOWN_SLIPS: Readonly<Record<string, string>> = {
  'gmail.comm': 'gmail.com', 'gmail.con': 'gmail.com', 'gmail.co': 'gmail.com', 'gmail.cm': 'gmail.com',
  'gmail.om': 'gmail.com', 'gmail.cim': 'gmail.com', 'gmail.vom': 'gmail.com', 'gmail.xom': 'gmail.com',
  'gmial.com': 'gmail.com', 'gmai.com': 'gmail.com', 'gamil.com': 'gmail.com', 'gmali.com': 'gmail.com',
  'gnail.com': 'gmail.com', 'gmaill.com': 'gmail.com', 'gmail.coml': 'gmail.com', 'g.mail.com': 'gmail.com',
  'hotmial.com': 'hotmail.com', 'hotmal.com': 'hotmail.com', 'hotmail.con': 'hotmail.com', 'hotmai.com': 'hotmail.com',
  'yaho.com': 'yahoo.com', 'yahooo.com': 'yahoo.com', 'yahoo.con': 'yahoo.com', 'yhoo.com': 'yahoo.com',
  'outlok.com': 'outlook.com', 'outloo.com': 'outlook.com', 'outlook.con': 'outlook.com',
  'iclod.com': 'icloud.com', 'icloud.con': 'icloud.com', 'icoud.com': 'icloud.com',
}

/** Levenshtein distance, capped — only "is it exactly one?" is ever asked. */
function oneEditApart(a: string, b: string): boolean {
  if (Math.abs(a.length - b.length) > 1 || a === b) return false
  let i = 0
  let j = 0
  let edits = 0
  while (i < a.length && j < b.length) {
    if (a[i] === b[j]) {
      i++
      j++
      continue
    }
    if (++edits > 1) return false
    if (a.length > b.length) i++
    else if (a.length < b.length) j++
    else {
      // A swap of two neighbours is one slip too ("gmial").
      if (a[i + 1] === b[j] && a[i] === b[j + 1]) {
        i += 2
        j += 2
        continue
      }
      i++
      j++
    }
  }
  return edits + (a.length - i) + (b.length - j) <= 1
}

/** The address with the domain it probably meant, or null when there is nothing to suggest. */
export function suggestEmail(address: string | null | undefined): string | null {
  const raw = (address ?? '').trim()
  const at = raw.lastIndexOf('@')
  if (at <= 0) return null
  const local = raw.slice(0, at)
  const domain = raw.slice(at + 1).toLowerCase()
  if (domain === '' || PROVIDERS.includes(domain)) return null
  const meant = KNOWN_SLIPS[domain] ?? PROVIDERS.find((p) => oneEditApart(domain, p)) ?? null
  return meant === null ? null : `${local}@${meant}`
}
