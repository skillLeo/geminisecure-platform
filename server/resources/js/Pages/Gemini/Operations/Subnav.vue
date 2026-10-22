<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { isCurrent, workforceTabs } from '../../../lib/workforceTabs.js'

/**
 * The Guard workforce sub-navigation, drawn on boards 18 to 27.
 *
 * One list in one place. The items appear on every screen in the module,
 * and a copy of them per page is copies to keep in step — which is how a
 * console ends up with a tab that is a live link on one screen and a dead
 * <div> on the next. (M12 §4.10: the Directory, Compliance and Supervisors
 * screens kept their own copies, and none of them had "On duty" — the duty
 * board could not be reached from the module's front page. They use this now.)
 *
 * TWO VIEWS (M30 §1.3): the new Schedule and Places, and the classic Roster and
 * Sites & geofences, side by side until the client chooses. The items, which view
 * the tabs open (M35 §1: the new one, one line to change), and the one plain link
 * at the end of the bar that crosses between the two, are in `lib/workforceTabs.js`.
 */
const props = defineProps({
    /** Which item is the current screen. */
    active: { type: String, required: true },
})

const tabs = computed(() => workforceTabs(props.active))
</script>

<template>
    <div class="subnav">
        <Link
            v-for="item in tabs.items"
            :key="item.key"
            :href="item.href"
            class="subnav-item"
            :class="{ active: isCurrent(item, props.active) }"
            :aria-current="isCurrent(item, props.active) ? 'page' : undefined"
        >
            {{ item.label }}
        </Link>
        <Link :href="tabs.switchLink.href" class="text-link-sm subnav-switch">{{ tabs.switchLink.label }}</Link>
    </div>
</template>

<style scoped>
/*
 * Nothing here adds a style. The board draws each tab as a <div>; here they are
 * anchors, and a browser gives an anchor an underline. That is all this takes
 * back off, so every visible property still comes from the board's own
 * .subnav-item rule. The view switch (M30 §1.3) is the board's own text link,
 * set apart from the tabs by space alone, at the bar's right end: where the bar
 * is too narrow for it (1280 px) it wraps there, under the last tab, not under
 * the first.
 */
a.subnav-item,
a.subnav-switch {
    text-decoration: none;
}

a.subnav-switch {
    align-self: center;
    margin-left: auto;
    padding-left: 18px;
    margin-right: 12px;
    white-space: nowrap;
}
</style>
