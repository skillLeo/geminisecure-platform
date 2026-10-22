<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import { toast } from '../../../lib/toasts.js'
import SettingsTabs from './SettingsTabs.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveKind } from '../../../composables/useLiveKind'

// A settings editor (M18H §7.2).
useLiveKind('form')

/**
 * Client line items — board screen super-admin-44.
 *
 * ONE CLIENT'S EXCEPTIONS, not the template. Screen 43 changes what a whole
 * tier includes; this records what a single client has above or below theirs.
 * The two are deliberately separate screens because the blast radius is
 * different: a template row reprices everyone on that tier, a line item
 * reprices one estate.
 *
 * Three sections, in the board's order, each with its own badge:
 *
 *   base       what the tier already includes, and the per-guard add-on
 *   additions  a feature granted above the tier, with who granted it and when
 *   removals   a base-plan feature the client asked to drop
 *
 * The additions and removals carry a PROVENANCE line — "added Sep 3 by Damian
 * Reid ahead of their upgrade conversation". That is not decoration: a
 * negotiated price nobody can account for is the thing that turns into a
 * dispute, and the board draws it because someone has to be able to answer
 * "why is this client paying this".
 *
 * ADDING AND ENDING ARE PRIVILEGED, DATED AND AUDITED WRITES (12 §2, Wave 4;
 * M10 §3). Either changes what a client is billed, so both need Platform
 * settings configure; for anyone else the board's controls stay inert and say
 * why. This said "nothing here writes" until M10 §3, which had stopped being
 * true in Wave 4.
 *
 * A CHANGE IS SAID BEFORE IT IS MADE (M10 §3). Which invoice an override is
 * first on, or last on, and that nothing already issued moves, is the server's
 * sentence (`addImpact`, `endImpact`) rather than this page's arithmetic — the
 * invoice run bills an override in full for every month it touches, and only
 * the server knows which months this client has already been invoiced for.
 */
const props = defineProps({
    tabs: { type: Array, required: true },
    clients: { type: Array, required: true },
    selected: { type: String, default: null },
    billing: { type: Object, default: null },
    /** What adding an override from the add form's date would do, and from when. */
    addImpact: { type: Object, default: null },
    /** What ending an override today would do. */
    endImpact: { type: Object, default: null },
    /** The platform's date, which "from today" means. */
    today: { type: String, required: true },
    canWrite: { type: Boolean, required: true },
    writeDisabledReason: { type: String, required: true },
})

/* ------------------------------------------------------------------ */
/* the two writes (12 §2, Wave 4) */
/* ------------------------------------------------------------------ */

/*
 * The server's date, not the browser's clock. The add waits until the server's
 * read-back is about the date in the form, so a browser whose clock disagreed by
 * a day would start with a read-back that never matched and an add that never
 * unlocked — and "from today" means the day the server refuses a backdate by.
 */
const today = props.today

const adding = ref(false)

const addForm = useForm({
    client: props.selected,
    name: '',
    reason: '',
    type: 'addition',
    amount: '',
    effective_from: today,
})

/*
 * WHETHER THE SERVER'S SENTENCE IS ABOUT THE DATE IN THE FORM. Asked again as
 * the date changes; until the answer is back the panel says so and the add
 * waits, because a statement about a different date is worse than none.
 */
const addImpactFresh = computed(
    () => props.addImpact !== null && props.addImpact.effective_from === addForm.effective_from
)

let impactTimer = null

watch(
    () => addForm.effective_from,
    (from) => {
        clearTimeout(impactTimer)

        if (!from || !props.selected) {
            return
        }

        impactTimer = setTimeout(() => {
            router.get(
                '/settings/line-items',
                { client: props.selected, from },
                { only: ['addImpact'], preserveState: true, preserveScroll: true, replace: true }
            )
        }, 250)
    }
)

onBeforeUnmount(() => clearTimeout(impactTimer))

const addBlockedReason = computed(() => {
    if (addForm.name.trim() === '') {
        return 'Name it. A line on an invoice that says nothing is one a client will ring about.'
    }

    if (!addImpactFresh.value) {
        return 'Still working out which invoice this is first on. It can be added once that is shown.'
    }

    return props.addImpact.refused
})

const submitAdd = () => {
    if (addBlockedReason.value) {
        return
    }

    addForm.client = props.selected
    addForm.post('/settings/line-items', {
        preserveScroll: true,
        onSuccess: () => {
            adding.value = false
            addForm.reset()
            addForm.effective_from = today
        },
    })
}

/*
 * ENDED, NEVER DELETED. The override belongs to the invoices it was billed on;
 * removing the row would make those invoices unexplainable. It stops applying
 * from today, and the server refuses a date in the past for the same reason
 * adding one is refused.
 */
const endForm = useForm({ client: props.selected, effective_to: today })

const endItem = (row) => {
    if (!props.canWrite) {
        return
    }

    endForm.client = props.selected
    endForm.effective_to = today
    // Pressed on its row with no dialog open (the date is today's, not typed), so a refusal is what just happened: a toast
    // that stays until it is closed (Hassam, 12 October). What ending it did is the shell's toast.
    endForm.post(`/settings/line-items/${row.id}/end`, {
        preserveScroll: true,
        onError: (errors) => {
            toast(errors.effective_to ?? Object.values(errors)[0] ?? 'The line item was not ended.', { tone: 'error' })
        },
    })
}

/** The end control's hover: what ending from today does, in the server's words. */
const endTitle = (row, verb) => {
    if (!props.canWrite) {
        return props.writeDisabledReason
    }

    return `${verb} ${row.name} from today. It stays on the record — the invoices it was billed on would otherwise be unexplainable. ${props.endImpact?.sentence ?? ''}`.trim()
}

const upcoming = computed(() => props.billing?.upcoming ?? [])

const rowCount = computed(
    () =>
        (props.billing?.base?.length ?? 0) +
        (props.billing?.additions?.length ?? 0) +
        (props.billing?.removals?.length ?? 0) +
        upcoming.value.length
)

const state = useScreenState({
    rows: () => rowCount.value,
})
</script>

<template>
    <Head title="Client line items" />

    <GeminiConsole title="Platform settings">
        <SettingsTabs :tabs="tabs" />

        <!--
          What an add or an end did is the shell's toast (Hassam, 12 October); a refused end is a toast too, and a
          refused add is said under the add form, in a sentence rather than silence.
        -->

        <!--
          The board draws every client as a chip and one as active. These are
          real links: the screen is per-client and the client is in the URL, so
          a chip that could not be opened in a new tab or bookmarked would be a
          filter pretending to be navigation.
        -->
        <div class="client-picker">
            <Link
                v-for="client in clients"
                :key="client.id"
                :href="client.href"
                class="client-picker-item"
                :class="{ active: client.active }"
            >{{ client.name }}</Link>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="3" />

        <!--
          No plan, or no client at all. Two different facts, and the second one
          is not an error: a platform with no clients yet has nothing to price.
        -->
        <EmptyState
            v-else-if="billing === null"
            variant="first-use"
            :title="clients.length === 0 ? 'No clients yet' : 'This client is not on a plan'"
            :body="
                clients.length === 0
                    ? 'Line items sit on top of a subscription. They can be recorded once a client is onboarded and placed on a tier.'
                    : 'Line items are priced against a base plan. Put this client on a tier first, and any additions or removals can be recorded here.'
            "
        />

        <div v-else class="invoice-panel">
            <div class="invoice-section-head">{{ billing.baseHead }}</div>

            <div v-for="(row, i) in billing.base" :key="'base-' + i" class="invoice-row">
                <div class="ir-txt">
                    <div class="ir1">{{ row.name }}</div>
                    <div class="ir2">{{ row.detail }}</div>
                </div>
                <div class="line-badge base">{{ row.badgeLabel }}</div>
                <div class="ir-price">{{ row.price }}</div>
            </div>

            <div class="invoice-section-head">Custom additions — single features added above their tier</div>

            <div v-for="(row, i) in billing.additions" :key="'add-' + i" class="invoice-row">
                <div class="ir-txt">
                    <div class="ir1">{{ row.name }}</div>
                    <div class="ir2">{{ row.detail }}</div>
                </div>
                <div class="line-badge added">{{ row.badgeLabel }}</div>
                <div class="ir-price">{{ row.price }}</div>
                <button
                    type="button"
                    class="remove-x"
                    :disabled="!canWrite || endForm.processing"
                    :title="endTitle(row, 'End')"
                    @click="endItem(row)"
                >
                    <BoardIcon name="close" :stroke="2.2" />
                </button>
            </div>

            <button
                type="button"
                class="add-feature-row"
                :disabled="!canWrite"
                :title="canWrite ? 'Add a dated override to this client\'s subscription. It is never retroactive — it appears on the next invoice raised on or after its date.' : writeDisabledReason"
                @click="adding = !adding"
            >
                <BoardIcon name="plus" :stroke="2" />
                <span>Add a single feature to this client's subscription</span>
            </button>

            <!--
              AUTHORED. The board draws a subscription nobody is changing, so it
              has no panel. An addition and a removal are both POSITIVE amounts
              with a type — a signed field would let a removal be entered as a
              negative addition and read as a discount nobody agreed.
            -->
            <form v-if="adding" class="li-panel" @submit.prevent="submitAdd">
                <div class="li-head">
                    A dated override, never retroactive: it appears on the next invoice raised on or after its date,
                    and nothing already invoiced changes.
                </div>

                <div class="li-fields">
                    <div class="li-field li-field--wide">
                        <label for="li-name">What it is</label>
                        <input id="li-name" v-model="addForm.name" type="text" required maxlength="120" />
                    </div>
                    <div class="li-field">
                        <label for="li-type">Kind</label>
                        <select id="li-type" v-model="addForm.type" required>
                            <option value="addition">Addition — costs this client more</option>
                            <option value="removal">Removal — costs this client less</option>
                        </select>
                    </div>
                    <div class="li-field">
                        <label for="li-amount">Amount per month, J$</label>
                        <input id="li-amount" v-model="addForm.amount" type="text" inputmode="decimal" required />
                    </div>
                    <div class="li-field">
                        <label for="li-from">In force from</label>
                        <input id="li-from" v-model="addForm.effective_from" type="date" required />
                    </div>
                    <div class="li-field li-field--wide">
                        <label for="li-reason">Why — read a year later, so say it plainly</label>
                        <input id="li-reason" v-model="addForm.reason" type="text" maxlength="300" />
                    </div>
                </div>

                <!--
                  WHAT IT WILL AFFECT AND FROM WHEN (M10 §3), in the server's
                  words for the date in the form.
                -->
                <div class="li-read" aria-live="polite">
                    <template v-if="addImpactFresh">
                        <p v-for="(line, i) in addImpact.lines" :key="i">{{ line }}</p>
                    </template>
                    <p v-else>Working out which of this client's invoices this is first on…</p>
                </div>

                <div v-for="(message, i) in Object.values(addForm.errors)" :key="'err-' + i" class="li-error">{{ message }}</div>

                <div class="li-actions">
                    <button
                        type="submit"
                        class="btn-primary-sm"
                        :disabled="addForm.processing || Boolean(addBlockedReason)"
                        :title="addBlockedReason || 'Record the override, as the panel above describes it.'"
                    >
                        <span>{{ addForm.processing ? 'Adding…' : 'Add override' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="adding = false">Cancel</button>
                </div>
            </form>

            <div class="invoice-section-head">Custom removals — base-plan features removed for this client</div>

            <div v-for="(row, i) in billing.removals" :key="'rem-' + i" class="invoice-row">
                <div class="ir-txt">
                    <div class="ir1">{{ row.name }}</div>
                    <div class="ir2">{{ row.detail }}</div>
                </div>
                <div class="line-badge removed">{{ row.badgeLabel }}</div>
                <div class="ir-price">{{ row.price }}</div>
                <!--
                  A removal's control RESTORES rather than removes, so the board
                  draws a tick on a tinted ground instead of a cross. Both
                  inline styles are the board's own.
                -->
                <button
                    type="button"
                    class="remove-x"
                    style="background: var(--navy-100)"
                    :disabled="!canWrite || endForm.processing"
                    :title="endTitle(row, 'Restore by ending the removal of')"
                    @click="endItem(row)"
                >
                    <BoardIcon name="restore" :stroke="2" />
                </button>
            </div>

            <!--
              AUTHORED (M10 §3). An override dated ahead is not in the total — it
              is not in force — and until this list it could not be seen again,
              or withdrawn, until it had started and been billed. Withdrawing one
              ends it before it began: it is on no invoice and stays on the record.
            -->
            <template v-if="upcoming.length">
                <div class="invoice-section-head">Recorded and not yet in force — not in the total below</div>

                <div v-for="row in upcoming" :key="'up-' + row.id" class="invoice-row">
                    <div class="ir-txt">
                        <div class="ir1">{{ row.name }}</div>
                        <div class="ir2">{{ row.detail }}</div>
                    </div>
                    <div class="line-badge" :class="row.badge">{{ row.badgeLabel }}</div>
                    <div class="ir-price">{{ row.price }}</div>
                    <button
                        type="button"
                        class="remove-x"
                        :disabled="!canWrite || endForm.processing"
                        :title="canWrite ? `Withdraw ${row.name} before it starts. It is on no invoice, and it stays on the record with who withdrew it.` : writeDisabledReason"
                        @click="endItem(row)"
                    >
                        <BoardIcon name="close" :stroke="2.2" />
                    </button>
                </div>
            </template>

            <div class="total-strip">
                <div class="ts1">Total monthly subscription — {{ billing.client }}</div>
                <div class="ts2">{{ billing.total }}</div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only.
 *
 * The board draws the client chips as <div>, and the add/remove controls as
 * <div> too. They are a link and buttons here so they can be reached by
 * keyboard and announced, and each arrives with browser chrome that would
 * change the pixels. The board's own .client-picker-item, .remove-x and
 * .add-feature-row rules supply everything visual.
 */
a.client-picker-item {
    text-decoration: none;
}

button.remove-x,
button.add-feature-row {
    border: 0;
    font: inherit;
    color: inherit;
    cursor: pointer;
}

/* .add-feature-row is a full-width row in the board; a <button> shrinks to
 * its content and centres its text. */
button.add-feature-row {
    width: 100%;
    text-align: left;
}

button.remove-x[disabled],
button.add-feature-row[disabled] {
    cursor: not-allowed;
}

button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a subscription nobody is changing,
 * so it has no panel. Kept to the tokens the Gemini boards define.
 */
.li-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    padding: 15px 16px;
    margin: 10px 0 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.li-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 760px;
}

.li-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.li-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.li-field--wide {
    grid-column: span 2;
}

.li-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.li-field input,
.li-field select {
    height: 33px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

/* A refusal of what was typed in the add form, under it: amber, as every ordinary refusal is. */
.li-error {
    font-size: 11px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

/* The same read-back as Pricing & rates' .ps-read. */
.li-read {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.55;
    background: var(--navy-100);
    border-radius: 10px;
    padding: 10px 14px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.li-read p {
    margin: 0;
}

.li-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
