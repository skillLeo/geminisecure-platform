<script setup>
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import Subnav from '../Operations/Subnav.vue'
import { useLiveReload } from '../../../composables/useLiveReload'

// Who supervises whom, and who is on shift now, re-reads itself as it changes (M18H §7.2).
useLiveReload({ entities: ['officer', 'shift'] })

/**
 * Who currently holds supervisor-override authority — Q-M28, D-M129. No board
 * draws this screen; it is built from the compliance register's components next
 * door, because it is the same kind of thing: a list read down to find the next
 * thing that needs attention.
 *
 * WHAT IT IS FOR. A guard whose phone cannot confirm who they are can still start
 * a shift if a supervisor types a PIN on the handset. The client confirmed the
 * rule — a supervisor is whoever the console already trusts with a decision about
 * a guard — and asked that it be visible, because a permission nobody can
 * enumerate is one nobody reviews.
 *
 * AUTHORITY, NOT PIN HOLDERS. Somebody who holds the permission and has never set
 * a PIN is on this list, marked so. They cannot override this minute, and they can
 * set a PIN this afternoon from their own account page with nobody asked. The list
 * answers "who may", which is the question an office actually has.
 *
 * NOTHING HERE IS A CONTROL. There is no button to set, clear or revoke a PIN, and
 * that is deliberate rather than unfinished: a PIN somebody else chose is a PIN two
 * people know, and "who vouched for this guard" stops meaning anything. Authority
 * is changed where it comes from — the role matrix — and a PIN only ever by its
 * owner.
 */
const props = defineProps({
    supervisors: { type: Array, required: true },
    /** The register as the shared table reads it (M32 F5). */
    table: { type: Object, required: true },
    /** How many of ALL of them need attention — not only this page's. */
    attention: { type: Number, default: 0 },
    windowDays: { type: Number, required: true },
    scoped: { type: Boolean, required: true },
    ownPinHref: { type: String, default: null },
})

/**
 * The four standings, in the words an office would use about them.
 *
 * `locked` and `attempts` are the two worth acting on: a locked PIN means that
 * supervisor cannot vouch until they set a new one, and wrong tries against a
 * named person's PIN are what somebody guessing looks like from here.
 */
const STANDING = {
    ready: { label: 'PIN set', badge: 'ok' },
    'no-pin': { label: 'No PIN set', badge: 'draft' },
    locked: { label: 'PIN locked', badge: 'expired' },
    attempts: { label: 'Wrong tries', badge: 'soon' },
}

const standing = (row) => STANDING[row.standing] ?? STANDING['no-pin']

// Counted by the server over the whole register (M32 F5): a page of it would undercount.
const needsAttention = computed(() => props.attention)

const covers = (row) => (row.covers_all ? 'Every estate' : row.covers.join(' · '))

const COLUMNS = [
    { key: 'name', label: 'Supervisor', sortable: true },
    { key: 'role', label: 'Role', sortable: true },
    { key: 'covers', label: 'May vouch at', cellClass: 'sv-covers' },
    { key: 'pin', label: 'PIN', sortable: true },
    { key: 'last_used', label: 'Last used', sortable: true },
    { key: 'authorised', label: 'Authorised', sortable: true },
]
</script>

<template>
    <Head title="Supervisor override authority" />

    <GeminiConsole title="Supervisor override authority">
        <!-- The module's one sub-navigation (M12 §4.10: this copy had no "On duty"). -->
        <Subnav active="supervisors" />

        <p class="sv-lead">
            These are the people who can start a shift on a guard's handset when the phone cannot confirm who the
            guard is — a failed fingerprint, or a handset with no biometrics. Every use is recorded against the
            shift, written to the audit log and reported to the Head of Security.
            <template v-if="scoped">
                This list covers the estates you are assigned to.
            </template>
            <template v-else>
                This list covers every estate.
            </template>
        </p>

        <p v-if="needsAttention" class="sv-lead sv-attention">
            {{ needsAttention }} of these needs attention below: a locked PIN cannot vouch for anybody until its
            owner sets a new one, and wrong tries against a named person's PIN are what guessing looks like.
        </p>

        <EmptyState
            v-if="table.total === 0"
            variant="first-use"
            title="Nobody can authorise an override"
            body="Supervisor authority comes with the permission that decides a handset rebind, a suspension or a reassignment. Nobody you can see holds it, so a guard whose phone cannot confirm them has no way to start a shift. It is granted in Settings → Roles, not here."
        />

        <DataTable v-else :columns="COLUMNS" :rows="supervisors" :table="table" noun="supervisors">
            <template #cell-name="{ row }">
                <div class="res-cell">
                    <div class="res-avatar">{{ row.initials }}</div>
                    <div class="res-name">{{ row.name }}</div>
                </div>
            </template>
            <template #cell-covers="{ row }">{{ covers(row) }}</template>
            <template #cell-pin="{ row }">
                <div class="status-badge" :class="standing(row).badge">{{ standing(row).label }}</div>
                <div v-if="row.failed_attempts" class="sv-note">
                    {{ row.failed_attempts }} wrong {{ row.failed_attempts === 1 ? 'try' : 'tries' }} on record
                </div>
                <div v-else-if="row.pin_set_at" class="sv-note">Set {{ row.pin_set_at }}</div>
            </template>
            <template #cell-last_used="{ row }">{{ row.last_used_at ?? 'Never' }}</template>
            <template #cell-authorised="{ row }">
                {{ row.authorised }}
                <span class="sv-note">in {{ windowDays }} days</span>
            </template>
        </DataTable>

        <p class="sv-lead" style="margin-top: 16px">
            Authority itself is granted with the role, in Settings → Roles — it is the same permission that approves
            a handset rebind or suspends an officer, so it is changed there and never here. A PIN is only ever set by
            the person it belongs to<template v-if="ownPinHref">
                (<Link :href="ownPinHref" class="text-link-sm">yours is here</Link>)</template>: a PIN somebody else
            chose is a PIN two people know, and "who vouched for this guard" stops meaning anything.
        </p>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Reset only, as on the compliance register. The board draws its sub-navigation
 * items as <div>s; real links arrive with an underline.
 */
a {
    text-decoration: none;
}

/* Authored: the boards have no lead paragraph on a register. Kept to their tokens. */
.sv-lead {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0 0 14px;
}

.sv-attention {
    color: var(--navy-900);
    font-weight: 600;
}

.sv-covers {
    line-height: 1.5;
}

.sv-note {
    display: block;
    font-size: 10.5px;
    color: var(--slate-500);
    line-height: 1.5;
    margin-top: 2px;
}
</style>
