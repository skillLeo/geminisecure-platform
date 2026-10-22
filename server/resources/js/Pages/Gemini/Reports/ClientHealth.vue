<script setup>
import { Head } from '@inertiajs/vue3'
import ReportShell from './ReportShell.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveKind } from '../../../composables/useLiveKind'

// A report is a figure worked out at a moment: "As of …", and Refresh works it out again (M18H §7.2).
useLiveKind('snapshot')

/**
 * Client health — board screen super-admin-07.
 *
 * One card per client, a bar per capability, and a chip saying whether they are
 * getting what they pay for.
 *
 * EVERY BAR IS TWO INTEGERS, NOT A SCORE. The hover says what was counted — "2
 * of 4 active posts worked in the last 7 days by a licensed guard on a bound
 * handset" — because a bar at 50% with nothing behind it is a number an account
 * manager cannot act on or defend in a renewal conversation.
 *
 * A CAPABILITY WITH NO BAR IS NAMED, NOT DROPPED. Two different things put it
 * there: nobody has counted it yet, or the client has nothing to count. Both are
 * stated in words under the bars, because a missing bar and a bar at zero look
 * the same on a chart and mean opposite things.
 *
 * NOTHING HERE READS AN ESTATE DATABASE. The figures come from the central
 * roll-up each side writes for the capabilities it owns; the note at the foot
 * says which capabilities are still waiting on their owner and why.
 */
const props = defineProps({
    clients: { type: Array, required: true },
    scoped: { type: Boolean, required: true },
    awaiting: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.clients.length,
})
</script>

<template>
    <Head title="Client health" />

    <ReportShell title="Client health">
        <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="3" />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            :title="scoped ? 'No clients at your sites' : 'No clients yet'"
            :body="
                scoped
                    ? 'Adoption appears here for the clients you are assigned to. You are not assigned to any yet.'
                    : 'Once a client is onboarded, how much of what they bought they are actually using appears here.'
            "
        />

        <template v-else>
            <div v-for="client in clients" :key="client.id" class="health-card">
                <div class="health-top">
                    <div class="health-name">{{ client.name }}</div>
                    <div class="health-score" :class="client.score_class" :title="client.score_title">
                        {{ client.score_label }}
                    </div>
                </div>

                <div v-for="bar in client.bars" :key="bar.key" class="feature-bar-row" :title="bar.title">
                    <div class="feature-lbl">{{ bar.label }}</div>
                    <div class="feature-track"><i :style="{ width: bar.pct + '%' }"></i></div>
                    <div class="feature-pct">{{ bar.pct }}%</div>
                </div>

                <!--
                  Named rather than dropped. A client with nothing to count is
                  not a client doing badly, and the card has to be able to say
                  the difference.
                -->
                <div v-for="row in client.unmeasured" :key="row.label" class="health-note">
                    {{ row.label }} · {{ row.reason }}
                </div>

                <div v-if="client.bars.length === 0 && client.unmeasured.length === 0" class="health-note">
                    Nothing has been reported for this client yet.
                </div>
            </div>

            <p class="health-foot">{{ awaiting }}</p>
        </template>
    </ReportShell>
</template>

<style scoped>
/*
 * Authored, and only where the board has nothing to copy.
 *
 * The cards, the chips and the bars are all the board's own classes and take
 * nothing from here. These two rules cover what the board never had to draw: a
 * capability that could not be counted, and the sentence explaining why some
 * still cannot be. Both are kept to the tokens the boards define.
 */
.health-note {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.6;
    padding: 5px 0;
}

.health-foot {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.6;
    max-width: 720px;
    margin: 14px 2px 0;
}
</style>
