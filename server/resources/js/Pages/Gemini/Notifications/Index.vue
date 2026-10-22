<script setup>
import { computed } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { GEMINI_ACTIVITY, useLiveReload } from '../../../composables/useLiveReload'

// What needs attention comes from all over the platform, so any change re-reads it (M18H §7.2).
useLiveReload({ entities: GEMINI_ACTIVITY })

/**
 * The notification centre — the dashboard bell (12 §2, item 41).
 *
 * NO BOARD DRAWS THIS SCREEN: board super-admin-01 draws the bell and nothing
 * behind it. Kept to the Gemini sheet's tokens.
 *
 * NOTHING HERE IS STORED BUT THE READ MARK. An open alert, a pending request, a
 * lapsing licence, an open incident, an invoice past due and a return owed are
 * each a live read of the record that owns the fact, and each leaves this list
 * the moment that record is dealt with — reading an item does not resolve it.
 *
 * EACH ITEM IS GATED ON ITS OWN MODULE, and scoped: a Head of Security covering
 * two sites hears about those two sites.
 */
const props = defineProps({
    items: { type: Array, required: true },
    unread: { type: Number, required: true },
})

const state = useScreenState({
    rows: () => props.items.length,
})

const form = useForm({ keys: [] })

const unreadKeys = computed(() => props.items.filter((item) => !item.is_read).map((item) => item.key))

const mark = (keys) => {
    if (keys.length === 0) {
        return
    }

    form.keys = keys
    form.post('/notifications/read', { preserveScroll: true })
}
</script>

<template>
    <Head title="Notifications" />

    <GeminiConsole title="Notifications" :back="{ href: '/dashboard', label: 'Back to dashboard' }">

        <template #actions>
            <button
                type="button"
                class="btn-outline-sm"
                :disabled="unreadKeys.length === 0 || form.processing"
                :title="unreadKeys.length === 0 ? 'You have looked at everything on this list.' : 'Mark all of these as seen. It is a note about you — nothing is resolved by reading it.'"
                @click="mark(unreadKeys)"
            >
                <span>Mark all as seen</span>
            </button>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="2" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The list could not be read"
            body="Nothing on this screen changes anything, so re-running the read is safe."
            action-label="Try again"
            @action="router.reload()"
        />

        <EmptyState
            v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
            variant="first-use"
            title="Nothing is waiting on you"
            body="This list holds what still needs somebody in the modules your role reaches — an open alert, a request waiting, a licence about to lapse, an incident not closed, an invoice past due, a return owed. None is outstanding right now."
        />

        <template v-else>
            <p class="nt-lead">
                {{ unread }} of {{ items.length }} not yet looked at. Urgent items come first; each leaves this list when
                its record is dealt with.
            </p>

            <div v-for="item in items" :key="item.key" class="nt-row" :class="{ read: item.is_read }">
                <div class="nt-dot" :class="{ on: !item.is_read, urgent: item.urgent && !item.is_read, alert: item.icon === 'alert' }" />

                <div class="nt-text">
                    <Link :href="item.href" class="nt-title" @click="mark(item.is_read ? [] : [item.key])">{{ item.title }}</Link>
                    <div class="nt-detail">{{ item.detail }}{{ item.when ? ` · ${item.when}` : '' }}</div>
                </div>

                <button
                    type="button"
                    class="text-link-sm"
                    :disabled="item.is_read || form.processing"
                    :title="item.is_read ? 'You have looked at this one.' : 'Mark this one as seen.'"
                    @click="mark([item.key])"
                >
                    {{ item.is_read ? 'Seen' : 'Mark seen' }}
                </button>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen. Kept to the tokens the Gemini boards
 * define.
 */

button {
    font-family: inherit;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
}

.nt-lead {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.55;
    margin: 0 0 14px;
}

.nt-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 12px;
    padding: 13px 15px;
    margin-bottom: 9px;
}

.nt-row.read {
    background: var(--navy-100);
    border-color: transparent;
}

.nt-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: transparent;
    margin-top: 5px;
    flex: 0 0 auto;
}

.nt-dot.on {
    background: var(--amber-600);
}

/*
 * RED IS AN OPEN ALERT'S ALONE (Hassam's three weights, 12 Oct; RED_MAP.md, routed to Security). Urgent is wider than
 * that: a lapsed licence, a mocked location at clock-in, a high-severity incident and a late return are urgent too, and
 * each is amber, a shade deeper than an ordinary unread item. Only an open panic or duress alert keeps the red.
 */
.nt-dot.urgent {
    background: var(--amber-700);
}

.nt-dot.urgent.alert {
    background: var(--red-700);
}

.nt-text {
    flex: 1 1 auto;
}

.nt-title {
    display: block;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.5;
    text-decoration: none;
}

.nt-detail {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.55;
}
</style>
