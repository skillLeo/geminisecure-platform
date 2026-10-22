<script setup>
import { Head, router } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * Messages from Gemini Security — the committee inbox (Q-M40, D-M128).
 *
 * WHY THIS SCREEN EXISTS. A Gemini operator could write to this committee, and
 * the platform recorded the message and delivered nothing: no inbox, no mail, no
 * notification. The sender's screen printed "unread" beside every message ever
 * sent, for ever, because the column it read was never written. These are the
 * messages that decide disputes — a guard stood down for a lapsed licence, an
 * invoice falling due, an incident overnight — so the fix was delivery rather
 * than better wording.
 *
 * EVERY MESSAGE IS SHOWN IN FULL, and there is no message to click into. A screen
 * that shows a subject and hides the body is one more place delivery can be
 * claimed and not happen; and opening the list is what marks a message opened, so
 * the mark has to mean the words were on the screen.
 *
 * ADDRESSED, NOT PERMISSIONED. The server reads only messages addressed to the
 * person signed in, at the estate this console is open on. Nothing on this page
 * takes an id, so there is nowhere to point it at somebody else's post.
 *
 * NO BOARD DRAWS THIS SCREEN. It wears the same board class as the notification
 * centre it is reached from — the two are the same surface, "something arrived
 * for you" and "here is what arrived" — so the rows, the empty state and the
 * back control are the ones a committee member already knows.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    messages: { type: Array, required: true },
    /** The notifications a message from Gemini Security is reached from, and the way back to them. */
    notificationsHref: { type: String, required: true },
})

useWireframe('community-admin-01-login-dashboard-structure-and-residents')

// A message from Gemini arrives on this list while it is open (M18H §7.2).
useLiveReload({ entities: ['client'] })

const state = useScreenState({
    rows: () => props.messages.length,
})

const retry = () => router.reload()
</script>

<template>
    <Head title="Messages from Gemini Security" />

    <EstateConsole
        title="Messages from Gemini Security"
        :estate-name="estate.name"
        active="dashboard"
        :back="{ href: notificationsHref, label: 'Back to notifications' }"
    >
        <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="2" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="Your messages could not be loaded"
            body="Nothing has changed, and trying again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
            variant="first-use"
            title="Nothing from Gemini Security yet"
            body="Messages Gemini Security writes to you about this estate arrive here: a guard stood down, an invoice falling due, an incident overnight. A message for another committee member goes to their console, not here."
        />

        <template v-else>
            <p class="msg-lead">
                Written to you by Gemini Security about {{ estate.name }}. They see when you first opened each one,
                and nothing more.
            </p>

            <article v-for="message in messages" :key="message.id" class="msg-card">
                <div class="msg-head">
                    <div class="msg-subject">{{ message.subject }}</div>
                    <div class="msg-tag">{{ message.category }}</div>
                </div>

                <div class="msg-meta">
                    From {{ message.from }} · {{ message.sent }}
                    <template v-if="message.opened"> · you first opened it {{ message.opened }}</template>
                    <template v-else> · opened just now</template>
                </div>

                <p class="msg-body">{{ message.body }}</p>
            </article>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen. Kept to the tokens the boards define,
 * and to the notification centre's own card shape so the two read as one place.
 */
.msg-lead {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.55;
    margin: 0 0 14px;
}

.msg-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 12px;
    padding: 15px 17px;
    margin-bottom: 10px;
}

.msg-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
}

.msg-subject {
    font-size: 13px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.45;
}

.msg-tag {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--navy-700);
    background: var(--navy-100);
    border-radius: 999px;
    padding: 3px 9px;
    flex: 0 0 auto;
}

.msg-meta {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.55;
    margin-top: 3px;
}

.msg-body {
    font-size: 12px;
    color: var(--navy-900);
    line-height: 1.65;
    margin: 11px 0 0;
    white-space: pre-wrap;
}
</style>
