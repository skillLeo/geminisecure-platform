<script setup>
import { watch } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'
import { toast } from '../../../lib/toasts.js'

/**
 * Reports — board screen community-admin-29.
 *
 * SEVEN CARDS, SIX OF WHICH RUN (12 §1). Each running card opens its report
 * over a period the reader chooses. Budget vs Actual stays inert with its own
 * sentence — there is no approved budget on this platform to compare against —
 * and a role without the Reports verb is told that instead, because the two
 * refusals send a reader to ask for different things.
 *
 * THE BOARD DRAWS `.rc-action` AS A DIV, AND HERE IT IS A BUTTON. That is the
 * rule this project has held to throughout: something that looks like a control
 * is a control, focusable and reachable by keyboard, and if it cannot act it
 * carries `disabled` and a title. A styled <div> that swallows a click is the
 * defect `gate:interactivity` exists to find.
 *
 * THE ICONS ARE THE BOARD'S OWN, ONE PER CARD. Seven different glyphs, restated
 * as real markup rather than injected as strings — a shared icon across two
 * cards would be visible in the diff, and `v-html` for an SVG is a habit worth
 * not starting.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    groups: { type: Array, required: true },
    canGenerate: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Board 29 is drawn in "Reports Unit Claims and Notices" — its own sheet
 * defines `.report-group`, `.report-grid` and `.report-card`, and no other
 * sheet defines any of them. Checked against the sheet rather than inferred
 * from the sidebar item it sits under.
 */
useWireframe('community-admin-08-reports-unit-claims-and-notices')

// Reports are worked out at a moment and say so — "As of …" — rather than moving under the reader (M18H §7.2).
useLiveKind('snapshot')

/*
 * The catalogue is a fixed list and cannot be empty, so the only states this
 * screen can actually reach are populated, loading and denied. `?_state=empty`
 * still resolves — it falls through to the catalogue, which is the truthful
 * answer: there is no estate for which this screen has nothing to show.
 */
const page = usePage()

const state = useScreenState({
    rows: () => props.groups.length,
})

/*
 * WHY A REPORT ASKED FOR IS NOT ON THE SCREEN, one this estate cannot run, sent back here from its own address, is a
 * message top right that stays until closed (M95 §2). The sentence is the server's; it was a red banner on the page.
 */
watch(
    () => page.props.errors,
    (errors) => {
        if (errors?.report) {
            toast(errors.report, { tone: 'error' })
        }
    },
    { immediate: true },
)

/**
 * What to say on a control that will not act.
 *
 * THREE DIFFERENT REFUSALS, AND A READER IS OWED THE ONE THAT APPLIES TO THEM. A
 * Secretary holds View on Reports and could not generate anything; a Treasurer
 * holds Reports and not Security, so the incident log is withheld from them for
 * that (`card.withheld`, the server's sentence — B137); and Budget vs Actual has
 * no budget to read. Naming the wrong one sends somebody to ask for access they
 * already have — or to wait for a feature that would still refuse them.
 */
const refusal = (card) => (props.canGenerate ? card.withheld ?? card.reason : props.reasons.access)

/** Where a card leads. Null for one this reader cannot run, whichever the reason. */
const reportHref = (card) =>
    props.canGenerate && card.built && !card.withheld
        ? `${page.url.split('?')[0].replace(/\/$/, '')}/${card.key}`
        : null

/*
 * M96 LEVELS. Blocked for now (level 2) is only a report this estate cannot run yet, Budget vs Actual until budgets are
 * switched on: its Generate greyed, the reason under it. A report that is never this role's to run (no Reports export,
 * or no view of the module it reads) is level 3: no button at all, only the reason in one line.
 */
const blockedForNow = (card) => props.canGenerate && !card.withheld && !card.built
</script>

<template>
    <Head title="Reports" />

    <EstateConsole title="Reports" :estate-name="estate.name" active="reports">
        <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Reports is not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <template v-else>
            <div v-for="group in groups" :key="group.heading" class="report-group">
                <div class="report-group-head">{{ group.heading }}</div>

                <div class="report-grid">
                    <div v-for="card in group.cards" :key="card.key" class="report-card">
                        <div class="rc-icon">
                            <svg viewBox="0 0 24 24" fill="none">
                                <template v-if="card.icon === 'currency'">
                                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                                </template>
                                <template v-else-if="card.icon === 'clock'">
                                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/>
                                    <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                                </template>
                                <template v-else-if="card.icon === 'calendar'">
                                    <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/>
                                    <path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                                </template>
                                <template v-else-if="card.icon === 'spanner'">
                                    <path d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                </template>
                                <template v-else-if="card.icon === 'shield'">
                                    <path d="M12 2 2 7v6c0 5.2 3.8 9 10 11 6.2-2 10-5.8 10-11V7l-10-5z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
                                </template>
                                <template v-else-if="card.icon === 'people'">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.7"/>
                                    <circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="1.7"/>
                                </template>
                                <template v-else-if="card.icon === 'megaphone'">
                                    <path d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
                                    <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                                </template>
                            </svg>
                        </div>

                        <div class="rc-name">{{ card.name }}</div>
                        <div class="rc-desc">{{ card.description }}</div>

                        <!--
                          Six of the seven run (12 §1). A card that cannot (Budget
                          vs Actual until budgets are switched on, a module the role
                          does not hold, a role that reads the catalogue only) LOOKS
                          it and says why on the card in one line (M83 item 3, M84
                          §3.3, REP-1; Law 1): it was a disabled button drawn exactly
                          like the six links, its only signal the cursor on hover,
                          which a touch screen never shows.
                        -->
                        <Link v-if="reportHref(card)" :href="reportHref(card)" class="rc-action">
                            {{ card.action }}
                        </Link>
                        <template v-else-if="blockedForNow(card)">
                            <button type="button" class="rc-action rc-action-off" disabled :title="refusal(card)">
                                {{ card.action }}
                            </button>
                            <p class="rc-why">{{ refusal(card) }}</p>
                        </template>
                        <!-- M96 level 3: never this role's to run, so no button, and why in one line. -->
                        <p v-else class="rc-why">{{ refusal(card) }}</p>
                    </div>
                </div>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws `.rc-action` as a <div>; here it is a
 * real <button>, which arrives with a border, buttonface grey, an intrinsic
 * width and the browser's own font. The sheet's `.report-card .rc-action` rule
 * supplies everything visible — background, radius, height, colour, weight —
 * and nothing below reaches past a browser default, which is the mistake D-045
 * records.
 *
 * `width: 100%` is the one line that looks like styling and is not: the board's
 * div is a block and fills the card, while a button shrinks to its text. This
 * restores what the element was, rather than deciding anything new.
 */
button.rc-action {
    width: 100%;
    border: 0;
    font-family: inherit;
    line-height: inherit;
    font-size: 11.5px;
    font-weight: 700;
    cursor: not-allowed;
}

/*
 * A CARD THAT CANNOT RUN LOOKS IT (M83 item 3, REP-1): no fill, a dashed edge and slate words, where a card that runs
 * is the board's filled navy. Its one-line reason sits under it, where a touch screen can read it too.
 */
.report-card button.rc-action.rc-action-off {
    background: transparent;
    border: 1px dashed var(--slate-300);
    color: var(--slate-500);
}

.rc-why {
    margin: 6px 0 0;
    font-size: 10.5px;
    line-height: 1.4;
    color: var(--slate-500);
}

/* A card that runs is a link. The sheet's own .rc-action supplies everything
 * visible; only the anchor's underline and its inline width come off. */
a.rc-action {
    display: block;
    width: 100%;
    text-align: center;
    text-decoration: none;
}
</style>
