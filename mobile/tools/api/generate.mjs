/**
 * THE API LAYER IS GENERATED FROM THE SERVER'S OWN CATALOGUE — never transcribed.
 *
 * `App\Api\Catalogue` is the one list the Laravel routes are registered from and the
 * one MOBILE_HANDOFF.md is written from. `npm run api:pull` dumps it to
 * _design/api/catalogue.json (read-only on the Laravel side); this script turns that
 * into, under packages/core/api/generated/:
 *
 *   endpoints.ts    every endpoint's method, path, ability, write/legacy/opaque flags,
 *                   offline behaviour and error codes — plus RESPONSE_KEYS, the exact
 *                   key paths each response may contain (the allowlist, invariant 3)
 *   types.ts        request and response types inferred from the catalogue's tables
 *   guardApi.ts     RTK Query endpoints + hooks for the Guard App (41)
 *   residentApi.ts  RTK Query endpoints + hooks for the Resident App (43 + shared)
 *   tags.ts         the cache tags: one per server capability
 *   liveTypes.ts    every live event type the server can emit (the catalogue's `live_types`)
 *
 * Rules the generator applies to EVERY endpoint, so no screen can forget one:
 *   - a write goes through write(): Idempotency-Key + X-Device-Time fixed once per act
 *   - sign-in, enrolment and the ballot are never retried (maxRetries 0)
 *   - the ballot is `opaque`: nothing about its body may be logged or hashed
 *   - every GUARD response passes enforceAllowlist(): a key the catalogue does not list
 *     is dropped before a screen can render it, and reported loudly in development
 *   - every read and write is tagged by tagPlan() below
 *
 * Leaf types are INFERRED from descriptions ("boolean", "decimal string", "ISO 8601",
 * "or null", `a`, `b` or `c`). A wrong inference costs a cast, never a runtime fault.
 *
 * `generate(catalogue)` is pure (tools/api/__tests__ calls it); run as a script, it reads the
 * catalogue and writes the files.
 */

import { readFileSync, writeFileSync, mkdirSync, realpathSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const OUT = path.join(ROOT, 'packages', 'core', 'api', 'generated')

const asMap = (v) => (v && !Array.isArray(v) ? v : {})
const camel = (name) => name.replace(/[._-]+([a-z0-9])/gi, (_, c) => c.toUpperCase())
const pascal = (name) => { const c = camel(name); return c[0].toUpperCase() + c.slice(1) }
// Never resent by the app on its own: sign-in, enrolment, the ballot — and anything that speaks of money. Today's
// payment routes cannot charge anyone (dues are paid by hand), but the day one can, it is already sent exactly once.
// Alerts are sent once by the alert engine itself; first-come claims and bookings get one try and an honest answer.
// A supervisor's PIN (shifts.clock_in_override) is typed once and sent once: online only, never queued, never resent.
const NO_RETRY = (name) => /^(auth\.|devices\.enrol|autopay\.|push\.)/.test(name) || [
  'elections.ballot', 'invoices.pay_intent', 'shifts.claim', 'shifts.clock_in_override', 'bookings.store', 'bookings.cancel', 'approvals.respond', 'alertness.respond',
  // A household act a person pressed and waits on (household invites, stage 2): one try, and an honest answer.
  'members.invite', 'members.invite_cancel', 'members.level', 'members.destroy', 'contacts.destroy', 'vehicles.destroy',
  // A helper's days and hours, and removing a pet (8 Oct 2026, M50 §5.3): the same — pressed, waited on, answered once.
  'members.access', 'pets.destroy',
].includes(name)

function appsOf(name, e) {
  if (e.apps && e.apps.length) return e.apps
  // A tokenless endpoint with no app named is the Guard App's when it is a handset's own (enrolment, and the
  // call-style ring's answer and receipt, which carry a ring ticket instead of a token); otherwise the Resident App's.
  return /^(devices|ring)\./.test(name) ? ['guard'] : ['resident']
}

/* ---- leaf type inference ------------------------------------------------------------ */

function leafType(key, desc) {
  const d = String(desc)
  const last = key.split('.').pop()
  let t
  const enumTokens = [...d.matchAll(/`([a-z0-9_:-]+)`/gi)].map((m) => m[1])
  const enumOnly = enumTokens.length >= 2 && /^\s*`/.test(d) && d.replace(/`[a-z0-9_:-]+`/gi, '').replace(/[,\s]|or|and/g, '').replace(/\(.*\)/, '').length < 24
  // "whether THIS handset may record …" (`can_en_route`) is a yes or no; read as a string, a strict `=== true` on it would not compile.
  if (/^(boolean|true\b|whether\b)/i.test(d) || /^(true|false) (when|while|if)/i.test(d)) t = 'boolean'
  else if (/decimal string/i.test(d)) t = 'string'
  else if (enumOnly) t = enumTokens.map((x) => `'${x}'`).join(' | ')
  else if (last === 'pass_id') t = 'string'
  // An estate's id is its key ("phoenixpark"); every other id is an integer. Both are only ever passed back, never computed on.
  else if (last === 'id' || /_id$/.test(last)) t = 'number | string'
  // "degrees north" / "degrees east": a coordinate is a number and is put on a map as one — never a string to parse.
  else if (/^(integer|how many|the number of|number of|a count|count of|minutes|metres|meters|seconds|degrees|hours worked|0.?100)/i.test(d) || /(_count|_minutes|_seconds|_m|_total|_hours)$/.test(last)) t = 'number'
  else t = 'string'
  if (/\bor null\b|^null (when|while|if)|, or null|null until/i.test(d)) t += ' | null'
  return t
}

/** dotted keys ("items.*.unit") → a nested TypeScript type literal */
function typeFromKeys(response) {
  const root = { kids: new Map() }
  for (const [key, desc] of Object.entries(response)) {
    let node = root
    const parts = key.split('.')
    parts.forEach((p, i) => {
      if (!node.kids.has(p)) node.kids.set(p, { kids: new Map() })
      node = node.kids.get(p)
      if (i === parts.length - 1) { node.desc = desc; node.key = key }
    })
  }
  function render(node, indent) {
    if (node.kids.size === 0) return leafType(node.key, node.desc)
    if (node.kids.has('*')) {
      const inner = render(node.kids.get('*'), indent)
      return /[|&]/.test(inner) && !inner.trim().startsWith('{') ? `Array<${inner}>` : `${inner}[]`
    }
    const pad = '  '.repeat(indent + 1)
    const lines = [...node.kids.entries()].map(([k, child]) => {
      // "only when" marks a key that is SOMETIMES MISSING ("present only when…"). "true only when a provider is connected" and
      // "draw the button only when true" describe a VALUE, on a key that is always there, and do not make it optional.
      const optional = child.desc && /\babsent\b|(?<!\btrue )only when(?! (true|false)\b)|while pending:|present only/i.test(child.desc) ? '?' : ''
      const name = /^[a-zA-Z_$][\w$]*$/.test(k) ? k : `'${k}'`
      const doc = child.desc ? `${pad}/** ${String(child.desc).replace(/\*\//g, '* /')} */\n` : ''
      return `${doc}${pad}${name}${optional}: ${render(child, indent + 1)}`
    })
    let t = `{\n${lines.join('\n')}\n${'  '.repeat(indent)}}`
    if (node.desc && /\bnull\b/i.test(node.desc)) t += ' | null'
    return t
  }
  return root.kids.size ? render(root, 0) : 'Record<string, never>'
}

function requestField(desc) {
  const parts = String(desc).split('·').map((s) => s.trim())
  const raw = parts[0].toLowerCase()
  const rest = parts.slice(1).join(' · ')
  let t = 'string'
  let array = false
  if (raw === 'integer' || raw === 'number') t = 'number'
  else if (raw === 'boolean') t = 'boolean'
  else if (raw === 'array of integers') t = 'number[]'
  // "array of strings · … `a`, `b`, `c`" is a LIST of those values, not one of them: the enum below
  // describes the ELEMENT, and `array` says so (`muted` on push.register was typed as a single value).
  else if (raw === 'array of strings') { t = 'string'; array = true }
  else if (raw === 'array') t = 'unknown[]'
  else if (raw === 'object') t = 'Record<string, unknown>'
  else if (raw === 'file') t = 'UploadFile'
  // The values are the field's OWN list — the run of `a`, `b` or `c` before the first " — " — and not every
  // backticked word in the prose after it: `type` on duress.store goes on to name the alert KINDS it maps to
  // (`panic`, `duress`), and a phone allowed to send one of those would be refused by the server. With fewer
  // than two values before the dash, the whole description is read, as it always was.
  const tokensIn = (text) => [...new Set([...text.matchAll(/`([a-z0-9_:-]+)`/gi)].map((m) => m[1]))]
  const listed = tokensIn(rest.split(/\s[—–]\s/)[0] ?? '')
  const enumTokens = listed.length >= 2 ? listed : tokensIn(rest)
  if (t === 'string' && enumTokens.length >= 2 && !/e\.g\./i.test(rest)) t = enumTokens.map((x) => `'${x}'`).join(' | ')
  // A list whose values are open-ended ("anything else is dropped") stays `string[]`: a phone built against
  // today's catalogue must still compile when the server learns a new category.
  if (array) t = /anything else|and any other|among others/i.test(rest) ? 'string[]' : `(${t})[]`
  const required = /\brequired\b/i.test(parts[1] ?? '') && !/required (without|with|when|if|unless)/i.test(parts[1] ?? '')
  return { t, required, query: /\(query\)/i.test(rest), file: raw === 'file', doc: rest }
}

/* ---- cache tags ---------------------------------------------------------------------------- */

/**
 * How one endpoint uses the cache tags, in one app. The tag's type is the endpoint's capability, or
 * `enrolment` / `auth` for the few that need no token. EVERY read provides it, so invalidating a capability
 * ('alerts') still refetches everything under it, as every write always has. A read also says which data it
 * holds, so a live event can refetch ONE record and nothing else (DESIGN §8.4):
 *
 *   record       a GET with a path parameter: [{ type, id: String(arg.<param>) }, type]. With two parameters
 *                the first is the record the rest hangs from: notices/{notice}/attachments/{attachment} is
 *                notice {notice}'s, and an event about that notice carries the notice's id. The id is the
 *                parameter, which is not always the entity an event names: a per-site read (orders.current,
 *                patrol.checkpoints, passes.keys) is keyed by {site}, and tags.ts lists every such read.
 *   list         a GET with no path parameter that answers rows (`items.*`, the catalogue's list shape, or an
 *                `.index` endpoint): [{ type, id: 'LIST' }, type].
 *   single       any other GET: one record with no id in its path, because the token already names it (the
 *                guard's own shift, the resident's own household, the e-pass), or a feed (sync.pull). It is its
 *                own record, named by its endpoint: [{ type, id: '<name>' }, type], so { type: 'household',
 *                id: 'me.household' } refetches the household and not the member, vehicle or contact lists
 *                under the same capability. Every cached argument of it shares that one tag (sync.pull's `since`).
 *   invalidates  a write: invalidatesTags [type], unchanged, so no existing screen refetches any differently.
 *   none         a POST that changes nothing (a verification, a sign-in): no tags, as before.
 *
 * A single read's id is a dotted endpoint name, so it can never be 'LIST' or a record's id (a path parameter:
 * a number, or an estate key).
 *
 * One capability can hold more than one kind of record (`dues` holds households and payments, `gate` holds
 * sites and approvals), so { type: 'dues', id: '5' } refetches household 5's reads AND payment 5's receipt.
 * That is one extra read at worst, never a missed one.
 */
export function tagPlan(name, e, app) {
  const type = e.capability ?? (app === 'guard' ? 'enrolment' : 'auth')
  if (e.method !== 'GET') return e.write ? { kind: 'invalidates', type } : { kind: 'none' }
  const params = [...e.uri.matchAll(/\{(\w+)\}/g)].map((m) => m[1])
  if (params.length) return { kind: 'record', type, param: params[0] }
  const rows = Object.keys(asMap(e.response)).some((k) => /^items\.\*(\.|$)/.test(k))
  if (rows || name.endsWith('.index')) return { kind: 'list', type }
  return { kind: 'single', type, id: name }
}

function tagsSrc(plan) {
  const t = `'${plan.type}'`
  // The same guard as the query's own (`arg ?? {}`): a tag is computed in the reducer, where a throw would break the store.
  if (plan.kind === 'record') return `providesTags: (_result, _error, arg) => { const a = (arg ?? {}) as Record<string, unknown>; return [{ type: ${t}, id: String(a.${plan.param}) }, ${t}] },`
  if (plan.kind === 'list') return `providesTags: [{ type: ${t}, id: 'LIST' }, ${t}],`
  if (plan.kind === 'single') return `providesTags: [{ type: ${t}, id: '${plan.id}' }, ${t}],`
  if (plan.kind === 'invalidates') return `invalidatesTags: [${t}],`
  return ''
}

/* ---- live event types ---------------------------------------------------------------------- */

// Dotted lower-case words, as the server names its events: `alert.raised`, `device.rebind_requested`.
const LIVE_TYPE = /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/

/**
 * The live event types the server can emit: the catalogue's `live_types`, which the server builds from its
 * event classes (App\Live\Registry). Absent from a server with no live layer, which is an empty list. Sorted and
 * each once, so the file changes only when the list does. A malformed list STOPS the generator: each type is
 * written into source, and a contract test is only as good as the list it checks against.
 *
 * A catalogue with live endpoints (live.*) and no list also stops it. The list comes from the same server as
 * those endpoints, so its absence means the pull dropped it (tools/api/pull-catalogue.ps1 must write it beside
 * `endpoints`), or the server's live layer is half-built. An empty liveTypes.ts would then fail every handler's
 * contract test as "type not in list", which names the wrong cause. A server that emits nothing sends [].
 */
export function liveTypesOf(cat) {
  const list = cat.live_types
  if (list === undefined) {
    const live = Object.keys(asMap(cat.endpoints)).filter((name) => name.startsWith('live.')).sort()
    if (live.length) {
      throw new Error(`live_types is missing from the catalogue, yet the server has live endpoints (${live.join(', ')}). The server exports its live event types with its catalogue, and npm run api:pull (tools/api/pull-catalogue.ps1) must write them to catalogue.json as live_types, beside endpoints. Without them liveTypes.ts would be empty and every live handler would fail its contract test for the wrong reason, so nothing was written.`)
    }
    return []
  }
  if (!Array.isArray(list)) throw new Error(`live_types must be a list of event types, not ${JSON.stringify(list)}`)
  for (const type of list) {
    if (typeof type !== 'string' || !LIVE_TYPE.test(type)) throw new Error(`live_types holds ${JSON.stringify(type)}, which is not an event type such as 'alert.raised'`)
  }
  return [...new Set(list)].sort()
}

function liveTypesSrc(types) {
  const union = types.length ? `\n${types.map((t) => `  | '${t}'`).join('\n')}` : ' never'
  const list = types.length ? `[\n${types.map((t) => `  '${t}',`).join('\n')}\n]` : '[]'
  return `// GENERATED by tools/api/generate.mjs from the server's catalogue — do not edit.
/* eslint-disable */

/**
 * Every live event type the server can emit: the catalogue's \`live_types\`, which the server builds from its
 * event classes (App\\Live\\Registry). A handler registered for a type that is not listed here can never run,
 * because nothing sends it, so a test holds every handler to this list (the handler-type contract, DESIGN §8.4).
 * ${types.length ? `${types.length} type${types.length === 1 ? '' : 's'}.` : 'EMPTY until the server exports the list: no type is known yet.'}
 */
export type LiveType =${union}

export const LIVE_TYPES: readonly LiveType[] = ${list}

/** Whether the server can emit \`type\` at all. */
export function isLiveType(type: string): type is LiveType {
  return (LIVE_TYPES as readonly string[]).includes(type)
}
`
}

/* ---- <app>Api.ts -------------------------------------------------------------------------- */

function endpointSrc(x, app) {
  const pathExpr = '`/' + x.e.uri.replace(/\{(\w+)\}/g, (_, p) => '${a.' + p + '}') + '`'
  const bodyFields = x.fields.filter((f) => !f.query).map((f) => f.name)
  const isGet = x.e.method === 'GET'
  const hasArgs = x.pathParams.length + x.fields.length > 0
  const argName = hasArgs ? 'arg' : '_arg'
  const pick = (names) => `pick(a, [${names.map((n) => `'${n}'`).join(', ')}])`
  const extra = NO_RETRY(x.name) ? `\n      extraOptions: { maxRetries: 0 },` : ''
  const guardScoped = app === 'guard'
  const transform = guardScoped ? `\n      transformResponse: (data: unknown) => enforceAllowlist('${x.name}', data) as T.${x.Type}Response,` : ''
  let query
  if (isGet) {
    const q = x.fields.map((f) => f.name)
    query = `(${argName}) => { const a = (${argName} ?? {}) as Record<string, unknown>; return { url: ${pathExpr}${q.length ? `, params: ${pick(q)}` : ''} } }`
  } else if (x.multipart) {
    query = `(${argName}) => { const a = (${argName} ?? {}) as Record<string, unknown>; return write(${pathExpr}, toFormData(${pick(bodyFields)}), { method: '${x.e.method}', stamp: a.__stamp as WriteStamp | undefined }) }`
  } else if (x.e.write) {
    // `__stamp` is how the offline outbox replays an act with the Idempotency-Key and device time it was CAPTURED with.
    query = `(${argName}) => { const a = (${argName} ?? {}) as Record<string, unknown>; return write(${pathExpr}, ${bodyFields.length ? pick(bodyFields) : 'undefined'}, { method: '${x.e.method}', stamp: a.__stamp as WriteStamp | undefined }) }`
  } else {
    query = `(${argName}) => { const a = (${argName} ?? {}) as Record<string, unknown>; return { url: ${pathExpr}, method: '${x.e.method}'${bodyFields.length ? `, body: ${pick(bodyFields)}` : ''} } }`
  }
  const kind = isGet ? 'query' : 'mutation'
  const tags = tagsSrc(tagPlan(x.name, x.e, app))
  const doc = `    /**\n     * ${x.e.method} /${x.e.uri} · ${x.ability ?? 'no token'}${x.e.write ? ' · write' : ''}${x.e.opaque ? ' · OPAQUE (never log or hash the body)' : ''}\n     * ${String(x.e.summary).replace(/\*\//g, '* /')}\n     * Offline: ${String(x.e.offline ?? '').replace(/\*\//g, '* /')}\n     */`
  return `${doc}\n    ${x.fn}: build.${kind}<T.${x.Type}Response, T.${x.Type}Request>({\n      query: ${query},${transform}${extra}\n      ${tags}\n    }),`
}

/* ---- the whole generated layer, from one catalogue ------------------------------------------ */

/** Every generated file's content, by file name, from a catalogue as `npm run api:pull` writes it. */
export function generate(cat) {
  const liveTypes = liveTypesOf(cat)
  const stripped = []

  const entries = Object.entries(cat.endpoints).map(([name, e]) => {
    const pathParams = [...e.uri.matchAll(/\{(\w+)\}/g)].map((m) => m[1])
    // INVARIANT 1. Two legacy endpoints still ACCEPT tenant_id / guard_id in the body (kept for the server's
    // simulators; a disagreeing value is 403 wrong_site). The apps must never send them — the estate and the
    // principal come from the token — so they are removed from the request types and cannot be passed at all.
    const PRINCIPAL = /^(tenant|tenant_id|estate_id|guard_id|account_id|user_id|resident_id)$/
    const all = Object.entries(asMap(e.request)).map(([k, v]) => ({ name: k, ...requestField(v) }))
    const fields = e.capability ? all.filter((f) => !PRINCIPAL.test(f.name)) : all
    for (const f of all) if (!fields.includes(f)) stripped.push(`${name}: ${f.name}`)
    return {
      name, e, apps: appsOf(name, e), pathParams, fields,
      fn: camel(name), Type: pascal(name),
      ability: e.capability ? `${e.capability}:${e.access}` : null,
      errors: Object.keys(asMap(e.errors)).map((k) => k.split(' ')[1]),
      multipart: fields.some((f) => f.file),
    }
  })

  const files = {}

  /* ---- endpoints.ts ---------------------------------------------------------------------------------- */

  const meta = entries.map((x) => `  '${x.name}': { method: '${x.e.method}', path: '/${x.e.uri}', apps: [${x.apps.map((a) => `'${a}'`).join(', ')}], ability: ${x.ability ? `'${x.ability}'` : 'null'}, write: ${!!x.e.write}, legacy: ${!!x.e.legacy}, opaque: ${!!x.e.opaque}, batch: ${!!x.e.write && x.e.batch !== false}, status: ${x.e.status}, errors: [${x.errors.map((c) => `'${c}'`).join(', ')}], offline: ${JSON.stringify(x.e.offline ?? '')} },`).join('\n')
  const keys = entries.map((x) => `  '${x.name}': [${Object.keys(asMap(x.e.response)).map((k) => `'${k}'`).join(', ')}],`).join('\n')

  files['endpoints.ts'] = `// GENERATED by tools/api/generate.mjs from the server's catalogue — do not edit.
/* eslint-disable */

export type EndpointName = keyof typeof ENDPOINTS

export const ENDPOINTS = {
${meta}
} as const

/**
 * The exact key paths each response may contain ("items.*.unit"). This is the ALLOWLIST:
 * a guard-scoped response is held to it on every call (enforceAllowlist), so a field the
 * catalogue does not list — a balance, a bucket, a reason — can never reach a guard screen.
 */
export const RESPONSE_KEYS: Record<EndpointName, readonly string[]> = {
${keys}
}

/** Every field each endpoint accepts (path params excluded) — what the tenant-isolation test reads. */
export const REQUEST_FIELDS: Record<EndpointName, readonly string[]> = {
${entries.map((x) => `  '${x.name}': [${x.fields.map((f) => `'${f.name}'`).join(', ')}],`).join('\n')}
}

/** Principal-naming fields the server still accepts on legacy endpoints and the apps are NOT ALLOWED to send. */
export const STRIPPED_PRINCIPAL_FIELDS = ${JSON.stringify(stripped)} as const

/** Errors any endpoint can return, from Catalogue::COMMON_ERRORS. */
export const COMMON_ERRORS = ${JSON.stringify(Object.keys(asMap(cat.common_errors)).map((k) => k.split(' ')[1]))} as const
`

  /* ---- types.ts -------------------------------------------------------------------------------------- */

  const typeBlocks = entries.map((x) => {
    const argLines = [
      ...x.pathParams.map((p) => `  /** path: {${p}} */\n  ${p}: number | string`),
      ...x.fields.map((f) => `  /** ${f.doc.replace(/\*\//g, '* /')} */\n  ${/^[a-zA-Z_$][\w$]*$/.test(f.name) ? f.name : `'${f.name}'`}${f.required ? '' : '?'}: ${f.t}`),
    ]
    if (x.e.write) argLines.push(`  /** Outbox replays only: the Idempotency-Key and device time the act was captured with. */\n  __stamp?: import('../write').WriteStamp`)
    const hasArgs = argLines.length > 0
    const allOptional = x.pathParams.length === 0 && x.fields.every((f) => !f.required)
    return `/** ${x.e.method} /${x.e.uri} — ${String(x.e.summary).replace(/\*\//g, '* /')} */
export type ${x.Type}Request = ${hasArgs ? `{\n${argLines.join('\n')}\n}${allOptional ? ' | void' : ''}` : 'void'}
export type ${x.Type}Response = ${typeFromKeys(asMap(x.e.response))}
`
  }).join('\n')

  files['types.ts'] = `// GENERATED by tools/api/generate.mjs from the server's catalogue — do not edit.
// Leaf types are inferred from the catalogue's descriptions; money is always a decimal string.
/* eslint-disable */

/** A file picked on the device, for the two media uploads. */
export type UploadFile = { uri: string; name: string; type: string }

${typeBlocks}`

  /* ---- guardApi.ts, residentApi.ts ------------------------------------------------------------------ */

  for (const app of ['guard', 'resident']) {
    const mine = entries.filter((x) => x.apps.includes(app))
    const usesWrite = mine.some((x) => x.e.write)
    files[`${app}Api.ts`] = `// GENERATED by tools/api/generate.mjs from the server's catalogue — do not edit.
// ${mine.length} endpoints for the ${app === 'guard' ? 'Guard' : 'Resident'} App.
/* eslint-disable */
import { baseApi } from '../baseApi'
${usesWrite ? `import { write, type WriteStamp } from '../write'\n` : ''}import { pick, toFormData${app === 'guard' ? ', enforceAllowlist' : ''} } from '../runtime'
import type * as T from './types'

export const ${app}Api = baseApi.injectEndpoints({
  endpoints: (build) => ({
${mine.map((x) => endpointSrc(x, app)).join('\n')}
  }),
  overrideExisting: true,
})
`
  }

  /* ---- tags.ts --------------------------------------------------------------------------------------- */

  const caps = [...new Set(entries.map((x) => x.e.capability).filter(Boolean)), 'enrolment', 'auth'].sort()
  // Every read's plan in every app it is in, once each, by endpoint name: what the comment below lists.
  const plans = [...new Map(entries.filter((x) => x.e.method === 'GET').flatMap((x) => x.apps.map((app) => ({ name: x.name, ...tagPlan(x.name, x.e, app) }))).map((p) => [`${p.name} ${p.type}`, p])).values()]
    .sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0))
  // A list of reads as comment lines, wrapped: " *     a ('x'), b ('y'),".
  const commentList = (items) => items.reduce((lines, item, i) => {
    const text = item + (i < items.length - 1 ? ',' : '')
    if (lines.length && lines[lines.length - 1].length + 1 + text.length <= 112) lines[lines.length - 1] += ` ${text}`
    else lines.push(` *     ${text}`)
    return lines
  }, []).join('\n')
  const singles = plans.filter((p) => p.kind === 'single').map((p) => `${p.name} ('${p.type}')`)
  const bySite = plans.filter((p) => p.kind === 'record' && p.param === 'site').map((p) => `${p.name} ('${p.type}')`)
  // A record's id is its path parameter; where that is the site, the entity a live event names (`i`) is not it.
  const siteNote = bySite.length ? `
 *
 * A record read's id is its first path parameter, which is not always what a live event is about (the
 * envelope's \`i\`). The reads below are keyed by the SITE, so an event about what one of them holds must
 * invalidate { type, id: String(siteId) }, with the site taken from the channel or the summary, never from
 * \`i\`. Keyed by the site:
${commentList(bySite)}` : ''
  files['tags.ts'] = `// GENERATED by tools/api/generate.mjs — one cache tag per server capability.
/* eslint-disable */

/**
 * Every read provides its capability's tag, so invalidating a capability ('alerts') refetches everything
 * under it, as every write does. Each read also provides ONE narrower tag, so a live event can refetch one
 * record, or one capability's lists, and nothing else (tagPlan in the generator, DESIGN §8.4):
 *
 *   a read with a path parameter   { type, id: String(<its first path parameter>) }
 *   a list                         { type, id: LIST_ID }
 *   any other read                 { type, id: '<its endpoint name>' }: one record with no id in its path
 *                                  (the token names it) or a feed, so { type: 'household', id: 'me.household' }
 *                                  refetches the household and none of the household lists. These are:
${commentList(singles)}${siteNote}
 */
export const TAG_TYPES = [${caps.map((c) => `'${c}'`).join(', ')}] as const
export type TagType = (typeof TAG_TYPES)[number]

/** The id of a list's tag: { type: 'passes', id: LIST_ID } refetches the passes lists and no single pass. */
export const LIST_ID = 'LIST'
`

  /* ---- liveTypes.ts ---------------------------------------------------------------------------------- */

  files['liveTypes.ts'] = liveTypesSrc(liveTypes)

  const summary = `generated ${entries.length} endpoints · guard ${entries.filter((x) => x.apps.includes('guard')).length} · resident ${entries.filter((x) => x.apps.includes('resident')).length} · ${caps.length} tags · ${liveTypes.length} live types`
  return { files, summary }
}

/* ---- run as a script: read the catalogue, write the files ----------------------------------- */

/**
 * Whether node was asked to run THIS file. Node gives the entry script's import.meta.url its real path but leaves
 * argv[1] as typed, so through a junction or a symlink the two differ: both are resolved to real paths first, or
 * the script would exit 0 having written nothing. Imported (a test, `node -e`), it is not the entry.
 */
function isEntry() {
  const typed = process.argv[1]
  if (!typed) return false
  const self = fileURLToPath(import.meta.url)
  const real = (p) => { try { return realpathSync(p) } catch { return path.resolve(p) } }
  const a = real(path.resolve(typed))
  const b = real(self)
  return process.platform === 'win32' ? a.toLowerCase() === b.toLowerCase() : a === b
}

if (isEntry()) {
  const cat = JSON.parse(readFileSync(path.join(ROOT, '_design', 'api', 'catalogue.json'), 'utf8'))
  const { files, summary } = generate(cat)
  mkdirSync(OUT, { recursive: true })
  for (const [name, content] of Object.entries(files)) writeFileSync(path.join(OUT, name), content, 'utf8')
  console.log(summary)
}
