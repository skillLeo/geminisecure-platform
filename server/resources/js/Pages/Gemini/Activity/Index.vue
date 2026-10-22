<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { GEMINI_ACTIVITY, useLiveReload } from '../../../composables/useLiveReload'

// Everything the platform does lands here, so every kind of change re-reads it (M18H §7.2).
useLiveReload({ entities: GEMINI_ACTIVITY })

/**
 * Recent activity — board screen super-admin-03.
 *
 * The board's body is one card holding a list of rows and nothing else: no
 * search, no filter chips, no pager. That is reproduced as drawn. The full
 * standing record of who did what is the Access & audit log, which is its own
 * module with its own board and its own permission — this screen is the
 * platform's recent history, and the dashboard panel shows its first rows.
 *
 * The card's wrapper carries an inline style because the board's stylesheet
 * defines no class for it. Copied verbatim rather than given a class of my own.
 */
const props = defineProps({
    events: { type: Array, required: true },
    /**
     * Why paid invoices and plan names are left out of this feed, or null when nothing is
     * (M10 §5). The server has already dropped those rows; this only stops the empty state
     * promising a kind of event this reader will never be shown.
     */
    withheld_reason: { type: String, default: null },
})

const state = useScreenState({
    rows: () => props.events.length,
})
</script>

<template>
    <Head title="Recent activity" />

    <GeminiConsole title="Recent activity" :back="{ href: '/dashboard', label: 'Back to dashboard' }">

        <div style="background: var(--white); border: 1px solid var(--navy-100); border-radius: 16px; overflow: hidden">
            <div v-if="state.isLoading.value" style="padding: 8px 18px">
                <SkeletonRows :rows="8" :columns="3" />
            </div>

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="Nothing has happened yet"
                :body="
                    withheld_reason
                        ? 'Onboarding and deployment events appear here as they happen across every client.'
                        : 'Onboarding, billing and deployment events appear here as they happen across every client.'
                "
            />

            <template v-else>
                <div v-for="event in events" :key="event.key" class="activity-row2">
                    <div class="a-icon2">
                        <BoardIcon :name="event.icon" :stroke="1.6" />
                    </div>
                    <div class="a-txt2">
                        <div class="at1">{{ event.title }}</div>
                        <div class="at2">{{ event.meta }}</div>
                    </div>
                    <div class="a-time">{{ event.when }}</div>
                </div>
            </template>
        </div>
    </GeminiConsole>
</template>

<style scoped>
</style>
