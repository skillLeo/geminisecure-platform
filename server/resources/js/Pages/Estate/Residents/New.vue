<script setup>
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import AddResidentFields from '../../../Components/AddResidentFields.vue'
import { useAddResident } from '../../../composables/useAddResident.js'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Add resident — board screen community-admin-34, as a page.
 *
 * THE REGISTER OPENS THIS FORM IN A MODAL NOW (Hassam, 11 Oct: "Add resident opens the form inline on the page. It must
 * open in a modal."). This page stays for the links that name `/residents/new`, and it is the same form: its rules are
 * `useAddResident()` and its fields `AddResidentFields.vue`, written once for both. Why the unit is asked of the
 * register as it is typed, never created here, and never decided by the page, is said there.
 *
 * A UNIT IS WHATEVER THE ESTATE CALLS IT (B041). "Lot 47" at Phoenix Park, "OV-1A" at Ocean View, "A-12" from a unit
 * import. The preview names the unit the register will actually use.
 *
 * THE PREVIEW'S PRONOUN IS GONE ON PURPOSE. The board's own copy is gendered because it was drawn against one named
 * person; a template filling in whoever was just typed cannot know a pronoun, so `{first}` and `{lot}` are the only two
 * blanks `newResidentBoard()` leaves. D-056 records the residual.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    phases: { type: Array, required: true },

    /**
     * The register's answer for the unit field, `Residents::lotLookup()`: { phase, typed, resolved, suggestions, more,
     * placeholder }, and `question`, the number this page gave the lookup it answers (null when it answers none).
     */
    lot: {
        type: Object,
        default: () => ({ phase: '', typed: '', resolved: null, suggestions: [], more: false, placeholder: null, question: null }),
    },
    verification: { type: Array, required: true },
    default_verification: { type: String, required: true },
    /** M32 F1: the three answers to "this lot already has a household". */
    household_choices: { type: Array, default: () => [] },
    biometric_consent: { type: Object, required: true },
    preview: { type: Object, required: true },
    canCreate: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
})

/*
 * Board 34 lives in the ninth Community Admin sheet, "Data Privacy, Add Resident and Meetings", the same one board
 * 35's New Charge wears, which is where its .form-layout, .form-panel and .preview-panel classes come from.
 */
useWireframe('community-admin-09-data-privacy-add-resident-and-meetings')

const page = usePage()

/*
 * Where this console is rooted, read off the page's own URL: production gives each estate its own hostname and no
 * prefix; local serves every estate from one host as /estate/{key}/residents/new. Cutting at /residents is right in both.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/residents')))
const residentsPath = computed(() => `${root.value}/residents`)

// The page's lookups re-read its own `lot` prop, under the names its address already carries.
const add = useAddResident({
    board: () => props,
    only: ['lot'],
    params: { phase: 'phase', lot: 'lot' },
    postTo: () => residentsPath.value,
})
</script>

<template>
    <Head title="Add resident" />

    <!-- A nested page (the links that name /residents/new open it): the way back, in words (M95 §3). -->
    <EstateConsole
        title="Add resident"
        :estate-name="estate.name"
        active="residents"
        :back="{ href: residentsPath, label: 'Back to residents' }"
    >

        <div class="form-layout">
            <form class="form-panel" @submit.prevent="add.submit">
                <AddResidentFields :add="add" :board="props" />

                <!--
                  M96 levels (M98 pass): a role that can never add a resident (the page itself opens to it) is told so
                  in place of the button (level 3); a save waiting on the household question is greyed, and why is said
                  under it, not only on hover (level 2).
                -->
                <template v-if="canCreate">
                    <button
                        type="submit"
                        class="stack-btn primary"
                        style="width: 100%"
                        :disabled="add.submitBlockedBy !== null || add.form.processing"
                        :title="add.submitBlockedBy ?? 'Put this resident on the estate\'s register.'"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                        <span>{{ add.form.processing ? 'Adding…' : 'Add resident' }}</span>
                    </button>
                    <p v-if="add.submitBlockedBy" class="new-resident-why">{{ add.submitBlockedBy }}</p>
                </template>
                <p v-else class="new-resident-why">{{ blockedReason }}</p>
            </form>

            <div class="preview-panel">
                <div class="preview-head">{{ preview.head }}</div>
                <div class="preview-note">{{ add.previewText }}</div>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/* The fields' own rules moved with them to AddResidentFields.vue; the button and the line under it are the page's. */
button.stack-btn {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.stack-btn[disabled] {
    cursor: not-allowed;
}

/* Why Add resident waits, or is not this role's, on the screen under it (M96 levels 2 and 3, M98 pass). */
.new-resident-why {
    font-size: 12px;
    color: var(--slate-500);
    margin: 8px 0 0;
    line-height: 1.5;
}
</style>
