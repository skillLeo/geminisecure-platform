// M45 §3: proof that the dev-only gallery is compiled OUT of a release bundle.
//
// Exports the Resident App's Android bundle twice, in production mode (minified, Hermes), exactly as a release build
// bundles it: first as a gallery build (EXPO_PUBLIC_GALLERY=1), the control, then — with Metro's cache left warm — as
// a release (no EXPO_PUBLIC_GALLERY, no EXPO_PUBLIC_FIDELITY). Then it looks for strings only the gallery's files
// contain. They must be in the gallery bundle — or the search proves nothing — and absent from the release bundle.
//
//   node tools/design/gallery-proof.mjs            (a few minutes: two production exports)
//
// Writes _design/GALLERY_PROOF.md.

import { spawnSync } from 'node:child_process'
import { readFileSync, readdirSync, statSync, writeFileSync, rmSync, mkdtempSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { tmpdir } from 'node:os'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const app = join(root, 'apps', 'resident')
// Literal ASCII strings only: Hermes keeps an all-ASCII string as bytes, so a byte search finds it in bytecode as in
// plain JS — but a string with one non-ASCII character (a §, a ·) is stored as UTF-16, and a template literal
// (`resident-extra-${slug}`) exists only as its constant part. Measured: both kinds were "absent" from the gallery build.
const MARKERS = ['gs-design-gallery-v1', 'Design gallery', 'resident-extra-', 'Test payment (goes with M42 E6)']
// Not the gallery: the screens' own fixtures, which ship today behind a runtime check (reported, not failed).
const FIXTURES = ['TropiClean Pest Control', 'andrea.fletcher@email.com']

const files = (dir) => readdirSync(dir).flatMap((n) => (statSync(join(dir, n)).isDirectory() ? files(join(dir, n)) : [join(dir, n)]))

function exportBundle(label, env) {
  const out = mkdtempSync(join(tmpdir(), `gs-gallery-${label}-`))
  const clean = { ...process.env, CI: '1', EXPO_NO_TELEMETRY: '1' }
  delete clean.EXPO_PUBLIC_GALLERY
  delete clean.EXPO_PUBLIC_FIDELITY
  const started = Date.now()
  // NO --clear, on purpose. Metro's transform cache does not key on EXPO_PUBLIC_* values by itself — measured: a gallery
  // export made after a release export came out byte for byte the release bundle (index-3762e8e5…hbc). The resident
  // metro.config.js names the flags in its cacheVersion; exporting the gallery FIRST and the release SECOND, with the
  // cache left warm, is the order that would leak the gallery into a release if that were ever undone.
  const r = spawnSync('npx', ['expo', 'export', '--platform', 'android', '--output-dir', out], { cwd: app, env: { ...clean, ...env }, encoding: 'utf8', shell: true, maxBuffer: 64 * 1024 * 1024 })
  if (r.status !== 0) throw new Error(`${label} export failed:\n${(r.stderr || r.stdout).slice(-3000)}`)
  const bundles = files(out).filter((f) => /\.(hbc|js)$/.test(f) && f.includes('android'))
  const bytes = Buffer.concat(bundles.map((f) => readFileSync(f)))
  const text = bytes.toString('latin1')
  const found = (list) => Object.fromEntries(list.map((m) => [m, text.includes(m)]))
  const result = { label, bundles: bundles.map((f) => `${f.slice(out.length + 1)} (${statSync(f).size} bytes)`), seconds: Math.round((Date.now() - started) / 1000), markers: found(MARKERS), fixtures: found(FIXTURES) }
  rmSync(out, { recursive: true, force: true })
  return result
}

const gallery = exportBundle('gallery', { EXPO_PUBLIC_GALLERY: '1' })
const release = exportBundle('release', {})

const leaked = MARKERS.filter((m) => release.markers[m])
const blind = MARKERS.filter((m) => !gallery.markers[m])
const pass = leaked.length === 0 && blind.length === 0
const commit = spawnSync('git', ['rev-parse', '--short', 'HEAD'], { cwd: root, encoding: 'utf8' }).stdout.trim()
const dirty = spawnSync('git', ['status', '--porcelain'], { cwd: root, encoding: 'utf8' }).stdout.trim() ? ' + uncommitted changes' : ''

const row = (r, list, key) => list.map((m) => `| \`${m}\` | ${r[key][m] ? 'present' : 'absent'} |`).join('\n')
const md = `# The gallery is compiled out of a release bundle (M45 §3)

Written by \`node tools/design/gallery-proof.mjs\` at ${commit}${dirty}. Two production exports of the Resident App's Android
bundle (\`npx expo export --platform android\`: minified, Hermes bytecode — what a release build bundles).

**Verdict: ${pass ? 'PASS' : 'FAIL'}** — ${pass ? 'every gallery-only string is in the gallery build and none is in the release build.' : `${leaked.length ? `leaked into the release: ${leaked.join(', ')}. ` : ''}${blind.length ? `not found even in the gallery build (the search proves nothing): ${blind.join(', ')}.` : ''}`}

## Release build (no EXPO_PUBLIC_GALLERY, no EXPO_PUBLIC_FIDELITY) — ${release.seconds}s

${release.bundles.map((b) => `- ${b}`).join('\n')}

| gallery-only string | in the bundle |
|---|---|
${row(release, MARKERS, 'markers')}

## Gallery build (EXPO_PUBLIC_GALLERY=1), the control — ${gallery.seconds}s

${gallery.bundles.map((b) => `- ${b}`).join('\n')}

| gallery-only string | in the bundle |
|---|---|
${row(gallery, MARKERS, 'markers')}

## Not the gallery: the screens' fidelity fixtures

These ship in BOTH builds today: each screen imports its fixture and reads it behind \`isFidelity\`, which a phone
can never turn on outside a gallery build. Inert, but present.

| fixture string | release | gallery |
|---|---|---|
${FIXTURES.map((m) => `| \`${m}\` | ${release.fixtures[m] ? 'present' : 'absent'} | ${gallery.fixtures[m] ? 'present' : 'absent'} |`).join('\n')}
`
writeFileSync(join(root, '_design', 'GALLERY_PROOF.md'), md, 'utf8')
console.log(md)
process.exit(pass ? 0 : 1)
