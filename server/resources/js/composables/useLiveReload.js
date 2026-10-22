import { onBeforeUnmount } from 'vue'
import { router } from '@inertiajs/vue3'
import { register, state as liveState } from '../live/store.js'

/** What an estate console page that sums up the whole estate follows — its activity, its dashboard, its bell (M18H §7.2). */
export const ESTATE_ACTIVITY = ['incident', 'shift', 'ticket', 'dues', 'notice', 'meeting', 'election', 'resident', 'account', 'booking', 'structure', 'accounting', 'staff', 'alert', 'gate', 'client']

/** The same for the Gemini console. */
export const GEMINI_ACTIVITY = ['incident', 'shift', 'request', 'payroll', 'orders', 'message', 'client', 'invoice', 'officer', 'alert', 'gate', 'chat']

/**
 * THIS PAGE MOVES BY ITSELF (M11 §2, D-175).
 *
 * A record changed anywhere — another console, a phone, a scheduled job — and the server told every screen that
 * shows it (`{entity}.changed` / `{entity}.removed`, a reference: which record, nothing about it). A page that
 * draws those entities re-reads ITS OWN props, through its own route and its own gate, one read for a burst of
 * events, keeping the scroll and whatever is typed. The shell holds the channels (LiveShell); the page says only
 * what it draws:
 *
 *     useLiveReload({ entities: ['ticket'], only: ['tickets', 'counts'] })
 *
 * Caught up after every drop like every other event, so a change made while the socket was down lands, and a
 * resync (a gap the catch-up could not fill) re-reads the page. The honesty line counts it as a live binding.
 *
 * @param {object} options
 * @param {string[]} options.entities   the live entities this page draws (`incident`, `ticket`, `dues`, …)
 * @param {string[]} [options.only]     the props to re-read; every prop when left out (one read either way)
 * @param {() => (Array<string|number>|null)} [options.ids]  a detail page: only these records' events count
 * @param {number} [options.wait]       ms to wait for the rest of a burst
 */
export function useLiveReload({ entities, only = null, ids = null, wait = 250 }) {
    let timer = null

    const reload = () => {
        clearTimeout(timer)
        timer = setTimeout(() => {
            timer = null
            router.reload({ ...(only ? { only } : {}), preserveScroll: true, preserveState: true })
        }, wait)
    }

    const wanted = (env) => {
        if (!entities.includes(env.e)) {
            return false
        }

        const mine = ids?.()

        return !Array.isArray(mine) || mine.map(String).includes(String(env.i))
    }

    const unregister = register({
        page: true,
        signals: false,
        channels: { has: () => true },
        accepts: wanted,
        apply: () => reload(),
        resync: () => reload(),
    })

    liveState.pageBindings++

    onBeforeUnmount(() => {
        clearTimeout(timer)
        unregister()
        liveState.pageBindings--
    })
}
