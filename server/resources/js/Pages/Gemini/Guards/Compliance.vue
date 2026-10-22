<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import Subnav from '../Operations/Subnav.vue'
import { useLiveReload } from '../../../composables/useLiveReload'

// A licence renewed or an officer suspended in another session moves this list by itself (M18H §7.2).
useLiveReload({ entities: ['officer'] })

/**
 * PSRA compliance — board screen super-admin-20.
 *
 * DOM and class names are the board's. The board's Guard cell here carries the
 * name alone, without the second line the directory's rows have, and that
 * difference is reproduced rather than tidied away. The board draws no search
 * field on this topbar either, so none is rendered: the directory next door is
 * where a guard is looked up by name.
 *
 * The register lists every licence, soonest expiry first, not only the ones
 * that have already lapsed. A compliance officer reads down this table to see
 * when the next thing goes wrong, and a table holding only today's failures
 * could not answer that.
 *
 * Licence state drives the badge, not employment status: a guard can be Active
 * and hold an expired licence, which is exactly the case this screen exists to
 * surface, so the two are never collapsed into one pill.
 *
 * The sub-navigation is the module's one list (Operations/Subnav.vue). It used
 * to be a copy, from when four of its destinations did not exist; the copy then
 * lacked the screens built since — On duty, Supervisors, Sites & geofences
 * (M12 §4.10).
 */
defineProps({
    guards: { type: Array, required: true },
    /** The register as the shared table reads it (M32 F5): soonest expiry first, a page at a time. */
    table: { type: Object, required: true },
    canExport: { type: Boolean, required: true },
    exportBlockedReason: { type: String, required: true },
})

const COLUMNS = [
    { key: 'name', label: 'Guard', sortable: true },
    { key: 'psra', label: 'PSRA number', sortable: true },
    { key: 'client', label: 'Client', sortable: true },
    { key: 'expiry', label: 'Expiry date', sortable: true },
    { key: 'status', label: 'Status' },
    { key: 'action', label: '' },
]

const rowOpens = (guard) => ({ onClick: (event) => open(event, guard), style: 'cursor:pointer' })

/**
 * The whole row opens the profile, not only the action link.
 *
 * A click that landed on a link inside the row is left to it rather than being
 * hijacked by the row underneath.
 */
const open = (event, guard) => {
    if (event.target.closest('a')) {
        return
    }

    /*
     * The COMPLIANCE screen, not the profile.
     *
     * This register exists to act on a licence — the column is headed "Take action" and every
     * row's label is an instruction. Sending the reader to `/guards/{id}`, the read-only
     * profile, answered a call to act with a page that cannot, and left
     * `/guards/{guard}/compliance` (routes/gemini/guards.php, GuardController::complianceAction)
     * reachable only by typing the URL.
     */
    router.get(`/guards/${guard.id}/compliance`)
}
</script>

<template>
    <Head title="PSRA compliance" />

    <GeminiConsole title="PSRA compliance">
        <!--
          The board's own topbar button. A licence register leaves the platform
          as a file naming officers and their PSRA numbers, and it reaches
          across estates — so the audit entry it writes names the estates in
          the file, which is the ruling's own second sentence (12 §1).
        -->
        <template #actions>
            <a
                v-if="canExport"
                href="/guards/compliance/export"
                class="btn-outline-sm"
                title="Download the register as a CSV. The platform records that it left, who took it, how many officers were in it and which estates it covered."
            >
                <BoardIcon name="export" :stroke="1.8" />
                <span>Export</span>
            </a>
            <button v-else type="button" class="btn-outline-sm" disabled :title="exportBlockedReason">
                <BoardIcon name="export" :stroke="1.8" />
                <span>Export</span>
            </button>
        </template>

        <Subnav active="compliance" />

        <EmptyState
            v-if="table.total === 0"
            variant="first-use"
            title="No licences on the register"
            body="Every guard on the roster carries a PSRA licence, and each one appears here with its renewal date as soon as the guard is added."
        />

        <!-- Soonest expiry first unless a heading asks otherwise, sorted and paged by the server (M32 F5). -->
        <DataTable v-else :columns="COLUMNS" :rows="guards" :table="table" noun="licences" :row-attrs="rowOpens">
            <template #cell-name="{ row: guard }">
                <div class="res-cell">
                    <div class="res-avatar">{{ guard.initials }}</div>
                    <div class="res-name">{{ guard.name }}</div>
                </div>
            </template>
            <template #cell-psra="{ row: guard }">{{ guard.psra_number }}</template>
            <template #cell-client="{ row: guard }">{{ guard.estate }}</template>
            <template #cell-expiry="{ row: guard }">{{ guard.expires_on }}</template>
            <template #cell-status="{ row: guard }">
                <div class="status-badge" :class="guard.licence_badge">{{ guard.licence_label }}</div>
            </template>
            <template #cell-action="{ row: guard }">
                <!-- The action screen, not the profile. See the row-click handler above. -->
                <Link :href="`/guards/${guard.id}/compliance`" class="text-link-sm">{{ guard.action }}</Link>
            </template>
        </DataTable>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Reset only, as on the directory. The board draws its sub-navigation items,
 * its topbar button and its row actions as <div>s; the real links and disabled
 * buttons that replace them arrive with an underline, a border, a button face
 * and a font of the browser's own. These rules remove exactly those.
 *
 * A scoped element selector carries an attribute and so outranks a single
 * class, which is why colour is never reset here and why the background is
 * taken off only .subnav-item, the one control whose board rule declares none.
 * .btn-outline-sm sets its own white and its own border, and they are left be.
 */
a {
    text-decoration: none;
}

button {
    font-family: inherit;
}

button.subnav-item {
    border: 0;
    background: none;
}
</style>
