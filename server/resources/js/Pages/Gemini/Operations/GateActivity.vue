<script setup>
import { computed, onBeforeUnmount, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { register as registerLive, state as liveState, statusFor } from '../../../live/store.js'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import Subnav from './Subnav.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLifeSafetyPoll } from '../../../composables/useLifeSafetyPoll'
import { useLiveReload } from '../../../composables/useLiveReload'

// Every entry and exit at every gate lands here as it happens — live, with the poll behind it (M18H §7.2).
useLiveReload({ entities: ['gate'] })

/**
 * Live gate activity across every client — board screen super-admin-26.
 *
 * READ FROM A CENTRAL TABLE, which is the only way this screen can exist. A
 * cross-client feed built by querying each estate's database in turn would be a
 * tenant-isolation breach wearing a report's clothes. `gate_events` is written
 * centrally at the moment of each scan — Gemini's own account of work its
 * employee did, on a post it staffs — and carries no household, no resident and
 * no money. The table's migration is the whole argument.
 *
 * LIVE, AND IT SAYS SO. The board's topbar carries an amber dot and "updates
 * every 5s", which is a promise: if the feed stopped refreshing, a dispatcher
 * would read a quiet screen as a quiet night. So the poll is real and the
 * indicator is driven by it rather than drawn.
 *
 * A denial is not a failure. It is a guard doing their job, which is why the
 * board colours it rather than flagging it.
 */
const props = defineProps({
    kpis: { type: Array, required: true },
    feed: { type: Array, required: true },
    /**
     * Whether the gate decisions and scans in this feed came from real handsets
     * or from the simulator. See App\Support\SourceBadge.
     */
    sourceBadge: { type: Object, required: true },
})

const state = useScreenState({
    rows: () => props.feed.length,
})

/*
 * The three glyphs the board draws, and the weight it draws each at. A denial
 * is heavier than an admit and a patrol lighter than both — the board's own
 * emphasis, kept rather than normalised to one stroke.
 */
const GLYPH = {
    admit: { icon: 'check', stroke: 3 },
    deny: { icon: 'close', stroke: 2.4 },
    patrol: { icon: 'map', stroke: 1.6 },
}

const glyph = (verdict) => GLYPH[verdict] ?? GLYPH.patrol

/*
 * LIVE FROM THE GATE, NOT FROM A CLOCK (M11 Part C, DESIGN.md §2.9 I10–I13). Every admission, exit, override
 * and refusal arrives on the workforce feed the shell holds, and this screen reads its feed and KPIs again at
 * once (one read for a burst). The five-second poll stays as the fallback, and runs only while live updates
 * are not working — and the indicator above says which of the two is happening, so a quiet screen is never
 * read as a quiet gate when it is a dead socket.
 */
const isLive = () => statusFor() === 'live'
const poll = useLifeSafetyPoll(['kpis', 'feed'], 5000, { active: () => !isLive() })

/*
 * THE POLL FOLLOWS THE CHANNEL, as `useLiveDispatch` makes it on the dispatch screens (M98 security proof, 11 Oct).
 * `active` is read only when the page mounts, and the socket always comes up after that: on its own the poll never
 * stopped once the page was live, and a page opened while live never started it when the socket dropped, under a
 * line saying it was checking every five seconds. Now it stops when the channel is live and starts, with a read at
 * once, the moment it is not.
 */
const liveNow = computed(() => isLive())

watch(liveNow, (live) => {
    if (live) {
        poll.stop()

        return
    }

    poll.refresh()
    poll.start()
})

const retry = () => router.reload()

let reread = null
const unregisterLive = registerLive({
    page: true,
    signals: false,
    channels: { has: (name) => name === 'gemini.workforce' || /^gemini\.[^.]+\.workforce$/.test(name) },
    accepts: (env) => env.e === 'gate' || env.e === 'alert',
    apply() {
        clearTimeout(reread)
        reread = setTimeout(() => poll.refresh(), 150)
    },
    resync: () => poll.refresh(),
})
liveState.pageBindings++

onBeforeUnmount(() => {
    clearTimeout(reread)
    unregisterLive()
    liveState.pageBindings--
})

const liveWords = computed(() => (liveState.socket === 'connected' && liveNow.value ? 'Live' : 'Live updates down · checking every 5 seconds'))
</script>

<template>
    <Head title="Live gate activity" />

    <GeminiConsole title="Live gate activity">
            <template #byline>
                <SourceBadge v-bind="sourceBadge" />
            </template>

        <template #actions>
            <!--
              A status indicator, not a control. It is a <div> here because the
              board draws one and because there is nothing to click: it says whether
              the screen is live, or checking every five seconds because it is not.
              A button that did nothing would be worse than the text.
            -->
            <div class="btn-outline-sm" role="status">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="6" cy="6" r="4" style="fill: var(--amber-700)" />
                </svg>
                <span>{{ liveWords }}</span>
            </div>
        </template>

        <Subnav active="gate-activity" />

        <!-- The five states (M98 §3.8): a refused role and a failed read are said in words, with nothing of the feed. -->
        <EmptyState
            v-if="state.isDenied.value"
            variant="denied"
            title="Live gate activity is not part of your role’s access"
            body="It shows every client’s gates, so it opens only to roles that look after guards and their posts. A Super Admin can grant it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The gate feed could not be read"
            body="The platform database did not answer. This screen only reads, so nothing at any gate has been changed. Reading it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <BoardIcon
                            :name="kpi.icon"
                            :stroke="kpi.icon === 'check' ? 3 : kpi.icon === 'close' ? 2.4 : 1.7"
                        />
                    </div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <div style="background: var(--white); border: 1px solid var(--navy-100); border-radius: 16px; overflow: hidden">
            <SkeletonRows v-if="state.isLoading.value" :rows="8" :columns="4" />

            <!--
              Nothing at the gates. On a live feed that is a fact worth stating
              rather than an empty box: a dispatcher needs to know the screen is
              working and the night is quiet, not wonder which.
            -->
            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="Nothing at the gates yet today"
                body="Admits, denials and patrol check-ins appear here the moment a guard records them, across every client."
            />

            <div v-for="(row, i) in feed" v-else :key="i" class="feed-row">
                <div class="feed-icon" :class="row.verdict">
                    <BoardIcon :name="glyph(row.verdict).icon" :stroke="glyph(row.verdict).stroke" />
                </div>
                <div class="feed-txt">
                    <div class="f1">{{ row.headline }}</div>
                    <div class="f2">{{ row.detail }}</div>
                </div>
                <div class="feed-client-tag">{{ row.estate }}</div>
                <div class="feed-time">{{ row.time }}</div>
            </div>
        </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * A REFUSAL AT THE GATE IS AMBER (Hassam, 12 Oct: solid red is an open panic's alone, amber is every refusal). The board
 * tinted a refused row's tile pale red; the shared sheet already draws its mark amber (app.css), so the tile follows.
 * Under `html body` to outrank the board's own `body.wf-…` rule.
 */
html body .feed-icon.deny {
    background: var(--amber-100);
}
</style>
