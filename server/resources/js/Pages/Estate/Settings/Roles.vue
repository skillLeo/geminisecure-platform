<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'
import { submitDialog } from '../../../lib/dialogSubmit.js'

/**
 * Role access matrix — board screen community-admin-24, and since M10 §4 the
 * editor for this community's own roles.
 *
 * NO PERMISSION IS SAVED WITHOUT A LIST OF WHAT CHANGES, AND NOTHING HERE DECIDES ONE (Hassam's closing order, 12 Oct:
 * sixty one cells wait on the committee's ruling, and one silent change would be unrecoverable). ONE ROLE'S
 * PERMISSIONS ARE A PAGE OF THEIR OWN (`RolePermissions.vue`, Hassam, 12 Oct): the editor was a modal holding a long
 * table with its own scrollbar, where the column saying why a control is off wrapped to a word a line. Edit
 * permissions, and a matrix cell, open that page; its review names every single change in words before anything is
 * saved. Add role, Rename and Delete stay dialogs, each one short form (they were forms opened on the page); Delete is
 * the dialog's red outline (it was a solid red button); a refusal is the server's sentence inside the dialog, which
 * stays open (`submitDialog`). What every cell may be is still `RoleEditor`'s.
 *
 * A READER (anybody but the role that may change roles) gets no editor and no "Invite user": a "View only" badge and
 * the sentence that says why (M96 level 3). Every refusal on a role's row is said under it, shared roles included.
 *
 * THE MATRIX STILL READS; THE EDITOR BESIDE IT WRITES, AND ONLY WHERE THE
 * SERVER WILL LET IT.
 * ======================================================================
 *
 * Until M10 this screen read and never wrote (D-048): changing what a role may
 * do is an `approve`-level act on Settings (D-013), and no estate role held
 * `estate.settings.approve`. The client asked for the Community Super Admin to
 * create, rename, re-grant and delete roles, the seeded matrix now gives that
 * one cell the Approver tag, and `can_edit` — shipped as a live flag rather
 * than a constant for exactly this day — arrives true for that one role and
 * false for the other seven.
 *
 * WHAT THIS PAGE DECIDES: NOTHING. Every control below is drawn from
 * `RoleEditor::board()` and posts to a route behind `can:estate.settings.approve`
 * that hands the request straight back to `RoleEditor`, which holds every guard
 * rail: nobody grants or takes away a permission they do not hold themselves,
 * nothing names a module of the Gemini Console, the eight shared roles are
 * Gemini's to set, and Ruling 1 binds a copy of the Property Manager. This page
 * only avoids OFFERING what the server would refuse — a verb the viewer does
 * not hold is drawn disabled, with the board's reason on it and beside it, a
 * shared role is drawn read-only with `shared_role_note`, a Ruling 1 cell is
 * drawn Locked — and prints the server's sentence whenever it refuses anyway.
 * A control that looked live and was then refused would be the screen claiming
 * something it cannot do.
 *
 * THE EIGHT SHARED ROLES ARE NOT EDITED FROM HERE, EVEN BY THE COMMUNITY SUPER
 * ADMIN (the lead's ruling on M10 §4). They are shared by every community on
 * the platform, so changing one here would change it for all of them. What an
 * estate does instead is copy one into a role of its own and change the copy;
 * the copy leaves out whatever the copier does not hold, and this page names
 * those permissions before the copy is made (`copy_withheld`) and the flash
 * names them after.
 *
 * ONE ROLE PER SAVE, AND ONLY THE MODULES THAT CHANGED. So the audit entry is
 * one change to one role, and a cell nobody touched is not re-asserted. The
 * change applies to the next page anybody holding the role opens: `RoleEditor`
 * recompiles and flushes the permission cache after the commit.
 *
 * FOURTEEN MODULES AND EIGHT SHARED ROLES WHERE THE BOARD DREW TEN AND SIX,
 * then this community's own. The extra modules are the Ruling 1 split (D-010,
 * D-014) and M10 §2's Security; the extra roles are the Community Super Admin,
 * which board 24's own audit note says it omits, and M10 §2's Head of
 * Security. A permission screen that hid any of them would not describe the
 * permissions, which is the one thing a permission screen must not do.
 *
 * WHERE THE DRAWN CELLS AND THE MODEL DISAGREE, RECORDED AND NOT RESOLVED
 * (D-044). Board 24 gives the Property Manager View on Dues & ledger and on
 * Accounting. The model gives them nothing on `dues_ledger`, `payments`,
 * `accounting_posting` and `payroll`, and `RbacMatrixSeeder` throws rather than
 * seeding otherwise. The model wins, this screen prints em dashes there, and
 * each of those cells — on the Property Manager and on any copy of it —
 * carries a marker and the invariant note.
 *
 * THE MONEY TOTALS, SAID WHERE THEY ARE HANDED OUT. Reports view together with
 * view on Dues & ledger, Payments or Accounting lets a role read the estate's
 * money about itself (`Dues::maySeeEstatePosition()`, M10 §5), and neither cell
 * says so on its own. The editor warns the moment a draft combines them, with
 * the server's sentence and the server's list of modules.
 *
 * ONE APPROVAL AT A TIME WHERE A MODULE HAS SEVERAL (M11 B3, PRICE-02). Dues &
 * ledger, Accounting, Payroll & HR and Governance each split their approval
 * into named acts (`App\Support\NamedActs`): setting dues rates is not reversing
 * a posted month. On those rows the one Approver box is a box per act, the
 * Approver tag is simply all of them ticked, and the matrix pill names the ones
 * a role holds on their own — "Sets dues rates". Settings splits `update` the
 * same way — managing people is not editing the profile — so at Custom its one
 * update box is two. Every act is checked like any
 * other permission: the viewer gives or takes only the ones their own role
 * holds, and what the page compiles to compare a draft with its saved cell is
 * what `RoleModuleAccess::permissionNames()` compiles on the server.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** The seven-item settings column, with this screen marked current. */
    sections: { type: Array, required: true },
    /** Full, View, Entry, — with the four descriptions the board prints. */
    legend: { type: Array, required: true },
    /** The eight shared estate roles, then this community's own, in the matrix's order. */
    roles: { type: Array, required: true },
    /** One row per module, each carrying a cell per role in the same order. */
    rows: { type: Array, required: true },
    audit_note: { type: String, required: true },
    /** True for the one role that holds the power to change roles, and printed rather than assumed. */
    can_edit: { type: Boolean, required: true },
    read_only_reason: { type: String, required: true },
    /** Role name → why even the editing viewer cannot change it: the eight shared roles. */
    uneditable_roles: { type: [Object, Array], default: () => ({}) },
    shared_role_note: { type: String, default: '' },
    invariant_note: { type: String, required: true },
    canInvite: { type: Boolean, required: true },
    /** Board 22 with its invite panel open — where this board's "Invite user" leads. */
    inviteHref: { type: String, required: true },
    inviteReason: { type: String, required: true },
    /**
     * `RoleEditor::board()`, plus what a copy of each role would leave out
     * (`copy_withheld`), the longest name a role may have and the position
     * rule the editor warns about.
     */
    editor: { type: Object, required: true },
})

const page = usePage()

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-06-settings-estate-profile-users-and-roles')

// The role editor is a form: re-reading its grid under somebody's changes would lose them (M18H §7.2).
useLiveKind('form')

const retry = () => router.reload()

/**
 * `/estate/<key>` locally, `` on an estate's own hostname — the same cut
 * Users.vue makes. On `/settings/` WITH its slash: an estate whose key begins
 * "settings" would otherwise be cut inside its own name.
 */
const settingsRoot = computed(() => page.url.split('/settings/')[0])

/* ------------------------------------------------------------------ */
/* reading the editor's board                                         */
/* ------------------------------------------------------------------ */

const editorRoles = computed(() => props.editor.roles ?? [])

const editorRoleByName = computed(() => Object.fromEntries(editorRoles.value.map((role) => [role.name, role])))

const moduleLabel = (key) => props.editor.modules.find((module) => module.key === key)?.label ?? key

/** Whether a matrix cell opens its role's permissions: for the viewer who may change roles, on a role they may change. */
const cellEditable = (cell) => props.editor.can_edit === true && editorRoleByName.value[cell.role]?.can_edit_permissions === true

/** One role's permissions, a page of its own (Hassam, 12 Oct: a long table read across is never a modal). */
const permissionsHref = (role) => `${settingsRoot.value}/settings/roles/${role.id}/permissions`


/**
 * Which cells the Property Manager lock actually produced.
 *
 * TRUE ONLY WHERE THE MODEL AND THE BOARD DISAGREE, and the three conditions
 * are each doing work. `locked_financial` is the platform invariant carried on
 * the module; the role is the one it locks — the Property Manager, or a copy
 * of it, which `RoleEditor` marks `locked` on the cell; and `none` is what the
 * lock leaves behind. A row the estate simply never granted — the Secretary on
 * Dues & ledger, say — is also an em dash, and marking it would tell a
 * committee that a decision they made was made for them.
 */
const isInvariantCell = (row, cell) =>
    row.locked_financial &&
    cell.level === 'none' &&
    (cell.role === 'estate.property_manager' || editorRoleByName.value[cell.role]?.cells?.[row.key]?.locked === true)

/** The verbs a custom cell lists, for the matrix to print under its pill. */
const customVerbs = (row, cell) => (editorRoleByName.value[cell.role]?.cells?.[row.key]?.verbs ?? []).join(', ')

/**
 * The board's own hard break in a column heading.
 *
 * Board 24 writes "Vice<br>President", "Property<br>Manager" and
 * "Admin<br>Assistant": the designer stacked every two-word role rather than
 * leaving a row of long single lines, and the heading band is two lines tall
 * because of it. Reproduced rather than left to the browser.
 *
 * TWO LINES AND NEVER THREE: the first word, then the rest. That is exactly what
 * the board does to its six, and it is what "Community Super Admin" — the one
 * board 24 deliberately omits — needs in order to sit beside them.
 */
const headLines = (label) => {
    const words = label.split(' ')

    return words.length < 2 ? [label] : [words[0], words.slice(1).join(' ')]
}

const holdersText = (count) => (count === 0 ? 'Nobody' : count === 1 ? '1 person' : `${count} people`)

const firstError = (form) => form.errors.role ?? Object.values(form.errors)[0] ?? null

/* ------------------------------------------------------------------ */
/* adding a role — from nothing, or as a copy                          */
/* ------------------------------------------------------------------ */

const creating = ref(false)
const createForm = useForm({ label: '', source: '' })

const createSource = computed(() => editorRoles.value.find((role) => String(role.id) === String(createForm.source)) ?? null)

/** What a copy of the chosen role would be made without — the server's list, read before the copy. */
const createWithheld = computed(() =>
    createSource.value ? (props.editor.copy_withheld?.[createSource.value.id] ?? []) : [],
)

/** Whether the chosen source is the Property Manager or a copy of it — Ruling 1 follows the copy. */
const createSourceLocked = computed(() => Object.values(createSource.value?.cells ?? {}).some((cell) => cell.locked))

const createSourcePosition = computed(() => {
    const rule = props.editor.position_rule
    const cells = createSource.value?.cells

    if (!rule || !cells || !cells[rule.reports]?.grants_view) {
        return []
    }

    return rule.money.filter((key) => cells[key]?.grants_view).map(moduleLabel)
})

const openCreate = (source = '') => {
    creating.value = true
    createForm.clearErrors()
    createForm.label = ''
    createForm.source = source === '' ? '' : String(source)
}

const closeCreate = () => {
    creating.value = false
    createForm.reset()
    createForm.clearErrors()
}

/*
 * An old `?edit=<id>` address (a create or a copy used to land on it, to open the editor here) goes to that role's own
 * permissions page now; the server sends a new role there itself.
 */
const openFromUrl = () => {
    const id = Number(new URLSearchParams(page.url.split('?')[1] ?? '').get('edit'))
    const role = editorRoles.value.find((each) => each.id === id)

    if (role && role.can_edit_permissions) {
        router.visit(permissionsHref(role), { replace: true })
    }
}

/** Why Make cannot be pressed yet, said under the dialog's buttons (M96 level 2), or null. */
const createBlockedBy = computed(() =>
    createForm.source === '' && createForm.label.trim() === '' ? 'Give the role a name first.' : null,
)

const submitCreate = () => {
    if (createBlockedBy.value !== null) {
        return
    }

    const url =
        createForm.source === ''
            ? `${settingsRoot.value}/settings/roles`
            : `${settingsRoot.value}/settings/roles/${createForm.source}/copy`

    // Made, it opens on its own permissions page: the server sends the new role there.
    submitDialog(createForm.transform((data) => ({ label: data.label })), 'post', url, {
        close: closeCreate,
    })
}

/* ------------------------------------------------------------------ */
/* renaming and deleting one of this community's own                   */
/* ------------------------------------------------------------------ */

const renaming = ref(null)
const renameForm = useForm({ label: '' })

const startRename = (role) => {
    if (!role.can_rename) {
        return
    }

    renameForm.label = role.label
    renameForm.clearErrors()
    renaming.value = role
}

const renameBlockedBy = computed(() => {
    if (renaming.value === null) {
        return null
    }

    if (renameForm.label.trim() === '') {
        return 'Give the role a name first.'
    }

    return renameForm.label.trim() === renaming.value.label ? 'That is its name now.' : null
})

const submitRename = () => {
    const role = renaming.value

    if (role === null || renameBlockedBy.value !== null) {
        return
    }

    submitDialog(renameForm, 'post', `${settingsRoot.value}/settings/roles/${role.id}`, {
        close: () => {
            renaming.value = null
        },
    })
}

const deleting = ref(null)
const deleteForm = useForm({})

const startDelete = (role) => {
    if (!role.can_delete) {
        return
    }

    deleteForm.clearErrors()
    deleting.value = role
}

const submitDelete = () => {
    const role = deleting.value

    if (role === null) {
        return
    }

    submitDialog(deleteForm, 'post', `${settingsRoot.value}/settings/roles/${role.id}/delete`, {
        close: () => {
            deleting.value = null
        },
    })
}

/*
 * "Reset to GeminiSecure default" on this community's own version of a shared role (M11 B4).
 * Everybody here who holds it goes back on the shared role — a change to what people may do,
 * so it is asked first, in the console's own dialog, naming the role; a refusal (a permission
 * the reset would move that the viewer does not hold) is shown inside it.
 */
const resetting = ref(null)
const resetForm = useForm({})

const startReset = (role) => {
    if (!role.can_reset) {
        return
    }

    resetForm.clearErrors()
    resetting.value = role
}

const confirmReset = () => {
    if (!resetting.value || resetForm.processing) {
        return
    }

    const role = resetting.value

    resetForm.post(`${settingsRoot.value}/settings/roles/${role.id}/reset`, {
        preserveScroll: true,
        onSuccess: () => {
            resetting.value = null
        },
    })
}

/**
 * The refusals a role's row prints under itself: every control on it that is greyed (M96 level 2). A shared role has
 * no Rename or Delete at all, so only its permissions can be refused; a community's version is reset, not renamed or
 * deleted (M11 B4).
 */
const rowRefusals = (role) =>
    [
        role.can_edit_permissions ? null : role.permissions_refusal,
        role.can_rename || !role.is_own || role.is_version ? null : role.rename_refusal,
        role.can_delete || !role.is_own || role.is_version ? null : role.delete_refusal,
        role.is_version && !role.can_reset ? role.reset_refusal : null,
    ].filter((reason, index, all) => reason && all.indexOf(reason) === index)

onMounted(openFromUrl)

/* ------------------------------------------------------------------ */
/* the six states */
/* ------------------------------------------------------------------ */

/*
 * Sources are functions, not values: useScreenState runs once during setup, and
 * a value read there would freeze on the first render.
 *
 * THE SIXTH IS REAL AND IS REACHED FROM DATA. A matrix is modules DOWN and roles
 * ACROSS, and both are read from the central catalogue filtered to the estate
 * console. A platform with modules and no estate role produces a table with rows
 * and no columns — emptiness the filter caused — which is a different sentence
 * from a console that has no modules at all.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => props.roles.length === 0,
})
</script>

<template>
    <Head title="Settings" />

    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <template #actions>
            <!--
              M96 level 3: "Invite user" only for a viewer who may invite (it leads to board 22 with its dialog open);
              a viewer who may change nothing here reads a badge instead.
            -->
            <Link v-if="canInvite" :href="inviteHref" class="btn-primary-sm" title="Invite somebody to hold a role on this estate.">
                <span>Invite user</span>
            </Link>
            <span v-else-if="!editor.can_edit" class="view-badge">View only</span>
        </template>

        <div class="settings-layout">
            <!--
              The settings column, on every settings screen. The item the reader
              is on is text rather than a control — there is nowhere for it to
              lead — and every sibling is a real link.
            -->
            <div class="settings-nav">
                <template v-for="section in sections" :key="section.key">
                    <div v-if="section.active" class="settings-nav-item active" aria-current="page">
                        {{ section.label }}
                    </div>
                    <Link v-else :href="section.href" class="settings-nav-item">
                        {{ section.label }}
                    </Link>
                </template>
            </div>

            <div class="roles-main">
                <SkeletonRows v-if="state.isLoading.value" :rows="10" :columns="7" />

                <EmptyState
                    v-else-if="state.isDenied.value"
                    variant="denied"
                    title="Settings is not part of your role’s access"
                    body="Ask your estate administrator if you need it."
                />

                <EmptyState
                    v-else-if="state.isError.value"
                    variant="error"
                    title="The role access matrix could not be read"
                    body="The permission model did not answer. Nothing has changed, and every role still holds exactly what it held. Trying again is safe."
                    action-label="Try again"
                    @action="retry"
                />

                <!--
                  Rows and no columns. See the note on `state`: modules exist and
                  no estate role does, which is the filter emptying the table
                  rather than a console with nothing in it.
                -->
                <EmptyState
                    v-else-if="state.isEmptyFiltered.value"
                    variant="filtered"
                    title="This console has modules and no roles to hold them"
                    body="A matrix is modules down and roles across, and there is not one estate role to put across the top. Until a role exists there is nothing for a permission to belong to."
                />

                <EmptyState
                    v-else-if="state.isEmpty.value"
                    variant="first-use"
                    title="This console has no modules yet"
                    body="The matrix is drawn from the modules this console holds, and it holds none. Every screen sits behind a module, so nobody could reach any part of it."
                />

                <template v-else>

                    <!--
                      The legend, in the board's own order: Full, View, Entry,
                      then no access. The order is the server's and the four
                      descriptions are the definitions the accounting note
                      treats as literal.
                    -->
                    <div class="role-legend">
                        <div v-for="item in legend" :key="item.key" class="role-legend-item">
                            <div class="perm-pill" :class="item.key">{{ item.label }}</div>
                            <span>{{ item.description }}</span>
                        </div>
                    </div>

                    <!--
                      Wrapped so a community with several roles of its own
                      scrolls sideways rather than pushing its last columns off
                      the edge where nobody can see them.
                    -->
                    <div class="rl-scroller">
                        <table class="matrix-table">
                            <thead>
                                <tr>
                                    <th>Module</th>
                                    <th v-for="role in roles" :key="role.name" :title="`${role.label} · ${role.scope_label}`">
                                        <!--
                                          Stacked on two lines, as the board stacks
                                          its own — see headLines(). A role this
                                          community made carries the one caption the
                                          board's `.role-head-scope` was drawn for;
                                          the shared eight carry none, as drawn.
                                        -->
                                        <div class="role-head-name">
                                            <template v-for="(line, i) in headLines(role.label)" :key="line">
                                                <br v-if="i > 0" />{{ line }}
                                            </template>
                                        </div>
                                        <div v-if="role.is_own" class="role-head-scope">This community’s</div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in rows" :key="row.key">
                                    <td>
                                        <div class="mod-name">{{ row.label }}</div>
                                    </td>

                                    <td v-for="cell in row.cells" :key="`${row.key}-${cell.role}`">
                                        <!--
                                          A CELL OPENS ITS ROLE'S PERMISSIONS (the client, 26 Sep: "user still not able to give
                                          or change or remove the permission to roles"): the matrix is where a person reaches to
                                          change a permission. Since 12 Oct that is a page of its own, the role's whole table
                                          (Hassam: a long table read across is never a modal). Wrapping the pill keeps every tag
                                          inside it as drawn.
                                        -->
                                        <Link
                                            v-if="cellEditable(cell)"
                                            :href="permissionsHref(editorRoleByName[cell.role])"
                                            class="rl-cell-button"
                                            :title="`${cell.label}. Open what ${editorRoleByName[cell.role]?.label ?? 'this role'} may do, module by module.`"
                                        >
                                            <span class="perm-pill" :class="cell.level">
                                                {{ cell.label }}
                                                <span v-if="cell.level === 'custom'" class="custom-verbs">{{ customVerbs(row, cell) || 'nothing' }}</span>
                                                <span v-if="cell.can_approve" class="approver-tag">Approver</span>
                                                <span v-if="cell.acts_label" class="approver-tag">{{ cell.acts_label }}</span>
                                                <span v-if="isInvariantCell(row, cell)" class="inv-tag" :title="invariant_note">Locked</span>
                                            </span>
                                        </Link>
                                        <div v-else class="perm-pill" :class="cell.level">
                                            {{ cell.label }}

                                            <!-- A custom cell names its verbs: the word alone tells a committee nothing. -->
                                            <span v-if="cell.level === 'custom'" class="custom-verbs">{{ customVerbs(row, cell) || 'nothing' }}</span>

                                            <!--
                                              The Approver tag, read from `can_approve`
                                              rather than from a list of cells, because
                                              D-008 is that Full and Approver are
                                              separate grants.
                                            -->
                                            <span v-if="cell.can_approve" class="approver-tag">Approver</span>

                                            <!--
                                              AN APPROVAL HELD ON ITS OWN (M11 B3): "Sets
                                              dues rates" where the tag would say every
                                              approval on the module, and "Full" none.
                                            -->
                                            <span v-if="cell.acts_label" class="approver-tag">{{ cell.acts_label }}</span>

                                            <!--
                                              WHERE THE BOARD AND THE MODEL DISAGREE,
                                              SAID ON THE CELL — on the Property
                                              Manager and on any copy of it.
                                            -->
                                            <span v-if="isInvariantCell(row, cell)" class="inv-tag" :title="invariant_note">Locked</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!--
                      The board's one note box. Its first line is board 24's own,
                      verbatim; then why this viewer can or cannot change a cell;
                      then which cells the platform closed rather than this estate.
                    -->
                    <div class="audit-note">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M12 2 2 7v6c0 5.2 3.8 9 10 11 6.2-2 10-5.8 10-11V7l-10-5z"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <div>
                            <p class="note-line">{{ audit_note }}</p>

                            <!--
                              Printed only to a viewer who cannot edit. The one who
                              can reads why the shared columns stay as they are right
                              above the list of roles, where the Copy is.
                            -->
                            <p v-if="!can_edit" class="note-line">{{ read_only_reason }}</p>
                            <p class="note-line">{{ invariant_note }}</p>
                        </div>
                    </div>

                    <!--
                      AUTHORED FROM HERE DOWN (M10 §4). The board is a still image of
                      a matrix nobody can change, so it draws no list of roles, no
                      editor and no panel. Built only for the viewer the server says
                      may use it; everybody else reads the matrix above and the
                      sentence that says why they cannot change it.
                    -->
                    <template v-if="editor.can_edit">
                        <div class="rl-section-head">
                            <span>Roles on this estate</span>
                            <button
                                type="button"
                                class="btn-primary-sm"
                                title="Make a role of this community’s own."
                                @click="openCreate()"
                            >
                                <svg viewBox="0 0 24 24" fill="none">
                                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                                </svg>
                                <span>Add role</span>
                            </button>
                        </div>

                        <p class="rl-caption">{{ shared_role_note }}</p>

                        <div class="rl-scroller">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Role</th>
                                        <th>Kind</th>
                                        <th>Held by</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="role in editorRoles" :key="role.id">
                                        <tr>
                                            <td>
                                                <div class="res-name">{{ role.label }}</div>
                                                <div v-if="role.copied_from" class="res-sub">Copied from {{ role.copied_from }}</div>
                                                <div v-if="role.note" class="res-sub">{{ role.note }}</div>
                                            </td>
                                            <td>
                                                <div class="role-badge" :class="{ owner: role.is_own }">
                                                    {{ role.is_version ? 'This community’s version' : role.is_own ? 'This community’s own' : 'Shared · GeminiSecure default' }}
                                                </div>
                                            </td>
                                            <td class="rl-nowrap">{{ holdersText(role.holders) }}</td>
                                            <td>
                                                <div class="rl-row-actions">
                                                    <!--
                                                      A shared role is edited too (M11 B4): the first change makes this
                                                      community's own version of it, which then stands in its place. The
                                                      table is a page of its own (Hassam, 12 Oct); a role this viewer may not
                                                      change keeps the control greyed, its reason said under the row.
                                                    -->
                                                    <Link
                                                        v-if="role.can_edit_permissions"
                                                        :href="permissionsHref(role)"
                                                        class="text-link-sm"
                                                        :title="`Change what ${role.label} may do, module by module.`"
                                                    >
                                                        Edit permissions
                                                    </Link>
                                                    <button
                                                        v-else
                                                        type="button"
                                                        class="text-link-sm"
                                                        disabled
                                                        :title="role.permissions_refusal"
                                                    >
                                                        Edit permissions
                                                    </button>
                                                    <button
                                                        v-if="role.is_version"
                                                        type="button"
                                                        class="text-link-sm"
                                                        :disabled="!role.can_reset"
                                                        :title="`Put everybody here back on GeminiSecure’s ${role.label}.`"
                                                        @click="startReset(role)"
                                                    >
                                                        Reset to GeminiSecure default
                                                    </button>
                                                    <template v-if="role.is_own && !role.is_version">
                                                        <button
                                                            type="button"
                                                            class="text-link-sm"
                                                            :disabled="!role.can_rename"
                                                            :title="`Rename ${role.label}.`"
                                                            @click="startRename(role)"
                                                        >
                                                            Rename
                                                        </button>
                                                    </template>
                                                    <button
                                                        type="button"
                                                        class="text-link-sm"
                                                        :title="`Make a role of this community’s own starting from ${role.label}.`"
                                                        @click="openCreate(role.id)"
                                                    >
                                                        Copy…
                                                    </button>
                                                    <button
                                                        v-if="role.is_own && !role.is_version"
                                                        type="button"
                                                        class="text-link-sm"
                                                        :disabled="!role.can_delete"
                                                        :title="`Delete ${role.label}.`"
                                                        @click="startDelete(role)"
                                                    >
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                        <!--
                                          WHY A CONTROL ABOVE IS DISABLED, IN WORDS —
                                          the board's own refusal, on the screen and
                                          not only in a tooltip.
                                        -->
                                        <tr v-if="rowRefusals(role).length > 0" class="rl-why-row">
                                            <td colspan="4">
                                                <p v-for="reason in rowRefusals(role)" :key="reason" class="rl-why">{{ reason }}</p>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- ADD ROLE, and "Copy…": one dialog, because a copy is a role made from a starting point. -->
                        <ConsoleDialog
                            :open="creating"
                            :title="`Add a role of ${estate.name}’s own`"
                            size="form"
                            :confirm-label="createSource ? 'Make the copy' : 'Make the role'"
                            busy-label="Making…"
                            :busy="createForm.processing"
                            :confirm-blocked-reason="createBlockedBy"
                            :error="firstError(createForm)"
                            :dirty="createForm.label.trim() !== ''"
                            discard-title="Discard this role?"
                            @confirm="submitCreate"
                            @cancel="closeCreate"
                        >
                            <!-- What the mark means, once, above the first field (batch 1 item 3). -->
                            <RequiredMark legend />
                            <div class="rl-fields">
                                <div class="rl-field">
                                    <label for="rl-new-label">Name <RequiredMark /></label>
                                    <input
                                        id="rl-new-label"
                                        v-model="createForm.label"
                                        type="text"
                                        required
                                        :maxlength="editor.label_max"
                                        autocomplete="off"
                                        :placeholder="createSource ? `e.g. Copy of ${createSource.label}` : 'e.g. Gate supervisor'"
                                    />
                                    <span class="rl-hint">What the sidebar and every list of users will print.</span>
                                </div>

                                <div class="rl-field">
                                    <label for="rl-new-source">Start from</label>
                                    <select id="rl-new-source" v-model="createForm.source">
                                        <option value="">Nothing: every module at no access</option>
                                        <option v-for="role in editorRoles" :key="role.id" :value="String(role.id)">
                                            A copy of {{ role.label }}{{ role.is_shared ? ' (shared)' : '' }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <!-- WHAT THE COPY WILL NOT HOLD, said before it is made. -->
                            <template v-if="createSource">
                                <p v-if="createWithheld.length > 0" class="rl-warn">
                                    A copy of {{ createSource.label }} leaves out what your own role does not hold, because
                                    nobody can give a role what they do not hold themselves:
                                    <strong>{{ createWithheld.join(', ') }}</strong>.
                                </p>
                                <p v-else class="rl-read">
                                    A copy of {{ createSource.label }} holds everything the original does. You can change
                                    it once it is made, and the original stays exactly as it is.
                                </p>
                                <p v-if="createSourceLocked" class="rl-read">{{ invariant_note }} That binds the copy too.</p>
                                <p v-if="createSourcePosition.length > 0" class="rl-warn">
                                    {{ editor.position_rule.warning }} The copy starts with Reports and
                                    {{ createSourcePosition.join(', ') }}.
                                </p>
                            </template>
                            <p v-else class="rl-read">
                                The new role reaches nothing until you give it modules. It belongs to this community alone;
                                no other estate sees it.
                            </p>
                        </ConsoleDialog>

                        <ConsoleDialog
                            :open="renaming !== null"
                            :title="renaming ? `Rename ${renaming.label}` : ''"
                            confirm-label="Save name"
                            :busy="renameForm.processing"
                            :confirm-blocked-reason="renameBlockedBy"
                            :error="firstError(renameForm)"
                            @confirm="submitRename"
                            @cancel="renaming = null"
                        >
                            <div class="rl-field">
                                <label for="rl-rename">New name <RequiredMark /></label>
                                <input id="rl-rename" v-model="renameForm.label" type="text" required :maxlength="editor.label_max" autocomplete="off" />
                                <span class="rl-hint">The people who hold it and what it may do stay exactly as they are.</span>
                            </div>
                        </ConsoleDialog>

                        <!-- DELETE: destructive, so the dialog's red outline, never solid red. -->
                        <ConsoleDialog
                            :open="deleting !== null"
                            :title="deleting ? `Delete ${deleting.label}?` : ''"
                            tone="danger"
                            confirm-label="Delete the role"
                            busy-label="Deleting…"
                            :busy="deleteForm.processing"
                            :error="firstError(deleteForm)"
                            @confirm="submitDelete"
                            @cancel="deleting = null"
                        >
                            <p v-if="deleting" class="rl-plain">
                                Nobody holds {{ deleting.label }}. The audit log keeps what it could do and who deleted it;
                                the role itself cannot be brought back.
                            </p>
                        </ConsoleDialog>
                    </template>
                </template>
            </div>
        </div>

        <ConsoleDialog
            :open="resetting !== null"
            :title="resetting ? `Reset ${resetting.label} to GeminiSecure's default?` : ''"
            tone="danger"
            :confirm-label="resetting ? `Reset ${resetting.label}` : 'Reset'"
            busy-label="Resetting…"
            :busy="resetForm.processing"
            :error="resetForm.errors.role ?? null"
            @confirm="confirmReset"
            @cancel="resetting = null"
        >
            <p style="margin:0;">
                Everybody here who holds {{ resetting?.label }} goes back to GeminiSecure's {{ resetting?.label }},
                shared by every community, and this community's own version is removed. What they may do changes on
                their next page. The audit log keeps what this community's version could do.
            </p>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only above the authored line, and each removal names the
 * element that needs it.
 *
 * `font-family` and never `font`. Vue's scoped attribute lifts a bare `button`
 * selector to the same weight as a single class, so `font: inherit` would
 * out-specify `.settings-nav-item`'s own 12.5px and `.text-link-sm`'s own 700
 * and render both at the body's size. The board declares no font-family on
 * either, so that one property is the whole of what is missing. See D-045.
 *
 * NO FOCUS RULE ANYWHERE IN THIS FILE, deliberately. The one focus treatment is
 * app.css's (M10 §6), and a per-component outline here would be the drift that
 * section was written to end.
 */
button {
    font-family: inherit;
}

/* .btn-primary-sm declares a background and a shadow and no border;
 * .settings-nav-item and .text-link-sm declare neither, and the amber face on
 * .active is a two-class rule no button here can match — the current section
 * is drawn as text. */
button.btn-primary-sm {
    border: 0;
    cursor: pointer;
}

button.settings-nav-item,
button.text-link-sm {
    border: 0;
    background: none;
    text-align: left;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
    opacity: 0.55;
}

/* =====================================================================
 * AUTHORED BELOW THIS LINE, in the tokens and shapes the estate boards use.
 * ===================================================================== */

/* The right-hand column is a grid child; without a floor it grows to the widest table in it. */
.roles-main {
    min-width: 0;
}

.rl-scroller {
    overflow-x: auto;
}

/*
 * The invariant marker, in the shape the board already uses for a sub-tag on a
 * pill — `.approver-tag`'s geometry exactly, and deliberately NOT its amber.
 * Amber on this board means a grant; this says a cell is closed by platform
 * rule, so it takes the slate the boards use for a fact nobody here decided.
 */
.inv-tag {
    display: block;
    font-size: 8px;
    font-weight: 700;
    color: var(--slate-500);
    margin-top: 2px;
}

/*
 * A fifth pill, for the fifth level. The board draws four; a custom cell is a
 * grant, but not one of the board's three, so it takes the navy the boards use
 * for a mid-strength fact rather than borrowing Full's amber or View's face.
 */
.perm-pill.custom {
    background: var(--navy-200);
    color: var(--navy-800);
}

.custom-verbs {
    display: block;
    font-size: 8px;
    font-weight: 600;
    margin-top: 2px;
    white-space: normal;
    max-width: 90px;
}

/* Three sentences in the board's note box, restating `.audit-note span`'s type on the <p> that took its job. */
.note-line {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.5;
    margin: 0;
}

.note-line + .note-line {
    margin-top: 7px;
}

/* Level 3's badge, where "Invite user" would be. */
.view-badge {
    display: inline-flex;
    align-items: center;
    height: 30px;
    padding: 0 12px;
    border-radius: 999px;
    background: var(--navy-100);
    color: var(--navy-700);
    font-size: 12px;
    font-weight: 700;
}

.rl-hint {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.45;
}

/* Paragraphs one after another in a dialog: a little air between them. */
.rl-read + .rl-read,
.rl-read + .rl-warn,
.rl-warn + .rl-read,
.rl-fields + .rl-read,
.rl-fields + .rl-warn {
    margin-top: 8px;
}

.rl-plain {
    margin: 0;
    font-size: 12.5px;
    color: var(--navy-900);
    line-height: 1.55;
}

.rl-section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: 22px 0 8px;
}

.rl-section-head > span {
    font-family: 'Poppins', sans-serif;
    font-size: 12.5px;
    color: var(--slate-600);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.rl-section-head .btn-primary-sm svg {
    width: 14px;
    height: 14px;
    color: var(--white);
}

.rl-caption {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.5;
    margin: 0 0 12px;
    max-width: 760px;
}

.rl-fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 11px;
}

.rl-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.rl-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.rl-field input,
.rl-field select {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.rl-read {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.5;
    margin: 0;
}

/* A warning is a fact the reader should weigh, not a fault — amber, the boards' "look at this". */
.rl-warn {
    font-size: 11.5px;
    color: var(--amber-700);
    background: var(--amber-100);
    border-radius: 10px;
    padding: 9px 13px;
    line-height: 1.5;
    margin: 0;
}

.rl-row-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.rl-nowrap {
    white-space: nowrap;
}

.rl-why-row td {
    background: var(--app-bg);
    padding: 8px 16px 10px;
}

.rl-why {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.5;
    margin: 0;
}

.rl-why + .rl-why {
    margin-top: 4px;
}

@media (max-width: 760px) {
    .rl-fields {
        grid-template-columns: 1fr;
    }
}

/* A matrix cell that opens its role's permissions page: the pill as drawn, and a ring on hover that says it can be pressed. */
.rl-cell-button {
    display: inline-block;
    border-radius: 999px;
    color: inherit;
    text-decoration: none;
    cursor: pointer;
}

/* Edit permissions, a link to the role's page where it may be changed. */
a.text-link-sm {
    text-decoration: none;
}

.rl-cell-button:hover .perm-pill {
    box-shadow: 0 0 0 2px var(--navy-200);
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
