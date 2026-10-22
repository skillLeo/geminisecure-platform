<script setup>
import { computed, reactive } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import { toast } from '../../../lib/toasts.js'
import SettingsTabs from './SettingsTabs.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveKind } from '../../../composables/useLiveKind'

// A settings editor (M18H §7.2).
useLiveKind('form')

/**
 * Subscription package builder — board screen super-admin-43.
 *
 * THE TEMPLATE, NOT A CLIENT. The warning banner the board draws is the whole
 * point of the screen: a row here changes what every current and future client
 * on that tier receives. One client's exceptions are screen 44, and the banner
 * says so in the board's own words.
 *
 * Three kinds of cell, and the difference between two of them matters:
 *
 *   locked  a core feature. In every package, and no package may drop it, so it
 *           is drawn as a FACT rather than as a setting that happens to be on.
 *           Rendering it as an ordinary tick would invite someone to reach for
 *           a switch that must not exist.
 *   on/off  a real per-tier inclusion
 *   value   a tier-graded value ("Logo only", "Full theme") rather than a yes
 *
 * NOTHING HERE WRITES. The save control is inert and says why: repricing what a
 * tier includes is a privileged, audited write with an effective date, and it
 * does not get built as a side effect of the screen that displays it.
 *
 * The board's table is drawn at 1440x1140. The emphasis on the top tier's
 * column comes from the plan ordering, not from the name "Premium", so renaming
 * or adding a tier above it moves the emphasis with it.
 */
const props = defineProps({
    tabs: { type: Array, required: true },
    plans: { type: Array, required: true },
    features: { type: Array, required: true },
    canWrite: { type: Boolean, required: true },
    saveDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.features.length,
})

/* ------------------------------------------------------------------ */
/* toggling the template (12 §2, Wave 4) */
/* ------------------------------------------------------------------ */

/*
 * WHAT IS PENDING, HELD HERE AND NOT POSTED PER CLICK. A tier's contents are
 * read across a row and a column at once — an administrator turning two things
 * on is making one decision about a package — so the changes gather and go in
 * one press, which is what the board's single "Save package changes" draws.
 *
 * A CORE FEATURE HAS NO SWITCH. It is in every package by definition and the
 * cell is drawn `locked`; offering one would invite somebody to reach for a
 * switch that must not exist, and the server refuses it too.
 */
const pending = reactive({})

const cellKey = (feature, cell) => `${cell.plan_id}:${feature.id}`

const isOn = (feature, cell) => {
    const key = cellKey(feature, cell)

    return key in pending ? pending[key] : cell.variant === 'on'
}

const toggle = (feature, cell) => {
    if (!props.canWrite || cell.variant === 'value' || cell.variant === 'locked') {
        return
    }

    const key = cellKey(feature, cell)
    const next = !isOn(feature, cell)

    // Back to where it started is not a change. Dropping it keeps the count
    // honest, so "3 changes" means three things actually move.
    if (next === (cell.variant === 'on')) {
        delete pending[key]

        return
    }

    pending[key] = next
}

const changeCount = computed(() => Object.keys(pending).length)

const form = useForm({ changes: [] })

const save = () => {
    if (changeCount.value === 0) {
        return
    }

    form.changes = Object.entries(pending).map(([key, included]) => {
        const [planId, featureId] = key.split(':')

        return { plan_id: Number(planId), feature_id: Number(featureId), included }
    })

    // Saved with no dialog open, so a refusal is what just happened: a toast that stays until it is closed, and the
    // marked cells stay marked (Hassam, 12 October). What a save did is the shell's toast.
    form.post('/settings/packages', {
        preserveScroll: true,
        onSuccess: () => {
            for (const key of Object.keys(pending)) {
                delete pending[key]
            }
        },
        onError: (errors) => {
            toast(errors.changes ?? Object.values(errors)[0] ?? 'The template was not changed.', { tone: 'error' })
        },
    })
}
</script>

<template>
    <Head title="Package builder" />

    <GeminiConsole title="Platform settings">
        <template #actions>
            <!--
              One press for the whole template, which is what the board draws:
              a tier's contents are read across a row and a column at once, so
              two toggles are one decision about a package.
            -->
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canWrite || changeCount === 0 || form.processing"
                :title="
                    !canWrite
                        ? saveDisabledReason
                        : changeCount === 0
                          ? 'Nothing on this template has been changed yet.'
                          : `Save ${changeCount} change(s). Every current and future client on those tiers is affected from now — a capability is not something a client is invoiced for on a date.`
                "
                @click="save"
            >
                <BoardIcon name="check" :stroke="2" />
                <span>{{ changeCount === 0 ? 'Save package changes' : `Save ${changeCount} change(s)` }}</span>
            </button>
        </template>

        <SettingsTabs :tabs="tabs" />


        <div class="warn-banner">
            <BoardIcon name="warning" :stroke="1.7" />
            <div>
                <div class="wb1">This edits the template, not one client</div>
                <div class="wb2">
                    Toggling a row here changes what's included by default for every current and future client on
                    that tier. For one-off changes to a single client, use Client Line Items instead.
                </div>
            </div>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="8" :columns="4" />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No package features defined"
            body="A tier's contents are built from the platform's feature catalogue. Until one exists there is nothing to include or exclude."
        />

        <table v-else class="pkg-table">
            <thead>
                <tr>
                    <th>Feature</th>
                    <th v-for="plan in plans" :key="plan.id" :class="{ 'premium-col': plan.emphasised }">
                        {{ plan.name }}
                        <br />
                        <span style="font-weight: 500">{{ plan.price }}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="feature in features" :key="feature.key">
                    <td>
                        <div class="pkg-feat-name">{{ feature.label }}</div>
                        <div v-if="feature.sub" class="pkg-feat-sub">{{ feature.sub }}</div>
                    </td>
                    <td v-for="(cell, i) in feature.cells" :key="i">
                        <!--
                          A tier-graded value, not a tick. The board tints the
                          top tier's tag; that emphasis is carried on the cell
                          rather than recomputed here.
                        -->
                        <div
                            v-if="cell.variant === 'value'"
                            class="branding-tag"
                            :style="cell.emphasised ? 'color:var(--navy-600);' : undefined"
                        >
                            {{ cell.value }}
                        </div>

                        <!--
                          A locked cell stays a fact and never a control: a core
                          feature is in every package, and a switch on it would
                          be one nobody may use. Everything else toggles.
                        -->
                        <div v-else-if="cell.variant === 'locked'" class="pkg-check locked">
                            <BoardIcon name="check" :stroke="3" />
                        </div>

                        <button
                            v-else
                            type="button"
                            class="pkg-check"
                            :class="[isOn(feature, cell) ? 'on' : 'off', { pending: cellKey(feature, cell) in pending }]"
                            :disabled="!canWrite"
                            :title="
                                canWrite
                                    ? `${isOn(feature, cell) ? 'Included' : 'Not included'} — press to change. It takes effect for every current and future client on this tier when you save.`
                                    : saveDisabledReason
                            "
                            :aria-pressed="isOn(feature, cell)"
                            @click="toggle(feature, cell)"
                        >
                            <BoardIcon v-if="isOn(feature, cell)" name="check" :stroke="3" />
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its save control as a <div>; as a real
 * <button> it arrives with a border, a system font and buttonface grey.
 * .btn-primary-sm supplies everything else.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

/*
 * A togglable cell is a real <button>. `.pkg-check` supplies the circle, the
 * ground and the glyph colour, so only what the UA adds comes off — and the
 * board's own `.locked` variant is untouched, because a locked cell is still a
 * <div> and still a fact rather than a control.
 */
button.pkg-check {
    border: 0;
    font: inherit;
    cursor: pointer;
    padding: 0;
}

button.pkg-check[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a template nobody is editing, so it
 * has no flash, no refusal and no pending mark. `pending` is a ring rather than
 * a colour change: what a cell IS and whether it is about to change are two
 * different facts, and recolouring would lose the first.
 */
button.pkg-check.pending {
    box-shadow: 0 0 0 2px var(--amber-600);
}
</style>
