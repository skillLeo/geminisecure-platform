<script setup>
import { computed, ref, watch } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import { toast } from '../../../lib/toasts.js'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload'

// An officer posted, suspended or clocked in at this client moves this list by itself (M18H §7.2).
useLiveReload({ entities: ['officer', 'shift'] })

/**
 * Manage guard assignment — board screen super-admin-10.
 *
 * THE ROSTER IS STAGED, THEN SAVED, and that is the board's design rather than
 * a convenience. It draws "Save changes" in the topbar, and the Build Spec
 * lists "save the roster" as an action beside assign, unassign and reassign.
 * Moving one officer off the Service Gate and another onto it is a single
 * decision about one estate's cover; committed row by row it would pass through
 * a state where the gate reads as unmanned, and the dispatch coverage board
 * reads that same record. So every control here edits a local roster and
 * nothing reaches the database until Save is pressed.
 *
 * Four controls, all live, one per action the spec names:
 *
 *   ×                     unassign. Takes the row off the staged roster.
 *   pencil                reassign. Turns the row's post into a picker of THIS
 *                         estate's posts — never another client's.
 *   the add row           assign. A real <select> of the workforce pool, which
 *                         is drawn as the board's own row rather than as a
 *                         field, because the board draws a row.
 *   Save changes          commits the lot, in one transaction, audited.
 *
 * THE LAPSED LICENCE IS BLOCKED BY ABSENCE. The picker never lists an officer
 * whose PSRA licence has expired — the spec is explicit that it blocks rather
 * than warns — and the service refuses one anyway, because a list is a courtesy
 * and the rule has to hold against a request made by hand.
 *
 * Devon Palmer is the case that shows the difference. He is on this roster with
 * an expired licence and he stays on it: D-034 keeps an officer visible to the
 * client whose gate they are standing, and clearing him here would tidy this
 * screen while the problem stood at Phoenix Park's service gate. He can be
 * removed. He cannot be re-added.
 *
 * DOM and class names are the board's, including the two inline styles it
 * carries — the fill pill and the panel that holds the rows. Neither has a class
 * in the board's stylesheet, and inventing one would be authoring CSS the design
 * does not have.
 */
const props = defineProps({
    roster: { type: Object, required: true },
    /** Whether this role may open a guard's own record from the pencil. */
    canOpenGuardRecords: { type: Boolean, required: true },
})

/*
 * The staged roster: guard id and the post they would hold, in board order.
 *
 * Seeded from the server and reseeded whenever the server answers again, so a
 * save, a back-navigation or a partial reload leaves the screen showing what is
 * actually recorded rather than an edit that was already committed.
 */
const seed = () => props.roster.assigned.map((guard) => ({ id: guard.id, postId: guard.postId }))

const staged = ref(seed())
const editing = ref(null)
const picked = ref('')

watch(
    () => props.roster.assigned,
    () => {
        staged.value = seed()
        editing.value = null
        picked.value = ''
    },
    { deep: true }
)

/** Every officer this screen can draw a row for: those on the roster, and the pool. */
const known = computed(() => {
    const all = new Map()

    for (const guard of props.roster.assigned) {
        all.set(guard.id, guard)
    }

    for (const guard of props.roster.pool) {
        all.set(guard.id, guard)
    }

    return all
})

const postName = (postId) =>
    props.roster.posts.find((post) => (post.id ?? null) === (postId ?? null))?.name ?? 'Unposted'

/**
 * The rows as drawn: the staged roster, resolved back into people.
 *
 * The second line is composed here rather than on the server precisely so that
 * restaging a post rewrites it — a row naming the old gate beside a picker
 * naming the new one would be the screen contradicting itself.
 */
const rows = computed(() =>
    staged.value
        .map((line) => {
            const guard = known.value.get(line.id)

            return guard === undefined
                ? null
                : { ...guard, postId: line.postId, detail: postName(line.postId) + guard.suffix }
        })
        .filter(Boolean)
)

/** Officers in the pool who are not already staged onto this estate. */
const available = computed(() =>
    props.roster.pool.filter((guard) => !staged.value.some((line) => line.id === guard.id))
)

const original = computed(() => JSON.stringify(seed()))
const dirty = computed(() => JSON.stringify(staged.value) !== original.value)

/* --- the six states --------------------------------------------------- */

const state = useScreenState({
    /*
     * A roster screen has rows when someone is on the roster. An estate with
     * nobody assigned is a real and ordinary state — a client that has just
     * signed, or one that runs its own security — and it gets the first-use
     * panel with the picker still reachable beneath it rather than a table of
     * nothing.
     */
    rows: () => props.roster.assigned.length,

    // No search and no filter on this screen: it shows one estate's roster
    // whole. Forcing empty-filtered still renders, and says exactly that.
    filtered: () => false,

    // The service hands back no id when the estate row disappeared between the
    // controller resolving it and the roster being read.
    failed: () => !props.roster.id,
})

/* --- the four actions -------------------------------------------------- */

const form = useForm({ roster: [] })

const unassign = (guardId) => {
    staged.value = staged.value.filter((line) => line.id !== guardId)

    if (editing.value === guardId) {
        editing.value = null
    }
}

const reassign = (guardId, postId) => {
    staged.value = staged.value.map((line) =>
        line.id === guardId ? { ...line, postId: postId === '' ? null : Number(postId) } : line
    )
    editing.value = null
}

const assign = () => {
    if (picked.value === '') {
        return
    }

    staged.value = [...staged.value, { id: Number(picked.value), postId: null }]
    picked.value = ''
}

/*
 * A rule the server refused (a lapsed licence, a post belonging to another client) just happened, with no dialog open:
 * a toast, top right, that stays until it is closed (Hassam, 12 October), and nothing was saved. The staged roster stays
 * as it was arranged. What a save did is the shell's toast.
 */
const save = () => {
    form.roster = staged.value.map((line) => ({ guard_id: line.id, post_id: line.postId }))
    form.post(`/clients/${props.roster.id}/guards`, {
        preserveScroll: true,
        onError: (errors) => {
            if (errors.roster) {
                toast(`Nothing was saved. ${errors.roster}`, { tone: 'error' })
            }
        },
    })
}

const retry = () => router.reload()

/**
 * Why the picker cannot be used, or null when it can.
 *
 * Two different empties, and they need different actions from whoever reads
 * them: everyone posted elsewhere is a hiring problem, everyone unlicensed is a
 * compliance one.
 */
const pickerBlockedBy = computed(() => {
    if (available.value.length > 0) {
        return null
    }

    return props.roster.pool.length > 0
        ? 'Every officer in the pool is already on this roster.'
        : props.roster.poolBlockedReason
})
</script>

<template>
    <Head :title="roster.title" />

    <GeminiConsole :title="roster.title" :back="{ href: `/clients/${roster.id}`, label: `Back to ${roster.name}` }">

        <template #actions>
            <!--
              The board's primary action. Inert until something has actually
              been changed, because a Save that writes the roster it just read
              is a write with no decision behind it — and this one appends to
              the audit log, so it would leave a trace of a change nobody made.
            -->
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!dirty || form.processing"
                :title="
                    dirty
                        ? 'Commit this roster'
                        : 'Nothing has been changed on this roster yet'
                "
                @click="save"
            >
                <span>{{ form.processing ? 'Saving…' : 'Save changes' }}</span>
            </button>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This client's roster is not yours to change"
            body="Assigning officers to an estate's gates needs the Clients module at full access, and it is scoped to the sites your role holds. An Operations Manager or the Director can widen that."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This roster could not be read"
            body="The estate exists but its roster could not be loaded from the platform database. Nobody has been assigned or unassigned — the roster is exactly as it was."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="This screen has no filter"
            body="A roster shows one estate's guards whole. Narrowing the workforce by name, licence state or estate happens on the guard workforce directory."
        />

        <template v-else>
            <div class="detail-head">
                <div class="hero-card">
                    <div class="hero-top">
                        <div>
                            <div class="hero-name">{{ roster.name }}</div>
                            <div class="hero-sub">{{ roster.subtitle }}</div>
                        </div>
                        <!--
                          The board's own inline pill. Green where cover is met,
                          and the boards' amber tokens where it is short — which
                          is the treatment board 05 gives this same slot. No
                          colour is introduced that the design does not declare.
                        -->
                        <div
                            v-if="roster.fill.short"
                            style="
                                font-size: 10.5px;
                                font-weight: 700;
                                color: var(--amber-700);
                                background: var(--amber-100);
                                padding: 5px 11px;
                                border-radius: 20px;
                            "
                        >{{ roster.fill.label }}</div>
                        <div
                            v-else
                            style="
                                font-size: 10.5px;
                                font-weight: 700;
                                color: var(--success-700);
                                background: var(--success-100);
                                padding: 5px 11px;
                                border-radius: 20px;
                            "
                        >{{ roster.fill.label }}</div>
                    </div>
                </div>
            </div>

            <div
                style="
                    background: var(--white);
                    border: 1px solid var(--navy-100);
                    border-radius: 16px;
                    overflow: hidden;
                    margin-bottom: 16px;
                "
            >
                <EmptyState
                    v-if="state.isEmpty.value"
                    variant="first-use"
                    title="Nobody is posted at this estate"
                    body="Either this client runs its own security and buys the software alone, or it has not been staffed yet. Assign an officer from the workforce pool below to start."
                />

                <div v-for="guard in rows" :key="guard.id" class="assign-row">
                    <div class="assign-avatar">{{ guard.initials }}</div>
                    <div class="assign-txt">
                        <div class="an">{{ guard.name }}</div>

                        <!--
                          The post, as a picker while the row is being edited
                          and as the board's own line the rest of the time. Only
                          this estate's posts are offered: a post belongs to one
                          client, and a list carrying another's gates would let
                          a save cross a tenant boundary by mis-click.
                        -->
                        <select
                            v-if="editing === guard.id"
                            class="ap post-picker"
                            :value="guard.postId ?? ''"
                            :aria-label="`Post for ${guard.name}`"
                            @change="reassign(guard.id, $event.target.value)"
                        >
                            <option v-for="post in roster.posts" :key="post.id ?? 'none'" :value="post.id ?? ''">
                                {{ post.name }}
                            </option>
                        </select>
                        <div v-else class="ap">{{ guard.detail }}</div>
                    </div>

                    <button
                        type="button"
                        class="icon-btn-sm"
                        :title="`Change which post ${guard.name} holds`"
                        :aria-label="`Change which post ${guard.name} holds`"
                        @click="editing = editing === guard.id ? null : guard.id"
                    >
                        <BoardIcon name="pencil" :stroke="1.6" />
                    </button>

                    <button
                        type="button"
                        class="icon-btn-sm remove"
                        :title="`Take ${guard.name} off this estate's roster`"
                        :aria-label="`Take ${guard.name} off this estate's roster`"
                        @click="unassign(guard.id)"
                    >
                        <BoardIcon name="close" :stroke="2.2" />
                    </button>
                </div>

                <!--
                  The board draws this as a row, not a field, so it stays a row:
                  the plus, then a <select> wearing the same three declarations
                  the board gives the text it replaces. Choosing a name stages
                  the officer; the list itself is the licence block, because an
                  operator who can see a name will eventually try it.
                -->
                <div class="add-guard-row">
                    <BoardIcon name="plus" :stroke="2" />
                    <select
                        v-model="picked"
                        class="add-guard-picker"
                        :disabled="pickerBlockedBy !== null"
                        :title="pickerBlockedBy ?? 'Add an officer to this estate’s roster'"
                        aria-label="Assign another guard from the workforce pool"
                        @change="assign"
                    >
                        <option value="">Assign another guard from the workforce pool</option>
                        <option v-for="guard in available" :key="guard.id" :value="guard.id">
                            {{ guard.label }}
                        </option>
                    </select>
                </div>
            </div>

            <!--
              The pencil's destination, once. A post is changed above; anything
              else about an officer — their licence, their leave, their
              employment — belongs to their own record in the guard workforce
              module, and a role that cannot open that module is told so here
              rather than sent to a 403 by a link that looked live.
            -->
            <div v-if="rows.length" class="action-stack">
                <Link v-if="canOpenGuardRecords" :href="`/guards?tenant=${roster.id}`" class="stack-btn outline">
                    <BoardIcon name="guards" :stroke="1.7" />
                    <span>Open these officers’ workforce records</span>
                </Link>
                <button
                    v-else
                    type="button"
                    class="stack-btn outline"
                    disabled
                    title="Guard workforce is not part of your role’s access, so an officer's own record cannot be opened from here."
                >
                    <BoardIcon name="guards" :stroke="1.7" />
                    <span>Open these officers’ workforce records</span>
                </button>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Reset only, and only where a board <div> had to become a real control.
 *
 * The board draws the two row chips as <div>s, the add row's label as a <span>,
 * the post line as a <div> and the back chevron as an inline-styled <div>. Each
 * is a real <button>, <select>, or <a> here, and a browser's own border,
 * background, font, padding and underline would otherwise show through and
 * change the pixels. Nothing below introduces a colour, a size or a spacing the
 * board does not already declare: where a value appears it is restated from the
 * board's own rule for the element being replaced.
 */
button {
    border: 0;
    background: transparent;
    font: inherit;
    padding: 0;
    cursor: pointer;
}

a {
    text-decoration: none;
}

/* .stack-btn.outline's own border, restated so the <button> default is
 * replaced rather than merely removed. */
button.stack-btn.outline {
    border: 1.5px solid var(--navy-200);
}

button.stack-btn[disabled] {
    opacity: 1;
    cursor: not-allowed;
}

/* A disabled control is dimmed and greyed by the UA. The board draws these at
 * one weight, and the reason they cannot be used is on the title. */
button[disabled],
select[disabled] {
    opacity: 1;
    cursor: not-allowed;
}

/*
 * The two <select>s.
 *
 * `appearance: none` is the load-bearing line: without it the platform draws a
 * dropdown arrow and a sunken well, neither of which the board has. The font,
 * colour and weight on each are copied from the board's own rule for the
 * element being replaced — .add-guard-row span, and .assign-txt .ap — so the
 * text lands where the text it stands in for landed.
 */
select {
    appearance: none;
    -webkit-appearance: none;
    border: 0;
    /* No outline reset (M12 §4.1): a keyboard user sees the consoles' one focus ring on it. */
    background: transparent;
    padding: 0;
    margin: 0;
    max-width: 100%;
    cursor: pointer;
}

/* .add-guard-row span, restated. */
.add-guard-picker {
    font-family: inherit;
    font-size: 12px;
    color: var(--navy-600);
    font-weight: 700;
}

/* .assign-txt .ap, restated, plus the 1px top margin that rule carries. */
.post-picker {
    font-family: inherit;
    font-size: 10.5px;
    color: var(--slate-500);
    margin-top: 1px;
    display: block;
}

</style>
