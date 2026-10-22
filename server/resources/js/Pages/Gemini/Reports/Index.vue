<script setup>
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import { useLiveKind } from '../../../composables/useLiveKind'

// The reports' front page: figures worked out at a moment, "As of …" (M18H §7.2).
useLiveKind('snapshot')

/**
 * Cross-tenant reports — board screen super-admin-36.
 *
 * A launcher, not a report. The board draws two labelled groups of three cards,
 * each card an icon, a name, a description and an Open action, and nothing else
 * on the screen; there is no chart, no date range and no export here, so none
 * is invented. Those belong to the report screens the cards open.
 *
 * The board draws the Open action as a <div>. Here it is a real control: a Link
 * where the report exists, and a disabled <button> carrying the reason where it
 * does not. Which of the two each card gets is decided on the server, from the
 * router and the viewer's permissions — see CrossTenantReports.
 */
defineProps({
    groups: { type: Array, required: true },
})
</script>

<template>
    <Head title="Cross-tenant reports" />

    <GeminiConsole title="Cross-tenant reports">
        <div v-for="group in groups" :key="group.name" class="report-group">
            <div class="report-group-head">{{ group.name }}</div>

            <div class="report-grid">
                <div v-for="report in group.reports" :key="report.key" class="report-card">
                    <div class="rc-icon">
                        <BoardIcon :name="report.icon" :stroke="1.7" />
                    </div>
                    <div class="rc-name">{{ report.name }}</div>
                    <div class="rc-desc">{{ report.description }}</div>

                    <!--
                      M96 levels. A report this role may never open has no Open at all (level 3); one not built yet
                      keeps a greyed Open (level 2). Both say why on the card, where a touch screen can read it.
                    -->
                    <Link v-if="report.href" class="rc-action" :href="report.href">Open</Link>
                    <button v-else-if="!report.never" type="button" class="rc-action" disabled :title="report.unavailable">Open</button>
                    <p v-if="!report.href" class="rc-why">{{ report.unavailable }}</p>
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS here, and only to take browser defaults back off.
 *
 * The board draws .rc-action as a <div>, which this renders as an <a> or a
 * <button> so that it actually does something. Both arrive with styling of
 * their own — an anchor underlines, a button brings its own border, padding,
 * font family and shrink-to-fit width — and every one of those would change the
 * pixels against the board. These rules remove them so the board's own
 * .report-card .rc-action is what is seen. Nothing here adds styling the board
 * does not already have.
 */
a.rc-action {
    text-decoration: none;
}

button.rc-action {
    width: 100%;
    border: 0;
    padding: 0;
    font-family: inherit;
}

/*
 * Deliberately inert, and it says so: the cursor and the title explain why,
 * and the browser refuses the click. No opacity change — the board draws these
 * cards one way, and dimming five of six would be a styling decision the design
 * did not make.
 */
/* Greyed means greyed (Law 1), as the shared modal greys its own button; why is under the card. */
button.rc-action[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
}

/* Why a card cannot be opened, in one line under it, readable without hovering (M96). */
.rc-why {
    margin: 6px 0 0;
    font-size: 10.5px;
    line-height: 1.4;
    color: var(--slate-500);
}
</style>
