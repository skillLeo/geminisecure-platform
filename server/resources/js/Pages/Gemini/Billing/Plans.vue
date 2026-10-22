<script setup>
import { Head, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BillingTabs from './BillingTabs.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveKind } from '../../../composables/useLiveKind'

// The plan catalogue is an editor: never re-read under somebody's changes (M18H §7.2).
useLiveKind('form')

/**
 * Subscription plans — board screen super-admin-34.
 *
 * The rate card: what each tier costs, who is on it, and what it is sold as.
 *
 * TWO VOCABULARIES, KEPT APART. The three lines under each price are
 * `highlights` — what a tier is SOLD as, in the customer's words. What a tier
 * CONTAINS is `plan_features`, the toggle grid on the package builder that the
 * runtime resolves an estate's feature set from. Deriving one from the other
 * would put toggle keys on a pricing card, or marketing copy into the table
 * the platform runs on.
 *
 * The client line under each price is counted from live subscriptions, so a
 * client moving tier moves between cards without anyone editing a number.
 *
 * NOTHING HERE WRITES. Changing a tier price re-prices every estate on it from
 * an effective date; that belongs to the package builder's approval path, not
 * to the screen that displays the rate card.
 */
const props = defineProps({
    plans: { type: Array, required: true },
    addon: { type: Object, default: null },
    writeDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.plans.length,
})
</script>

<template>
    <Head title="Subscription plans" />

    <GeminiConsole title="Subscription plans">
        <BillingTabs active="plans" />

        <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="3" />

        <!-- The fifth state (M95): a read that failed says so, with a way to read it again, where it fell to the cards. -->
        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The plans could not be read"
            body="Nothing on this screen changes anything, so reading it again is safe."
            action-label="Try again"
            @action="router.reload()"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No active plans"
            body="A client is priced against a tier. Until one is published there is nothing to place an estate on."
        />

        <template v-else>
            <div class="plan-grid">
                <!--
                  The board draws each card as a <div> and nothing on it is a
                  control: a tier is not chosen from here, it is set on the
                  client. So these stay <div>s, which is the board's own DOM
                  and is also correct — an element that cannot be clicked
                  should not look like it can.
                -->
                <div v-for="plan in plans" :key="plan.key" class="plan-card" :class="{ premium: plan.emphasised }">
                    <div class="plan-name">{{ plan.name }}</div>
                    <div class="plan-price">
                        {{ plan.price }}
                        <span>/ unit / mo</span>
                    </div>
                    <div class="plan-clients">{{ plan.clients }}</div>

                    <!-- A tier nobody has written a pitch for renders without
                         the list rather than with an empty box. -->
                    <div v-if="plan.highlights.length" style="margin-top: 14px">
                        <div v-for="(line, i) in plan.highlights" :key="i" class="plan-feat">
                            <BoardIcon name="check" :stroke="3" />
                            <span>{{ line }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!--
              The per-guard charge sits on top of any tier, so it is drawn
              below the grid rather than as a fourth card. Omitted entirely
              when no such rate is on file: a platform that does not charge per
              guard has no add-on to describe.
            -->
            <div v-if="addon" class="addon-card">
                <div class="addon-icon">
                    <BoardIcon name="shield" :stroke="1.7" />
                </div>
                <div class="addon-info">
                    <div class="addon-name">{{ addon.name }}</div>
                    <div class="addon-desc">{{ addon.detail }}</div>
                </div>
                <div class="addon-price">
                    {{ addon.price }}
                    <span>/ guard / mo</span>
                </div>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * ON A PHONE (seen at 390 px, 12 Oct: the third tier card ran off the right, the page 440 px wide). The three tier
 * cards one under another, and the add-on's price under its words. Doubled class: the board's sheet names each once.
 */
@media (max-width: 640px) {
    .plan-grid.plan-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .addon-card.addon-card {
        flex-wrap: wrap;
    }

    .addon-info.addon-info {
        flex: 1 1 160px;
        min-width: 0;
    }
}
</style>
