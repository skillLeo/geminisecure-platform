<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'
import { toast } from '../../../lib/toasts.js'

/**
 * ONE ROLE'S PERMISSIONS, ON A PAGE OF ITS OWN (Hassam, 12 October 2026; the client found it on /settings/roles).
 *
 * Edit permissions opened this table in a modal: a level and a row of boxes per module, an Approver column and a
 * column saying why a control is off, inside the modal's own scrollbar, where that last column wrapped to a word a line
 * and a phone could not have used it at all. "A modal is for one short form, a confirmation or a quick choice"; content
 * that needs its own scrollbar, has more than about eight fields, or is a table read and compared across gets a page.
 * So: the role's name as the heading and who holds it; the money warning full width at the top, where it can be read;
 * the table with real column widths and its header row kept in view; a running count of what has changed; Save and
 * Cancel always in view at the foot of the window; and below 760 px every module a card of its own, nothing sideways.
 *
 * NO PERMISSION IS SAVED WITHOUT ITS REVIEW, AND NOTHING HERE DECIDES ONE (the rule that cannot bend: sixty one cells
 * wait on the committee's ruling, and one silent change would be unrecoverable). The foot's button saves nothing: it
 * opens the review, which names every single change in plain words ("Treasurer gains approve on Payments",
 * "Treasurer loses view on Accounting"), and only the review's own button posts, the grid exactly as listed. Leaving
 * with changes unsaved asks first. What each cell may be is still `RoleEditor`'s: this page offers only what the server
 * would accept, every greyed control says why on the screen, and the server refuses whatever it would refuse anyway.
 *
 * THE LOGIC IS THE ROLES PAGE'S EDITOR, MOVED WHOLE (named acts, the guard rails mirrored, the position rule). See the
 * note at the top of Roles.vue for why each piece is what it is.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** This role's row from `RoleEditor::board()`: its cells, who may do what to it, and how many hold it. */
    role: { type: Object, required: true },
    /** Who holds the role on this estate now, by name. */
    holderNames: { type: Array, required: true },
    /** `RoleEditor::board()` less its roles: verbs, levels, modules with their named acts, and what this viewer holds. */
    editor: { type: Object, required: true },
    invariant_note: { type: String, required: true },
    rolesHref: { type: String, required: true },
    saveHref: { type: String, required: true },
})

const page = usePage()

useWireframe('community-admin-06-settings-estate-profile-users-and-roles')

// A form: re-reading it under somebody's changes would lose them (M18H §7.2).
useLiveKind('form')

const state = useScreenState({ rows: () => props.editor.modules.length })

const retry = () => router.reload()

/* ------------------------------------------------------------------ */
/* reading the board                                                  */
/* ------------------------------------------------------------------ */

const moduleLabel = (key) => props.editor.modules.find((module) => module.key === key)?.label ?? key

const levelVerbs = (key) => props.editor.levels.find((level) => level.key === key)?.verbs ?? []

/** The six verbs a level or a custom list carries; `approve` is the Approver tag's. */
const plainVerbs = computed(() => props.editor.verbs.map((verb) => verb.key).filter((key) => key !== 'approve'))

/** "None" in a select, where the matrix's pill prints its lone dash. */
const levelWord = (level) => (level.key === 'none' ? 'None' : level.label)

/** Whether the viewer holds this verb or named act on this module, and so may give it or take it away. */
const holds = (moduleKey, verb) => props.editor.grantable?.[moduleKey]?.[verb] === true

const actsUnder = (moduleKey, verb) =>
    (props.editor.modules.find((module) => module.key === moduleKey)?.acts ?? []).filter((act) => act.verb === verb)

const actKeysOf = (moduleKey) => (props.editor.modules.find((module) => module.key === moduleKey)?.acts ?? []).map((act) => act.key)

const savedCell = (moduleKey) =>
    props.role.cells?.[moduleKey] ?? { level: 'none', verbs: [], can_approve: false, acts: [], locked: false, minimum: false }

/** A cell nobody may change from here: Ruling 1's, or a structural minimum. */
const isLocked = (moduleKey) => savedCell(moduleKey).locked === true || savedCell(moduleKey).minimum === true

const lockedReason = (moduleKey) => savedCell(moduleKey).locked_reason ?? props.invariant_note

/** What a cell compiles to, as `RoleModuleAccess::permissionNames()` compiles it on the server. */
const compiled = (moduleKey, cell) => {
    const out = new Set()
    const verbs = cell.level === 'custom' ? (cell.verbs ?? []) : levelVerbs(cell.level)

    const expand = (verb) => {
        const under = actsUnder(moduleKey, verb)

        if (under.length === 0) {
            out.add(verb)
        } else {
            under.forEach((act) => out.add(act.key))
        }
    }

    verbs.filter((verb) => verb !== 'approve').forEach(expand)

    if (cell.can_approve) {
        expand('approve')
    }

    ;(cell.acts ?? []).forEach((key) => out.add(key))

    return [...out].sort()
}

/* ------------------------------------------------------------------ */
/* the draft                                                          */
/* ------------------------------------------------------------------ */

/** module key → { level, verbs, can_approve, acts }: what the review would post. */
const draft = reactive({})

const putBack = () => {
    for (const module of props.editor.modules) {
        const cell = props.role.cells?.[module.key] ?? { level: 'none', verbs: [], can_approve: false, acts: [] }

        draft[module.key] = {
            level: cell.level,
            verbs: (cell.verbs ?? []).filter((verb) => verb !== 'approve'),
            can_approve: cell.can_approve === true,
            acts: [...(cell.acts ?? [])],
        }
    }
}

putBack()

const draftVerbs = (moduleKey) => {
    const cell = draft[moduleKey]

    if (!cell) {
        return []
    }

    return cell.level === 'custom' ? cell.verbs : levelVerbs(cell.level)
}

const draftGrantsView = (moduleKey) => draftVerbs(moduleKey).includes('view')

/** GUARD RAIL 3, MIRRORED: what a candidate would give or take away that the viewer does not hold. */
const unheldChange = (moduleKey, candidate) => {
    const before = compiled(moduleKey, savedCell(moduleKey))
    const after = compiled(moduleKey, candidate)
    const changed = [...before.filter((key) => !after.includes(key)), ...after.filter((key) => !before.includes(key))]

    return changed.filter((key) => !holds(moduleKey, key))
}

const hasAct = (moduleKey, act) => {
    const cell = draft[moduleKey]

    if (!cell) {
        return false
    }

    const parent = act.verb === 'approve' ? cell.can_approve : draftVerbs(moduleKey).includes(act.verb)

    return parent || (cell.acts ?? []).includes(act.key)
}

const setParent = (moduleKey, verb, on) => {
    const cell = draft[moduleKey]

    if (verb === 'approve') {
        cell.can_approve = on

        return
    }

    const verbs = new Set(draftVerbs(moduleKey))

    if (on) {
        verbs.add(verb)
    } else {
        verbs.delete(verb)
    }

    cell.level = 'custom'
    cell.verbs = plainVerbs.value.filter((key) => verbs.has(key))
}

/** Tick or untick one act, keeping the canonical form `RoleEditor::normalise()` stores. */
const toggleAct = (moduleKey, act, on) => {
    const cell = draft[moduleKey]
    const siblings = actsUnder(moduleKey, act.verb).map((each) => each.key)
    const parentOn = act.verb === 'approve' ? cell.can_approve : draftVerbs(moduleKey).includes(act.verb)
    const picked = new Set(cell.acts ?? [])

    if (parentOn) {
        if (on) {
            return
        }

        setParent(moduleKey, act.verb, false)
        siblings.filter((key) => key !== act.key).forEach((key) => picked.add(key))
    } else if (on) {
        picked.add(act.key)

        if (siblings.every((key) => picked.has(key))) {
            siblings.forEach((key) => picked.delete(key))
            setParent(moduleKey, act.verb, true)
        }
    } else {
        picked.delete(act.key)
    }

    cell.acts = actKeysOf(moduleKey).filter((key) => picked.has(key))
}

/** Why an act's box is off limits here, or null. */
const actBlocked = (moduleKey, act) => {
    if (isLocked(moduleKey)) {
        return lockedReason(moduleKey)
    }

    if (!holds(moduleKey, act.key)) {
        return props.editor.grantable_reason
    }

    if (!draftGrantsView(moduleKey)) {
        return `Give ${moduleLabel(moduleKey)} at least view before giving it ${act.label}. Nobody can do what they cannot open.`
    }

    return null
}

/** Drop every act the viewer holds once the cell no longer opens the module. */
const dropHeldActs = (moduleKey) => {
    const cell = draft[moduleKey]

    cell.acts = (cell.acts ?? []).filter((key) => !holds(moduleKey, key))

    if (cell.level === 'custom') {
        cell.verbs = (cell.verbs ?? []).filter(
            (verb) => actsUnder(moduleKey, verb).length === 0 || !actsUnder(moduleKey, verb).every((act) => holds(moduleKey, act.key))
        )
    }
}

const holdsUnheldSplitVerb = (moduleKey) =>
    draftVerbs(moduleKey).some((verb) => actsUnder(moduleKey, verb).some((act) => !holds(moduleKey, act.key)))

/** At Custom: each verb, or, for a verb split into acts, each of its acts. Also the order a module's grants are read in. */
const customItems = (moduleKey) =>
    props.editor.verbs.flatMap((verb) => {
        const under = actsUnder(moduleKey, verb.key)

        return under.length === 0 ? [{ kind: 'verb', key: verb.key, verb }] : under.map((act) => ({ kind: 'act', key: act.key, act }))
    })

/** May this level be chosen for this cell? The server's rule, asked before the press (see Roles.vue). */
const levelAllowed = (moduleKey, level) => {
    if (isLocked(moduleKey)) {
        return level.key === draft[moduleKey]?.level
    }

    if (level.key === 'custom') {
        return true
    }

    const cell = draft[moduleKey]
    const opens = level.verbs.includes('view')

    return (
        unheldChange(moduleKey, {
            level: level.key,
            verbs: level.verbs,
            can_approve: opens ? cell.can_approve : false,
            acts: opens ? cell.acts : [],
        }).length === 0
    )
}

const setLevel = (moduleKey, key) => {
    const cell = draft[moduleKey]

    // Custom starts from what the cell grants now, so switching to it moves nothing.
    if (key === 'custom' && cell.level !== 'custom') {
        cell.verbs = [...levelVerbs(cell.level)]
    }

    cell.level = key

    if (!draftGrantsView(moduleKey)) {
        if (holds(moduleKey, 'approve')) {
            cell.can_approve = false
        }

        dropHeldActs(moduleKey)
    }
}

const approveLocked = (moduleKey) => isLocked(moduleKey) || !holds(moduleKey, 'approve') || !draftGrantsView(moduleKey)

const verbLocked = (moduleKey, verb) => {
    if (verb === 'approve') {
        return approveLocked(moduleKey)
    }

    if (isLocked(moduleKey) || !holds(moduleKey, verb)) {
        return true
    }

    // Unticking view would strand an Approver tag, or a named act, this viewer cannot take away.
    return (
        verb === 'view' &&
        ((draft[moduleKey].can_approve && !holds(moduleKey, 'approve')) ||
            (draft[moduleKey].acts ?? []).some((key) => !holds(moduleKey, key)) ||
            holdsUnheldSplitVerb(moduleKey))
    )
}

const hasVerb = (moduleKey, verb) => (verb === 'approve' ? draft[moduleKey].can_approve : draftVerbs(moduleKey).includes(verb))

const toggleVerb = (moduleKey, verb, on) => {
    const cell = draft[moduleKey]

    if (verb === 'approve') {
        cell.can_approve = on

        return
    }

    const picked = new Set(cell.verbs)

    if (on) {
        picked.add(verb)
    } else {
        picked.delete(verb)
    }

    cell.verbs = plainVerbs.value.filter((key) => picked.has(key))

    if (!draftGrantsView(moduleKey)) {
        if (holds(moduleKey, 'approve')) {
            cell.can_approve = false
        }

        dropHeldActs(moduleKey)
    }
}

/** Why the Approver box is off limits here, or what it does. */
const approveTitle = (moduleKey) => {
    if (isLocked(moduleKey)) {
        return lockedReason(moduleKey)
    }

    if (!holds(moduleKey, 'approve')) {
        return props.editor.grantable_reason
    }

    if (!draftGrantsView(moduleKey)) {
        return `Give ${moduleLabel(moduleKey)} at least view before tagging it Approver. Nobody can approve what they cannot open.`
    }

    return `Approver on ${moduleLabel(moduleKey)}: may make this module's irreversible acts final.`
}

/** The verbs and named acts this viewer cannot give or take away on this module, by name. */
const blockedVerbs = (moduleKey) =>
    props.editor.verbs.flatMap((verb) => {
        const under = actsUnder(moduleKey, verb.key)

        if (under.length === 0) {
            return holds(moduleKey, verb.key) ? [] : [verb.key]
        }

        return under.filter((act) => !holds(moduleKey, act.key)).map((act) => act.label)
    })

const anyBlocked = computed(() => props.editor.modules.some((module) => blockedVerbs(module.key).length > 0))

/** One module's cell exactly as the save posts it. */
const postedCell = (key) => {
    const cell = draft[key]

    return {
        level: cell.level,
        verbs: cell.level === 'custom' ? cell.verbs : null,
        can_approve: cell.can_approve && draftGrantsView(key),
        acts: draftGrantsView(key) ? (cell.acts ?? []) : [],
    }
}

/** The modules whose grant the draft changes: the only ones the save posts. */
const changedModules = computed(() =>
    props.editor.modules
        .map((module) => module.key)
        .filter((key) => draft[key] && compiled(key, postedCell(key)).join(',') !== compiled(key, savedCell(key)).join(',')),
)

/** The money modules the draft opens alongside Reports: the position rule, read from the server's list. */
const positionModules = computed(() => {
    const rule = props.editor.position_rule

    if (!rule || !draftGrantsView(rule.reports)) {
        return []
    }

    return rule.money.filter((key) => draftGrantsView(key)).map(moduleLabel)
})

/* ------------------------------------------------------------------ */
/* every change, in words                                             */
/* ------------------------------------------------------------------ */

/** A grant in words: a verb as the word itself ("approve"), a named act by its name, in quotes. */
const grantWords = (moduleKey, key) => {
    const act = (props.editor.modules.find((module) => module.key === moduleKey)?.acts ?? []).find((each) => each.key === key)

    return act ? `“${act.label}”` : key
}

/**
 * EVERY SINGLE CHANGE THE SAVE MAKES, ONE SENTENCE EACH, in the order the module's grants are read: "Treasurer gains
 * approve on Payments", "Treasurer loses view on Accounting". Counted from exactly what is posted against exactly what
 * is saved, so the review cannot say less than the save does.
 */
const changes = computed(() =>
    changedModules.value.flatMap((key) => {
        const before = compiled(key, savedCell(key))
        const after = compiled(key, postedCell(key))
        const order = customItems(key).map((item) => item.key)
        const rank = (grant) => (order.includes(grant) ? order.indexOf(grant) : order.length)
        const inOrder = (a, b) => rank(a) - rank(b)
        const module = moduleLabel(key)

        return [
            ...after
                .filter((grant) => !before.includes(grant))
                .sort(inOrder)
                .map((grant) => ({ key: `${key}+${grant}`, text: `${props.role.label} gains ${grantWords(key, grant)} on ${module}` })),
            ...before
                .filter((grant) => !after.includes(grant))
                .sort(inOrder)
                .map((grant) => ({ key: `${key}-${grant}`, text: `${props.role.label} loses ${grantWords(key, grant)} on ${module}` })),
        ]
    }),
)

const changeCount = computed(() => changes.value.length)

/** The running count, at the foot of the window, where the modal used to say only that nothing had changed. */
const countWords = computed(() => `${changeCount.value} ${changeCount.value === 1 ? 'change' : 'changes'} made`)

/** Who holds the role, by name, under the heading. */
const holdersWords = computed(() => {
    const names = props.holderNames

    if (names.length === 0) {
        return `Nobody here holds ${props.role.label} yet, so a saved change reaches nobody until somebody is given it.`
    }

    const shown = names.slice(0, 8)
    const more = names.length - shown.length
    const list =
        more > 0 ? `${shown.join(', ')} and ${more} more` : shown.length === 1 ? shown[0] : `${shown.slice(0, -1).join(', ')} and ${shown.at(-1)}`

    return `Held by ${list}. A saved change reaches ${names.length === 1 ? 'them' : 'every one of them'} from the next page they open.`
})

/* ------------------------------------------------------------------ */
/* review, save, and leaving                                          */
/* ------------------------------------------------------------------ */

/** The page's second step: the list of what the save changes, before anything is saved. */
const reviewing = ref(false)

const review = () => {
    if (changeCount.value === 0) {
        return
    }

    reviewing.value = true

    if (typeof window !== 'undefined' && typeof window.scrollTo === 'function') {
        window.scrollTo({ top: 0 })
    }
}

const backToTable = () => {
    reviewing.value = false
}

const saveForm = useForm({})

/** Set once the person has chosen to leave, or has saved: no question stands between them and the next page. */
let mayLeave = false

/** The review's button: the one press that saves, the grid exactly as listed. */
const save = () => {
    if (!reviewing.value || changeCount.value === 0 || saveForm.processing) {
        return
    }

    const grid = {}

    for (const key of changedModules.value) {
        grid[key] = postedCell(key)
    }

    saveForm.transform(() => ({ grid })).post(props.saveHref, {
        preserveScroll: true,
        onBefore: () => {
            mayLeave = true
        },
        onError: () => {
            mayLeave = false
        },
    })
}

/*
 * A REFUSED SAVE IS A TOAST, TOP RIGHT, in the server's words (D-235: a refusal on a page with no dialog open), and the
 * review stays as it was, so nothing drafted is lost.
 */
watch(
    () => page.props.errors?.role,
    (text) => {
        if (text) {
            toast(text, { tone: 'warning' })
        }
    },
    { immediate: true },
)

/*
 * NOTHING CHANGED HERE IS THROWN AWAY WITHOUT ASKING (M95 §1.4.5): Cancel, the back control, the sidebar or any link
 * that leaves this page asks first while changes stand unsaved, in the console's own dialog. A reload or a closed tab
 * meets the browser's own question.
 */
const leavingTo = ref(null)

const cancel = () => {
    if (changeCount.value === 0) {
        router.visit(props.rolesHref)

        return
    }

    leavingTo.value = props.rolesHref
}

const discardAndLeave = () => {
    const url = leavingTo.value

    leavingTo.value = null
    mayLeave = true
    router.visit(url ?? props.rolesHref)
}

const keepEditing = () => {
    leavingTo.value = null
}

let stopGuard = null

const warnBeforeUnload = (event) => {
    if (!mayLeave && changeCount.value > 0) {
        event.preventDefault()
        event.returnValue = ''
    }
}

/*
 * THE HEADER ROW STAYS UNDER THE SHELL'S TOP BAR (Hassam, 12 Oct, batch 1 item 4). The shell pins its top bar at the red
 * bar's height since 85c79680, and this table's header row, pinned at that same height, slid beneath it. The row now
 * sits below the bar. The bar's height is measured and followed, because below 1023 px it wraps to two rows.
 */
const topbarHeight = ref(0)
let topbarWatch = null

const followTopbar = () => {
    const bar = typeof document === 'undefined' ? null : document.querySelector('.main-col > .topbar')

    if (bar === null) {
        return
    }

    topbarHeight.value = bar.offsetHeight

    if (typeof ResizeObserver === 'function') {
        topbarWatch = new ResizeObserver(() => {
            topbarHeight.value = bar.offsetHeight
        })
        topbarWatch.observe(bar)
    }
}

onMounted(() => {
    followTopbar()

    stopGuard = router.on('before', (event) => {
        const visit = event.detail.visit
        const leaves = visit.method === 'get' && typeof window !== 'undefined' && visit.url.pathname !== window.location.pathname

        if (mayLeave || changeCount.value === 0 || !leaves) {
            return
        }

        event.preventDefault()
        leavingTo.value = visit.url.href
    })

    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
        window.addEventListener('beforeunload', warnBeforeUnload)
    }
})

onBeforeUnmount(() => {
    stopGuard?.()
    topbarWatch?.disconnect()

    if (typeof window !== 'undefined' && typeof window.removeEventListener === 'function') {
        window.removeEventListener('beforeunload', warnBeforeUnload)
    }
})
</script>

<template>
    <Head :title="`${role.label} permissions`" />

    <EstateConsole :title="role.label" :estate-name="estate.name" active="settings" :back="{ href: rolesHref, label: 'Back to roles' }">
        <SkeletonRows v-if="state.isLoading.value" :rows="10" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Changing roles is not part of your role’s access"
            body="Only the role that may change what every role here may do opens this page."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            :title="`What ${role.label} may do could not be read`"
            body="Nothing has changed, and the role still holds exactly what it held. Trying again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="This console has no modules yet"
            body="A role is given modules, and this console holds none, so there is nothing to give it."
        />

        <div v-else class="rp-page">
            <!-- Who holds it, under the heading the shell draws (the role's name). -->
            <div class="rp-intro">
                <p class="rp-holders">{{ holdersWords }}</p>
                <p v-if="role.is_shared" class="rp-read">
                    {{ role.label }} is shared by every community, so the first saved change makes this community’s own
                    version of it. Every other community keeps GeminiSecure’s.
                </p>
                <p v-else-if="role.note" class="rp-read">{{ role.note }}</p>
            </div>

            <!-- THE MONEY TOTALS, full width at the top, where it can be read (the position rule, in the server's words). -->
            <p v-if="positionModules.length > 0" class="rl-warn rp-warn" role="note">
                {{ editor.position_rule.warning }} As drafted, {{ role.label }} has Reports view with
                {{ positionModules.join(', ') }}.
            </p>

            <!-- THE REVIEW: every single change, in words, before anything is saved. -->
            <section v-if="reviewing" class="rp-review" aria-labelledby="rp-review-head">
                <h2 id="rp-review-head" class="rp-section-head">
                    {{ changeCount === 1 ? 'The one change' : `The ${changeCount} changes` }} to {{ role.label }}, before anything is saved
                </h2>
                <ul class="rp-changes">
                    <li v-for="change in changes" :key="change.key">{{ change.text }}</li>
                </ul>
                <p class="rp-read">
                    Nothing is saved until you press Save. It is one change to {{ role.label }} on the audit log, with your
                    name, and it applies from the next page anybody holding it opens.
                </p>
            </section>

            <template v-else>
                <p class="rp-read">
                    Only the modules you change are saved, and only after the review lists every change in words.
                </p>

                <!-- The reason every greyed action shares, said once in full; each row names its own. -->
                <p v-if="anyBlocked" class="rp-read">
                    Greyed actions are ones your own role does not hold on that module. {{ editor.grantable_reason }}
                </p>

                <table class="data-table rl-grid rp-table" :style="{ '--rp-head-top': `${topbarHeight}px` }">
                    <colgroup>
                        <col class="rp-col-module" />
                        <col class="rp-col-access" />
                        <col class="rp-col-approver" />
                        <col class="rp-col-why" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col">Module</th>
                            <th scope="col">Access</th>
                            <th scope="col">Approver</th>
                            <th scope="col">Why a control is off</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="module in editor.modules" :key="module.key" :class="{ 'rl-changed': changedModules.includes(module.key) }">
                            <td class="rp-module">
                                <div class="mod-name">{{ module.label }}</div>
                                <div v-if="changedModules.includes(module.key)" class="res-sub">Changed</div>
                            </td>

                            <td data-label="Access">
                                <select
                                    :id="`rl-level-${module.key}`"
                                    class="rl-select"
                                    :value="draft[module.key]?.level"
                                    :disabled="isLocked(module.key)"
                                    :title="isLocked(module.key) ? lockedReason(module.key) : undefined"
                                    :aria-label="`${module.label} access`"
                                    @change="setLevel(module.key, $event.target.value)"
                                >
                                    <option v-for="level in editor.levels" :key="level.key" :value="level.key" :disabled="!levelAllowed(module.key, level)">
                                        {{ levelWord(level) }}
                                    </option>
                                </select>

                                <!-- The seven actions, at Custom only; a verb split into named acts shown as its acts. -->
                                <div v-if="draft[module.key]?.level === 'custom'" class="rl-verbs">
                                    <template v-for="item in customItems(module.key)" :key="item.key">
                                        <label
                                            v-if="item.kind === 'verb'"
                                            class="rl-check"
                                            :class="{ 'rl-off': verbLocked(module.key, item.verb.key) }"
                                            :title="
                                                !holds(module.key, item.verb.key)
                                                    ? editor.grantable_reason
                                                    : item.verb.key === 'approve'
                                                      ? approveTitle(module.key)
                                                      : `${item.verb.label} on ${module.label}`
                                            "
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="hasVerb(module.key, item.verb.key)"
                                                :disabled="verbLocked(module.key, item.verb.key)"
                                                @change="toggleVerb(module.key, item.verb.key, $event.target.checked)"
                                            />
                                            {{ item.verb.label }}
                                        </label>
                                        <label
                                            v-else
                                            class="rl-check"
                                            :class="{ 'rl-off': actBlocked(module.key, item.act) !== null }"
                                            :title="actBlocked(module.key, item.act) ?? item.act.description"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="hasAct(module.key, item.act)"
                                                :disabled="actBlocked(module.key, item.act) !== null"
                                                @change="toggleAct(module.key, item.act, $event.target.checked)"
                                            />
                                            {{ item.act.label }}
                                        </label>
                                    </template>
                                </div>
                            </td>

                            <td data-label="Approver">
                                <!-- One box per approval where the module has several (M11 B3). -->
                                <div v-if="draft[module.key] && actsUnder(module.key, 'approve').length > 0" class="rl-acts">
                                    <label
                                        v-for="act in actsUnder(module.key, 'approve')"
                                        :key="act.key"
                                        class="rl-check"
                                        :class="{ 'rl-off': actBlocked(module.key, act) !== null }"
                                        :title="actBlocked(module.key, act) ?? act.description"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="hasAct(module.key, act)"
                                            :disabled="actBlocked(module.key, act) !== null"
                                            @change="toggleAct(module.key, act, $event.target.checked)"
                                        />
                                        {{ act.label }}
                                    </label>
                                </div>
                                <label v-else-if="draft[module.key]" class="rl-check" :class="{ 'rl-off': approveLocked(module.key) }" :title="approveTitle(module.key)">
                                    <input
                                        type="checkbox"
                                        :checked="draft[module.key].can_approve"
                                        :disabled="approveLocked(module.key)"
                                        :title="approveTitle(module.key)"
                                        @change="toggleVerb(module.key, 'approve', $event.target.checked)"
                                    />
                                    Approver
                                </label>
                            </td>

                            <!-- Every disabled control says why, in words and not only on hover. -->
                            <td
                                class="rl-why-cell"
                                :class="{ 'rp-why-empty': !isLocked(module.key) && blockedVerbs(module.key).length === 0 }"
                                data-label="Why a control is off"
                            >
                                <template v-if="isLocked(module.key)">
                                    <span class="inv-tag rl-inline-tag">Locked</span>
                                    {{ lockedReason(module.key) }}
                                </template>
                                <template v-else-if="blockedVerbs(module.key).length > 0">
                                    Your role does not hold {{ blockedVerbs(module.key).join(', ') }} here, so
                                    {{ blockedVerbs(module.key).length === 1 ? 'it stays' : 'they stay' }} as {{ role.label }} has
                                    {{ blockedVerbs(module.key).length === 1 ? 'it' : 'them' }}.
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <!--
              SAVE AND CANCEL, ALWAYS IN VIEW: at the foot of the window however long the table is, with the running count.
              The table's button reviews; only the review's button saves.
            -->
            <div class="rp-bar" role="group" aria-label="Save or cancel">
                <span class="rp-count" aria-live="polite">{{ countWords }}</span>

                <template v-if="reviewing">
                    <button type="button" class="rp-btn rp-btn--outline" :disabled="saveForm.processing" @click="backToTable">Back to the table</button>
                    <button type="button" class="rp-btn rp-btn--primary" :disabled="saveForm.processing" @click="save">
                        {{ saveForm.processing ? 'Saving…' : `Save ${changeCount === 1 ? 'this change' : `these ${changeCount} changes`}` }}
                    </button>
                </template>

                <template v-else>
                    <!-- Law 1 (DR-025): with nothing changed there is nothing to put back, and the reason is beside Review. -->
                    <button
                        type="button"
                        class="rp-link"
                        :disabled="changeCount === 0"
                        :title="changeCount === 0 ? 'Change a permission first.' : 'Put back what was saved.'"
                        @click="putBack"
                    >
                        Discard changes
                    </button>
                    <span v-if="changeCount === 0" class="rp-why">Change a permission first.</span>
                    <button type="button" class="rp-btn rp-btn--outline" @click="cancel">Cancel</button>
                    <button
                        type="button"
                        class="rp-btn rp-btn--primary"
                        :disabled="changeCount === 0"
                        :title="changeCount === 0 ? 'Change a permission first.' : 'See every change in words before anything is saved.'"
                        @click="review"
                    >
                        {{ changeCount === 0 ? 'Review changes' : `Review ${changeCount === 1 ? 'the change' : `${changeCount} changes`}` }}
                    </button>
                </template>
            </div>
        </div>

        <!-- Leaving with changes unsaved: asked, in the console's dialog (a confirmation, so a modal). -->
        <ConsoleDialog
            :open="leavingTo !== null"
            title="Discard these permission changes?"
            tone="danger"
            confirm-label="Discard changes"
            cancel-label="Keep editing"
            @confirm="discardAndLeave"
            @cancel="keepEditing"
        >
            <p class="rp-read">
                {{ countWords }} to {{ role.label }} {{ changeCount === 1 ? 'is' : 'are' }} not saved. Nothing about the role
                has changed yet; leaving puts every change back.
            </p>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
/* Default removal for the real buttons; see Roles.vue on `font-family` and never `font`. */
button {
    font-family: inherit;
}

button[disabled] {
    cursor: not-allowed;
}

.rp-page {
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-width: 0;
}

.rp-intro {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.rp-holders {
    margin: 0;
    font-size: 13px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.5;
}

.rp-read {
    margin: 0;
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.55;
}

/* The money warning, full width: a fact to weigh, amber, never a fault (the boards' "look at this"). */
.rl-warn {
    margin: 0;
    padding: 12px 15px;
    border-radius: 12px;
    background: var(--amber-100);
    border: 1px solid var(--amber-500);
    color: var(--navy-900);
    font-size: 12.5px;
    line-height: 1.6;
}

.rp-section-head {
    margin: 0 0 8px;
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: var(--navy-900);
}

.rp-review {
    padding: 16px 18px;
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    background: var(--white);
}

.rp-changes {
    margin: 0 0 12px;
    padding: 10px 14px 10px 30px;
    border-radius: 10px;
    background: var(--navy-100);
    color: var(--navy-900);
    font-size: 13px;
    line-height: 1.8;
}

/*
 * THE TABLE. Real column widths, so the explanation has room and never breaks a word a line; the header row kept in
 * view while the page scrolls under it. The board's table clips its own corners (`overflow: hidden`) and collapses its
 * borders, and either one stops a header from sticking: both are undone here, doubled classes outranking the sheet.
 */
.rp-table.rp-table {
    table-layout: fixed;
    border-collapse: separate;
    border-spacing: 0;
    overflow: visible;
}

.rp-col-module {
    width: 19%;
}

.rp-col-access {
    width: 33%;
}

.rp-col-approver {
    width: 20%;
}

.rp-col-why {
    width: 28%;
}

/* Below the shell's pinned top bar, whose height the page measures (batch 1 item 4). */
.rp-table.rp-table thead th {
    position: sticky;
    top: calc(var(--panic-bar-h, 0px) + var(--rp-head-top, 0px));
    z-index: 2;
}

.rp-table.rp-table thead th:first-child {
    border-top-left-radius: 16px;
}

.rp-table.rp-table thead th:last-child {
    border-top-right-radius: 16px;
}

.rp-table.rp-table tbody td {
    vertical-align: top;
    overflow-wrap: anywhere;
}

.rl-changed td {
    background: var(--amber-100);
}

.rl-select {
    height: 32px;
    width: 100%;
    max-width: 220px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font-family: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.rl-select:disabled {
    background: var(--navy-100);
    color: var(--slate-500);
}

.rl-verbs {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 14px;
    margin-top: 9px;
}

/* One box per approval on a module whose approval is several (M11 B3). */
.rl-acts {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.rl-check {
    display: inline-flex;
    align-items: flex-start;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.4;
}

.rl-check input {
    flex: 0 0 auto;
    accent-color: var(--navy-600);
    width: 15px;
    height: 15px;
    margin: 1px 0 0;
}

/* M18B: an "off" mark is still read: the placeholder token, 7.28:1 on white. */
.rl-check.rl-off {
    color: var(--text-placeholder);
}

.rl-why-cell {
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.55;
}

/* The invariant marker, in the slate the boards use for a fact nobody here decided. */
.inv-tag {
    font-size: 9px;
    font-weight: 700;
    color: var(--slate-500);
}

.rl-inline-tag {
    display: inline;
    margin: 0 4px 0 0;
}

/* THE FOOT: Save and Cancel always in view, however long the table, with the running count. */
.rp-bar {
    position: sticky;
    bottom: 0;
    z-index: 3;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 10px 14px;
    margin: 4px 0 0;
    padding: 12px 16px;
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    background: var(--white);
    box-shadow: 0 -8px 22px rgba(11, 31, 51, 0.1);
}

.rp-count {
    margin-right: auto;
    font-size: 13px;
    font-weight: 700;
    color: var(--navy-900);
}

.rp-why {
    font-size: 12px;
    color: var(--slate-600);
}

.rp-link {
    border: 0;
    background: none;
    padding: 0;
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-700);
    cursor: pointer;
}

.rp-link:disabled {
    color: var(--text-placeholder);
}

.rp-btn {
    height: 40px;
    padding: 0 18px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

.rp-btn--outline {
    background: var(--white);
    color: var(--navy-900);
    border: 1.5px solid var(--navy-200);
}

.rp-btn--primary {
    background: var(--navy-600);
    color: var(--white);
    border: 0;
}

.rp-btn:disabled {
    opacity: 0.55;
}

/*
 * ON A PHONE: every module a card of its own, each control labelled, nothing sideways (Hassam: "it works at 390 px with
 * no sideways scroll"). The header row goes, because each card says what each control is.
 */
@media (max-width: 760px) {
    .rp-table.rp-table,
    .rp-table.rp-table tbody,
    .rp-table.rp-table tr,
    .rp-table.rp-table td {
        display: block;
        width: 100%;
    }

    .rp-table.rp-table {
        border: 0;
        background: none;
    }

    .rp-table.rp-table colgroup,
    .rp-table.rp-table thead {
        display: none;
    }

    .rp-table.rp-table tbody tr {
        margin: 0 0 12px;
        padding: 12px 14px;
        border: 1px solid var(--navy-100);
        border-radius: 12px;
        background: var(--white);
    }

    .rp-table.rp-table tbody tr.rl-changed {
        background: var(--amber-100);
    }

    .rp-table.rp-table tbody td {
        padding: 6px 0;
        border: 0;
        background: none;
    }

    .rp-table.rp-table tbody td[data-label]::before {
        content: attr(data-label);
        display: block;
        margin: 0 0 4px;
        font-size: 10.5px;
        font-weight: 700;
        color: var(--slate-500);
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .rp-table.rp-table tbody td.rp-why-empty {
        display: none;
    }

    .rl-select {
        max-width: none;
    }

    .rp-bar {
        padding: 10px 12px;
    }

    .rp-count {
        flex: 1 1 100%;
    }

    .rp-btn {
        flex: 1 1 auto;
    }
}
</style>
