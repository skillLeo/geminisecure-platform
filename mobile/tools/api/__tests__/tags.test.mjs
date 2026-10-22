/**
 * The generated API's cache tags, and the generated list of live event types.
 *
 *   npm run test:api          (node --test "tools/api/__tests__/*.test.mjs"; part of npm run verify)
 *
 * Live updates refetch ONE record (DESIGN §8.4): a GET with a path parameter provides
 * [{ type: cap, id: String(<path parameter>) }, cap], a list provides [{ type: cap, id: 'LIST' }, cap], any
 * other GET (one record with no id in its path, or a feed) provides [{ type: cap, id: '<its name>' }, cap], and
 * every write still invalidates [cap], exactly as before, so no existing screen behaves differently.
 * liveTypes.ts is the server's list of the live event types it can emit, for the handler-type contract.
 *
 * The runtime half drives the COMMITTED generated modules (guardApi.ts, residentApi.ts, tags.ts,
 * endpoints.ts, liveTypes.ts) in a real RTK Query store. Only the network and the three runtime helpers
 * (write, pick/toFormData, enforceAllowlist) are stood in for, and none of them decides a tag, so what is
 * asserted about tags is what the apps ship. A first test proves the committed files are exactly what the
 * generator makes from the committed catalogue.
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { createRequire } from 'node:module'
import { tmpdir } from 'node:os'
import { fileURLToPath, pathToFileURL } from 'node:url'
import path from 'node:path'
import { generate, liveTypesOf, tagPlan } from '../generate.mjs'

const require = createRequire(import.meta.url)
const ts = require('typescript')
const { configureStore } = require('@reduxjs/toolkit')
const { createApi } = require('@reduxjs/toolkit/query')

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '..')
const GENERATED = path.join(ROOT, 'packages', 'core', 'api', 'generated')
const catalogue = JSON.parse(readFileSync(path.join(ROOT, '_design', 'api', 'catalogue.json'), 'utf8'))
const committed = (name) => readFileSync(path.join(GENERATED, name), 'utf8').replace(/\r\n/g, '\n')

/* ---- loading a generated TypeScript module into this process ---------------------------------------- */

const compiled = new Map()
function load(name, stubs = {}) {
  if (!compiled.has(name)) {
    const out = ts.transpileModule(committed(name), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
    compiled.set(name, new Function('require', 'module', 'exports', out.outputText))
  }
  const mod = { exports: {} }
  const req = (spec) => {
    if (Object.hasOwn(stubs, spec)) return stubs[spec]
    throw new Error(`${name} imports ${spec}, which this test does not stand in for`)
  }
  compiled.get(name)(req, mod, mod.exports)
  return mod.exports
}

const { ENDPOINTS } = load('endpoints.ts')
const camel = (n) => n.replace(/[._-]+([a-z0-9])/gi, (_, c) => c.toUpperCase())
const paramsOf = (n) => [...ENDPOINTS[n].path.matchAll(/\{(\w+)\}/g)].map((m) => m[1])
const inApp = (app) => Object.keys(ENDPOINTS).filter((n) => ENDPOINTS[n].apps.includes(app))
/** The tag the generator gave every endpoint BEFORE record tags: the capability, or enrolment/auth. */
const capabilityTag = (n, app) => (ENDPOINTS[n].ability ? ENDPOINTS[n].ability.split(':')[0] : app === 'guard' ? 'enrolment' : 'auth')
/** A path parameter of 1 wherever the path has one. */
const argFor = (n, value = 1) => (paramsOf(n).length ? Object.fromEntries(paramsOf(n).map((p) => [p, value])) : undefined)
const urlFor = (n, value = 1) => ENDPOINTS[n].path.replace(/\{\w+\}/g, String(value))

/** A real RTK Query store around one app's generated endpoints; every request is recorded as "METHOD /path". */
function harness(app) {
  const { TAG_TYPES } = load('tags.ts')
  const calls = []
  const baseApi = createApi({
    reducerPath: 'api',
    tagTypes: TAG_TYPES,
    baseQuery: async (args) => {
      calls.push(`${args.method ?? 'GET'} ${args.url}`)
      return { data: {} }
    },
    endpoints: () => ({}),
  })
  const api = load(`${app}Api.ts`, {
    '../baseApi': { baseApi },
    '../write': { write: (url, body, opts = {}) => ({ url, method: opts.method ?? 'POST', body }) },
    '../runtime': {
      pick: (a, names) => Object.fromEntries(names.filter((k) => a[k] !== undefined).map((k) => [k, a[k]])),
      toFormData: (fields) => fields,
      enforceAllowlist: (_name, data) => data,
    },
  })[`${app}Api`]
  const store = configureStore({
    reducer: { api: baseApi.reducer },
    middleware: (defaults) => defaults({ serializableCheck: false, immutableCheck: false }).concat(baseApi.middleware),
  })
  const settle = async () => {
    await new Promise((resolve) => setTimeout(resolve, 0))
    await Promise.all(store.dispatch(baseApi.util.getRunningQueriesThunk()))
    await Promise.all(store.dispatch(baseApi.util.getRunningMutationsThunk()))
  }
  return {
    baseApi, api, store, calls,
    /** Subscribe, as a mounted screen does, and wait for the first answer. */
    async cache(name, arg = argFor(name)) {
      await store.dispatch(api.endpoints[camel(name)].initiate(arg))
    },
    /** The GET requests `act` causes once every cached read has settled. */
    async refetchedBy(act) {
      await settle()
      calls.length = 0
      await act()
      await settle()
      return calls.filter((c) => c.startsWith('GET ')).sort()
    },
    /** Which cached reads a set of tags would invalidate — RTK's own selector, so it is exactly what a refetch would hit. */
    selected(tags) {
      return baseApi.util.selectInvalidatedBy(store.getState(), tags).map((q) => `${q.endpointName}(${JSON.stringify(q.originalArgs ?? null)})`).sort()
    },
  }
}

/* ---- the generated files are the catalogue, regenerated ------------------------------------------------ */

test('the committed generated files are exactly what the generator makes from the committed catalogue', () => {
  const { files } = generate(catalogue)
  assert.deepEqual(Object.keys(files).sort(), ['endpoints.ts', 'guardApi.ts', 'liveTypes.ts', 'residentApi.ts', 'tags.ts', 'types.ts'])
  for (const [name, content] of Object.entries(files)) assert.equal(committed(name), content, `${name} is stale or edited by hand: run npm run api:generate`)
})

/**
 * A copy of the generator and a catalogue in a scratch folder under the OS temp directory, with a link to it, so the
 * script can be run for real and the repo's own files are never rewritten. `real/packages` is where it writes.
 */
function withScratchGenerator(cat, fn) {
  const tmp = mkdtempSync(path.join(tmpdir(), 'gs-api-generate-'))
  try {
    const real = path.join(tmp, 'real')
    mkdirSync(path.join(real, 'tools', 'api'), { recursive: true })
    mkdirSync(path.join(real, '_design', 'api'), { recursive: true })
    copyFileSync(path.join(ROOT, 'tools', 'api', 'generate.mjs'), path.join(real, 'tools', 'api', 'generate.mjs'))
    writeFileSync(path.join(real, '_design', 'api', 'catalogue.json'), JSON.stringify(cat), 'utf8')
    const link = path.join(tmp, 'link')
    symlinkSync(real, link, 'junction') // a junction on Windows (no privilege needed), a directory symlink elsewhere
    const script = (root) => path.join(root, 'tools', 'api', 'generate.mjs')
    const run = (root) => spawnSync(process.execPath, [script(root)], { encoding: 'utf8' })
    return fn({ real, link, script, run, out: path.join(real, 'packages', 'core', 'api', 'generated'), written: () => existsSync(path.join(real, 'packages')), clear: () => rmSync(path.join(real, 'packages'), { recursive: true, force: true }) })
  } finally {
    rmSync(tmp, { recursive: true, force: true })
  }
}

// Node gives the entry script's import.meta.url its REAL path but leaves argv[1] as typed, so a script check that
// compared the two directly exited 0, printed nothing and wrote nothing when the repo was reached through a link.
test('run as a script, the generator writes every file, also through a junction or a symlink; imported, it writes nothing', () => {
  withScratchGenerator(catalogue, ({ real, link, script, run, out, written, clear }) => {
    const expected = generate(catalogue)
    for (const root of [link, real]) {
      clear()
      const ran = run(root)
      assert.equal(ran.status, 0, ran.stderr)
      assert.equal(ran.stdout.trim(), expected.summary, `run from ${root}`)
      assert.deepEqual(readdirSync(out).sort(), Object.keys(expected.files).sort(), `run from ${root}`)
      for (const [name, content] of Object.entries(expected.files)) assert.equal(readFileSync(path.join(out, name), 'utf8'), content, `${name}, run from ${root}`)
    }
    clear()
    const imported = spawnSync(process.execPath, ['--input-type=module', '-e', `await import(${JSON.stringify(pathToFileURL(script(link)).href)})`], { encoding: 'utf8' })
    assert.equal(imported.status, 0, imported.stderr)
    assert.equal(imported.stdout, '')
    assert.equal(written(), false)
  })
})

/* ---- how each read is tagged ---------------------------------------------------------------------------- */

// Every read at this base (42), classified by hand. A read the server adds later follows the rule (the
// fixtures below); one of these changing its tags is a behaviour change, and this table changes with it.
const RECORD = {
  'passes.keys': ['gate', 'site'],
  'orders.current': ['orders', 'site'],
  'patrol.checkpoints': ['patrol', 'site'],
  'gate.approvals.show': ['gate', 'approval'],
  'duress.show': ['alerts', 'alert'],
  'alerts.show': ['alerts', 'alert'],
  'payslips.pdf': ['payslips', 'payslip'],
  'households.balance': ['dues', 'household'],
  'households.statement': ['dues', 'household'],
  'payments.receipt': ['dues', 'payment'],
  // Two path parameters: the record is the notice the attachment hangs from, the id a notice event carries.
  'notices.attachment': ['notices', 'notice'],
  'elections.eligibility': ['elections', 'ballot'],
  'elections.results': ['elections', 'ballot'],
}
const LIST = [
  'standing_orders.index', 'shifts.me', 'shifts.open', 'gate.search', 'gate.activity', 'incidents.me', 'requests.index',
  'payslips.me', 'messages.index', 'estates.index', 'members.index', 'vehicles.index', 'contacts.index',
  'visitor_passes.index', 'invoices.index', 'payments.index', 'tickets.index', 'notices.index', 'meetings.index',
  'elections.index', 'amenities.index', 'bookings.index',
]
// One record with no id in its path (the guard's own shift, the resident's own household, the e-pass), or a
// feed (sync.pull): the read is its own record, and its record tag's id is its endpoint name.
const SINGLE = ['shifts.me.current', 'presence.summary', 'guards.me', 'sync.pull', 'me.show', 'me.household', 'epass.show']

const isGet = (n) => ENDPOINTS[n].method === 'GET'
/** The table's plan for a read it lists; the generator's rule for one the server added after this base. */
const planOf = (name, app) =>
  RECORD[name] ? { kind: 'record', type: RECORD[name][0], param: RECORD[name][1] }
    : LIST.includes(name) ? { kind: 'list', type: capabilityTag(name, app) }
      : SINGLE.includes(name) ? { kind: 'single', type: capabilityTag(name, app), id: name }
        : tagPlan(name, catalogue.endpoints[name], app)

test('the generator plans every read in the table as the table says: a record, a list, or a single read', () => {
  const listed = [...Object.keys(RECORD), ...LIST, ...SINGLE]
  assert.equal(new Set(listed).size, listed.length, 'a read is listed twice')
  for (const name of listed) {
    assert.ok(ENDPOINTS[name] && isGet(name), `${name} is not a read in the catalogue`)
    for (const app of ENDPOINTS[name].apps) assert.deepEqual(tagPlan(name, catalogue.endpoints[name], app), planOf(name, app), `${app}: ${name}`)
  }
  // A single read's id is its name, which can never be a list's id or a record's (a path parameter, a number or an estate key).
  for (const name of SINGLE) assert.match(name, /^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/, name)
  assert.ok(!SINGLE.includes(load('tags.ts').LIST_ID))
})

test('the rule, on endpoints the server does not have yet', () => {
  const get = (uri, response, extra = {}) => ({ method: 'GET', uri, capability: 'tickets', access: 'read', apps: ['resident'], response, ...extra })
  assert.deepEqual(tagPlan('tickets.show', get('tickets/{ticket}', { id: 'integer' }), 'resident'), { kind: 'record', type: 'tickets', param: 'ticket' })
  assert.deepEqual(tagPlan('tickets.photo', get('tickets/{ticket}/photos/{photo}', {}), 'resident'), { kind: 'record', type: 'tickets', param: 'ticket' })
  assert.deepEqual(tagPlan('tickets.mine', get('tickets/mine', { 'items.*.id': 'integer', next_before: 'string' }), 'resident'), { kind: 'list', type: 'tickets' })
  assert.deepEqual(tagPlan('tickets.index', get('tickets', { 'rows.*.id': 'integer' }), 'resident'), { kind: 'list', type: 'tickets' })
  // One record that happens to carry a collection is not a list (live.config will answer channels.*): it is a single read.
  assert.deepEqual(tagPlan('live.config', get('live/config', { 'ws.key': 'string', 'channels.*.name': 'string', pulse: 'integer' }), 'resident'), { kind: 'single', type: 'tickets', id: 'live.config' })
  assert.deepEqual(tagPlan('tickets.summary', get('tickets/summary', { open: 'integer' }), 'resident'), { kind: 'single', type: 'tickets', id: 'tickets.summary' })
  assert.deepEqual(tagPlan('estates.index', get('estates', { 'items.*.id': 'string' }, { capability: undefined }), 'guard'), { kind: 'list', type: 'enrolment' })
  assert.deepEqual(tagPlan('estates.index', get('estates', { 'items.*.id': 'string' }, { capability: undefined }), 'resident'), { kind: 'list', type: 'auth' })
  assert.deepEqual(tagPlan('tickets.store', { method: 'POST', uri: 'tickets', capability: 'tickets', write: true }, 'resident'), { kind: 'invalidates', type: 'tickets' })
  assert.deepEqual(tagPlan('tickets.store', { method: 'POST', uri: 'tickets/{ticket}/media', capability: 'tickets', write: true }, 'resident'), { kind: 'invalidates', type: 'tickets' })
  assert.deepEqual(tagPlan('passes.verify', { method: 'POST', uri: 'passes/verify', capability: 'gate', write: false }, 'guard'), { kind: 'none' })
})

/* ---- writes: no behaviour change ------------------------------------------------------------------------ */

test('every mutation is generated with the tags it had before: a write invalidates its capability, anything else nothing', () => {
  for (const app of ['guard', 'resident']) {
    const src = committed(`${app}Api.ts`)
    for (const name of inApp(app).filter((n) => ENDPOINTS[n].method !== 'GET')) {
      const at = src.indexOf(`    ${camel(name)}: build.mutation<`)
      assert.notEqual(at, -1, `${app}: ${name} is not generated as a mutation`)
      const block = src.slice(at, src.indexOf('\n    }),', at))
      assert.doesNotMatch(block, /providesTags/, `${app}: ${name}`)
      if (ENDPOINTS[name].write) assert.match(block, new RegExp(`\\n      invalidatesTags: \\['${capabilityTag(name, app)}'\\],$`), `${app}: ${name}`)
      else assert.doesNotMatch(block, /invalidatesTags/, `${app}: ${name}`)
    }
  }
})

test('every mutation, run, refetches exactly the cached reads of its capability (records, lists and the rest), as before', async () => {
  for (const app of ['guard', 'resident']) {
    const gets = inApp(app).filter((n) => ENDPOINTS[n].method === 'GET')
    for (const name of inApp(app).filter((n) => ENDPOINTS[n].method !== 'GET')) {
      const h = harness(app)
      for (const g of gets) await h.cache(g)
      const refetched = await h.refetchedBy(() => h.store.dispatch(h.api.endpoints[camel(name)].initiate(argFor(name))))
      const expected = ENDPOINTS[name].write ? gets.filter((g) => capabilityTag(g, app) === capabilityTag(name, app)).map((g) => `GET ${urlFor(g)}`).sort() : []
      assert.deepEqual(refetched, expected, `${app}: ${name}`)
    }
  }
})

test('invalidating a capability by name, as the guard poller does, still refetches its records and its lists', async () => {
  const h = harness('guard')
  await h.cache('duress.show', { alert: 5 })
  await h.cache('alerts.show', { alert: 6 })
  await h.cache('messages.index')
  await h.cache('shifts.me.current')
  assert.deepEqual(await h.refetchedBy(() => h.store.dispatch(h.baseApi.util.invalidateTags(['alerts', 'messages']))), ['GET /alerts/6', 'GET /duress/5', 'GET /messages'])
})

/* ---- record tags ---------------------------------------------------------------------------------------- */

test('a show endpoint provides its record tag: invalidating {type: alerts, id: 5} refetches duress.show(5) only', async () => {
  const h = harness('guard')
  await h.cache('duress.show', { alert: 5 })
  await h.cache('duress.show', { alert: 6 })
  await h.cache('alerts.show', { alert: 6 })
  await h.cache('gate.approvals.show', { approval: 5 })
  await h.cache('messages.index')
  assert.deepEqual(h.selected([{ type: 'alerts', id: '5' }]), ['duressShow({"alert":5})'])
  assert.deepEqual(await h.refetchedBy(() => h.store.dispatch(h.baseApi.util.invalidateTags([{ type: 'alerts', id: '5' }]))), ['GET /duress/5'])
})

test('the record id is the path parameter as a string, so a number and its digits name the same record', async () => {
  const h = harness('guard')
  await h.cache('duress.show', { alert: 5 })
  await h.cache('alerts.show', { alert: '5' })
  assert.deepEqual(h.selected([{ type: 'alerts', id: '5' }]), ['alertsShow({"alert":"5"})', 'duressShow({"alert":5})'])
  assert.deepEqual(h.selected([{ type: 'alerts', id: 5 }]), ['alertsShow({"alert":"5"})', 'duressShow({"alert":5})'])
  assert.deepEqual(h.selected([{ type: 'alerts', id: 'LIST' }]), [])
})

test('every read, in both apps, provides exactly the tags of its kind, and always the capability tag every write invalidates', async () => {
  const written = {
    record: (p) => `      providesTags: (_result, _error, arg) => { const a = (arg ?? {}) as Record<string, unknown>; return [{ type: '${p.type}', id: String(a.${p.param}) }, '${p.type}'] },`,
    list: (p) => `      providesTags: [{ type: '${p.type}', id: 'LIST' }, '${p.type}'],`,
    single: (p) => `      providesTags: [{ type: '${p.type}', id: '${p.id}' }, '${p.type}'],`,
  }
  for (const app of ['guard', 'resident']) {
    const src = committed(`${app}Api.ts`)
    for (const name of inApp(app).filter(isGet)) {
      const plan = planOf(name, app)
      assert.equal(plan.type, capabilityTag(name, app), `${app}: ${name}`)
      const at = src.indexOf(`    ${camel(name)}: build.query<`)
      assert.notEqual(at, -1, `${app}: ${name} is not generated as a query`)
      const block = src.slice(at, src.indexOf('\n    }),', at))
      assert.equal(block.slice(block.lastIndexOf('\n') + 1), written[plan.kind](plan), `${app}: ${name}`)
      assert.doesNotMatch(block, /invalidatesTags/, `${app}: ${name}`)
      const h = harness(app)
      const arg = argFor(name, 7)
      await h.cache(name, arg)
      const me = [`${camel(name)}(${JSON.stringify(arg ?? null)})`]
      assert.deepEqual(h.selected([plan.type]), me, `${app}: ${name}`)
      assert.deepEqual(h.selected([{ type: plan.type, id: '7' }]), plan.kind === 'record' ? me : [], `${app}: ${name}`)
      assert.deepEqual(h.selected([{ type: plan.type, id: '8' }]), [], `${app}: ${name}`)
      assert.deepEqual(h.selected([{ type: plan.type, id: 'LIST' }]), plan.kind === 'list' ? me : [], `${app}: ${name}`)
      assert.deepEqual(h.selected([{ type: plan.type, id: name }]), plan.kind === 'single' ? me : [], `${app}: ${name}`)
    }
  }
})

/* ---- single reads: one record with no id in its path -------------------------------------------------- */

// DESIGN §8.4: approval.* refetches me.household, claim.decided me.show, orders.* shifts.me.current; §5.2 and §5.5
// invalidate the mounted reads' record tags. Each of these reaches the one read, never its capability's lists.
test('a read with no id in its path is its own record: {type, id: <its name>} refetches that read alone, not the lists beside it', async () => {
  const r = harness('resident')
  for (const name of ['me.household', 'me.show', 'members.index', 'vehicles.index', 'contacts.index', 'epass.show', 'visitor_passes.index']) await r.cache(name)
  await r.cache('visitor_passes.index', { status: 'active' })
  const resident = (tag) => r.refetchedBy(() => r.store.dispatch(r.baseApi.util.invalidateTags([tag])))
  assert.deepEqual(await resident({ type: 'household', id: 'me.household' }), ['GET /me/household'])
  assert.deepEqual(await resident({ type: 'household', id: 'me.show' }), ['GET /me'])
  assert.deepEqual(await resident({ type: 'passes', id: 'epass.show' }), ['GET /e-pass'])

  const g = harness('guard')
  for (const name of ['shifts.me.current', 'guards.me', 'shifts.me', 'shifts.open', 'presence.summary']) await g.cache(name)
  await g.cache('sync.pull', { since: '2026-09-25T08:00:00Z' })
  await g.cache('sync.pull', { since: '2026-09-25T09:00:00Z' })
  await g.cache('orders.current', { site: 3 })
  const guard = (tag) => g.refetchedBy(() => g.store.dispatch(g.baseApi.util.invalidateTags([tag])))
  assert.deepEqual(await guard({ type: 'shifts', id: 'shifts.me.current' }), ['GET /shifts/me/current'])
  assert.deepEqual(await guard({ type: 'shifts', id: 'guards.me' }), ['GET /guards/me'])
  assert.deepEqual(await guard({ type: 'presence', id: 'presence.summary' }), ['GET /guards/me/activity-summary'])
  // A feed is one record whatever its query: every cached `since` is refetched, and nothing else.
  assert.deepEqual(await guard({ type: 'sync', id: 'sync.pull' }), ['GET /sync/pull', 'GET /sync/pull'])

  // The capability still reaches them with everything else under it, as every write does.
  assert.deepEqual(await resident('household'), ['GET /emergency-contacts', 'GET /household-members', 'GET /me', 'GET /me/household', 'GET /vehicles'])
  assert.deepEqual(await guard('shifts'), ['GET /guards/me', 'GET /shifts/me', 'GET /shifts/me/current', 'GET /shifts/open'])
})

/** The reads a tags.ts comment lists under the line ending `heading`, as "name ('type')" pairs. */
function listedIn(src, heading) {
  const lines = src.split('\n')
  const at = lines.findIndex((l) => l.trimEnd().endsWith(heading))
  assert.notEqual(at, -1, `tags.ts has no line ending "${heading}"`)
  const block = []
  for (const line of lines.slice(at + 1)) {
    if (!line.startsWith(' *     ')) break
    block.push(line)
  }
  return [...block.join(' ').matchAll(/([a-z][a-z0-9_.]*) \('([a-z]+)'\)/g)].map((m) => `${m[1]} ${m[2]}`).sort()
}

test('tags.ts lists every single read and the capability it is under, so a handler knows the id to invalidate', () => {
  const src = committed('tags.ts')
  // Every single read the catalogue has today: the table's, and those the server added since, planned by the rule.
  const singles = Object.keys(ENDPOINTS).filter((n) => isGet(n) && planOf(n, ENDPOINTS[n].apps[0]).kind === 'single')
  for (const name of SINGLE) assert.ok(singles.includes(name), name)
  const expected = singles.map((n) => `${n} ${capabilityTag(n, ENDPOINTS[n].apps[0])}`).sort()
  assert.deepEqual(listedIn(src, 'These are:'), expected)
})

// A record's id is its path parameter. For these the parameter is the site, while a live event names the order
// set, checkpoint or key it is about (envelope `i`): a handler that invalidated `i` would miss the read.
test('tags.ts names every read keyed by the site, so a handler invalidates the site and not the event\'s own id', () => {
  const src = committed('tags.ts')
  const bySite = Object.entries(RECORD).filter(([, [, param]]) => param === 'site').map(([name, [type]]) => `${name} ${type}`).sort()
  assert.deepEqual(bySite, ['orders.current orders', 'passes.keys gate', 'patrol.checkpoints patrol'])
  assert.deepEqual(listedIn(src, 'Keyed by the site:'), bySite)
  assert.match(src, /\{ type, id: String\(siteId\) \}/)
})

/* ---- list tags ------------------------------------------------------------------------------------------ */

test('invalidating {type: passes, id: LIST} refetches visitor_passes.index for every cached argument, and not the e-pass', async () => {
  assert.equal(load('tags.ts').LIST_ID, 'LIST')
  const h = harness('resident')
  await h.cache('visitor_passes.index', undefined)
  await h.cache('visitor_passes.index', {})
  await h.cache('visitor_passes.index', { status: 'active' })
  await h.cache('epass.show')
  assert.deepEqual(await h.refetchedBy(() => h.store.dispatch(h.baseApi.util.invalidateTags([{ type: 'passes', id: 'LIST' }]))), ['GET /visitor-passes', 'GET /visitor-passes', 'GET /visitor-passes'])
  assert.deepEqual(h.calls.filter((c) => c === 'GET /e-pass'), [])
})

/* ---- the live types list -------------------------------------------------------------------------------- */

const moduleOf = (source) => {
  const out = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
  const mod = { exports: {} }
  new Function('require', 'module', 'exports', out.outputText)((spec) => { throw new Error(`liveTypes.ts imports nothing, yet asked for ${spec}`) }, mod, mod.exports)
  return mod.exports
}

/*
 * A catalogue from before the live layer: no live endpoints and no live_types. The committed catalogue has both
 * since M11 Part C, so the "none yet" cases are held against this, and the committed files against the real list.
 */
const preLive = (() => {
  const { live_types: _drop, ...rest } = catalogue
  return { ...rest, endpoints: Object.fromEntries(Object.entries(catalogue.endpoints).filter(([n]) => !n.startsWith('live.'))) }
})()

test('with no live_types in the catalogue the list is empty, and the committed liveTypes.ts is the server\'s list', () => {
  assert.deepEqual(liveTypesOf(preLive), [])
  assert.match(generate(preLive).files['liveTypes.ts'], /\nexport type LiveType = never\n/)
  // The committed file is exactly the committed catalogue's list — pulled and generated together.
  assert.ok(Array.isArray(catalogue.live_types) && catalogue.live_types.length > 0, 'the committed catalogue carries live_types')
  const { LIVE_TYPES, isLiveType } = load('liveTypes.ts')
  assert.deepEqual([...LIVE_TYPES], liveTypesOf(catalogue))
  assert.equal(committed('liveTypes.ts'), generate(catalogue).files['liveTypes.ts'].replace(/\r\n/g, '\n'))
  assert.equal(isLiveType('alert.site_alert'), false)
})

test('the server list is written sorted and once each, and isLiveType admits exactly those types', () => {
  const cat = { ...catalogue, live_types: ['pass.cancelled', 'alert.raised', 'device.rebind_requested', 'alert.raised', 'access.changed'] }
  const expected = ['access.changed', 'alert.raised', 'device.rebind_requested', 'pass.cancelled']
  assert.deepEqual(liveTypesOf(cat), expected)
  const source = generate(cat).files['liveTypes.ts']
  assert.match(source, /\nexport type LiveType =\n {2}\| 'access\.changed'\n {2}\| 'alert\.raised'\n {2}\| 'device\.rebind_requested'\n {2}\| 'pass\.cancelled'\n\n/)
  assert.match(source, /\n \* 4 types\.\n/)
  assert.match(generate({ ...catalogue, live_types: ['alert.raised'] }).files['liveTypes.ts'], /\n \* 1 type\.\n/)
  const { LIVE_TYPES, isLiveType } = moduleOf(source)
  assert.deepEqual(LIVE_TYPES, expected)
  for (const type of expected) assert.equal(isLiveType(type), true, type)
  // The type no emitter produces, which this list exists to catch (DESIGN §8.4).
  assert.equal(isLiveType('alert.site_alert'), false)
  assert.equal(isLiveType('ALERT.RAISED'), false)
  // Nothing else in the generated layer depends on the list.
  const plain = generate(catalogue).files
  const withList = generate(cat).files
  for (const name of ['endpoints.ts', 'types.ts', 'guardApi.ts', 'residentApi.ts', 'tags.ts']) assert.equal(withList[name], plain[name], name)
})

test('a malformed live_types list stops the generator instead of being written into source', () => {
  for (const bad of [null, {}, 'alert.raised', [1], [null], [['alert.raised']], ['Alert.Raised'], ['alert'], ['alert.'], ['.raised'], ['alert..raised'], ['1alert.raised'], ["alert.raised']; evil(); ['"], ['alert.raised\n'], ['alert raised'], ['alert-raised.x']]) {
    assert.throws(() => generate({ ...catalogue, live_types: bad }), /live_types/, JSON.stringify(bad))
  }
})

// The server's live endpoints (live.config, live.auth, then live.catch_up) and its live_types come from the same
// server. A catalogue with the endpoints and without the list is a pull that dropped it (pull-catalogue.ps1 writes
// only `endpoints` and `common_errors` today): an empty liveTypes.ts would then fail every live handler's contract
// test as "type not in list", which names the wrong cause. So it stops the generator, and says why.
const liveConfig = { method: 'GET', uri: 'live/config', capability: 'alerts', access: 'read', apps: ['guard', 'resident'], write: false, status: 200, summary: 'Where the phone opens its live socket, and its channels.', offline: 'Not cached.', request: [], response: { 'ws.key': 'the socket key', 'channels.*.name': 'a channel name' }, errors: {} }
const withLive = (extra = {}) => ({ ...preLive, endpoints: { ...preLive.endpoints, 'live.config': liveConfig }, ...extra })

test('a catalogue with live endpoints and no live_types stops the generator, naming the pull, and writes nothing', () => {
  assert.equal(Object.keys(preLive.endpoints).some((n) => n.startsWith('live.')), false)
  assert.equal(preLive.live_types, undefined)
  const refusal = /live_types is missing from the catalogue, yet the server has live endpoints \(live\.config\)[^]*pull-catalogue\.ps1/
  assert.throws(() => liveTypesOf(withLive()), refusal)
  assert.throws(() => generate(withLive()), refusal)
  withScratchGenerator(withLive(), ({ link, run, written }) => {
    const ran = run(link)
    assert.notEqual(ran.status, 0)
    assert.match(ran.stderr, refusal)
    assert.equal(ran.stdout, '')
    assert.equal(written(), false)
  })
})

test('with live endpoints, the list the server exports is written, and an empty list is the server saying none', () => {
  assert.deepEqual(liveTypesOf(withLive({ live_types: [] })), [])
  assert.match(generate(withLive({ live_types: [] })).files['liveTypes.ts'], /\nexport type LiveType = never\n/)
  assert.deepEqual(liveTypesOf(withLive({ live_types: ['pass.cancelled', 'access.changed'] })), ['access.changed', 'pass.cancelled'])
  assert.match(generate(withLive({ live_types: ['access.changed'] })).files['liveTypes.ts'], /\nexport const LIVE_TYPES: readonly LiveType\[\] = \[\n {2}'access\.changed',\n\]\n/)
})
