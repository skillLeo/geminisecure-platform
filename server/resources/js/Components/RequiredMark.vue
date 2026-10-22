<script setup>
/**
 * A FIELD THAT MUST BE FILLED IN SAYS SO (Hassam, 12 Oct, batch 1 item 3: "why the mandatory fields are not mentioned
 * across the website"). The one mark for every form in both consoles, so every lane draws it the same way.
 *
 *   In the field's label, after its words:   <label for="v-name">Name <RequiredMark /></label>
 *   Once per form, above its first field:    <RequiredMark legend />   ("Fields marked * must be filled in.")
 *
 * Give the input `required` too (or aria-required="true" where the browser's own check must stay off), so a screen
 * reader hears it as well as seeing it. An optional field keeps "(optional)" in its label, as the consoles already do.
 * Amber, never red: solid red is an open panic's alone (rule 20).
 */
defineProps({
    /** The one line that explains the mark, drawn once above a form's first field. */
    legend: { type: Boolean, default: false },
})
</script>

<template>
    <p v-if="legend" class="req-legend"><span class="req-mark" aria-hidden="true">*</span> Fields marked * must be filled in.</p>
    <span v-else class="req-wrap"><span class="req-mark" aria-hidden="true">*</span><span class="req-sr">required</span></span>
</template>

<style scoped>
.req-mark {
    margin-left: 2px;
    color: var(--amber-700);
    font-weight: 700;
}

.req-legend {
    margin: 0 0 10px;
    font-size: 12px;
    line-height: 1.4;
    color: var(--slate-600);
}

.req-legend .req-mark {
    margin-left: 0;
}

/* Heard, not seen: the star says it to the eye, this says it to a screen reader. */
.req-sr {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}
</style>
