/**
 * The rules behind `gate:contact`, as pure functions, so `gates/selftest.mjs` can prove each one
 * bites on a known-bad input without writing files into the repository.
 *
 * WHAT THIS IS FOR. Both app stores load the public pages themselves and read the contact details
 * off the listing. A placeholder there is not a cosmetic problem, it is a rejection — and the way a
 * placeholder comes back is never that somebody types "TBD" on purpose. It is that a second copy of
 * the address is added somewhere, the first one moves, and the two drift. So the rules below do two
 * separate jobs:
 *
 *   1  the VALUE must be a real contact detail, not a stand-in (`support@example.com`, `{{EMAIL}}`,
 *      "your company", an empty string)
 *   2  every place that has to carry it must carry THE SAME ONE, and no shipped source file may
 *      hold a company address of its own — it comes from `packages/core/lib/legal.ts` or nowhere
 *
 * A form HINT is not a contact detail. `placeholder="you@email.com"` in a sign-in box is the shape
 * of an address, shown greyed out, and is left alone deliberately: rule 2 only looks at addresses
 * at a company mailbox (support@, help@, info@, privacy@ …), which is what a store reads.
 */

/** Applied to a VALUE, never to a whole document — the word "placeholder" in prose is not a fault. */
export const PLACEHOLDER_VALUE = [
  { re: /\bexample\.(com|org|net)\b/i, why: 'example.com is the reserved stand-in domain' },
  { re: /\b(?:your|our)[-\s]?(?:company|domain|email|address|name)\b/i, why: 'a fill-this-in token' },
  { re: /\b(?:tbd|tbc|fixme|todo|xxx+)\b/i, why: 'a fill-this-in marker' },
  { re: /\bplaceholder\b/i, why: 'the word "placeholder"' },
  { re: /\blorem ipsum\b/i, why: 'filler text' },
  { re: /\b(?:acme|foobar|somecompany)\b/i, why: 'a stand-in company name' },
  { re: /\{\{|\}\}|<[A-Za-z][^>]*>/, why: 'an unfilled template token' },
  { re: /\b(?:localhost|127\.0\.0\.1)\b/i, why: 'a developer machine' },
]

/** An address AT A COMPANY MAILBOX — what a store reads as "the support contact". */
export const COMPANY_MAILBOX = /\b(?:support|help|helpdesk|info|contact|enquiries|privacy|legal|dpo|admin)@[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\.[A-Za-z]{2,}\b/gi

/**
 * ANY MAILBOX ON geminisl.com (M33 §3). The domain publishes no MX record, so nothing sent to an address on it is ever
 * read: the support address Q-M33 gave was one, and it was printed in both apps for a fortnight. The client's own
 * address is gemisecja@gmail.com. Only the domain itself counts: `…@phoenixpark.demo.geminisl.com`, the demonstration
 * committee's sign-in domain, is not a contact anybody is offered.
 */
export const DEAD_MAILBOX = /\b[A-Za-z0-9._%+-]+@geminisl\.com\b/gi

const RFC_ISH = /^[^\s@]+@[^\s@.]+(?:\.[^\s@.]+)+$/

/**
 * @param {object} input
 * @param {{company: string, email: string, address: string, addressLines: string[]}} input.contact
 *        the one source of truth, parsed from packages/core/lib/legal.ts
 * @param {{path: string, text: string}[]} input.documents  files that MUST carry the details verbatim
 * @param {{path: string, text: string}[]} input.sources    shipped .ts/.tsx that must not hold their own
 * @param {string} input.owner  the module the details are allowed to live in
 * @param {{path: string, text: string, needs: (string|RegExp)[]}[]} [input.server]  server files, when present
 * @returns {string[]} one sentence per problem, empty when there is none
 */
export function contactProblems({ contact, documents = [], sources = [], owner, server = [] }) {
  const fails = []

  /* 1 ── the values themselves are real ------------------------------------------------------- */
  for (const [what, value] of [['company name', contact.company], ['support email', contact.email], ['postal address', contact.address]]) {
    if (typeof value !== 'string' || value.trim() === '') {
      fails.push(`the ${what} is missing from ${owner} — a store listing cannot be published without it`)
      continue
    }
    for (const { re, why } of PLACEHOLDER_VALUE) {
      if (re.test(value)) fails.push(`the ${what} in ${owner} is a placeholder ("${value.slice(0, 60)}") — ${why}`)
    }
  }
  if (typeof contact.email === 'string' && contact.email !== '' && !RFC_ISH.test(contact.email)) {
    fails.push(`the support email in ${owner} is not an address ("${contact.email}")`)
  }
  if (typeof contact.email === 'string' && /@geminisl\.com$/i.test(contact.email.trim())) {
    fails.push(`the support email in ${owner} is ${contact.email}, on geminisl.com, which takes no mail — the client's address is gemisecja@gmail.com`)
  }
  if (Array.isArray(contact.addressLines) && contact.addressLines.length > 0 && contact.addressLines.length < 3) {
    fails.push(`the postal address in ${owner} is ${contact.addressLines.length} line(s) — a store asks for one somebody could post a letter to`)
  }

  /* 2 ── every document that must carry them carries THE SAME ones ---------------------------- */
  for (const { path, text } of documents) {
    if (contact.email && !text.includes(contact.email)) fails.push(`${path} does not carry the support email (${contact.email})`)
    if (contact.address && !text.includes(contact.address)) fails.push(`${path} does not carry the postal address as one line`)
    for (const found of text.match(COMPANY_MAILBOX) ?? []) {
      if (found.toLowerCase() !== String(contact.email).toLowerCase()) fails.push(`${path} carries a second support address (${found}) — there is one, and it is ${contact.email}`)
    }
    for (const found of new Set(text.match(DEAD_MAILBOX) ?? [])) {
      if (found.toLowerCase() !== String(contact.email).toLowerCase()) fails.push(`${path} offers ${found}, on geminisl.com, which takes no mail — the address is ${contact.email}`)
    }
  }

  /* 3 ── no shipped source holds a company address of its own --------------------------------- */
  for (const { path, text } of sources) {
    if (path === owner) continue
    for (const found of text.match(COMPANY_MAILBOX) ?? []) {
      fails.push(`${path} writes a company address (${found}) into the source — import SUPPORT_CONTACT from ${owner} instead, or the two will drift`)
    }
    // The client's own address is no company mailbox by its shape, so it is looked for by name (M33 §3).
    if (typeof contact.email === 'string' && contact.email !== '' && text.toLowerCase().includes(contact.email.toLowerCase())) {
      fails.push(`${path} writes the support address (${contact.email}) into the source — import SUPPORT_CONTACT from ${owner} instead, or the two will drift`)
    }
    // A company mailbox on that domain was named just above; any other mailbox there is named here.
    for (const found of new Set(text.match(DEAD_MAILBOX) ?? [])) {
      if (!/^(?:support|help|helpdesk|info|contact|enquiries|privacy|legal|dpo|admin)@/i.test(found)) fails.push(`${path} writes ${found}, on geminisl.com, which takes no mail, into the source`)
    }
  }

  /* 4 ── the server says the same thing, when the server is reachable ------------------------- */
  for (const { path, text, needs } of server) {
    for (const need of needs) {
      const ok = typeof need === 'string' ? text.includes(need) : need.test(text)
      if (!ok) fails.push(`${path} does not ${typeof need === 'string' ? `carry "${String(need).slice(0, 70)}"` : `match ${String(need)}`} — the server and the apps would show different contact details`)
    }
  }

  return fails
}
