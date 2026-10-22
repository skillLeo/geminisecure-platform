<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveKind } from '../../../composables/useLiveKind'

/**
 * A GUARD'S LOCATION SHARING: WHAT THEY AGREED TO, AND WHEN (Hassam, 12 October 2026). Authored: no board draws it.
 *
 * Every answer the officer gave the location policy on the Guard App, newest first: when, Agreed or Refused, the button
 * they pressed in that version's own words, and which answer stands now. Under it, the words of each version as the
 * officer read them (`App\Support\TrackingPolicyWords`, pinned to the app's own text), so "what was agreed" is the words
 * themselves and not a version number. Read only: the officer answers on their phone, and only there.
 *
 * A record read at a moment (`useLiveKind('snapshot')`): an answer is rare, and it comes from the phone, not this page.
 */
useLiveKind('snapshot')

const props = defineProps({
    guard: { type: Object, required: true },
    canSeeTrack: { type: Boolean, default: false },
    enabled: { type: Boolean, required: true },
    current_version: { type: String, required: true },
    current_label: { type: String, required: true },
    retention_days: { type: Number, required: true },
    /** `{ state: 'agreed'|'refused'|'unanswered'|'off', line }` */
    standing: { type: Object, required: true },
    /** `{ id, when, phone_time, version, version_label, answer, agreed, pressed, stands, note }`, newest first. */
    answers: { type: Array, required: true },
    /** Each version answered, and the one in force: `{ label, title, lead, sections: [{ heading, body }], agree, refuse }`. */
    words: { type: Object, required: true },
})

// Never answered is this record's own first state; there is no filter, so nothing is ever filtered out.
const state = useScreenState({ rows: () => props.answers.length })

const retry = () => router.reload()

const versions = () => Object.entries(props.words)
</script>

<template>
    <Head :title="`${guard.name} · location sharing`" />

    <GeminiConsole :title="`${guard.name} · location sharing`" :back="{ href: `/guards/${guard.id}`, label: `Back to ${guard.name}` }">
        <div v-if="state.isLoading.value" class="info-panel">
            <SkeletonRows :rows="5" :columns="2" />
        </div>

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This guard's location sharing is not part of your role's access"
            body="Ask your platform administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This record could not be loaded"
            body="Nothing has changed. Please try again."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <div class="info-panel ls-standing" :class="`ls-${standing.state}`">
                <div class="info-panel-head">Where {{ guard.name }} stands</div>
                <p class="ls-line">{{ standing.line }}</p>
                <p class="ls-note">The officer answers on the Guard App, and can change their answer there under More, Location sharing.</p>
                <Link v-if="canSeeTrack" :href="`/guards/${guard.id}/track`" class="text-link-sm">See the positions held about {{ guard.name }}</Link>
            </div>

            <EmptyState
                v-if="state.isEmpty.value"
                variant="first-use"
                title="No answer yet"
                :body="`${guard.name} is asked the first time they open the Guard App on duty.`"
            />

            <div v-else class="info-panel">
                <div class="info-panel-head">Every answer, newest first</div>
                <ol class="ls-answers">
                    <li v-for="answer in answers" :key="answer.id" class="ls-answer" :class="{ 'is-standing': answer.stands }">
                        <div class="ls-answer-top">
                            <span class="ls-when">{{ answer.when }}</span>
                            <span class="ls-answer-word" :class="answer.agreed ? 'is-agreed' : 'is-refused'">{{ answer.answer }}</span>
                        </div>
                        <p class="ls-pressed">
                            <template v-if="answer.pressed">Pressed “{{ answer.pressed }}”</template>
                            <template v-else>The words of this version are not held on the server.</template>
                            · the words of {{ answer.version_label }}
                        </p>
                        <p v-if="answer.note" class="ls-note">{{ answer.note }}</p>
                        <p v-if="answer.phone_time && answer.phone_time !== answer.when" class="ls-note">The phone's own clock said {{ answer.phone_time }}.</p>
                    </li>
                </ol>
            </div>

            <!-- What was agreed is the words themselves: each version as the officer read it, in force now first. -->
            <div class="info-panel">
                <div class="info-panel-head">The words they were shown</div>
                <details v-for="([version, text], index) in versions()" :key="version" class="ls-words" :open="index === 0">
                    <summary>
                        The words of {{ text.label }}<template v-if="version === current_version"> · in force now</template>
                    </summary>
                    <h3 class="ls-title">{{ text.title }}</h3>
                    <p class="ls-lead">{{ text.lead }}</p>
                    <div v-for="section in text.sections" :key="section.heading" class="ls-section">
                        <h4>{{ section.heading }}</h4>
                        <p>{{ section.body }}</p>
                    </div>
                    <p class="ls-buttons">The two buttons: “{{ text.agree }}” and “{{ text.refuse }}”.</p>
                </details>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
.ls-line {
    margin: 4px 0 6px;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.5;
    color: var(--navy-900);
}

.ls-note {
    margin: 2px 0 6px;
    font-size: 12px;
    line-height: 1.5;
    color: var(--slate-600);
}

/* Refusing is an answer, an HR matter, not a fault: amber, never red (Hassam's three weights). */
.ls-refused .ls-line {
    color: var(--amber-700);
}

.ls-answers {
    margin: 0;
    padding: 0;
    list-style: none;
}

.ls-answer {
    padding: 10px 0;
    border-top: 1px solid var(--navy-100);
}

.ls-answer:first-child {
    border-top: 0;
}

.ls-answer-top {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 4px 12px;
}

.ls-when {
    font-size: 13px;
    font-weight: 600;
    color: var(--navy-900);
}

.ls-answer-word {
    font-size: 12px;
    font-weight: 700;
}

.ls-answer-word.is-agreed {
    color: var(--success-700);
}

.ls-answer-word.is-refused {
    color: var(--amber-700);
}

.ls-pressed {
    margin: 4px 0 0;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--slate-700);
}

.ls-words {
    padding: 8px 0;
    border-top: 1px solid var(--navy-100);
}

.ls-words:first-of-type {
    border-top: 0;
}

.ls-words summary {
    cursor: pointer;
    font-size: 13px;
    font-weight: 700;
    color: var(--navy-800);
}

.ls-title {
    margin: 10px 0 4px;
    font-size: 15px;
    color: var(--navy-900);
}

.ls-lead,
.ls-section p,
.ls-buttons {
    margin: 0 0 8px;
    font-size: 12.5px;
    line-height: 1.6;
    color: var(--slate-700);
}

.ls-section h4 {
    margin: 8px 0 2px;
    font-size: 12.5px;
    color: var(--navy-900);
}

.ls-buttons {
    font-style: italic;
}
</style>
