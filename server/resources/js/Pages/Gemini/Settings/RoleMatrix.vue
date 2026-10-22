<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import { toast } from '../../../lib/toasts.js'
import SettingsTabs from './SettingsTabs.vue'
import { useLiveKind } from '../../../composables/useLiveKind'

// The role matrix is an editor: never re-read under somebody's changes (M18H §7.2).
useLiveKind('form')

/**
 * Role Access Matrix — board screen super-admin-45, drawn at 1440x1140.
 *
 * DOM and class names are the board's: a tab strip, the "New custom role"
 * button the board draws top right, and one table. Every pill is a row of
 * role_module_access, which is the same table the sidebar is generated from
 * and the same table `can:` resolves against.
 *
 * IT IS THE EDITOR NOW, FOR THE DIRECTOR (M10 §4). It was read-only while there
 * was no backend behind a change; `App\Services\Rbac\RoleEditor` is that
 * backend, and every rule is its and not this file's. For a viewer who may
 * edit (`canEdit`), each column header grows Edit, Rename and Delete, and
 * Edit turns that one column's pills into a level select, an Approver
 * checkbox and — for a Custom cell — the row of per-action checkboxes. One
 * column at a time, saved as one post, so the audit log reads one change to
 * one role. For anyone else the board is as drawn, with the reason said.
 *
 * NOTHING IS OFFERED THAT THE SERVER WOULD REFUSE, AND NOTHING IS REFUSED IN
 * SILENCE. `grantable` says which verbs the viewer holds on each module;
 * a level, an action or the Approver tag that would give or take away one
 * they do not hold renders disabled, with the reason on it as a title AND in
 * words beside it. A seeded role's Rename and Delete are disabled with
 * RoleEditor's own sentence, readable under "Why not?". The Director's
 * Platform settings cell, the one that lets somebody restore everything else,
 * is not a control at all and says so. None of this is the enforcement —
 * `RoleEditor::setPermissions()` refuses a hand-built post all the same, and
 * its sentence is printed in the open panel.
 *
 * A PLATFORM UPDATE KEEPS WHAT THE DIRECTOR CHANGED, AND THE PAGE SAYS SO. A
 * seeded role the Director has re-granted is marked, and a re-seed leaves it
 * alone; the notes under the table carry the board's own sentence saying so.
 * The way back is "Reset to GeminiSecure default", drawn on a seeded role's
 * column only while it differs from its default, with who changed it and when.
 * It confirms against the server's list of what it would give back and take
 * away before anything is written, and it is disabled with its reason when it
 * cannot be done — nothing to reset, or a reset that would give the role
 * something the Director no longer holds.
 *
 * A VERB THAT IS SEVERAL POWERS IS SHOWN AS THEM (M11 B3). Configure on Platform
 * settings is three permissions — prices, mail and SMS delivery, the simulator
 * (`App\Support\NamedActs`) — so at Custom its one box is three, each given or
 * taken on its own and checked against `grantable` like any verb. A cell holding
 * some of them says which on its pill ("View · Sets prices"). What a draft
 * compiles to is compared the way the server compiles it.
 *
 * MONEY IS SAID OUT LOUD. Under M10 §5 (`App\Support\PlatformMoney`) holding
 * view on Billing & subscriptions or on Payroll & Accounting is what lets a
 * role read what clients pay or what officers are paid, on screens far from
 * those two modules. A change that would give a role either is warned about in
 * plain words before it is saved, and so is a copy that would carry one.
 *
 * The tab strip is SettingsTabs, shared with the other screens in this
 * module: it is the same tabs at the same widths on all of them, and a reader
 * moving between them must see it as a fixed thing that does not move.
 *
 * Text in the pills sits tight against its tags on purpose: the board's pill is
 * a <div> whose only child is the label, and a stray space either side would
 * widen it.
 */
const props = defineProps({
    tabs: { type: Array, required: true },
    roles: { type: Array, required: true },
    modules: { type: Array, required: true },
    canEdit: { type: Boolean, required: true },
    editBlockedReason: { type: String, default: null },
    /** The six per-action verbs, approve excluded — that is the Approver tag. */
    verbs: { type: Array, required: true },
    /** The five levels, with the verbs each grants. */
    levels: { type: Array, required: true },
    /** grantable[moduleKey][verb]: does the viewer hold it themselves? */
    grantable: { type: Object, required: true },
    grantableReason: { type: String, required: true },
    /** The modules whose view unlocks money (M10 §5), with the warning to print. */
    moneyModules: { type: Array, required: true },
    labelMax: { type: Number, required: true },
    scopes: { type: Array, required: true },
    /** The server's statement of what a copy would hold — see the Copy panel. */
    copyOffer: { type: Object, default: null },
})

const page = usePage()

/**
 * The board hard-breaks a multi-word role name before its last word —
 * "Operations / Manager", "Head of / Security", "Admin / Assistant" — so the
 * six columns keep the widths the design allots them. Derived from the label
 * rather than hardcoded per role, so a renamed role still breaks in the right
 * place and a one-word name stays on one line.
 */
const nameLines = (label) => {
    const words = String(label).trim().split(/\s+/)

    return words.length < 2 ? [label] : [words.slice(0, -1).join(' '), words[words.length - 1]]
}

/* ------------------------------------------------------------------ */
/* which panel is open — one at a time                                 */
/* ------------------------------------------------------------------ */

/** { kind: 'create' | 'copy' | 'edit' | 'rename' | 'delete' | 'reset', roleId } or null. */
const panel = ref(null)

const isOpen = (kind, roleId = null) => panel.value?.kind === kind && (roleId === null || panel.value.roleId === roleId)

const close = () => {
    panel.value = null
}

const roleById = (id) => props.roles.find((role) => role.id === id) ?? null

const panelRole = computed(() => (panel.value?.roleId ? roleById(panel.value.roleId) : null))

// A role that has gone — deleted, here or elsewhere — takes its panel with it.
watch(
    () => props.roles,
    () => {
        if (panel.value?.roleId && panelRole.value === null) {
            close()
        }
    }
)

/**
 * RoleEditor's refusal, in its own words. Said inside the panel that is open, which stays open (Hassam, 12 October); one
 * that arrives with no panel open is what just happened, so a toast that stays until it is closed.
 */
const refusal = computed(() => page.props.errors?.roles ?? null)

watch(refusal, (text) => {
    if (text && panel.value === null) {
        toast(text, { tone: 'error' })
    }
})

/**
 * Every disabled control on a column header, in RoleEditor's own sentences. The
 * reset's refusal only when the reset control is drawn — a role at its default
 * has no control to explain.
 */
const whyNot = (role) =>
    [
        ...new Set(
            [
                role.permissions_refusal,
                role.rename_refusal,
                role.delete_refusal,
                role.resettable && !role.can_reset ? role.reset_refusal : null,
            ].filter(Boolean)
        ),
    ]

/** Every role the board has something to say about — the seeded ones — for the notes under the table. */
const notedRoles = computed(() => props.roles.filter((role) => role.note))

/* ------------------------------------------------------------------ */
/* a cell, as the editor reasons about it                              */
/* ------------------------------------------------------------------ */

const levelVerbs = (key) => props.levels.find((level) => level.key === key)?.verbs ?? []

const levelLabel = (level) => (level.key === 'none' ? 'No access' : level.label)

const savedCell = (module, roleId) => module.cells.find((cell) => cell.role_id === roleId)

/** The verbs a cell grants: a preset's own, or a Custom cell's list. */
const verbsOf = (cell) => (cell.level === 'custom' ? cell.verbs : levelVerbs(cell.level))

const grantsView = (cell) => verbsOf(cell).includes('view')

/* ---- named acts (M11 B3): a verb split into one permission per power ---- */

/** The named acts one verb of this module is split into — Platform settings' configure is three. */
const actsUnder = (module, verb) => (module.acts ?? []).filter((act) => act.verb === verb)

/**
 * An Approver tag, or a named act, with nothing to open is not a cell: RoleEditor
 * refuses one, so it is dropped here first — an act under a verb on a Custom list
 * too, so that verb goes with it (Platform settings' configure is its three acts).
 */
const settle = (module, cell) => {
    const opens = grantsView(cell)

    return {
        ...cell,
        verbs: cell.level === 'custom' && !opens ? cell.verbs.filter((verb) => actsUnder(module, verb).length === 0) : cell.verbs,
        can_approve: Boolean(cell.can_approve) && opens,
        acts: opens ? [...(cell.acts ?? [])] : [],
    }
}

/**
 * What a cell compiles to, as the server compiles it
 * (`RoleModuleAccess::permissionNames()`): each verb — or, for a verb split into
 * named acts, its acts — the Approver tag the same way, and the acts it lists on
 * their own.
 */
const permsOf = (module, cell) => {
    const settled = settle(module, cell)
    const perms = new Set()

    const expand = (verb) => {
        const under = actsUnder(module, verb)

        if (under.length === 0) {
            perms.add(verb)
        } else {
            under.forEach((act) => perms.add(act.key))
        }
    }

    verbsOf(settled).filter((verb) => verb !== 'approve').forEach(expand)

    if (settled.can_approve) {
        expand('approve')
    }

    settled.acts.forEach((key) => perms.add(key))

    return [...perms]
}

const sameCell = (module, a, b) => {
    const pa = permsOf(module, a).slice().sort()
    const pb = permsOf(module, b).slice().sort()

    return pa.length === pb.length && pa.every((verb, i) => verb === pb[i])
}

/**
 * GUARD RAIL 3, MIRRORED: the verbs and acts this candidate would give or take
 * away that the viewer does not hold. RoleEditor checks both directions, so this
 * does.
 */
const unheldChange = (module, saved, candidate) => {
    const before = permsOf(module, saved)
    const after = permsOf(module, candidate)
    const changed = [...before.filter((verb) => !after.includes(verb)), ...after.filter((verb) => !before.includes(verb))]

    return [...new Set(changed)].filter((verb) => !props.grantable[module.key]?.[verb])
}

/** Does this cell grant the act — through the verb above it, or on its own? */
const hasAct = (cell, act) =>
    (act.verb === 'approve' ? Boolean(cell.can_approve) : verbsOf(cell).includes(act.verb)) || (cell.acts ?? []).includes(act.key)

/**
 * The cell with one act ticked or unticked. Unticking one act of a verb that
 * grants them all takes the verb off and keeps the others one by one; ticking
 * the last one on puts the verb back — the form RoleEditor stores.
 */
const withAct = (module, cell, act, on) => {
    const siblings = actsUnder(module, act.verb).map((each) => each.key)
    const parentOn = act.verb === 'approve' ? Boolean(cell.can_approve) : verbsOf(cell).includes(act.verb)
    const picked = new Set(cell.acts ?? [])
    let next = { ...cell, verbs: [...verbsOf(cell)] }

    const setParent = (value) => {
        if (act.verb === 'approve') {
            next.can_approve = value

            return
        }

        const verbs = new Set(next.verbs)

        if (value) {
            verbs.add(act.verb)
        } else {
            verbs.delete(act.verb)
        }

        next = { ...next, level: 'custom', verbs: props.verbs.map((verb) => verb.key).filter((key) => verbs.has(key)) }
    }

    if (parentOn) {
        if (on) {
            return cell
        }

        setParent(false)
        siblings.filter((key) => key !== act.key).forEach((key) => picked.add(key))
    } else if (on) {
        picked.add(act.key)

        if (siblings.every((key) => picked.has(key))) {
            siblings.forEach((key) => picked.delete(key))
            setParent(true)
        }
    } else {
        picked.delete(act.key)
    }

    return { ...next, acts: (module.acts ?? []).map((each) => each.key).filter((key) => picked.has(key)) }
}

/* ------------------------------------------------------------------ */
/* editing one column                                                  */
/* ------------------------------------------------------------------ */

const editingId = computed(() => (panel.value?.kind === 'edit' ? panel.value.roleId : null))

/** moduleKey -> { level, verbs, can_approve, acts } for the column being edited. */
const draft = reactive({})

const permissionsForm = useForm({})

const startEdit = (role) => {
    if (!props.canEdit || !role.can_edit_permissions) {
        return
    }

    for (const key of Object.keys(draft)) {
        delete draft[key]
    }

    for (const module of props.modules) {
        const cell = savedCell(module, role.id)

        draft[module.key] = { level: cell.level, verbs: [...cell.verbs], can_approve: cell.can_approve, acts: [...(cell.acts ?? [])] }
    }

    permissionsForm.clearErrors()
    panel.value = { kind: 'edit', roleId: role.id }
}

/*
 * A PILL OPENS ITS OWN COLUMN (the client, 26 Sep: "why still user is not able to change the permission against a
 * role"). Only the small Edit link under a role's name used to; the pills — where anybody reaching to change a
 * permission clicks — did nothing. Now a pill in a column that may be edited starts editing that column, with the
 * pressed cell's own select focused.
 */
const canEditColumn = (roleId) => props.canEdit && roleById(roleId)?.can_edit_permissions === true
const editFromCell = (cell, module) => {
    const role = roleById(cell.role_id)

    if (!role || !canEditColumn(role.id)) {
        return
    }

    if (editingId.value !== role.id) {
        startEdit(role)
    }

    nextTick(() => document.getElementById(`rm-level-${module.key}`)?.focus())
}

const blocked = (module, candidate) => unheldChange(module, savedCell(module, editingId.value), candidate).length > 0

/** A cell nobody may change here: the structural minimum, or Ruling 1. Its reason, or null. */
const frozenReason = (module) => {
    const cell = savedCell(module, editingId.value)

    if (cell.minimum) {
        return panelRole.value?.note ?? 'This cell is what lets somebody restore every other role, so it always stays as it is.'
    }

    return cell.locked ? cell.locked_reason : null
}

const optionBlocked = (module, levelKey) => {
    const current = draft[module.key]

    return blocked(module, {
        level: levelKey,
        verbs: levelKey === 'custom' ? [...verbsOf(current)] : levelVerbs(levelKey),
        can_approve: current.can_approve,
        acts: current.acts,
    })
}

const setLevel = (module, levelKey) => {
    const current = draft[module.key]
    const verbs = levelKey === 'custom' ? [...verbsOf(current)] : [...levelVerbs(levelKey)]

    draft[module.key] = settle(module, { level: levelKey, verbs, can_approve: current.can_approve, acts: current.acts })
}

const toggled = (verbs, verb) => (verbs.includes(verb) ? verbs.filter((v) => v !== verb) : [...verbs, verb])

const verbBlocked = (module, verb) => {
    const current = draft[module.key]

    return blocked(module, { level: 'custom', verbs: toggled(current.verbs, verb), can_approve: current.can_approve, acts: current.acts })
}

const toggleVerb = (module, verb) => {
    const current = draft[module.key]

    draft[module.key] = settle(module, { level: 'custom', verbs: toggled(current.verbs, verb), can_approve: current.can_approve, acts: current.acts })
}

/** Why one act's box cannot be changed: 'view' (nothing to open), 'unheld', or null. */
const actBlocked = (module, act) => {
    const current = draft[module.key]
    const on = hasAct(current, act)

    if (!on && !grantsView(current)) {
        return 'view'
    }

    return blocked(module, withAct(module, current, act, !on)) ? 'unheld' : null
}

const toggleAct = (module, act) => {
    const current = draft[module.key]

    draft[module.key] = settle(module, withAct(module, current, act, !hasAct(current, act)))
}

const actTitle = (module, act) =>
    ({
        view: `Give ${module.label} at least view before giving it ${act.label}. Nobody can do what they cannot open.`,
        unheld: props.grantableReason,
    })[actBlocked(module, act)] ?? act.description

/** At Custom: each verb, or — for a verb split into acts — each of its acts, one box each. */
const customItems = (module) =>
    props.verbs.flatMap((verb) => {
        const under = actsUnder(module, verb.key)

        return under.length === 0 ? [{ kind: 'verb', key: verb.key, verb }] : under.map((act) => ({ kind: 'act', key: act.key, act }))
    })

/** Why the Approver box cannot be changed: 'view' (nothing to open), 'unheld', or null. */
const approveBlocked = (module) => {
    const current = draft[module.key]

    if (!current.can_approve && !grantsView(current)) {
        return 'view'
    }

    return blocked(module, { ...current, can_approve: !current.can_approve }) ? 'unheld' : null
}

const toggleApprove = (module) => {
    const current = draft[module.key]

    draft[module.key] = settle(module, { ...current, can_approve: !current.can_approve })
}

const approveNote = (module) =>
    ({
        view: 'needs view first',
        unheld: 'not yours to give',
    })[approveBlocked(module)] ?? null

const approveTitle = (module) =>
    ({
        view: 'Give this module at least view before tagging it Approver. Nobody can approve what they cannot open.',
        unheld: props.grantableReason,
    })[approveBlocked(module)] ??
    'Approver lets the role commit the irreversible act on this module, not only prepare it.'

/**
 * The actions on this module the viewer does not hold, said in words under the
 * cell — a verb split into named acts by the acts, since holding some of them
 * is not holding none.
 */
const unheldNote = (module) => {
    const items = props.verbs.flatMap((verb) => {
        const under = actsUnder(module, verb.key)

        return under.length === 0
            ? [{ key: verb.key, label: verb.key }]
            : under.map((act) => ({ key: act.key, label: act.label }))
    })

    const unheld = items.filter((item) => !props.grantable[module.key]?.[item.key]).map((item) => item.label)

    if (unheld.length === 0) {
        return null
    }

    if (unheld.length === items.length) {
        return `Your own role holds nothing on ${module.label}, so this cell is not yours to change.`
    }

    return `Not yours to give or take: ${unheld.join(', ')}.`
}

const changedModules = computed(() =>
    editingId.value === null
        ? []
        : props.modules.filter((module) => draft[module.key] && !sameCell(module, draft[module.key], savedCell(module, editingId.value)))
)

/** Money this change would newly open to the role (M10 §5), in the server's words. */
const moneyWarnings = computed(() => {
    if (editingId.value === null) {
        return []
    }

    return props.moneyModules.filter((money) => {
        const module = props.modules.find((m) => m.key === money.key)

        return module && draft[money.key] && !grantsView(savedCell(module, editingId.value)) && grantsView(draft[money.key])
    })
})

const saveBlockedReason = computed(() => {
    if (changedModules.value.length === 0) {
        return 'Nothing has changed yet. Change a cell in the highlighted column, then save it here.'
    }

    const unheld = changedModules.value.find((module) => blocked(module, draft[module.key]))

    if (unheld) {
        return `${props.grantableReason} The change to ${unheld.label} is not yours to make.`
    }

    return null
})

const changeSummary = computed(() => {
    const names = changedModules.value.map((module) => module.label)

    return names.length === 0 ? 'No cell has changed.' : `${names.length} ${names.length === 1 ? 'module' : 'modules'} changed: ${names.join(', ')}.`
})

const savePermissions = () => {
    if (saveBlockedReason.value || editingId.value === null) {
        return
    }

    const grid = {}

    for (const module of changedModules.value) {
        const cell = settle(module, draft[module.key])

        grid[module.key] = {
            level: cell.level,
            verbs: cell.level === 'custom' ? [...cell.verbs] : null,
            can_approve: cell.can_approve,
            acts: [...cell.acts],
        }
    }

    permissionsForm
        .transform(() => ({ grid }))
        .post(`/settings/roles/${editingId.value}/permissions`, { preserveScroll: true, onSuccess: () => close() })
}

/* ------------------------------------------------------------------ */
/* rename and delete                                                   */
/* ------------------------------------------------------------------ */

const renameForm = useForm({ label: '' })

const startRename = (role) => {
    if (!props.canEdit || !role.can_rename) {
        return
    }

    renameForm.clearErrors()
    renameForm.label = role.label
    panel.value = { kind: 'rename', roleId: role.id }
}

const saveRename = () => renameForm.post(`/settings/roles/${panel.value.roleId}`, { preserveScroll: true, onSuccess: () => close() })

const deleteForm = useForm({})

const startDelete = (role) => {
    if (!props.canEdit || !role.can_delete) {
        return
    }

    deleteForm.clearErrors()
    panel.value = { kind: 'delete', roleId: role.id }
}

const confirmDelete = () => deleteForm.post(`/settings/roles/${panel.value.roleId}/delete`, { preserveScroll: true, onSuccess: () => close() })

/*
 * RESET TO GEMINISECURE DEFAULT. Drawn on a seeded role's column only while it
 * differs from its default (`resettable`), and confirmed against the server's
 * own list of what it would give back and take away — `defaultDifference()` —
 * before anything is written.
 */
const resetForm = useForm({})

const startReset = (role) => {
    if (!props.canEdit || !role.resettable || !role.can_reset) {
        return
    }

    resetForm.clearErrors()
    panel.value = isOpen('reset', role.id) ? null : { kind: 'reset', roleId: role.id }
}

const confirmReset = () => resetForm.post(`/settings/roles/${panel.value.roleId}/reset`, { preserveScroll: true, onSuccess: () => close() })

/* ------------------------------------------------------------------ */
/* a new role, and a copy                                              */
/* ------------------------------------------------------------------ */

const createForm = useForm({ label: '', scope: props.scopes[0]?.value ?? 'all' })

const openCreate = () => {
    if (!props.canEdit) {
        return
    }

    createForm.clearErrors()
    panel.value = isOpen('create') ? null : { kind: 'create' }
}

/** The label as RoleEditor will store it, to find the new column after the redirect. */
const cleaned = (label) => String(label).replace(/\s+/g, ' ').trim().toLowerCase()

const saveCreate = () => {
    const wanted = cleaned(createForm.label)

    createForm.post('/settings/roles', {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset()

            // Straight into its column: a new role holds nothing until given it.
            const made = props.roles.find((role) => !role.is_system && cleaned(role.label) === wanted)

            if (made) {
                startEdit(made)
            } else {
                close()
            }
        },
    })
}

const scopedChosen = computed(() => createForm.scope === 'assigned_sites')

const copyForm = useForm({ source: null, label: '' })

let copyTimer = null

/*
 * WHAT A COPY WOULD HOLD IS ASKED OF THE SERVER, the way the price panel asks
 * for its impact: `copyOffer` is `RoleEditor::copyableGrid()` for the chosen
 * source, reloaded alone as the source changes. The copy waits until the
 * answer is about the role in the select.
 */
const askCopy = () => {
    clearTimeout(copyTimer)

    if (copyForm.source === null) {
        return
    }

    copyTimer = setTimeout(() => {
        router.get(
            '/settings/roles',
            { copy: copyForm.source },
            { only: ['copyOffer'], preserveState: true, preserveScroll: true, replace: true }
        )
    }, 150)
}

onBeforeUnmount(() => clearTimeout(copyTimer))

watch(
    () => copyForm.source,
    () => askCopy()
)

const openCopy = () => {
    if (!props.canEdit) {
        return
    }

    if (isOpen('copy')) {
        close()

        return
    }

    copyForm.clearErrors()
    copyForm.source = copyForm.source ?? props.roles[0]?.id ?? null
    panel.value = { kind: 'copy' }
    askCopy()
}

const offerFresh = computed(() => props.copyOffer !== null && props.copyOffer.role_id === copyForm.source)

const saveCopy = () => {
    if (!offerFresh.value) {
        return
    }

    copyForm
        .transform((data) => ({ label: data.label }))
        .post(`/settings/roles/${copyForm.source}/copy`, {
            preserveScroll: true,
            onSuccess: () => {
                copyForm.reset()
                close()
            },
        })
}

/** Field errors a form holds — `roles`, the refusal, is printed once, above them. */
const fieldErrors = (form) =>
    Object.entries(form.errors)
        .filter(([key]) => key !== 'roles')
        .map(([, message]) => message)
</script>

<template>
    <Head title="Role access matrix" />

    <GeminiConsole title="Platform settings">
        <template #actions>
            <!--
              The board draws "New custom role" here as a <div>. It is a real
              button, live for a viewer who may change roles and inert with the
              reason for anyone else. "Copy from…" is authored beside it: a copy
              is how the Director starts from a seeded role without changing it.
            -->
            <button
                type="button"
                class="btn-outline-sm"
                :disabled="!canEdit"
                :title="canEdit ? 'Copy an existing role\'s permissions into a new one of your own.' : editBlockedReason"
                @click="openCopy"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <rect x="9" y="9" width="11" height="11" rx="2" stroke="currentColor" stroke-width="2" />
                    <path d="M5 15V5a2 2 0 0 1 2-2h8" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Copy from…</span>
            </button>
            <button
                type="button"
                class="btn-outline-sm"
                :disabled="!canEdit"
                :title="canEdit ? 'Make a role of your own. It starts with no access to anything and nobody holding it.' : editBlockedReason"
                @click="openCreate"
            >
                <svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
                <span>New custom role</span>
            </button>
        </template>

        <SettingsTabs :tabs="tabs" />

        <!-- A standing fact about this viewer, so a bar that stays. -->
        <p v-if="!canEdit && editBlockedReason" class="rm-bar">{{ editBlockedReason }} This matrix is yours to read.</p>

        <!-- AUTHORED. The board draws a matrix nobody is changing, so it has none of the panels below. -->

        <form v-if="isOpen('create')" class="rm-panel" @submit.prevent="saveCreate">
            <div class="rm-head">A new role of the Gemini Console's own</div>
            <p class="rm-note">
                It starts with no access to anything and nobody holding it. Its column opens as soon as it is made, for
                you to give it what it needs; somebody is moved into it under Platform admins.
            </p>
            <div class="rm-fields">
                <div class="rm-field">
                    <label for="rm-create-label">Name</label>
                    <input id="rm-create-label" v-model="createForm.label" type="text" :maxlength="labelMax" required />
                </div>
                <div class="rm-field">
                    <label for="rm-create-scope">Reaches</label>
                    <select id="rm-create-scope" v-model="createForm.scope">
                        <option v-for="scope in scopes" :key="scope.value" :value="scope.value">{{ scope.label }}</option>
                    </select>
                </div>
            </div>
            <p v-if="scopedChosen" class="rm-note">
                Somebody holding a role scoped to assigned sites sees nothing until sites are assigned to them under
                Platform admins. The reach is set now and does not change later.
            </p>
            <p v-if="refusal" class="rm-error">{{ refusal }}</p>
            <p v-for="(message, i) in fieldErrors(createForm)" :key="'ce-' + i" class="rm-error">{{ message }}</p>
            <div class="rm-actions">
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="createForm.processing || createForm.label.trim() === ''"
                    :title="createForm.label.trim() === '' ? 'Give it a name people will recognise first.' : 'Make the role.'"
                >
                    <span>{{ createForm.processing ? 'Making…' : 'Make the role' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="close">Cancel</button>
            </div>
        </form>

        <form v-if="isOpen('copy')" class="rm-panel" @submit.prevent="saveCopy">
            <div class="rm-head">A new role, starting from another's permissions</div>
            <p class="rm-note">The role you copy from is not changed. The copy is yours to rename, change and delete.</p>
            <div class="rm-fields">
                <div class="rm-field">
                    <label for="rm-copy-source">Copy from</label>
                    <select id="rm-copy-source" v-model="copyForm.source">
                        <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.label }}</option>
                    </select>
                </div>
                <div class="rm-field">
                    <label for="rm-copy-label">Name of the copy</label>
                    <input
                        id="rm-copy-label"
                        v-model="copyForm.label"
                        type="text"
                        :maxlength="labelMax"
                        :placeholder="offerFresh ? copyOffer.default_label : ''"
                    />
                </div>
            </div>
            <div class="rm-read" aria-live="polite">
                <template v-if="offerFresh">
                    <p v-for="(line, i) in copyOffer.lines" :key="i">{{ line }}</p>
                </template>
                <p v-else>Working out what the copy would hold…</p>
            </div>
            <p v-if="refusal" class="rm-error">{{ refusal }}</p>
            <p v-for="(message, i) in fieldErrors(copyForm)" :key="'cp-' + i" class="rm-error">{{ message }}</p>
            <div class="rm-actions">
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="copyForm.processing || !offerFresh"
                    :title="offerFresh ? 'Make the copy as described above.' : 'Still working out what the copy would hold. It can be made once that is shown.'"
                >
                    <span>{{ copyForm.processing ? 'Copying…' : 'Make the copy' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="close">Cancel</button>
            </div>
        </form>

        <form v-if="isOpen('rename') && panelRole" class="rm-panel" @submit.prevent="saveRename">
            <div class="rm-head">Rename {{ panelRole.label }}</div>
            <p class="rm-note">
                Only the name people see changes. Its machine name, {{ panelRole.name }}, never does, so everyone who holds
                it keeps it and what it may do stays exactly as it is.
            </p>
            <div class="rm-fields">
                <div class="rm-field">
                    <label for="rm-rename-label">New name</label>
                    <input id="rm-rename-label" v-model="renameForm.label" type="text" :maxlength="labelMax" required />
                </div>
            </div>
            <p v-if="refusal" class="rm-error">{{ refusal }}</p>
            <p v-for="(message, i) in fieldErrors(renameForm)" :key="'rn-' + i" class="rm-error">{{ message }}</p>
            <div class="rm-actions">
                <button type="submit" class="btn-primary-sm" :disabled="renameForm.processing"><span>Rename</span></button>
                <button type="button" class="text-link-sm" @click="close">Cancel</button>
            </div>
        </form>

        <form v-if="isOpen('delete') && panelRole" class="rm-panel" @submit.prevent="confirmDelete">
            <div class="rm-head">Delete {{ panelRole.label }}?</div>
            <p class="rm-note">
                Nobody holds it and no invitation names it. Deleting it cannot be undone; the audit log keeps what it held
                and who deleted it.
            </p>
            <p v-if="refusal" class="rm-error">{{ refusal }}</p>
            <div class="rm-actions">
                <button type="submit" class="btn-red-sm" :disabled="deleteForm.processing"><span>Delete the role</span></button>
                <button type="button" class="text-link-sm" @click="close">Cancel</button>
            </div>
        </form>

        <form v-if="isOpen('reset') && panelRole && panelRole.reset_difference" class="rm-panel" @submit.prevent="confirmReset">
            <div class="rm-head">Reset {{ panelRole.label }} to GeminiSecure default?</div>
            <p class="rm-note">
                {{ panelRole.edited }} Resetting puts back the permissions it shipped with, and from then on it follows
                platform updates again. {{ panelRole.holders }} {{ panelRole.holders === 1 ? 'person holds' : 'people hold' }}
                it; each has exactly the default from their next page. The audit log keeps what it held before.
            </p>
            <div class="rm-read" aria-live="polite">
                <template v-if="panelRole.reset_difference.add.length || panelRole.reset_difference.remove.length">
                    <p v-if="panelRole.reset_difference.add.length">Gives back:</p>
                    <ul v-if="panelRole.reset_difference.add.length" class="rm-diff">
                        <li v-for="permission in panelRole.reset_difference.add" :key="'a-' + permission">+ {{ permission }}</li>
                    </ul>
                    <p v-if="panelRole.reset_difference.remove.length">Takes away:</p>
                    <ul v-if="panelRole.reset_difference.remove.length" class="rm-diff">
                        <li v-for="permission in panelRole.reset_difference.remove" :key="'r-' + permission">− {{ permission }}</li>
                    </ul>
                </template>
                <p v-else>No permission is added or taken away: only how its cells are recorded differs from the default.</p>
            </div>
            <p v-if="refusal" class="rm-error">{{ refusal }}</p>
            <div class="rm-actions">
                <button type="submit" class="btn-primary-sm" :disabled="resetForm.processing">
                    <span>{{ resetForm.processing ? 'Resetting…' : 'Reset to GeminiSecure default' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="close">Cancel</button>
            </div>
        </form>

        <form v-if="isOpen('edit') && panelRole" class="rm-panel" @submit.prevent="savePermissions">
            <div class="rm-head">
                Editing {{ panelRole.label }} — {{ panelRole.holders }} {{ panelRole.holders === 1 ? 'person holds' : 'people hold' }} it
            </div>
            <p class="rm-note">
                Change its cells in the highlighted column. A saved change applies from the next page anybody holding it
                opens. {{ grantableReason }} What your role does not hold is shown, and cannot be ticked.
            </p>
            <p v-if="panelRole.note" class="rm-note">{{ panelRole.note }}</p>
            <p v-for="money in moneyWarnings" :key="money.key" class="rm-warn">{{ panelRole.label }}: {{ money.warning }}</p>
            <p class="rm-read">{{ changeSummary }}</p>
            <p v-if="refusal" class="rm-error">{{ refusal }}</p>
            <div class="rm-actions">
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="permissionsForm.processing || Boolean(saveBlockedReason)"
                    :title="saveBlockedReason || `Save these changes to ${panelRole.label}.`"
                >
                    <span>{{ permissionsForm.processing ? 'Saving…' : 'Save permissions' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="close">Cancel</button>
                <span v-if="saveBlockedReason" class="rm-inline-note">{{ saveBlockedReason }}</span>
            </div>
        </form>

        <!--
          A wrapper that only matters once the Director has made enough roles
          for the columns to outgrow the content width. At the board's six it
          changes nothing.
        -->
        <div class="rm-scroll">
            <table class="matrix-table">
                <thead>
                    <tr>
                        <th>Module</th>
                        <th v-for="role in roles" :key="role.id" :class="{ 'rm-editing': editingId === role.id }">
                            <div class="role-head-name">
                                <template v-for="(line, i) in nameLines(role.label)" :key="i"
                                    ><br v-if="i" />{{ line }}</template
                                >
                            </div>
                            <div class="role-head-scope">{{ role.scope }}</div>

                            <!--
                              AUTHORED (M10 §4). A seeded role that differs from its
                              default says who changed it and when, to everyone; the
                              way back is below, for a viewer who may change roles.
                            -->
                            <div v-if="role.resettable" class="rm-edited">{{ role.edited }}</div>

                            <!-- AUTHORED (M10 §4): for a viewer who may change roles, and nobody else. -->
                            <template v-if="canEdit">
                                <div class="rm-acts">
                                    <button
                                        type="button"
                                        class="text-link-sm"
                                        :disabled="!role.can_edit_permissions"
                                        :title="role.can_edit_permissions ? `Change what ${role.label} may do, module by module.` : role.permissions_refusal"
                                        @click="editingId === role.id ? close() : startEdit(role)"
                                    >
                                        {{ editingId === role.id ? 'Close' : 'Edit' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="text-link-sm"
                                        :disabled="!role.can_rename"
                                        :title="role.can_rename ? `Rename ${role.label}.` : role.rename_refusal"
                                        @click="startRename(role)"
                                    >
                                        Rename
                                    </button>
                                    <button
                                        type="button"
                                        class="text-link-sm rm-danger"
                                        :disabled="!role.can_delete"
                                        :title="role.can_delete ? `Delete ${role.label}.` : role.delete_refusal"
                                        @click="startDelete(role)"
                                    >
                                        Delete
                                    </button>
                                </div>
                                <div v-if="role.resettable" class="rm-acts">
                                    <button
                                        type="button"
                                        class="text-link-sm"
                                        :disabled="!role.can_reset"
                                        :title="role.can_reset ? `Put back the permissions ${role.label} shipped with. You see what changes before anything is written.` : role.reset_refusal"
                                        @click="startReset(role)"
                                    >
                                        Reset to GeminiSecure default
                                    </button>
                                </div>
                                <details v-if="whyNot(role).length" class="rm-why">
                                    <summary>Why not?</summary>
                                    <p v-for="(line, i) in whyNot(role)" :key="i">{{ line }}</p>
                                </details>
                            </template>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="module in modules" :key="module.id">
                        <td>
                            <div class="mod-name">{{ module.label }}</div>
                        </td>
                        <td v-for="cell in module.cells" :key="cell.role_id" :class="{ 'rm-editing': editingId === cell.role_id }">
                            <template v-if="editingId !== cell.role_id || !draft[module.key]">
                                <button
                                    v-if="canEditColumn(cell.role_id)"
                                    type="button"
                                    class="perm-pill rm-pill-button"
                                    :class="cell.variant"
                                    :title="`${cell.label}. Click to change what ${roleById(cell.role_id)?.label} may do on ${module.label}.`"
                                    @click="editFromCell(cell, module)"
                                >
                                    {{ cell.label }}
                                </button>
                                <div v-else class="perm-pill" :class="cell.variant">{{ cell.label }}</div>
                            </template>

                            <div v-else class="rm-cell">
                                <div class="perm-pill" :class="cell.variant" :title="`Saved now: ${cell.label}`">{{ cell.label }}</div>

                                <p v-if="frozenReason(module)" class="rm-cell-note">{{ frozenReason(module) }}</p>

                                <template v-else>
                                    <select
                                        :id="`rm-level-${module.key}`"
                                        class="rm-level"
                                        :aria-label="`${module.label}: what ${panelRole?.label} may do`"
                                        :value="draft[module.key].level"
                                        @change="setLevel(module, $event.target.value)"
                                    >
                                        <option
                                            v-for="level in levels"
                                            :key="level.key"
                                            :value="level.key"
                                            :disabled="optionBlocked(module, level.key)"
                                        >
                                            {{ levelLabel(level) }}{{ optionBlocked(module, level.key) ? ' — not yours to give' : '' }}
                                        </option>
                                    </select>

                                    <!--
                                      ONE BOX PER APPROVAL where the module's approval
                                      is several named acts (M11 B3); the Approver tag
                                      is all of them. No Gemini module splits its
                                      approval today, so this is the single box.
                                    -->
                                    <div
                                        v-if="actsUnder(module, 'approve').length > 0"
                                        class="rm-verbs"
                                        role="group"
                                        :aria-label="`${module.label}: approvals, one by one`"
                                    >
                                        <label v-for="act in actsUnder(module, 'approve')" :key="act.key" class="rm-check" :title="actTitle(module, act)">
                                            <input
                                                type="checkbox"
                                                :checked="hasAct(draft[module.key], act)"
                                                :disabled="actBlocked(module, act) !== null"
                                                @change="toggleAct(module, act)"
                                            />
                                            <span>{{ act.label }}</span>
                                        </label>
                                    </div>
                                    <label v-else class="rm-check" :title="approveTitle(module)">
                                        <input
                                            type="checkbox"
                                            :checked="draft[module.key].can_approve"
                                            :disabled="approveBlocked(module) !== null"
                                            @change="toggleApprove(module)"
                                        />
                                        <span>Approver<template v-if="approveNote(module)"> — {{ approveNote(module) }}</template></span>
                                    </label>

                                    <!--
                                      The actions one by one, at Custom — and a verb
                                      split into named acts (Platform settings'
                                      configure is three, M11 B3) as its acts.
                                    -->
                                    <div
                                        v-if="draft[module.key].level === 'custom'"
                                        class="rm-verbs"
                                        role="group"
                                        :aria-label="`${module.label}: actions, one by one`"
                                    >
                                        <template v-for="item in customItems(module)" :key="item.key">
                                            <label
                                                v-if="item.kind === 'verb'"
                                                class="rm-check"
                                                :title="verbBlocked(module, item.verb.key) ? grantableReason : `${item.verb.label} on ${module.label}.`"
                                            >
                                                <input
                                                    type="checkbox"
                                                    :checked="draft[module.key].verbs.includes(item.verb.key)"
                                                    :disabled="verbBlocked(module, item.verb.key)"
                                                    @change="toggleVerb(module, item.verb.key)"
                                                />
                                                <span>{{ item.verb.label }}</span>
                                            </label>
                                            <label v-else class="rm-check" :title="actTitle(module, item.act)">
                                                <input
                                                    type="checkbox"
                                                    :checked="hasAct(draft[module.key], item.act)"
                                                    :disabled="actBlocked(module, item.act) !== null"
                                                    @change="toggleAct(module, item.act)"
                                                />
                                                <span>{{ item.act.label }}</span>
                                            </label>
                                        </template>
                                    </div>

                                    <p v-if="unheldNote(module)" class="rm-cell-note" :title="grantableReason">{{ unheldNote(module) }}</p>
                                </template>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!--
          AUTHORED (M10 §4). What the board says about each seeded role, in its
          own words — among them that a platform update keeps what the Director
          changed, and that the reset is the way back.
        -->
        <div v-if="notedRoles.length" class="rm-notes">
            <div class="rm-head">About the seeded roles</div>
            <p v-for="role in notedRoles" :key="role.id" class="rm-note">
                <b>{{ role.label }}.</b> {{ role.note }}
            </p>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The authored CSS above the line takes defaults back off.
 *
 * The board draws all seven tabs as <div>. This renders the one with a route
 * as a Link and the six without one as disabled buttons, and the browser
 * brings its own chrome to both element types — an underline on the anchor,
 * and a border, background and Arial font on the button. These rules remove
 * exactly that, so the board's own .subnav-item rule is what is seen. The
 * board's reset already zeroes padding and margin on every element, and
 * .subnav-item sets the font size, weight and colour, so nothing else needs
 * restating.
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

/*
 * The board's buttons as <button>s. The board's own rules set their size,
 * border, ground and radius; only what the UA adds is taken off.
 */
button.btn-outline-sm,
button.btn-primary-sm,
button.btn-red-sm {
    appearance: none;
    font-family: inherit;
    cursor: pointer;
}

button.btn-primary-sm,
button.btn-red-sm {
    border: 0;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE (M10 §4). The board draws a matrix nobody is
 * changing. Kept to the tokens the Gemini boards define; no focus rule, because
 * the one focus treatment is app.css's.
 */
.rm-error,
.rm-bar {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

/* A refusal, inside the panel it refused: amber, as every ordinary refusal is (red is an open panic's alone). */
.rm-error {
    background: var(--amber-100);
    color: var(--amber-700);
}

.rm-bar {
    background: var(--navy-100);
    color: var(--navy-800);
}

.rm-panel .rm-error {
    margin: 0;
}

.rm-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 17px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.rm-head {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.5;
}

.rm-note,
.rm-inline-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.55;
    margin: 0;
    max-width: 860px;
}

.rm-warn {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.55;
    margin: 0;
    border-radius: 10px;
    padding: 9px 13px;
    background: var(--amber-100);
    color: var(--amber-700);
}

.rm-read {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.55;
    margin: 0;
    background: var(--navy-100);
    border-radius: 10px;
    padding: 10px 14px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.rm-read p {
    margin: 0;
}

.rm-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 320px));
    gap: 11px;
}

.rm-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.rm-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.rm-field input,
.rm-field select,
.rm-level {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.rm-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
}

.rm-scroll {
    overflow-x: auto;
}

/* The column under edit: wide enough for its controls, and marked. */
.matrix-table th.rm-editing,
.matrix-table td.rm-editing {
    min-width: 190px;
    background: var(--navy-50);
}

.rm-acts {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 4px 9px;
    margin-top: 6px;
}

.rm-acts .text-link-sm {
    font-size: 10px;
}

.rm-acts .rm-danger {
    color: var(--red-700);
}

.rm-acts .text-link-sm[disabled] {
    color: var(--slate-500);
}

.rm-why {
    margin-top: 4px;
    font-size: 9.5px;
    font-weight: 500;
    color: var(--slate-600);
    text-align: left;
    max-width: 200px;
    margin-left: auto;
    margin-right: auto;
}

.rm-why summary {
    cursor: pointer;
    text-align: center;
    color: var(--navy-600);
    font-weight: 700;
}

.rm-why p {
    margin: 4px 0 0;
    line-height: 1.45;
}

.rm-cell {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 6px;
    text-align: left;
}

.rm-cell .perm-pill {
    align-self: center;
}

.rm-level {
    height: 30px;
    font-size: 11.5px;
    padding: 0 6px;
    width: 100%;
}

.rm-check {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 10.5px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.35;
}

.rm-check input[disabled] + span {
    color: var(--slate-500);
}

.rm-verbs {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 3px 8px;
    padding: 6px 7px;
    border: 1px solid var(--navy-100);
    border-radius: 8px;
}

.rm-cell-note {
    font-size: 9.5px;
    color: var(--slate-600);
    line-height: 1.45;
    margin: 0;
}

.rm-edited {
    font-size: 9px;
    font-weight: 600;
    color: var(--amber-700);
    margin-top: 4px;
    line-height: 1.4;
}

.rm-diff {
    margin: 0;
    padding: 0;
    list-style: none;
    columns: 3 180px;
    column-gap: 18px;
    font-weight: 500;
}

.rm-notes {
    margin-top: 16px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

/* A pill that opens its column: the pill as drawn, and a ring on hover that says it can be pressed. */
button.rm-pill-button {
    appearance: none;
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.rm-pill-button:hover {
    box-shadow: 0 0 0 2px var(--navy-200);
}
</style>
