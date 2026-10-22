<script setup>
import { Link } from '@inertiajs/vue3'

/**
 * The Platform settings tab strip — drawn identically on boards 42 to 45.
 *
 * One component rather than four copies, because the strip is the one piece of
 * these screens that MUST be pixel-identical across all of them: it is the same
 * seven tabs in the same order at the same widths, and a reader moving between
 * the four screens sees it as a fixed thing that does not move. Four copies is
 * four places for one of them to drift.
 *
 * The board draws all seven tabs as <div>. The ones with a route are real links
 * and the ones without are disabled buttons carrying the reason on hover —
 * never a silent click, and never a tab quietly dropped, because a strip that
 * loses a tab tells the reader the module is smaller than it is.
 *
 * Text sits tight against its tags on purpose: the board's tab is a <div> whose
 * only child is the label, and a stray space either side would widen it.
 */
defineProps({
    tabs: { type: Array, required: true },
})
</script>

<template>
    <div class="subnav">
        <template v-for="tab in tabs" :key="tab.label">
            <Link v-if="tab.href" :href="tab.href" class="subnav-item" :class="{ active: tab.active }">{{
                tab.label
            }}</Link>
            <button v-else type="button" class="subnav-item" disabled :title="tab.reason">{{ tab.label }}</button>
        </template>
    </div>
</template>

<style scoped>
/*
 * The only authored CSS here, and only to take defaults back off.
 *
 * The board draws all seven tabs as <div>. This renders the ones with a route
 * as links and the rest as disabled buttons, and the browser brings its own
 * chrome to both element types — an underline on the anchor, and a border,
 * background and Arial font on the button. These rules remove exactly that, so
 * the board's own .subnav-item rule is what is seen. The board's reset already
 * zeroes padding and margin on every element, and .subnav-item sets the font
 * size, weight and colour, so nothing else needs restating.
 */
a.subnav-item {
    text-decoration: none;
}

button.subnav-item {
    appearance: none;
    border: 0;
    background: transparent;
    font-family: inherit;
}

/*
 * Inert, and it says why on hover. Not dimmed: the board draws these tabs at
 * full weight, and the disabled attribute plus the title already rule out a
 * silent click without changing a pixel.
 */
button.subnav-item[disabled] {
    cursor: not-allowed;
}
</style>
