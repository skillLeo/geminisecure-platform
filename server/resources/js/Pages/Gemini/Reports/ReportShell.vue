<script setup>
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BackLink from '../../../Components/BackLink.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'

/**
 * The chrome every drill-down report shares — boards 07, 37, 38, 39 and 40.
 *
 * All five draw the same topbar: a back chevron to the catalogue, the report's
 * title, and on two of them an Export control. One component rather than five
 * copies, because the chevron is a 34px circle built from an inline style the
 * board's stylesheet has no class for, and five hand-copies of the same
 * declarations is five chances for one to drift by a pixel.
 *
 * `exportable` is false by default. Boards 39, 40 and 07 draw no Export
 * control at all, and rendering a disabled one there would put a control on a
 * screen the design does not have — the opposite of the fidelity rule, and a
 * worse offence than a missing feature.
 */
defineProps({
    title: { type: String, required: true },
    exportable: { type: Boolean, default: false },
    /** Where the file comes from, or null for a viewer who may not take one. */
    exportHref: { type: String, default: null },
    exportDisabledReason: { type: String, default: null },
})
</script>

<template>
    <GeminiConsole :title="title">
        <!-- M95 §3: the shared back control, in words, where the bare round arrow was. -->
        <template #lead>
            <BackLink href="/reports" label="Back to reports" />
        </template>

        <!--
          A CROSS-TENANT EXPORT puts every client's commercial position into a
          file somebody can forward. That is exactly the act 12 §1 requires an
          audit entry for, and the entry names the clients in the file.
        -->
        <!--
          M96 level 3: a viewer who may never take a file away has no Export at all; it was a greyed button whose reason
          lived only in its tooltip. The report itself is theirs to read.
        -->
        <template v-if="exportable && exportHref" #actions>
            <a
                :href="exportHref"
                class="btn-outline-sm"
                title="Download this report as a spreadsheet file. The platform records who took it."
            >
                <BoardIcon name="export" :stroke="1.8" />
                <span>Export</span>
            </a>
        </template>

        <slot />
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal: the board's Export is a <div>; as a <button> it brings a
 * system font and buttonface grey.
 *
 * `border` is deliberately NOT reset. The board gives .btn-outline-sm a 1.5px
 * navy outline, and resetting it here out-specifies the board and strips that
 * outline off the control entirely — a real fidelity defect that survived
 * several passing measurements, because 1.5px on one small button is well under
 * the threshold. The browser's own border never needed removing: an author rule
 * already beats the user agent's.
 */
button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

button.btn-outline-sm[disabled] {
    cursor: not-allowed;
}

/* A download is a navigation, so the live export is an <a>. Only the UA
 * underline comes off — the border is the board's and stays (see above). */
a.btn-outline-sm {
    text-decoration: none;
}
</style>
