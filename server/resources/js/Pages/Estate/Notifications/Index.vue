<script setup>
import { computed } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { ESTATE_ACTIVITY, useLiveReload } from '../../../composables/useLiveReload'

/**
 * The notification centre — the dashboard bell (12 §2, Wave 2).
 *
 * NOTHING HERE IS STORED. Every item is derived from the table that owns the
 * fact: a claim waiting for review is a row in `unit_claims`, a ticket past its
 * target is a row in `maintenance_tickets`. An item that resolves stops being
 * derived — a claim approved on board 34 is simply gone from this list the next
 * time it is drawn, with nothing to go back and tidy. A notifications table
 * would instead leave a row standing that says the claim still needs review,
 * and the reader would believe it.
 *
 * WHAT IS STORED IS THE READ MARK, per viewer. Two officers do not share an
 * inbox: what the Treasurer has read is not what the Secretary has read, and a
 * bell that cleared for everybody the moment one person looked would hide work
 * from the rest.
 *
 * EACH ITEM IS GATED ON ITS OWN RECORD. A notification is a summary, and a role
 * that may not read the record may not read the summary either — the server
 * asks the gate per item, so each role sees only what its own modules hold.
 *
 * NO DETAIL PAGE, AND NO LINK THAT REFUSES (M98). An item is a line about a
 * record the reader's own module already shows; only a message from Gemini
 * Security carries a link, to the inbox every estate member reaches.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    items: { type: Array, required: true },
    unread: { type: Number, required: true },
    dashboardHref: { type: String, required: true },
    path: { type: String, required: true },
})

useWireframe('community-admin-01-login-dashboard-structure-and-residents')

// What needs the office's attention comes from all over the estate, so any change re-reads it (M18H §7.2).
useLiveReload({ entities: ESTATE_ACTIVITY })

const state = useScreenState({
    rows: () => props.items.length,
})

const retry = () => router.reload()

const form = useForm({ keys: [] })

const unreadKeys = computed(() => props.items.filter((item) => !item.is_read).map((item) => item.key))

const markAll = () => {
    if (unreadKeys.value.length === 0) {
        return
    }

    form.keys = unreadKeys.value
    form.post(`${props.path}/read`, { preserveScroll: true })
}

const markOne = (item) => {
    if (item.is_read) {
        return
    }

    form.keys = [item.key]
    form.post(`${props.path}/read`, { preserveScroll: true })
}
</script>

<template>
    <Head title="Notifications" />

    <EstateConsole
        title="Notifications"
        :estate-name="estate.name"
        active="dashboard"
        :back="{ href: dashboardHref, label: 'Back to the dashboard' }"
    >
        <template #actions>
            <!-- M96 level 2: greyed, with why beside it, while everything here has been seen. -->
            <span v-if="items.length > 0 && unreadKeys.length === 0" class="nt-why">Everything here has been seen.</span>
            <button
                v-if="items.length > 0"
                type="button"
                class="btn-outline-sm"
                :disabled="unreadKeys.length === 0 || form.processing"
                title="Mark everything on this list as seen."
                @click="markAll"
            >
                <span>Mark all as seen</span>
            </button>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="2" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="Your notifications could not be loaded"
            body="Nothing has changed, and trying again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
            variant="first-use"
            title="Nothing is waiting on you"
            body="This list holds what still needs somebody in the parts of the estate your role reaches: a unit claim waiting for review, a maintenance ticket past its target, a deposit still held after a booking ended. Nothing is waiting right now."
        />

        <template v-else>
            <p class="nt-lead">
                {{ unread === 0 ? `All ${items.length} seen.` : `${unread} of ${items.length} not seen yet.` }}
                Marking one as seen does not settle it: it leaves this list once it is dealt with.
            </p>

            <div v-for="item in items" :key="item.key" class="nt-row" :class="{ read: item.is_read }">
                <div class="nt-dot" :class="{ on: !item.is_read }" />

                <div class="nt-text">
                    <!--
                        MOST ITEMS HAVE NOWHERE TO GO, and that is not an omission.
                        Each one is a summary of a record the viewer already reaches
                        through its own module, so a link here would be a second
                        route to the same screen. A message from Gemini Security has
                        no other home, so the server gives that one an href and the
                        title becomes the way in.
                    -->
                    <Link v-if="item.href" :href="item.href" class="nt-title">{{ item.title }}</Link>
                    <div v-else class="nt-title">{{ item.title }}</div>

                    <div class="nt-detail">{{ item.detail }}{{ item.when ? ` · ${item.when}` : '' }}</div>
                </div>

                <!-- Seen is where this item stands, not something to press (M96 level 3). -->
                <span v-if="item.is_read" class="nt-seen">Seen</span>
                <button
                    v-else
                    type="button"
                    class="text-link-sm"
                    :disabled="form.processing"
                    title="Mark this one as seen."
                    @click="markOne(item)"
                >
                    Mark seen
                </button>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen — the dashboard draws the bell that
 * leads here and nothing behind it. Kept to the tokens the boards define.
 */
button {
    font-family: inherit;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/* The boards' text link (board 3 declares it; this board does not), on a button: what "Mark seen" does. */
button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font-size: 11.5px;
    color: var(--amber-600);
    font-weight: 700;
    white-space: nowrap;
}

button.text-link-sm[disabled] {
    opacity: 0.55;
}

/* Where an item stands once it has been seen: a word, not a control. */
.nt-seen {
    font-size: 11.5px;
    color: var(--slate-500);
    font-weight: 600;
    white-space: nowrap;
}

/* Why "Mark all as seen" is greyed, beside it and readable without hovering (M96 level 2). */
.nt-why {
    font-size: 11.5px;
    color: var(--slate-600);
    align-self: center;
    margin-right: 10px;
}

.btn-outline-sm[disabled] {
    opacity: 0.55;
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

.nt-text {
    flex: 1 1 auto;
}

.nt-title {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.5;
}

/* Default-removal only: an anchor arrives underlined, and the row above already
 * carries the weight and colour the board gives a title. */
a.nt-title {
    display: block;
    text-decoration: none;
}

a.nt-title:hover {
    text-decoration: underline;
}

.nt-detail {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.55;
}
</style>
