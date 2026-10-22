<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive } from 'vue'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useLiveKind } from '../../../composables/useLiveKind'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/*
 * READ AS OF A MOMENT, as the Gemini Console's audit trail is (M18H §7.2): every audited act would otherwise be a live
 * event too. It is a record of what has happened, read when it is asked for.
 */
useLiveKind('snapshot')

/**
 * The estate's audit log (M84 §3.2; SET-2: Settings promised one and there was no page to read it).
 *
 * READ ONLY, FOR EVERY ROLE. Who did what, in which role, to what, before and after, newest first, for this estate
 * only, and only under the modules the viewer can open (the server decides which; `modules` is what it allows);
 * filtered by person, module and date range; exported with the same filters. Nothing here writes: the log is append
 * only in the database and no control on this page edits, removes or acts on an entry. The page exists only while its
 * switch is on (M84 §0.1); off, it is absent from Settings and its address answers 404.
 *
 * THE FIVE STATES (the Settings pass, Hassam's closing order, 12 Oct): it drew only its two empty lists, so a read that
 * failed or a role without Settings saw an empty log, which reads as "nothing has happened". Loading, denied and error
 * are drawn now, and the two empties say which they are (nothing yet, or nothing matching the filters, with the way to
 * clear them). It wore no board stylesheet of its own; it wears the settings board, as its siblings do.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    sections: { type: Array, required: true },
    rows: { type: Array, required: true },
    table: { type: Object, required: true },
    filters: { type: Object, required: true },
    people: { type: Array, default: () => [] },
    modules: { type: Array, default: () => [] },
})

const COLUMNS = [
    { key: 'when', label: 'When' },
    { key: 'who', label: 'Who' },
    { key: 'role', label: 'Role' },
    { key: 'what', label: 'What they did' },
    { key: 'target', label: 'Done to' },
    { key: 'before', label: 'Before' },
    { key: 'after', label: 'After' },
]

// The page's own address, as the strip names it: right on a subdomain and on the path form alike.
const base = computed(() => props.sections.find((s) => s.key === 'audit')?.href ?? '/settings/audit')

const form = reactive({
    actor: props.filters.actor ?? '',
    module: props.filters.module ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
})

const chosen = () => Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '' && value !== null))
const filtering = computed(() => ['actor', 'module', 'from', 'to'].some((key) => props.filters[key]))

const show = () => router.get(base.value, chosen(), { preserveScroll: true })
const clear = () => {
    Object.assign(form, { actor: '', module: '', from: '', to: '' })
    show()
}

const exportHref = computed(() => {
    const query = new URLSearchParams(chosen()).toString()
    return `${base.value}/export${query ? `?${query}` : ''}`
})

// The settings board's stylesheet, as every settings page wears it.
useWireframe('community-admin-06-settings-estate-profile-users-and-roles')

/* Sources are functions: useScreenState runs once during setup. Filtered is the filters this page was drawn with. */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => filtering.value,
})

const retry = () => router.reload()
</script>

<template>
    <Head title="Audit log" />

    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <div class="settings-layout">
            <div class="settings-nav">
                <template v-for="section in sections" :key="section.key">
                    <div v-if="section.active" class="settings-nav-item active" aria-current="page">{{ section.label }}</div>
                    <Link v-else :href="section.href" class="settings-nav-item">{{ section.label }}</Link>
                </template>
            </div>

            <div class="audit-panel">
                <div class="form-sec-head">Audit log</div>
                <p class="aud-note">Changes to this estate's records, from the console and the resident app, newest first. Nothing on the log can be changed or removed.</p>

                <form class="audit-filters" aria-label="Filter the audit log" @submit.prevent="show">
                    <label class="audit-filter">
                        <span>Person</span>
                        <select v-model="form.actor">
                            <option value="">Everybody</option>
                            <option v-for="person in people" :key="person" :value="person">{{ person }}</option>
                        </select>
                    </label>
                    <label class="audit-filter">
                        <span>Module</span>
                        <select v-model="form.module">
                            <option value="">Every module</option>
                            <option v-for="module in modules" :key="module.key" :value="module.key">{{ module.label }}</option>
                        </select>
                    </label>
                    <label class="audit-filter">
                        <span>From</span>
                        <input v-model="form.from" type="date" />
                    </label>
                    <label class="audit-filter">
                        <span>To</span>
                        <input v-model="form.to" type="date" />
                    </label>
                    <button type="submit" class="btn-primary-sm" title="Show the entries that match.">Show</button>
                    <button type="button" class="text-link-sm" title="Show every entry." @click="clear">Clear</button>
                    <a :href="exportHref" class="aud-export" title="Download these entries as a spreadsheet.">Export</a>
                </form>

                <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="7" />

                <EmptyState
                    v-else-if="state.isDenied.value"
                    variant="denied"
                    title="Settings is not part of your role’s access"
                    body="The audit log is kept in Settings, and your role does not include Settings."
                />

                <EmptyState
                    v-else-if="state.isError.value"
                    variant="error"
                    title="The audit log could not be read"
                    body="The log did not answer. Nothing on it has changed, and nothing can be. Trying again is safe."
                    action-label="Try again"
                    @action="retry"
                />

                <EmptyState
                    v-else-if="state.isEmptyFiltered.value"
                    variant="filtered"
                    title="Nothing matches these filters"
                    body="Every entry is still on the log. Clear the filters to see them all."
                    action-label="Clear the filters"
                    @action="clear"
                />

                <EmptyState
                    v-else-if="state.isEmpty.value"
                    variant="first-use"
                    title="Nothing on the log yet"
                    body="Changes made in this console and the resident app appear here as they happen."
                />

                <DataTable v-else :columns="COLUMNS" :rows="rows" :table="table" noun="entries">
                    <template #cell-role="{ row }">{{ row.role || '—' }}</template>
                    <template #cell-before="{ row }">
                        <span v-for="line in row.before" :key="line" class="audit-line">{{ line }}</span>
                        <template v-if="row.before.length === 0">—</template>
                    </template>
                    <template #cell-after="{ row }">
                        <span v-for="line in row.after" :key="line" class="audit-line">{{ line }}</span>
                        <template v-if="row.after.length === 0">—</template>
                    </template>
                </DataTable>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
.audit-panel {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 0;
}

/* The page's own line, not the board's note box (`.audit-note` is the roles and features pages' boxed notice). */
.aud-note {
    margin: 0;
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.5;
}

.audit-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px;
}

.audit-filter {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

/* The filters drawn as the console's fields (the settings pass): they were the browser's own boxes. */
.audit-filter span {
    font-size: 11px;
    font-weight: 700;
    color: var(--slate-500);
}

.audit-filter select,
.audit-filter input {
    height: 34px;
    min-width: 150px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

button.btn-primary-sm {
    border: 0;
    cursor: pointer;
    font-family: inherit;
}

button.text-link-sm {
    border: 0;
    background: none;
    cursor: pointer;
    font-family: inherit;
}

.aud-export {
    display: inline-flex;
    align-items: center;
    height: 32px;
    padding: 0 14px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    color: var(--navy-800);
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}

.audit-line {
    display: block;
}

/*
 * ON A PHONE (checked at 390 px with the new shell, 12 Oct). The board keeps the section list beside the page, 200 px of
 * a 390 px screen, and every Settings page ran past the window. Below 760 px the list sits above the page as one row
 * that scrolls sideways, and the page takes the full width. Doubled classes outrank the board's own rules.
 */
@media (max-width: 760px) {
    .settings-layout.settings-layout {
        grid-template-columns: minmax(0, 1fr);
        gap: 14px;
    }

    .settings-nav.settings-nav {
        flex-direction: row;
        overflow-x: auto;
    }

    .settings-nav-item.settings-nav-item {
        flex: 0 0 auto;
        width: auto;
        white-space: nowrap;
    }
}
</style>
