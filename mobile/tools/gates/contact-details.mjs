/**
 * GATE — the company's contact details are REAL, and there is exactly one of them.
 *
 * Both stores load the public pages themselves and read the support contact off the listing, so a
 * placeholder there is a rejection rather than a tidy-up. This gate holds the answer the client gave
 * on 2026-09-21 (Q-M33) in place:
 *
 *   1  `packages/core/lib/legal.ts` holds the one copy the apps use, and it is a real address and a
 *      real postal address — not example.com, not an unfilled token, not empty
 *   2  every document a console is filled from carries the SAME pair, and no second support address
 *      has appeared beside it
 *   3  no shipped source file writes a company mailbox of its own — it comes from SUPPORT_CONTACT
 *   4  the server, when it is beside this repository, has the same pair as its config DEFAULTS (not
 *      as blanks waiting on an env file), and the privacy policy and terms still reach them through
 *      their tokens rather than a second hard-coded copy
 *
 * A form hint is not a contact detail: `placeholder="you@email.com"` in a sign-in box is the shape of
 * an address and is deliberately not touched. See tools/gates/contact-rules.mjs.
 *
 *   node tools/gates/contact-details.mjs
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { contactProblems } from './contact-rules.mjs'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const OWNER = 'packages/core/lib/legal.ts'
const SKIP = new Set(['node_modules', '.expo', 'dist', 'android', 'ios', '__tests__', 'generated', 'lifted', 'fidelity', '__gateprobe__'])
const rel = (full) => path.relative(ROOT, full).replace(/\\/g, '/')
const read = (full) => readFileSync(full, 'utf8')

const walk = (dir) =>
  !existsSync(dir) ? [] : readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) ? [full] : []
  })

/* ── 1. the one copy, parsed out of the module that owns it ──────────────────────────────────── */
const ownerFile = path.join(ROOT, OWNER)
if (!existsSync(ownerFile)) {
  console.log(`GATE contact · FAIL — ${OWNER} is missing, so nothing holds the company's contact details`)
  process.exit(1)
}
const ownerSource = read(ownerFile)
const literal = (key) => (ownerSource.match(new RegExp(`${key}:\\s*'([^']*)'`)) ?? [])[1] ?? ''
const addressLines = [...((ownerSource.match(/addressLines:\s*\[([^\]]*)\]/) ?? [])[1] ?? '').matchAll(/'([^']*)'/g)].map((m) => m[1])
const contact = {
  company: literal('company'),
  email: literal('email'),
  addressLines,
  address: addressLines.join(', '),
}

/* ── 2. the documents a console is filled from ───────────────────────────────────────────────── */
const notes = []
// M25: one app goes to the stores. The Guard App has no listing; its own policy reads SUPPORT_CONTACT, checked below.
const DOCUMENTS = ['store/listing/resident.md', 'store/MANIFEST.md', 'STORE_REVIEW_NOTES.md']
const documents = []
for (const name of DOCUMENTS) {
  const full = path.join(ROOT, name)
  // The submission working notes are not published with the source (see tools/gates/store.mjs §1).
  // Where one is absent the check is SKIPPED and the skip is printed, never silently passed.
  if (!existsSync(full)) { notes.push(`${name} is not in this checkout, so it was not checked — it must be, on the machine a submission is prepared from`); continue }
  documents.push({ path: name, text: read(full) })
}

/* ── 3. everything that ships to a phone ─────────────────────────────────────────────────────── */
const sources = [path.join(ROOT, 'packages', 'core'), ...['guard', 'resident'].flatMap((app) => [path.join(ROOT, 'apps', app, 'src'), path.join(ROOT, 'apps', app, 'app')])]
  .flatMap(walk)
  .map((full) => ({ path: rel(full), text: read(full) }))

/* ── 4. the server, when it is beside this repository ────────────────────────────────────────── */
// GS_SERVER_CHECKOUT names the server checkout to hold the apps to — production's branch lives in a worktree of its own
// (M33 §3), while the checkout beside this one can sit on any branch. It is the server's GS_MOBILE_CHECKOUT, mirrored.
const SERVER = process.env.GS_SERVER_CHECKOUT ? path.resolve(process.env.GS_SERVER_CHECKOUT) : path.resolve(ROOT, '..', 'geminisecure')
const server = []
if (existsSync(path.join(SERVER, 'config', 'legal.php'))) {
  server.push({
    path: '(server) config/legal.php',
    text: read(path.join(SERVER, 'config', 'legal.php')),
    // The DEFAULT, not a blank: a fresh deployment with no env file still serves the real details.
    needs: [new RegExp(`'support_email'\\s*=>\\s*env\\('LEGAL_SUPPORT_EMAIL',\\s*'${contact.email.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}'\\)`), contact.address],
  })
  for (const doc of ['privacy', 'terms']) {
    const full = path.join(SERVER, 'resources', 'legal', `${doc}.md`)
    if (!existsSync(full)) { notes.push(`(server) resources/legal/${doc}.md is missing`); continue }
    // Through the tokens, so there is never a second copy to keep in step.
    server.push({ path: `(server) resources/legal/${doc}.md`, text: read(full), needs: ['{{support_email}}', '{{address}}'] })
  }
} else {
  notes.push('the Laravel project is not beside this repository, so the server half of this gate was SKIPPED (config/legal.php, resources/legal/*.md)')
}

/* ── 5. and the two Help screens read it rather than repeating it ────────────────────────────── */
const fails = contactProblems({ contact, documents, sources, owner: OWNER, server })
for (const screen of ['apps/guard/src/features/more/screens/HelpScreen.tsx', 'apps/resident/src/features/settings/useSettings.ts']) {
  const full = path.join(ROOT, screen)
  if (!existsSync(full)) { fails.push(`${screen} is missing — one of the two places a person finds the contact details in the app`); continue }
  if (!/SUPPORT_CONTACT/.test(read(full))) fails.push(`${screen} does not use SUPPORT_CONTACT — the app would show contact details that are not the ones the stores were given`)
}
// The server's own literal, in the one file allowed to hold it, must be THE SAME one.
if (server.length > 0 && !server[0].text.includes(contact.email)) {
  fails.push(`(server) config/legal.php and ${OWNER} name different support emails`)
}

if (fails.length > 0) {
  console.log(`GATE contact · FAIL — ${fails.length} thing(s) a store would reject or a person could not reach:`)
  for (const f of fails) console.log(`  ${f}`)
  for (const n of notes) console.log(`  note  ${n}`)
  process.exit(1)
}
console.log(`GATE contact · ${contact.email} · ${contact.address}`)
console.log(`GATE contact · one copy (${OWNER}), ${documents.length} document(s), ${sources.length} shipped source(s) and ${server.length} server file(s) all agree`)
for (const n of notes) console.log(`  note  ${n}`)
