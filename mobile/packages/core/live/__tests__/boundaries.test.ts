/**
 * THE C9 BOUNDARY (DESIGN §8.7): live is a reader. It never writes a record, confirms, drains or offers an outbox
 * op, touches an idempotency stamp, raises a banner, or logs a payload — so the outbox suite, the panic path and
 * "no duplicates on sync" are exactly what they were before live existed.
 *
 * Read from the source (comments removed), so a new file in packages/core/live is held to it the day it is added,
 * and then run: a whole session of events through the real client, with notify's show() watched.
 */

import { readdirSync, readFileSync } from 'node:fs'
import path from 'node:path'
import { onLiveEvent } from '../configure'
import { EP, PASSES, deliver, env, open, resetLive, settle, startHarness } from './fakes'

const mockShow = jest.fn(async () => true)
jest.mock('../../notify', () => ({ show: (...a: unknown[]) => mockShow(...(a as [])) }))
jest.mock('../../notify/runtime', () => ({ show: (...a: unknown[]) => mockShow(...(a as [])) }))

const LIVE = path.join(__dirname, '..')
const FILES = [
  ...readdirSync(LIVE)
    .filter((f) => /\.tsx?$/.test(f))
    .map((f) => path.join(LIVE, f)),
  path.join(LIVE, '..', 'ui', 'LiveStatusBanner.tsx'),
]

/** The source with its comments taken out — a comment may say "write", code may not. */
function code(file: string): string {
  return readFileSync(file, 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:'"`])\/\/.*$/gm, '$1')
}

/** Every module a file brings in: `from '…'`, `require('…')` and `import('…')`, with whether it is types only. */
function imports(file: string): Array<{ spec: string; typeOnly: boolean }> {
  const src = code(file)
  const out: Array<{ spec: string; typeOnly: boolean }> = []
  for (const m of src.matchAll(/(^|\n)\s*(import|export)\s+(type\s+)?[^;]*?from\s+'([^']+)'/g)) out.push({ spec: m[4]!, typeOnly: !!m[3] })
  for (const m of src.matchAll(/require\('([^']+)'\)/g)) out.push({ spec: m[1]!, typeOnly: false })
  for (const m of src.matchAll(/import\('([^']+)'\)/g)) out.push({ spec: m[1]!, typeOnly: true }) // used in type positions only
  return out
}

const rel = (file: string) => path.relative(path.join(LIVE, '..'), file).replace(/\\/g, '/')

describe('what packages/core/live may bring in', () => {
  it('there is a live client to hold to it', () => {
    expect(FILES.map(rel)).toEqual(expect.arrayContaining(['live/client.ts', 'live/socket.ts', 'live/catchUp.ts', 'ui/LiveStatusBanner.tsx']))
  })

  it.each(FILES.map((f) => [rel(f), f]))('%s: nothing from the offline outbox except types', (_, file) => {
    const offline = imports(file).filter((i) => /(^|\/)offline(\/|$)|@gs\/core\/offline/.test(i.spec))
    expect(offline.filter((i) => !i.typeOnly)).toEqual([])
  })

  it.each(FILES.map((f) => [rel(f), f]))('%s: nothing from notify: live never raises a banner', (_, file) => {
    expect(imports(file).filter((i) => /notify|expo-notifications/.test(i.spec))).toEqual([])
  })

  it.each(FILES.map((f) => [rel(f), f]))('%s: nothing that stamps a write (api/write, the idempotency key)', (_, file) => {
    expect(imports(file).filter((i) => /api\/write|idempotency/i.test(i.spec))).toEqual([])
  })
})

describe('what packages/core/live may do', () => {
  it.each(FILES.map((f) => [rel(f), f]))('%s: no call to write, enqueue, drain, drainNow, offer or settle', (_, file) => {
    expect(code(file).match(/(?<![.\w])(write|enqueue|drain|drainNow|offer|settle)\s*\(/g) ?? []).toEqual([])
  })

  it.each(FILES.map((f) => [rel(f), f]))('%s: no request of its own: every call goes through the one base query', (_, file) => {
    expect(code(file).match(/\bfetch\s*\(|XMLHttpRequest/g) ?? []).toEqual([])
  })

  it.each(FILES.map((f) => [rel(f), f]))('%s: no address of its own: the socket’s host is the API’s (gate:api-url)', (_, file) => {
    expect(code(file).match(/['"`](wss?|https?):\/\/|EXPO_PUBLIC_/g) ?? []).toEqual([])
  })

  it('logs nothing about an event but its sequence number and type: not the envelope, not its data, not a summary', () => {
    const calls = FILES.flatMap((file) => [...code(file).matchAll(/console\.(\w+)\(([^\n]*)\)/g)].map((m) => ({ file: rel(file), how: m[1]!, args: m[2]! })))
    for (const { args } of calls) {
      const interpolated = [...args.matchAll(/\$\{([^}]+)\}/g)].map((m) => m[1]!.trim())
      expect(interpolated.filter((x) => !['env.q', 'env.t', 'event.t', 'Date.now()'].includes(x))).toEqual([])
      expect(args).not.toMatch(/JSON\.stringify|\benv\b(?!\.[qt]\b)|\bevent\b(?!\.t\b)|\.d\b|\.sum\b|\.o\b/)
    }
    // The one line the proof harness reads (§11.4), and only in a build that asks for it.
    expect(calls.filter((c) => c.how === 'log')).toEqual([{ file: 'live/client.ts', how: 'log', args: '`GSLIVE applied q=${env.q} t=${env.t} at=${Date.now()}`' }])
  })
})

describe('and when it runs', () => {
  beforeEach(() => {
    jest.useFakeTimers()
    mockShow.mockClear()
  })

  afterEach(() => {
    resetLive()
    jest.useRealTimers()
  })

  it('a whole session of events, alerts, a cancelled pass, a permission change, raises no notification', async () => {
    const h = await startHarness({ options: { onAccessChanged: () => undefined } })
    onLiveEvent('alert.*', () => undefined)
    onLiveEvent('pass.*', () => undefined)
    h.http.answerWith(() => ({ channels: [{ name: PASSES, status: 'ok', events: [env(1, 0, { t: 'alert.raised', e: 'alert' })], head: { e: 1 }, ep: { e: EP } }] }))
    await open(h)
    deliver(h, PASSES, env(2, 1, { t: 'alert.resolved', e: 'alert' }))
    deliver(h, PASSES, env(3, 2))
    deliver(h, PASSES, env(4, 3, { t: 'access.changed', e: 'principal', sum: { effect: 'reduced' } }))
    await settle()
    expect(mockShow).not.toHaveBeenCalled()
  })
})
