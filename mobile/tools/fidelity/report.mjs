/**
 * Rewrites FIDELITY.md from the harness's own result files, so the table is never typed by hand.
 *   _design/FIDELITY_LIFTED.json   the lifter's output, measured (`npm run fidelity -- --lifted`)
 *   _design/FIDELITY_RESULTS.json  the wired screens, measured (`npm run fidelity`)
 * Residual causes live in _design/SCREEN_ROUTES.json (`residual`) and are carried into the table.
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const D = path.join(ROOT, '_design')
const load = (f) => (existsSync(path.join(D, f)) ? JSON.parse(readFileSync(path.join(D, f), 'utf8')) : [])
const regions = JSON.parse(readFileSync(path.join(D, 'SCREEN_REGIONS.json'), 'utf8')).screens
const routes = JSON.parse(readFileSync(path.join(D, 'SCREEN_ROUTES.json'), 'utf8')).routes
const lifted = new Map(load('FIDELITY_LIFTED.json').map((r) => [r.id, r]))
const wired = new Map(load('FIDELITY_RESULTS.json').map((r) => [r.id, r]))

/**
 * The device sweep, when one has been run (`npm run device:fidelity`). Additive: with
 * no device file this report is byte-for-byte what it has always been, so nothing that
 * reads FIDELITY.md changes on a laptop that has never seen a handset.
 *
 * Where the two disagree, the device number is the real one — a browser is not a phone.
 */
const deviceFile = existsSync(path.join(D, 'FIDELITY_DEVICE.json')) ? JSON.parse(readFileSync(path.join(D, 'FIDELITY_DEVICE.json'), 'utf8')) : null
const device = new Map((deviceFile?.screens ?? []).map((r) => [r.id, r]))

const pct = (r) => (r && r.percent !== null && r.percent !== undefined ? r.percent.toFixed(2) + '%' : '—')
const rows = regions.map((s) => {
  const l = lifted.get(s.id), w = wired.get(s.id), route = routes[s.id]
  const status = w ? w.status : route ? 'routed, not measured' : 'lifted only — not wired'
  // The device cell carries its OWN verdict: a screen can be under 2% in the browser and
  // over it on the handset, and one Status column showing only the browser's would hide that.
  const d = device.get(s.id)
  const dev = deviceFile ? ` ${pct(d)}${d && d.status && d.status !== 'PASS' ? ` **${d.status}**` : ''} |` : ''
  return `| ${s.id} | ${s.title} | ${pct(l)} | ${pct(w)} |${dev} ${status} | ${route?.residual ?? ''} |`
})

const lv = [...lifted.values()].map((r) => r.percent).filter((v) => v !== null)
const wv = [...wired.values()].map((r) => r.percent).filter((v) => v !== null)
const dv = [...device.values()].map((r) => r.percent).filter((v) => v !== null && v !== undefined)
const stat = (v) => (v.length ? `${v.length} measured · ${v.filter((x) => x < 2).length} under 2% · max ${Math.max(...v).toFixed(2)}% · median ${[...v].sort((a, b) => a - b)[Math.floor(v.length / 2)].toFixed(2)}%` : 'none measured')

const deviceMethod = deviceFile
  ? `
**Device.** ${deviceFile.handset?.model ?? 'a handset'} (Android ${deviceFile.handset?.android ?? '?'}), ${deviceFile.handset?.screen?.width}×${deviceFile.handset?.screen?.height} @ ${deviceFile.handset?.density}, captured with \`adb screencap\`,
scaled to the board's width and anchored at the top of the app's content, then put through the SAME \`maskedDiff\` and the
same device mask as the browser sweep. A further ${deviceFile.excludedExtraPx} px are excluded for this handset's own status bar,
navigation bar and any board rows the capture does not reach; the threshold is unchanged at 2%. Reference: ${deviceFile.against === 'board' ? 'the design board' : "the browser harness's own capture"}.
${deviceFile.caveat}
`
  : ''

/**
 * The legibility pass (M8 §6). Fidelity asks "does it match the board"; this asks "can it be read at all",
 * which the boards themselves cannot answer — the pale text the client could not read matched its board
 * exactly. Recorded from `tools/gates/contrast.mjs`, which now fails the build on the same measurements.
 */
const legibilityFile = existsSync(path.join(ROOT, 'tools/fidelity/legibility.json'))
  ? JSON.parse(readFileSync(path.join(ROOT, 'tools/fidelity/legibility.json'), 'utf8'))
  : null

const legibility = legibilityFile ? `
## Legibility — before and after

Measured ${legibilityFile.measured} with \`${legibilityFile.by}\`. The client's words: "${legibilityFile.complaint}"

${legibilityFile.note}

| Where | Was | Before | Now | After |
|---|---|---|---|---|
${legibilityFile.rows.map((r) => `| ${r.where} | ${r.was} | ${r.before.toFixed(2)} : 1 | ${r.now} | ${r.after.toFixed(2)} : 1 |`).join('\n')}

${legibilityFile.changes.map((c) => `- ${c}`).join('\n')}

Every pairing above now clears 4.5 : 1, the AA floor for body text. The pixel numbers in the table below were
measured before these colour changes; the colours moved, so re-run the harness before quoting them as current.
` : ''

const deviceRow = deviceFile ? `| Device | ${stat(dv)} |\n` : ''
const deviceHead = deviceFile ? ' Device |' : ''
const deviceRule = deviceFile ? '---|' : ''

writeFileSync(path.join(ROOT, 'FIDELITY.md'), `# FIDELITY — measured, per screen

Generated by \`node tools/fidelity/report.mjs\` from the harness's result files. Do not edit by hand.

Method: Expo web at 397×856 @1x vs the board cropped to the screen's region; pixelmatch 0.1; the device mask
(corners, island, home indicator, status-bar glyphs = 3.11%) excluded. Threshold 2%. Three iterations maximum,
then the residual is recorded in \`_design/SCREEN_ROUTES.json\` with its cause and swept in one pass at P9.

- **Lifted** = the screen exactly as tools/lift generates it from the board's own stylesheet (static, board data).
- **Wired** = the real screen — API data, all states, interactions — put in the board's state by fidelity fixtures.${deviceFile ? '\n- **Device** = the same screen captured on a real handset with `npm run device:fidelity`. Where the two disagree, the device number is the real one.' : ''}
${deviceMethod}${legibility}
| | |
|---|---|
| Lifted | ${stat(lv)} |
| Wired | ${stat(wv)} |
${deviceRow}
| Screen | Title | Lifted | Wired |${deviceHead} Status | Residual cause |
|---|---|---|---|${deviceRule}---|---|
${rows.join('\n')}
`, 'utf8')
console.log(`FIDELITY.md · lifted: ${stat(lv)} · wired: ${stat(wv)}`)
