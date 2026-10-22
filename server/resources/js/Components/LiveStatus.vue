<script setup>
import { computed, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { fallbackCadence, refresh, state, statusDetail, TIMING } from '../live/store.js'

/**
 * WHETHER THIS SCREEN IS LIVE, SAID PLAINLY (M11 Part C, design §6.4).
 *
 * A screen must never look live when it is not. One line, directly after the shell's
 * byline, says which of these holds:
 *
 *   nothing       the page is live (a dispatch screen still says "Live", in green), or it
 *                 is a form, which shows no record
 *   grey          "This screen does not update by itself · updated … · Refresh" — a page
 *                 no live binding has reached yet, which is every page until its unit
 *                 wires it; "Some live updates are not available to you"; a report's
 *                 "As of …"; a small dot, no words, while connecting has taken more than 2 s
 *                 (M78 §3: it was "Connecting…")
 *   amber         "Live updates are down. This screen may be out of date · updated … ·
 *                 checking every 10 s · Refresh" after 3 s of it; "Live updates are
 *                 delayed" when the relay is behind or its pulse is stale; "Your session
 *                 ended. Sign in" on a 401 or 419 (M78 §4: no em dashes)
 *   quiet         "New version. Reload"; "Refreshed: you were away longer than live
 *                 updates are kept."
 *
 * "Live" needs ALL of: the socket connected, every shell and page channel subscribed and
 * caught up, the last catch-up answered, the relay keeping up, and its pulse under 25 s old
 * (store.js decides; this only says it). Refresh does what it says: on a bound page it
 * catches up now and asks every binding for its data again; on an unbound page or a report
 * it asks for the page's props again, because there is nothing narrower to ask for.
 *
 * It reads the page's `live` prop (`{bindings, kind, …}`) unless handed one.
 */
const props = defineProps({
    /** The page's live prop; by default `usePage().props.live`. */
    live: { type: Object, default: undefined },
})

const page = usePage()

const liveProp = computed(() => (props.live !== undefined ? props.live : (page?.props?.live ?? null)))

// When this page's data was drawn: the time its live prop arrived.
const loadedAt = ref(Date.now())

watch(liveProp, () => {
    loadedAt.value = Date.now()
})

/*
 * HOW LONG AGO, NOT WHAT TIME (M19A §4.1). This line used to print the reader's own clock — "updated 09:38:12" — with
 * nothing saying whose, on a console whose every other time is the estate's. A relative time belongs to no clock.
 */
const ago = (at) => {
    const seconds = Math.max(0, Math.round((Math.max(state.now, Date.now()) - at) / 1000))

    if (seconds < 45) return 'just now'
    if (seconds < 90) return 'a minute ago'
    if (seconds < 3600) return `${Math.round(seconds / 60)} minutes ago`
    if (seconds < 7200) return 'an hour ago'

    return `${Math.floor(seconds / 3600)} hours ago`
}
const clock = ago

const asOf = (live) => {
    const parsed = typeof live?.as_of === 'string' ? Date.parse(live.as_of) : NaN

    return Number.isNaN(parsed) ? loadedAt.value : parsed
}

const RESET_NOTICE_MS = 10_000

const view = computed(() => {
    const live = liveProp.value

    if (!live || typeof live !== 'object') {
        return null
    }

    // A create or edit page shows no record to go stale (§6.4), whatever the shared default says; and a page that SAID
    // what it is (`useLiveKind`, M18H §7.2) is taken at its word.
    const declared = state.pageKind
    const kind = live.kind === 'data' && declared !== null
        ? declared
        : live.kind === 'data' && /\/(Create|New|Edit)[A-Za-z]*$/.test(String(page?.component ?? '')) ? 'form' : (live.kind ?? 'data')

    if (kind === 'form') {
        return null
    }

    if (kind === 'snapshot') {
        return { tone: 'grey', parts: [`As of ${clock(asOf(live))}`], action: 'page' }
    }

    if (state.signedOut) {
        return { tone: 'amber', parts: ['Your session ended.'], action: 'sign-in' }
    }

    /*
     * Bound when the screen's own code holds live bindings right now (useLive, or a composable that re-reads on
     * live events): that is what makes it move. The server's list says what the page is meant to bind; a page
     * whose code binds nothing says so, whatever the list says.
     */
    if (state.pageBindings === 0) {
        return { tone: 'grey', parts: ['This screen does not update by itself', `updated ${clock(asOf(live))}`], action: 'page' }
    }

    const { status, since } = statusDetail()
    const updated = `updated ${clock(Math.max(loadedAt.value, state.lastSyncAt))}`

    switch (status) {
        case 'degraded':
            return {
                tone: 'amber',
                parts: [
                    'Live updates are down. This screen may be out of date',
                    updated,
                    `checking every ${fallbackCadence() / 1000} s`,
                ],
                action: 'live',
            }

        case 'delayed':
            return { tone: 'amber', parts: ['Live updates are delayed', updated], action: 'live' }

        case 'refused':
            return { tone: 'grey', parts: ['Some live updates are not available to you'], action: 'live' }

        // M78 §3: a small grey dot, no words, that goes once connected; a connection that never comes goes amber above.
        case 'connecting':
        case 'catching-up':
            return state.now - since >= TIMING.connectingAfter ? { tone: 'grey', parts: [], action: null, dot: 'Connecting' } : null

        default:
            if (state.newVersion) {
                return { tone: 'quiet', parts: ['New version.'], action: 'reload' }
            }

            if (state.resetAt !== 0 && state.now - state.resetAt < RESET_NOTICE_MS) {
                return { tone: 'quiet', parts: ['Refreshed: you were away longer than live updates are kept.'], action: null }
            }

            return state.dispatchPages > 0 ? { tone: 'green', parts: ['Live'], action: null } : null
    }
})

const signInHref = computed(() => page?.url ?? '/')

function onRefresh() {
    if (view.value?.action === 'page') {
        // The reader asked: the page's props, again. The one kind of reload a reader starts (§6.5).
        router.reload({ preserveScroll: true })

        return
    }

    refresh()
}

function reloadPage() {
    window.location.reload()
}
</script>

<template>
    <div v-if="view" class="live-status" :class="`live-status--${view.tone}`" role="status" :aria-label="view.dot ?? undefined" :title="view.dot ?? undefined">
        <span v-if="view.tone === 'green' || view.dot" class="live-status__dot" aria-hidden="true"></span>
        <span v-if="view.parts.length" class="live-status__text">{{ view.parts.join(' · ') }}</span>
        <template v-if="view.action === 'live' || view.action === 'page'">
            <span class="live-status__sep" aria-hidden="true"> · </span>
            <button type="button" class="live-status__action" @click="onRefresh">Refresh</button>
        </template>
        <template v-else-if="view.action === 'sign-in'">
            {{ ' ' }}<a class="live-status__action" :href="signInHref">Sign in</a>
        </template>
        <template v-else-if="view.action === 'reload'">
            {{ ' ' }}<button type="button" class="live-status__action" @click="reloadPage">Reload</button>
        </template>
    </div>
</template>

<style scoped>
.live-status {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0 4px;
    margin-left: 8px;
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.45;
    color: var(--slate-600);
}

.live-status__dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--success-600);
    margin-right: 2px;
    flex: 0 0 auto;
}

.live-status--green {
    color: var(--slate-600);
}

.live-status--grey {
    color: var(--slate-500);
}

.live-status--grey .live-status__dot {
    background: var(--slate-300);
}

/* Amber: a stated degradation, the same pair the dispatch bar and the dead-channel notice use. */
.live-status--amber {
    color: var(--navy-900);
    background: var(--amber-100);
    border: 1px solid var(--amber-500);
    border-radius: 8px;
    padding: 3px 10px;
}

.live-status--quiet {
    color: var(--slate-500);
    font-weight: 500;
}

.live-status__action {
    font: inherit;
    color: var(--navy-600);
    background: none;
    border: 0;
    padding: 0;
    text-decoration: underline;
    cursor: pointer;
}

.live-status--amber .live-status__action {
    color: var(--navy-900);
    font-weight: 700;
}
</style>
