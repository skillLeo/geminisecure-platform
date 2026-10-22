<script setup>
import { computed, ref, watch } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import Subnav from './Subnav.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * Standing orders library — board screen super-admin-25.
 *
 * TWO KINDS OF SET, and the difference is the screen. A MASTER TEMPLATE applies
 * to every post at every client and nobody in particular acknowledges it. A
 * POST-SPECIFIC set belongs to one gate and has to be acknowledged by the guard
 * standing it — so an unacknowledged one means a guard working to orders they
 * have not read, which the board marks in red.
 *
 * That red state is COMPUTED, not stored: a set is unacknowledged because no
 * acknowledgement row exists for its current version, and it becomes
 * unacknowledged again the moment the version is bumped. Storing a flag would
 * mean remembering to clear it on every revision, and the revision nobody
 * cleared it on is the one that matters.
 *
 * PUBLISHING IS THE START OF A CYCLE (12 §2, item 28): a set is published at
 * version 1, the guards on its post acknowledge that version from their
 * handsets, and a revision publishes the next version and asks them again. The
 * form is authored — the board draws a library — and closed on a fresh GET.
 */
const props = defineProps({
    sets: { type: Array, required: true },
    canCreate: { type: Boolean, required: true },
    categories: { type: Object, required: true },
    /** Each carries `today` — the date it is now at that post's estate. */
    posts: { type: Array, required: true },
    /** The operations room's today, for the company-wide orders. */
    today: { type: String, required: true },
    writeDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.sets.length,
})

const creating = ref(false)

const form = useForm({
    category: Object.keys(props.categories)[0] ?? 'post_specific',
    post_id: props.posts[0]?.id ?? '',
    title: '',
    summary: '',
    body: '',
    effective_on: props.today,
})

/*
 * THE SITE'S TODAY, SENT BY THE SERVER (bug hunt B106). This was
 * `new Date().toISOString().slice(0, 10)` — the UTC date, whatever zone the browser
 * is in — so from 19:00 in Kingston the form opened on tomorrow and would not let
 * the office choose today. Orders for a post take effect on that post's estate's
 * calendar; company-wide orders on the operations room's. The server judges them
 * on the same clock.
 */
const earliest = computed(() =>
    form.category === 'post_specific'
        ? (props.posts.find((post) => String(post.id) === String(form.post_id))?.today ?? props.today)
        : props.today
)

/* A date before the earliest the chosen orders may take effect is brought up to it. */
watch(
    earliest,
    (min) => {
        if (form.effective_on < min) {
            form.effective_on = min
        }
    },
    { immediate: true }
)

const submit = () => form.post('/guards/standing-orders')

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['orders'] })
</script>

<template>
    <Head title="Standing orders" />

    <GeminiConsole title="Standing orders">
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canCreate"
                :title="canCreate ? 'Publish a new order set at version 1.' : writeDisabledReason"
                @click="creating = !creating"
            >
                <BoardIcon name="plus" :stroke="2" />
                <span>New order set</span>
            </button>
        </template>

        <Subnav active="standing-orders" />

        <form v-if="creating" class="so-panel" @submit.prevent="submit">
            <div class="so-head">
                Orders are published at version 1 and take effect today or later. The guards on a post acknowledge the
                version they read from their handsets; revising the orders later asks them again.
            </div>

            <div class="so-fields">
                <div class="so-field">
                    <label for="so-category">Kind of orders</label>
                    <select id="so-category" v-model="form.category" required>
                        <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>
                <div class="so-field">
                    <label for="so-effective">Takes effect</label>
                    <input id="so-effective" v-model="form.effective_on" type="date" :min="earliest" required />
                </div>

                <div v-if="form.category === 'post_specific'" class="so-field so-field--wide">
                    <label for="so-post">Post</label>
                    <select id="so-post" v-model="form.post_id" required>
                        <option v-for="post in posts" :key="post.id" :value="post.id">{{ post.label }}</option>
                    </select>
                </div>

                <template v-else>
                    <div class="so-field">
                        <label for="so-title">Title</label>
                        <input id="so-title" v-model="form.title" type="text" maxlength="140" required />
                    </div>
                    <div class="so-field">
                        <label for="so-summary">Where they apply, in one line</label>
                        <input id="so-summary" v-model="form.summary" type="text" maxlength="190" required />
                    </div>
                </template>

                <div class="so-field so-field--wide">
                    <label for="so-body">The orders, as a guard will read them</label>
                    <textarea id="so-body" v-model="form.body" rows="8" maxlength="20000" required />
                </div>
            </div>

            <div v-for="(message, key) in form.errors" :key="key" class="so-error">{{ message }}</div>

            <div class="so-actions">
                <button type="submit" class="btn-primary-sm" :disabled="form.processing" title="Publish at version 1.">
                    <span>{{ form.processing ? 'Publishing…' : 'Publish orders' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="creating = false">Cancel</button>
            </div>
        </form>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="3" />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No standing orders published"
            body="Standing orders are the written instructions a guard works to at a post. Until a set exists, guards are working to nothing written down."
        />

        <div v-for="set in sets" v-else :key="set.id" class="order-set-card">
            <div class="order-set-icon">
                <BoardIcon :name="set.icon" :stroke="1.7" />
            </div>
            <div class="order-set-info">
                <!-- The set itself: every version, and who has acknowledged which. -->
                <Link :href="`/guards/standing-orders/${set.id}`" class="order-set-name so-link">{{ set.name }}</Link>
                <div class="order-set-meta">{{ set.meta }}</div>
            </div>
            <!--
              The board tints the unassigned badge red inline, because its
              stylesheet has no variant for it. Copied verbatim rather than
              given a class of my own.
            -->
            <div
                class="order-set-badge"
                :class="{ template: set.variant !== 'active' }"
                :style="set.variant === 'unassigned' ? 'background:var(--red-100);color:var(--red-700);' : undefined"
            >
                {{ set.badge }}
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/* Default-removal: the board's action is a <div>; as a <button> it brings a
 * border, a system font and buttonface grey. .btn-primary-sm does the rest. */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

/* The set's name is a link; it reads as the board's own name line. */
a.so-link {
    display: block;
    text-decoration: none;
}

/*
 * AUTHORED BELOW THIS LINE: the publishing form, which the board does not draw.
 * Closed on a fresh GET, and kept to the tokens the Gemini boards define.
 */
.so-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.so-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 800px;
}

.so-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.so-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.so-field--wide {
    grid-column: span 2;
}

.so-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.so-field input,
.so-field select,
.so-field textarea {
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 7px 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.so-field input,
.so-field select {
    height: 33px;
    padding: 0 10px;
}

.so-error {
    font-size: 11.5px;
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
    background: var(--red-100);
    color: var(--red-700);
}

.so-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
