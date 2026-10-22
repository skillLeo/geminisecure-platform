/**
 * WHAT A SCREEN BINDS, AND WHAT AN EVENT DOES TO IT (M11 Part C, design §6.2, §4.1–§4.4).
 *
 *   liveList(prop, opts)     a list prop, patched row by row
 *   liveRecord(prop, opts)   a detail page's record, asked for again
 *   liveCounter(prop, opts)  a count or badge
 *   liveTiles(prop, opts)    a set of dashboard tiles
 *
 * Each returns a description; `useLive` turns it into a binding for the component that
 * called it. Nothing here ever reloads the page for data. An `inline` event carries the row
 * (hydrated at publish, for an audience already entitled to it) and is applied as it is; a
 * `ref` event carries an id, and that one row or record is asked for from the screen's own
 * endpoint, which applies the page's gate and scope again. Either way the prop is updated
 * with `router.replaceProp` — a client-side update that keeps state and scroll — so Back
 * still shows current data.
 *
 * liveList options
 *   entity     'ticket' or ['alert', 'incident']       events of these entities (required)
 *   types      ['ticket.updated', …]                    only these types (optional)
 *   key        'id' | (row) => …                        the row's key; an event's `i` names it
 *   rowsUrl    string | () => string                    GET <rowsUrl>?<param>[]=…&<query>
 *              answering {<answerKey>: {key: row|null}, tiles?, as_of}; null removes the row.
 *              Membership is the SERVER'S: it answers null for a row not in this filter.
 *   param      'ids'          answerKey 'rows'          the endpoint's own names
 *   query      () => filters                            the page's filter, sent along
 *   idsFor     (env) => [keys]                          which rows an event affects (default [env.i])
 *   compare    (a, b) => number                         the list's order, for placing a new row
 *   insert     'start' | 'end'                          where a new row goes without `compare`
 *   pageSize   number | () => number                    the visible window; a row sorting past a
 *   firstPage  boolean | () => boolean                  full window's end, or above page 2's
 *              first row, is not inserted: it counts towards the "N new · show" pill
 *   hold       (row) => boolean                         a row being worked on is not moved or
 *              removed (nor one with the focus inside it); it is marked "changed elsewhere"
 *   accepts    (row) => boolean                         for inline rows: whether one belongs here
 *   inline     false                                    treat inline events as refs
 *   flash      'ticket'                                 the v-live-flash namespace (default: entity)
 *   onShowNew  () => void                               what "show" does (default: reload this prop)
 *
 * The rest are described where they are made.
 */
import { reactive } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { createRefetcher, getJson, withQuery } from './rows.js'
import { hasFocusWithin, isOwnOrigin, markFlash } from './store.js'
import { fetchTiles } from './tiles.js'

export function liveList(prop, opts = {}) {
    return { type: 'list', prop, opts }
}

export function liveRecord(prop, opts = {}) {
    return { type: 'record', prop, opts }
}

export function liveCounter(prop, opts = {}) {
    return { type: 'counter', prop, opts }
}

export function liveTiles(prop, opts = {}) {
    return { type: 'tiles', prop, opts }
}

const asList = (value) => (value === undefined || value === null ? [] : Array.isArray(value) ? value : [value])

const hasOwn = (object, key) => object !== null && typeof object === 'object' && Object.prototype.hasOwnProperty.call(object, key)

const resolve = (value) => (typeof value === 'function' ? value() : value)

const pathGet = (object, path) =>
    String(path)
        .split('.')
        .reduce((at, key) => (at === null || at === undefined ? undefined : at[key]), object)

/** A failed read is asked again, twice, 2 s and then 4 s later; after that the next event or resync asks. */
const RETRIES = [2_000, 4_000]

function retrying(request) {
    const attempts = new Map()

    return {
        failed(id) {
            const n = attempts.get(id) ?? 0

            if (n >= RETRIES.length) {
                attempts.delete(id)

                return
            }

            attempts.set(id, n + 1)
            setTimeout(() => request(id), RETRIES[n])
        },
        succeeded(id) {
            attempts.delete(id)
        },
    }
}

function listBinding(prop, opts, ctx) {
    const entities = asList(opts.entity).map(String)

    if (entities.length === 0) {
        throw new Error(`liveList('${prop}') needs the entity its rows are.`)
    }

    const key = opts.key ?? 'id'
    const types = opts.types ? new Set(asList(opts.types)) : null
    const param = opts.param ?? 'ids'
    const answerKey = opts.answerKey ?? 'rows'
    const flashAs = opts.flash ?? entities[0]
    const page = usePage()

    const keyOf = (row) => {
        if (row === null || typeof row !== 'object') {
            return null
        }

        const value = typeof key === 'function' ? key(row) : row[key]

        return value === undefined || value === null ? null : String(value)
    }

    const api = reactive({
        /** New rows that belong to this list but outside the visible window. */
        pill: 0,
        /** The list may be missing rows (after a reset): "show" brings it up to date. */
        stale: false,
        /** key → true while a change to that row is held back. */
        held: {},
        showNew,
        changedElsewhere: (rowKey) => api.held[String(rowKey)] === true,
        release: (rowKey) => release(String(rowKey)),
    })

    const pending = new Map()
    const outside = new Set()
    const wantFlash = new Set()

    const rowsNow = () => {
        const value = pathGet(page.props, prop)

        return Array.isArray(value) ? value : []
    }

    const isHeld = (rowKey) => {
        const row = rowsNow().find((each) => keyOf(each) === rowKey) ?? null

        return (row !== null && typeof opts.hold === 'function' && opts.hold(row) === true) || hasFocusWithin(`${flashAs}:${rowKey}`)
    }

    const pageSize = () => {
        const value = resolve(opts.pageSize)

        return Number.isInteger(value) && value > 0 ? value : null
    }

    const positionOf = (list, row) => {
        if (typeof opts.compare === 'function') {
            const at = list.findIndex((each) => opts.compare(row, each) < 0)

            return at < 0 ? list.length : at
        }

        return opts.insert === 'end' ? list.length : 0
    }

    const outsideWindow = (list, index) => {
        const first = resolve(opts.firstPage) !== false

        if (!first && index === 0 && list.length > 0) {
            return true
        }

        const size = pageSize()

        return size !== null && list.length >= size && index >= list.length
    }

    function put(rowKey, row, flash, force = false) {
        const accepted = row !== null && row !== undefined && typeof row === 'object'
            && (typeof opts.accepts !== 'function' || opts.accepts(row) === true)
        const next = accepted ? row : null

        if (!force && isHeld(rowKey)) {
            pending.set(rowKey, next)
            api.held[rowKey] = true

            return
        }

        router.replaceProp(prop, (current) => {
            const list = Array.isArray(current) ? current : []
            const at = list.findIndex((each) => keyOf(each) === rowKey)

            if (next === null) {
                if (outside.delete(rowKey)) {
                    api.pill = outside.size
                }

                return at < 0 ? list : list.filter((_, index) => index !== at)
            }

            if (at >= 0) {
                const copy = list.slice()
                copy[at] = next

                return copy
            }

            const index = positionOf(list, next)

            if (outsideWindow(list, index)) {
                outside.add(rowKey)
                api.pill = outside.size

                return list
            }

            const copy = list.slice()
            copy.splice(index, 0, next)
            const size = pageSize()

            if (size !== null && copy.length > size) {
                copy.length = size
            }

            return copy
        })

        if (flash && next !== null) {
            markFlash(`${flashAs}:${rowKey}`)
        }
    }

    function release(rowKey) {
        if (!pending.has(rowKey)) {
            return
        }

        const row = pending.get(rowKey)
        pending.delete(rowKey)
        delete api.held[rowKey]
        put(rowKey, row, row !== null, true)
    }

    /** A held change is applied as soon as nobody is working on that row any more. */
    function releaseFreed() {
        for (const rowKey of [...pending.keys()]) {
            if (!isHeld(rowKey)) {
                release(rowKey)
            }
        }
    }

    function showNew() {
        outside.clear()
        api.pill = 0
        api.stale = false

        if (typeof opts.onShowNew === 'function') {
            opts.onShowNew()

            return
        }

        // The reader pressed it: one of the sanctioned whole-list refetches (§6.5).
        router.reload({ only: [String(prop).split('.')[0]], preserveScroll: true })
    }

    const retry = retrying((id) => refetcher.request(id))

    const refetcher = createRefetcher({
        debounceMs: opts.debounce ?? 100,
        max: 200,
        run: async (ids) => {
            const url = resolve(opts.rowsUrl)

            if (!url) {
                return null
            }

            const query = resolve(opts.query) ?? {}
            const answer = await getJson(withQuery(url, { ...query, [`${param}[]`]: ids }))

            return answer.ok ? answer.body : null
        },
        onResult: (body, ids) => {
            if (body === null || typeof body !== 'object') {
                ids.forEach((id) => retry.failed(id))

                return
            }

            releaseFreed()
            const rows = hasOwn(body, answerKey) && typeof body[answerKey] === 'object' && body[answerKey] !== null ? body[answerKey] : {}

            for (const [rowKey, row] of Object.entries(rows)) {
                put(String(rowKey), row, wantFlash.delete(String(rowKey)))
            }

            for (const id of ids) {
                wantFlash.delete(id)
                retry.succeeded(id)
            }

            if (hasOwn(body, 'tiles') && body.tiles !== null && typeof body.tiles === 'object') {
                ctx.shareTiles(body.tiles)
            }
        },
    })

    const handler = {
        signals: false,
        accepts: (env) => entities.includes(env.e) && (types === null || types.has(env.t)),
        apply(env) {
            releaseFreed()
            const own = isOwnOrigin(env.o)

            if (env.m === 'inline' && opts.inline !== false && hasOwn(env, 'd')) {
                const row = env.d ?? null
                const rowKey = (row !== null ? keyOf(row) : null) ?? env.i

                if (rowKey !== '') {
                    put(String(rowKey), row, !own)
                }

                return
            }

            const ids = typeof opts.idsFor === 'function' ? asList(opts.idsFor(env)).map(String) : [env.i]

            for (const id of ids) {
                if (id === '') {
                    continue
                }

                if (!own) {
                    wantFlash.add(id)
                }

                refetcher.request(id)
            }
        },
        resync(reason) {
            for (const row of rowsNow()) {
                const rowKey = keyOf(row)

                if (rowKey !== null) {
                    refetcher.request(rowKey)
                }
            }

            for (const rowKey of pending.keys()) {
                refetcher.request(rowKey)
            }

            if (reason === 'reset' || reason === 'blind') {
                api.stale = true
            }
        },
    }

    /**
     * The server replaced the list while the page stayed (new live props, useLive): the rows
     * counted outside the window and the changes held back were about the old list. The new
     * one is the server's as of its heads, and whatever came after is applied to it again.
     */
    function reset() {
        outside.clear()
        api.pill = 0
        api.stale = false
        pending.clear()
        wantFlash.clear()

        for (const rowKey of Object.keys(api.held)) {
            delete api.held[rowKey]
        }
    }

    return { handler, api, dispose: () => refetcher.dispose(), reset }
}

/*
 * liveRecord options
 *   entity   'ticket'                    events of this entity whose id is this record's
 *   id       () => props.ticket.number   the record's id, as events name it
 *   url      () => `${…}/live`           GET, answering {record: object|null, as_of?}
 *   types    only these types (optional)
 *   inline   true                        apply an inline event's `d` as the record
 *   apply    (record) => void            instead of replaceProp(prop, record)
 *   onGone   () => void                  the record was removed, or is no longer visible
 *   flash    the v-live-flash namespace (default: entity), keyed by the id
 */
function recordBinding(prop, opts) {
    const entities = asList(opts.entity).map(String)

    if (entities.length === 0) {
        throw new Error(`liveRecord('${prop}') needs the entity it is.`)
    }

    const types = opts.types ? new Set(asList(opts.types)) : null
    const flashAs = opts.flash ?? entities[0]
    const api = reactive({ gone: false })
    let wantFlash = false

    const idOf = () => {
        const value = resolve(opts.id)

        return value === undefined || value === null ? '' : String(value)
    }

    function applyRecord(record, flash) {
        if (record === null || record === undefined) {
            api.gone = true
            opts.onGone?.()

            return
        }

        api.gone = false

        if (typeof opts.apply === 'function') {
            opts.apply(record)
        } else {
            router.replaceProp(prop, record)
        }

        if (flash) {
            markFlash(`${flashAs}:${idOf()}`)
        }
    }

    const retry = retrying((id) => refetcher.request(id))

    const refetcher = createRefetcher({
        debounceMs: opts.debounce ?? 100,
        max: 1,
        run: async () => {
            const url = resolve(opts.url)

            if (!url) {
                return undefined
            }

            const answer = await getJson(url)

            return answer.ok ? { body: answer.body } : undefined
        },
        onResult: (result, ids) => {
            if (result === undefined) {
                ids.forEach((id) => retry.failed(id))

                return
            }

            ids.forEach((id) => retry.succeeded(id))
            const body = result.body
            const record = hasOwn(body, 'record') ? body.record : body
            applyRecord(record, wantFlash)
            wantFlash = false
        },
    })

    const handler = {
        signals: false,
        accepts: (env) => entities.includes(env.e) && env.i !== '' && env.i === idOf() && (types === null || types.has(env.t)),
        apply(env) {
            const own = isOwnOrigin(env.o)

            if (env.m === 'inline' && opts.inline === true && hasOwn(env, 'd')) {
                applyRecord(env.d ?? null, !own)

                return
            }

            wantFlash = wantFlash || !own
            refetcher.request('record')
        },
        resync() {
            refetcher.request('record')
        },
    }

    return { handler, api, dispose: () => refetcher.dispose() }
}

/*
 * liveCounter options
 *   entities   ['alert']            events of these entities move it (or `types`)
 *   tile       'open_alerts'        ask GET /live/tiles for this key (the usual way), or
 *   url        () => string         ask this endpoint, answering {value} | {count} | {unread}
 *   pick       (body) => value      how to read the endpoint's answer, if it is none of those
 *   debounce   250                  events within this many ms cost one request
 *   afterCatchUp true               also ask once after every catch-up (a count fed by signals)
 */
function counterBinding(prop, opts) {
    const entities = asList(opts.entities ?? opts.entity).map(String)
    const types = opts.types ? new Set(asList(opts.types)) : null
    const tile = typeof opts.tile === 'string' && opts.tile !== '' ? opts.tile : null

    if (entities.length === 0 && types === null && tile === null) {
        throw new Error(`liveCounter('${prop}') needs the entities, types or tile that move it.`)
    }

    const pick = typeof opts.pick === 'function' ? opts.pick : (body) => body?.value ?? body?.count ?? body?.unread

    const refetcher = createRefetcher({
        debounceMs: opts.debounce ?? 250,
        max: 1,
        run: async () => {
            if (tile !== null) {
                const tiles = await fetchTiles([tile])

                return tiles !== null && hasOwn(tiles, tile) ? { value: tiles[tile] } : undefined
            }

            const url = resolve(opts.url)

            if (!url) {
                return undefined
            }

            const answer = await getJson(url)

            return answer.ok ? { value: pick(answer.body) } : undefined
        },
        onResult: (result) => {
            if (result !== undefined && result.value !== undefined) {
                router.replaceProp(prop, result.value)
            }
        },
    })

    const handler = {
        accepts: (env) =>
            entities.includes(env.e) || (types !== null && types.has(env.t)) || (tile !== null && env.e === 'tile' && env.i === tile),
        apply: () => refetcher.request('count'),
        resync: () => refetcher.request('count'),
        ...(opts.afterCatchUp === true ? { afterCatchUp: () => refetcher.request('count') } : {}),
    }

    return { handler, api: reactive({}), dispose: () => refetcher.dispose() }
}

/*
 * liveTiles options
 *   keys       ['duress_open', …] | () => keys    the tiles this prop holds, from GET /live/tiles;
 *              a tile event (entity 'tile', id = key) for one of them asks for them again
 *   entities   events of these entities ask too (optional)
 *   from       'rows'                             or take them from that list's answers
 *   afterCatchUp false                            by default the keys are asked for once after every
 *              catch-up, as a resync for the dashboard (§4.4)
 */
function tilesBinding(prop, opts) {
    const keys = () => asList(resolve(opts.keys)).map(String)
    const entities = asList(opts.entities ?? opts.entity).map(String)
    const from = typeof opts.from === 'string' ? opts.from : null

    if (from === null && keys().length === 0) {
        throw new Error(`liveTiles('${prop}') needs its keys, or the list it comes from.`)
    }

    const merge = (tiles) =>
        router.replaceProp(prop, (old) => ({ ...(old !== null && typeof old === 'object' ? old : {}), ...tiles }))

    const refetcher = createRefetcher({
        debounceMs: opts.debounce ?? 250,
        max: 1,
        run: () => fetchTiles(keys()),
        onResult: (tiles) => {
            if (tiles !== null && typeof tiles === 'object' && Object.keys(tiles).length > 0) {
                merge(tiles)
            }
        },
    })

    const ask = () => {
        if (keys().length > 0) {
            refetcher.request('tiles')
        }
    }

    const handler = {
        accepts: (env) => (env.e === 'tile' && keys().includes(env.i)) || entities.includes(env.e),
        apply: ask,
        resync: ask,
        ...(opts.afterCatchUp !== false ? { afterCatchUp: ask } : {}),
    }

    return { handler, api: reactive({}), dispose: () => refetcher.dispose(), from, receive: merge }
}

const MAKERS = { list: listBinding, record: recordBinding, counter: counterBinding, tiles: tilesBinding }

/**
 * Make the binding `spec` describes. `ctx.shareTiles(tiles)` is how a list hands the tiles
 * its endpoint answered with to the tiles bindings reading `from` it.
 */
export function createBinding(spec, ctx) {
    const make = MAKERS[spec?.type]

    if (!make) {
        throw new Error('useLive: a binding must be made with liveList, liveRecord, liveCounter or liveTiles.')
    }

    return make(spec.prop, spec.opts ?? {}, ctx)
}
