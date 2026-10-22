<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * Request history — the inbox's "Request history" (12 §2, Wave 4).
 *
 * NO BOARD DRAWS THIS SCREEN, and it is not a fidelity target. Board 16 draws
 * the inbox and a button to its history; this is where the button leads, built
 * in the inbox's own sheet so the two read as one module.
 *
 * READ FROM THE REQUEST ROWS, NOT THE AUDIT LOG. Every decision also writes an
 * audit entry, and the audit screen lists those. This is the dispatch view of
 * the same decisions — what was asked, in the words the inbox used, and what
 * became of it — and the row carries every fact it shows.
 *
 * A DENIAL SHOWS ITS REASON. The inbox refuses a denial without one because a
 * guard told no without a reason can neither act on it nor appeal it; a history
 * that dropped the reason would undo that on the only screen anyone reads later.
 */
const props = defineProps({
    /** Category B (13 C3): whether this screen's records came from a real handset or the simulator. */
    sourceBadge: { type: Object, required: true },
    sections: { type: Array, required: true },
    rows: { type: Array, required: true },
    /*
     * WHERE THE NEXT PAGE STARTS — a cursor, not a page number, and null when nothing is older.
     * This list is newest-decision-first and decisions arrive at the top of it, so an offset
     * served the same decision twice to anybody reading while a colleague was clearing the
     * queue. A cursor names the last row actually read.
     *
     * A cursor points ONE WAY, so there is no "one page newer" to offer: the way back is the
     * top of the list, and it is drawn only when the reader is not already there.
     */
    next_before: { type: String, default: null },
})

const atNewest = () => !new URL(window.location.href).searchParams.get('before')

const state = useScreenState({
    rows: () => props.rows.length,
})

const retry = () => router.reload()

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['request'] })
</script>

<template>
    <Head title="Request history" />

    <GeminiConsole title="Dispatch · request history" :back="{ href: '/dispatch/requests', label: 'Back to requests' }">
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
        </template>


        <div class="subnav">
            <Link
                v-for="section in sections"
                :key="section.label"
                :href="section.href"
                class="subnav-item"
                :class="{ active: section.active }"
            >
                {{ section.label }}
            </Link>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="8" :columns="4" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The history could not be read"
            body="Nothing on this screen writes anything, so no request has changed and reading it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Dispatch is not part of your role's access"
            body="Request history is part of the dispatch module, and your role does not hold it."
        />

        <EmptyState
            v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
            variant="first-use"
            title="No request has been decided yet"
            body="Every leave, duty and equipment request that is approved or denied from the inbox appears here — what was asked, what was decided, who decided it and when, and the reason for any denial."
            action-label="Open the inbox"
            @action="router.visit('/dispatch/requests')"
        />

        <template v-else>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Request</th>
                        <th>Decision</th>
                        <th>Decided by</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>
                            <div class="rh-title">{{ row.title }}</div>
                            <div class="rh-detail">{{ row.detail }}</div>
                        </td>
                        <td>
                            <span class="rh-status" :class="row.status">{{ row.status_label }}</span>
                            <div v-if="row.note" class="rh-detail">{{ row.note }}</div>
                        </td>
                        <td>{{ row.decided_by }}</td>
                        <td>{{ row.decided_at }}</td>
                    </tr>
                </tbody>
            </table>

            <div class="rh-pager">
                <Link v-if="!atNewest()" href="/dispatch/requests/history" class="rh-page">Newest</Link>
                <span v-else class="rh-page off">Newest</span>
                <Link v-if="next_before" :href="`/dispatch/requests/history?before=${encodeURIComponent(next_before)}`" class="rh-page">Older</Link>
                <span v-else class="rh-page off">Older</span>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen — see the head of the script. The table
 * is the boards' own `.data-table`; only the decision pill, the pager and the
 * back chevron need rules, all kept to the tokens the Gemini boards define.
 */
a.subnav-item {
    text-decoration: none;
}


.rh-title {
    font-weight: 600;
    color: var(--navy-900);
}

.rh-detail {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.5;
}

.rh-status {
    display: inline-block;
    font-size: 10.5px;
    font-weight: 700;
    border-radius: 20px;
    padding: 3px 10px;
}

.rh-status.approved {
    background: var(--success-100);
    color: var(--success-700);
}

.rh-status.denied {
    background: var(--red-100);
    color: var(--red-700);
}

.rh-pager {
    display: flex;
    gap: 18px;
    margin-top: 14px;
}

.rh-page {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-600);
    text-decoration: none;
}

.rh-page.off {
    color: var(--slate-400);
}
</style>
