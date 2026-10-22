<script setup>
import { computed, ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import BackLink from '../../../Components/BackLink.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import Subnav from './Subnav.vue'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * One incident — board 27's "View" (12 §2, item 27).
 *
 * NO BOARD DRAWS THIS SCREEN, and it is not a fidelity target. It is built in
 * the operations sheet, under the operations subnav, so it reads as the log's
 * own record.
 *
 * A RESOLUTION CLOSES THE INCIDENT, AND IS FINAL. The form asks for what was
 * done, because "resolved" with nothing after it reads the same as open to
 * whoever comes to it later. A closed incident shows its resolution and offers
 * nothing: it is not rewritten, and something that happens again is logged
 * again, with its own date.
 */
const props = defineProps({
    /** Category B (13 C3): whether this screen's records came from a real handset or the simulator. */
    sourceBadge: { type: Object, required: true },
    incident: { type: Object, required: true },
    canResolve: { type: Boolean, required: true },
    writeDisabledReason: { type: String, required: true },
})

/*
 * EVERY MESSAGE A TOAST, TOP RIGHT (M95 §2): what a close did is the Gemini shell's toast now (12 October), so this page
 * says none of its own; a refusal of the close is said inside its dialog, under what was typed.
 */

/*
 * CLOSING IS THE CONSOLE'S MODAL (M95 §1, M98 pass): a Close incident button for a role that may close it (M96 level
 * 3 otherwise: nothing to press), opening the dialog with what was done. It was a form always open under the record.
 */
const form = useForm({ resolution: '' })
const closing = ref(false)

const closeBlocked = computed(() => (form.resolution.trim() === '' ? 'Write what was done first.' : null))

const openClosing = () => {
    form.clearErrors()
    closing.value = true
}

const resolve = () => form.post(`/guards/incidents/${props.incident.id}/resolve`, {
    preserveScroll: true,
    onSuccess: () => {
        closing.value = false
        form.reset()
    },
})

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['incident'] })
</script>

<template>
    <Head :title="`Incident: ${incident.kind}`" />

    <GeminiConsole title="Security incident">
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
        </template>

        <!-- The way back, in words, to the log this incident is opened from (M95 §3): the shared back control. -->
        <template #lead>
            <BackLink href="/guards/incidents" label="Back to incidents" />
        </template>

        <Subnav active="incidents" />

        <div class="inc-card">
            <div class="inc-top">
                <div>
                    <div class="inc-kind">{{ incident.kind }}</div>
                    <div class="inc-sub">{{ incident.estate }} · {{ incident.occurred_at }}</div>
                </div>
                <div class="inc-badges">
                    <!-- Severity is a scale, not a status (Hassam, 11 Oct): High is a red outline with an icon and the word. -->
                    <div class="severity-badge sev" :class="incident.severity">
                        <BoardIcon v-if="incident.severity === 'high'" name="alert" :stroke="2" class="sev-icon" />
                        <span>{{ incident.severity_label }}</span>
                    </div>
                    <div class="status-badge" :class="incident.status">{{ incident.status_label }}</div>
                </div>
            </div>

            <dl class="inc-dl">
                <dt>Guard on post</dt>
                <dd>{{ incident.guard }}</dd>
                <dt>Logged</dt>
                <dd>{{ incident.logged_at ?? 'Not recorded' }} by {{ incident.logged_by }}</dd>
                <dt>What happened</dt>
                <dd class="inc-text">{{ incident.detail ?? 'No account recorded.' }}</dd>
                <template v-if="incident.resolution">
                    <dt>What was done</dt>
                    <dd class="inc-text">{{ incident.resolution }}</dd>
                    <dt>Closed</dt>
                    <dd>{{ incident.closed_at ?? 'Not recorded' }}</dd>
                </template>
            </dl>
        </div>

        <!--
          M96: an open incident and a role that may close it get Close incident (level 1); a role that never may gets
          nothing to press (level 3); a closed incident already shows what was done above (level 3).
        -->
        <div v-if="incident.status === 'open' && canResolve">
            <button type="button" class="btn-primary-sm" title="Close the incident with what was done." @click="openClosing">
                <span>Close incident</span>
            </button>
        </div>

        <ConsoleDialog
            :open="closing"
            title="Close this incident?"
            size="form"
            confirm-label="Close incident"
            busy-label="Closing…"
            cancel-label="Keep it open"
            :busy="form.processing"
            :dirty="form.isDirty"
            discard-title="Discard what you wrote?"
            :confirm-blocked-reason="closeBlocked"
            :error="form.errors.incident ?? null"
            @confirm="resolve"
            @cancel="closing = false"
        >
            <p class="inc-note">
                Record what was done. Closing is final: a closed incident is not rewritten, and anything that happens again
                is logged again.
            </p>
            <RequiredMark legend />
            <label for="inc-resolution">What was done <RequiredMark /></label>
            <textarea id="inc-resolution" v-model="form.resolution" rows="5" maxlength="4000" placeholder="e.g. Patrol increased on that stretch for the rest of the shift." required />
            <div v-if="form.errors.resolution" class="inc-error">{{ form.errors.resolution }}</div>
        </ConsoleDialog>
    </GeminiConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen. Badges are the operations sheet's own
 * classes; the card, the notes and the form are kept to the tokens the Gemini
 * boards define.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

.inc-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px 18px;
    margin-bottom: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 860px;
}

.inc-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
}

.inc-kind {
    font-size: 15px;
    font-weight: 700;
    color: var(--navy-900);
}

.inc-sub {
    font-size: 11.5px;
    color: var(--slate-500);
}

.inc-badges {
    display: flex;
    gap: 8px;
}

.inc-dl {
    display: grid;
    grid-template-columns: 130px 1fr;
    gap: 8px 14px;
    margin: 0;
    font-size: 12px;
}

.inc-dl dt {
    color: var(--slate-500);
    font-weight: 700;
}

.inc-dl dd {
    margin: 0;
    color: var(--navy-900);
}

.inc-text {
    white-space: pre-line;
    line-height: 1.6;
}

.inc-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.inc-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0;
}

textarea {
    width: 100%;
    margin-top: 10px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 8px 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

/*
 * SEVERITY IS A SCALE, NOT A STATUS (Hassam, 11 Oct): Low neutral grey, Medium solid amber, High a red OUTLINE with an
 * icon and the word, never solid saturated red, which is the open panic's alone. `.sev` outranks the board's sheet.
 */
.sev.severity-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.sev.severity-badge.low {
    background: var(--white);
    color: var(--slate-600);
    box-shadow: inset 0 0 0 1px var(--slate-300);
}

.sev.severity-badge.med {
    background: var(--amber-600);
    color: var(--navy-900);
}

.sev.severity-badge.high {
    background: var(--white);
    color: var(--red-700);
    box-shadow: inset 0 0 0 1.5px var(--red-700);
}

.sev-icon {
    width: 12px;
    height: 12px;
}

/* A field's error, under its field, in amber: red is reserved for panic and life safety (Hassam, 11 Oct). */
.inc-error {
    margin-top: 4px;
    font-size: 11px;
    font-weight: 600;
    color: var(--amber-700);
}
</style>
