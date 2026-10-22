import { computed, getCurrentInstance, isRef, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { createBinding, liveCounter, liveList, liveRecord, liveTiles } from '../live/bindings.js'
import { acquire, forgetAfter, refresh, register, release, state, statusFor } from '../live/store.js'

export { liveCounter, liveList, liveRecord, liveTiles }

/**
 * A SCREEN'S LIVE BINDINGS (M11 Part C, design §6.2).
 *
 *     const live = useLive(props.live, {
 *         rows:   liveList('rows', { entity: 'ticket', key: 'number', rowsUrl: …, query: () => filters }),
 *         kpis:   liveTiles('kpis', { from: 'rows' }),
 *         ticket: liveRecord('ticket', { entity: 'ticket', id: () => props.ticket.number, url: … }),
 *         open:   liveCounter('counts.open_alerts', { entities: ['alert'], tile: 'open_alerts' }),
 *     })
 *     live.status     'connecting' | 'catching-up' | 'live' | 'degraded' | 'refused' | 'delayed' | 'signed-out'
 *     live.rows.pill  new rows outside the window;  live.rows.showNew()
 *     live.refresh()  catch up now and resync these bindings
 *
 * `props.live` is the page's live prop as the server builds it (`LivePage::for`): the
 * channel names THIS viewer may hold for this page (`channels`), the heads its data was read
 * at (`heads`), and `bindings` / `kind`, which the indicator reads. The channel list is never
 * built here. When it is the page's own `live` prop, it is followed across reloads: after an
 * `access.changed` reload, channels the viewer lost are let go and new ones are held.
 *
 * Options: `dispatch: true` on the dispatch screens (the fallback poll runs every 3 s and
 * the indicator keeps its "Live" label); `shell: true` for a shell's own bindings, which
 * do not make an unbound page look bound.
 *
 * The channels are held from setup and let go on unmount; the store keeps each for 30 s
 * after, so the next page holding the same channel finds it subscribed and caught up.
 */
export function useLive(live, specs = {}, options = {}) {
    const page = usePage()

    const source = typeof live === 'function'
        ? live
        : isRef(live)
            ? () => live.value
            : live !== undefined && live !== null && live === page?.props?.live
                ? () => page.props.live
                : () => live

    const names = ref([])
    let held = []

    const entriesOf = (value) =>
        (Array.isArray(value?.channels) ? value.channels : [])
            .map((entry) => (typeof entry === 'string' ? { name: entry, streams: [] } : entry))
            .filter((entry, index, all) => entry && typeof entry.name === 'string' && all.findIndex((other) => other?.name === entry.name) === index)

    const hold = (value) => {
        const heads = value?.heads ?? page?.props?.live?.heads ?? {}
        const nextNames = []

        for (const entry of entriesOf(value)) {
            const already = held.includes(entry.name)

            if (!acquire(entry.name, heads, Array.isArray(entry.streams) ? entry.streams : [])) {
                continue
            }

            // A channel the page already held keeps its one reference; the new heads still wind it back.
            if (already) {
                release(entry.name)
            }

            nextNames.push(entry.name)
        }

        for (const name of held) {
            if (!nextNames.includes(name)) {
                release(name)
            }
        }

        held = nextNames
        names.value = held
    }

    const channelSet = { has: (name) => held.includes(name) }
    const tileSinks = new Map()
    const made = []
    const apis = {}

    for (const [name, spec] of Object.entries(specs ?? {})) {
        const binding = createBinding(spec, {
            shareTiles: (tiles) => {
                for (const sink of tileSinks.get(name) ?? []) {
                    sink.receive(tiles)
                }
            },
        })

        binding.handler.channels = channelSet
        binding.handler.page = options.shell !== true
        made.push(binding)
        apis[name] = binding.api
    }

    for (const binding of made) {
        if (binding.from) {
            if (!tileSinks.has(binding.from)) {
                tileSinks.set(binding.from, [])
            }

            tileSinks.get(binding.from).push(binding)
        }
    }

    hold(source())

    const unregisters = made.map((binding) => register(binding.handler))
    const counted = options.shell === true ? 0 : made.length
    state.pageBindings += counted

    if (options.dispatch === true) {
        state.dispatchPages++
    }

    /*
     * NEW LIVE PROPS WHILE THE PAGE STAYS: the server served this page again (a write that
     * redirects back keeps the component), so its data is the server's as of the new heads.
     * Each binding drops what it held on the old data (a list's "N new" and held-back
     * changes) and forgets what it applied after those heads; holding the channels with the
     * new heads winds them back, and the catch-up applies again what the new data may lack.
     *
     * SYNCHRONOUS, as the props are replaced: the cursors are wound back before any event can
     * be applied against the new data, and the catch-up is asked for from that moment.
     */
    const stopWatching = watch(source, (next, previous) => {
        if (next !== previous) {
            const heads = next?.heads ?? page?.props?.live?.heads ?? {}

            for (const binding of made) {
                binding.reset?.()
                forgetAfter(binding.handler, heads)
            }

            hold(next)
        }
    }, { flush: 'sync' })

    let stopped = false

    const stop = () => {
        if (stopped) {
            return
        }

        stopped = true
        stopWatching()
        unregisters.forEach((unregister) => unregister())
        made.forEach((binding) => binding.dispose())
        state.pageBindings -= counted

        if (options.dispatch === true) {
            state.dispatchPages--
        }

        for (const name of held) {
            release(name)
        }

        held = []
        names.value = []
    }

    if (getCurrentInstance()) {
        onBeforeUnmount(stop)
    }

    return reactive({
        status: computed(() => statusFor(names.value)),
        refresh,
        stop,
        ...apis,
    })
}
