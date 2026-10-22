<script setup>
import { computed, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import { toast } from '../../../lib/toasts.js'
import { submitDialog } from '../../../lib/dialogSubmit.js'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * New charge — board screen community-admin-35.
 *
 * POSTING A CHARGE IS AN ACCOUNTING ACT, not a form submission. It credits the
 * income account named in the GL field — the board draws "4000 — Maintenance
 * Fee Income" — and debits the unit's receivable control account for the same
 * figure. That is why the account is a field a treasurer chooses rather than a
 * constant this screen assumes, and why the amount travels as a decimal string:
 * the server hands it to `Money::of`, which refuses anything it cannot hold
 * exactly, and a float would have already lost the cent by then.
 *
 * THE LEDGER PREVIEW IS DERIVED HERE AND STORED NOWHERE. Current balance is the
 * server's figure, summed off posted journal lines; the new balance is that plus
 * whatever is in the amount field this instant. A second stored "new balance"
 * would be a number that could disagree with the journal, and a number that can
 * disagree with the journal eventually does.
 *
 * ALL THREE SCOPES ARE BUILT (12 §2, Wave 2). A single unit asks first: the press
 * opens a confirmation naming the amount, the household and the due date, and only
 * its own "Post charge" posts (M62 B1, DR-006).
 * A whole phase or the whole estate takes two: the first draws the list — which
 * units, how many, what it comes to — and posts nothing, and the second bills
 * every unit on that list in one transaction, all of them or none. The extra
 * press is not ceremony: it is hundreds of journal entries against hundreds of
 * households, and half of them posted is worse than none.
 */
const props = defineProps({
    estate: { type: Object, default: null },
    unit: { type: Object, default: null },
    currentBalanceMinor: { type: Number, required: true },
    accounts: { type: Array, required: true },
    types: { type: Array, required: true },
    canPost: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    /** The phases a whole-phase charge may reach, off the register. */
    phases: { type: Array, required: true },
    /** The list a bulk charge has been drawn against, or null. */
    bulkPreview: { type: Object, default: null },
    previewToken: { type: String, default: null },
})

/*
 * The shell carries the estate's chrome but not the board's stylesheet: each
 * board's CSS is lifted verbatim and scoped to its own body class, and board 35
 * lives in the ninth Community Admin sheet alongside Add Resident and Meetings.
 */
useWireframe('community-admin-09-data-privacy-add-resident-and-meetings')

const page = usePage()

/*
 * Where this estate lives, in whichever shape the environment serves.
 *
 * Production gives every estate its own hostname and the path starts at
 * /finance; local serves them all from one host with the estate in the path
 * (/estate/phoenixpark/finance/charges/new). Cutting the current URL at
 * /finance yields the right prefix in both, and — unlike a tenant id read off a
 * prop — it cannot address an estate other than the one already open.
 */
const estatePath = computed(() => {
    const cut = page.url.indexOf('/finance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const chargesPath = computed(() => `${estatePath.value}/finance/charges`)
const arrearsPath = computed(() => `${estatePath.value}/finance/arrears`)

const form = useForm({
    unit: props.unit?.reference ?? '',
    type: props.types[0]?.value ?? '',
    /*
     * The due date is deliberately blank. Defaulting it to today would make
     * every charge fall due the moment it is posted and start ageing that
     * afternoon, which is a collections decision taken by a default nobody
     * chose.
     */
    due_on: '',
    amount: '',
    description: '',
    account: props.accounts[0]?.code ?? '',
})

/**
 * Five of the six. `denied` is not reachable by data — the route already
 * requires Dues & ledger read access, so someone without it never renders this
 * screen — but it is forceable with ?_state=denied and must say something true
 * when it is. `canPost` is a separate question: it refuses the POST, not the
 * page, and lands on the button rather than over the whole screen.
 */
const state = useScreenState({
    rows: () => (props.unit === null ? 0 : 1),
    filtered: () => form.unit.trim() !== '',
})

/* ------------------------------------------------------------------ */
/* money */
/* ------------------------------------------------------------------ */

/*
 * One formatter, to the cent, for the amount field and the ledger preview alike (the money rule: every figure ties to
 * the ledger). The board drew the preview in whole dollars, so $12,400.50 and a $15,000.00 charge read $12,401 +
 * $15,000 = $27,401, a running balance the ledger never held. It is the same figure twice, both off `form.amount`.
 */
const withCents = new Intl.NumberFormat('en-JM', {
    style: 'currency',
    currency: 'JMD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})

/**
 * Read the amount field as a number.
 *
 * What is in the field is typed, and once it has been tidied on blur it also
 * carries a currency symbol and thousands separators — "$15,000.00". Everything
 * but digits, one point and a leading minus is stripped, and anything that still
 * will not parse counts as zero: "$NaN" in a ledger preview is a defect in the
 * one figure on this screen a treasurer is actually checking.
 */
const parseAmount = (value) => {
    const parsed = Number.parseFloat(String(value ?? '').replace(/[^0-9.-]/g, ''))

    return Number.isFinite(parsed) ? parsed : 0
}

const amountMinor = computed(() => Math.round(parseAmount(form.amount) * 100))

const preview = (minor) => withCents.format(minor / 100)

/*
 * The field reads like the board; the server receives arithmetic.
 *
 * "$15,000.00" is what board 35 prints in the amount field and what this one
 * shows once the value has been tidied, and it is not a number any validator
 * will accept. The transform is where the two part company, so the display can
 * stay faithful without the POST ever carrying a currency symbol.
 *
 * ONE TRANSFORM, INSTALLED ONCE, FOR EVERY PRESS (bug hunt B102). The bulk press
 * used to install its own — which spread the raw "$15,000.00" the blur had tidied
 * the field into — and then an identity one in its place, because Inertia's
 * `transform()` replaces the callback rather than adding to it. A whole-phase
 * charge failed "must be a number", and so did every single-unit charge after
 * it until the page was reloaded. The scope is read here, at the moment of the
 * post, so nothing ever needs swapping.
 */
form.transform((data) => ({
    ...data,
    amount: parseAmount(data.amount).toFixed(2),
    ...(scope.value === 'unit' ? {} : { scope: scope.value, phase: scope.value === 'phase' ? phase.value : null }),
}))

const tidyAmount = () => {
    if (form.amount.trim() !== '') {
        form.amount = withCents.format(parseAmount(form.amount))
    }
}

/* ------------------------------------------------------------------ */
/* the unit, and the balance that belongs to it */
/* ------------------------------------------------------------------ */

/**
 * Ask the server for a unit, and for the balance that goes with it.
 *
 * The current balance is read off the journal for one unit. Typing a different
 * reference over the top of the field without asking again would leave the
 * preview adding this charge to somebody else's balance — a wrong figure drawn
 * with total confidence, which is the failure this preview exists to prevent.
 * So the same GET that served this screen is asked again for those two props
 * alone, with the form's state preserved so nothing already typed is lost.
 */
const loadUnit = (reference) => {
    router.get(
        `${estatePath.value}/finance/charges/new`,
        reference === '' ? {} : { unit: reference },
        {
            only: ['unit', 'currentBalanceMinor'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            // A match comes back in the estate's own spelling — "lot 47" becomes
            // "Lot 47" — and a miss leaves what was typed in the field so the
            // empty state can quote it back.
            onSuccess: () => {
                form.unit = props.unit?.reference ?? reference
            },
        }
    )
}

/*
 * On change rather than on input: a lookup per keystroke would ask the server
 * for "L", "Lo", "Lot", and none of those are units. An emptied field is a field
 * being retyped, not a request for a different unit.
 */
const lookUpUnit = () => {
    const reference = form.unit.trim()

    if (reference !== '') {
        loadUnit(reference)
    }
}

/** The empty-filtered way back: drop the reference that matched nothing. */
const resetUnit = () => loadUnit('')

/**
 * The unit as the board prints it: "Phase 2 · Lot 47 — Andrea Fletcher".
 *
 * The board's Unit field is a picker showing a unit somebody already chose, so
 * it can print all three parts. A real field holds the value that posts, and
 * what posts is the reference alone — the estate register's own name for the
 * unit. The composite stays on the control as its title and the resident it
 * names is read back in the preview note, so the identity the board prints is
 * still on the screen — just not inside a field whose value has to be postable.
 */
const unitLabel = computed(() =>
    props.unit === null
        ? ''
        : [props.unit.phase, props.unit.reference, props.unit.resident].filter(Boolean).join(' · ')
)

/**
 * Who is notified, or null when nobody is.
 *
 * The board writes "Andrea will see this on her Dues statement", with a pronoun
 * for the one resident it was drawn from. The application knows a name and does
 * not know a pronoun, so the name carries the sentence — and a vacant unit gets
 * a different sentence entirely, because "No resident on record will see this"
 * is what the board's wording turns into when the household is empty.
 */
const notifies = computed(() => {
    const resident = props.unit?.resident ?? ''

    return resident === '' || resident === 'No resident on record' ? null : resident.split(' ')[0]
})

/* ------------------------------------------------------------------ */
/* scope (12 §2, Wave 2) */
/* ------------------------------------------------------------------ */

/*
 * Which of the three the treasurer is posting to. A phase or the estate draws
 * a list first and posts nothing; only the single-unit scope posts on one
 * press, because only it is one entry.
 */
const scope = ref('unit')

const phase = ref(props.phases[0] ?? '')

const SCOPES = [
    { key: 'unit', label: 'Single unit' },
    { key: 'phase', label: 'Whole phase' },
    { key: 'estate', label: 'Whole estate' },
]

const scopeTitle = (key) =>
    ({
        unit: 'One unit, one charge. The next press asks before anything is posted.',
        phase: 'Every unit in one phase. The next press draws the list and posts nothing.',
        estate: 'Every unit on the register. The next press draws the list and posts nothing.',
    })[key]

const chooseScope = (key) => {
    if (!props.canPost) {
        return
    }

    scope.value = key
}

/*
 * POST CHARGE ASKS FIRST (M62 B1, DR-006). One charge lands on a household's ledger
 * and in its app the moment it posts, so the press opens a confirmation that names
 * the amount, the household by name (never a lot number alone) and the due date, and
 * only the dialog's own "Post charge" posts. Cancel sends nothing at all.
 *
 * A form the server would refuse (no unit the register knows, no amount, no due date)
 * still goes straight to it, so the server's own sentences land on the fields that
 * need them; nothing can post that way.
 */
const confirming = ref(false)

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

/** "2026-10-25" as the console writes a date, "25 Oct 2026" (Law 5), read off the field: no clock can shift it. */
const asDate = (iso) => {
    const [year, month, day] = String(iso).split('-').map(Number)

    return year && month && day ? `${day} ${MONTHS[month - 1]} ${year}` : ''
}

const hasHousehold = computed(() => {
    const resident = props.unit?.resident ?? ''

    return resident !== '' && resident !== 'No resident on record'
})

const askTitle = computed(() => `Post ${withCents.format(amountMinor.value / 100)} to ${props.unit?.reference ?? form.unit.trim()}?`)

const askWho = computed(() =>
    hasHousehold.value
        ? `${props.unit.resident}'s household. Due ${asDate(form.due_on)}.`
        : `No household is on record at ${props.unit?.reference ?? form.unit.trim()}. Due ${asDate(form.due_on)}.`
)

const askWhat = computed(() =>
    hasHousehold.value
        ? 'This appears on their ledger and in their app straight away.'
        : "This appears on the unit's ledger straight away."
)

const readyToAsk = computed(() => props.unit !== null && amountMinor.value !== 0 && form.due_on !== '')

// From the question through the one save (lib/dialogSubmit.js): a refusal keeps every field as it was typed.
const postAsked = () => {
    submitDialog(form, 'post', chargesPath.value, {
        close: () => (confirming.value = false),
        // A refusal belongs on the fields it names, so the dialog steps aside for it.
        onError: () => {
            confirming.value = false
        },
    })
}

const submit = () => {
    /*
     * A form submits on Enter from any field, and a disabled button does not
     * stop it. The server refuses this too — `estate.dues_ledger.create` guards
     * the route — so this is only about not sending a charge that will bounce.
     */
    if (!props.canPost) {
        return
    }

    if (scope.value === 'unit') {
        if (readyToAsk.value) {
            confirming.value = true

            return
        }

        form.post(chargesPath.value, { preserveScroll: true })

        return
    }

    // Step one: draw the list. Nothing posts. The transform above adds the scope.
    form.post(`${chargesPath.value}/preview`, { preserveScroll: true })
}

/** Step two: bill everybody on the list that was drawn, from the console's dialog (M95 §1). */
const confirmBulk = useForm({ token: props.previewToken })

/* The dialog opens when a list arrives and Not now closes it; a new list opens it again. */
const bulkOpen = ref(props.bulkPreview !== null)

watch(
    () => props.previewToken,
    (token) => {
        bulkOpen.value = token !== null && props.bulkPreview !== null
    },
)

const postBulk = () => {
    confirmBulk.token = props.previewToken
    submitDialog(confirmBulk, 'post', `${chargesPath.value}/bulk`, {
        // A refused list is said as a toast (M95 §2): it was a line inside the panel.
        onError: (errors) => {
            const first = Object.values(errors)[0]

            if (first) {
                toast(first, { tone: 'error', key: 'bulk-charge-refused' })
            }
        },
    })
}

const retry = () => router.reload()
</script>

<template>
    <Head title="New charge" />

    <!-- A nested page: the way back to the arrears list, in words, top left (M95 §3). -->
    <EstateConsole title="New charge" :estate-name="estate.name" active="dues_ledger" :back="{ href: arrearsPath, label: 'Back to arrears' }">

        <div v-if="state.isLoading.value" class="form-layout">
            <div class="form-panel">
                <SkeletonRows :rows="6" :columns="2" />
            </div>
            <div class="preview-panel">
                <div class="preview-head">Ledger preview</div>
                <SkeletonRows :rows="3" :columns="2" />
            </div>
        </div>

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Dues &amp; ledger is not part of your role's access"
            body="A charge names a household and what it owes, so only roles that handle dues can open it. Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This unit's balance could not be loaded"
            body="No charge has been posted, and the unit owes what it owed before. Reading it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            :title="`No unit is registered as “${form.unit.trim()}”`"
            body="A unit is found by its reference on the estate register, such as “Lot 47”, not by a household or resident's name. Nothing else you typed has been lost."
            action-label="Clear the unit"
            @action="resetUnit"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="A charge has to be posted to a unit"
            body="A charge is raised against a unit, and this estate has no units on its register yet."
            action-label="Open Dues &amp; ledger"
            @action="router.get(arrearsPath)"
        />

        <div v-else class="form-layout">
            <form class="form-panel" @submit.prevent="submit">
                <!--
                  All three scopes, and the two bulk ones take a second press. M96 level 3: a role that can never post a
                  charge has no scopes to choose and no Post charge; the one line under the form says why.
                -->
                <div v-if="canPost" class="seg" role="radiogroup" aria-label="What this charge is posted to">
                    <button
                        v-for="option in SCOPES"
                        :key="option.key"
                        type="button"
                        class="seg-item"
                        :class="{ active: scope === option.key }"
                        role="radio"
                        :aria-checked="scope === option.key"
                        :title="scopeTitle(option.key)"
                        @click="chooseScope(option.key)"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <!-- Hassam, 12 Oct (batch 1 item 3): a field that must be filled in says so. -->
                <RequiredMark v-if="canPost" legend />

                <!-- AUTHORED: which phase, drawn only when the scope needs one. -->
                <div v-if="scope === 'phase'" class="bulk-phase">
                    <label for="ch-phase">Which phase <RequiredMark /></label>
                    <select id="ch-phase" v-model="phase" required>
                        <option v-for="name in phases" :key="name" :value="name">{{ name }}</option>
                    </select>
                </div>

                <!--
                  The board draws this field .focused — the screenshot was taken
                  with the cursor in it — and a unit arrives already resolved, so
                  the amber ring follows the field having a value, exactly as the
                  sibling form pages bind it.
                -->
                <div v-if="scope === 'unit'" class="m-field">
                    <label for="unit">Unit <RequiredMark /></label>
                    <div class="m-input" :class="{ focused: form.unit !== '' }">
                        <span>
                            <input
                                id="unit"
                                v-model="form.unit"
                                type="text"
                                required
                                placeholder="Lot 47"
                                :title="unitLabel"
                                @change="lookUpUnit"
                            />
                        </span>
                    </div>
                    <div v-if="form.errors.unit" class="field-error">{{ form.errors.unit }}</div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="type">Charge type <RequiredMark /></label>
                        <div class="m-input">
                            <span>
                                <select id="type" v-model="form.type" required>
                                    <option v-for="option in types" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </span>
                        </div>
                        <div v-if="form.errors.type" class="field-error">{{ form.errors.type }}</div>
                    </div>

                    <div class="m-field">
                        <label for="amount">Amount <RequiredMark /></label>
                        <div class="m-input">
                            <span>
                                <input
                                    id="amount"
                                    v-model="form.amount"
                                    type="text"
                                    inputmode="decimal"
                                    required
                                    placeholder="$0.00"
                                    @blur="tidyAmount"
                                />
                            </span>
                        </div>
                        <div v-if="form.errors.amount" class="field-error">{{ form.errors.amount }}</div>
                    </div>
                </div>

                <div class="m-field">
                    <label for="description">Description <RequiredMark /></label>
                    <div class="m-input">
                        <span>
                            <!--
                              200 characters is the server's limit, stated here
                              so the field stops rather than the submission.
                            -->
                            <input
                                id="description"
                                v-model="form.description"
                                type="text"
                                required
                                maxlength="200"
                                placeholder="What this charge is for"
                            />
                        </span>
                    </div>
                    <div v-if="form.errors.description" class="field-error">{{ form.errors.description }}</div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="due_on">Due date <RequiredMark /></label>
                        <div class="m-input">
                            <BoardIcon name="calendar" :stroke="1.6" />
                            <span>
                                <input id="due_on" v-model="form.due_on" type="date" required />
                            </span>
                        </div>
                        <div v-if="form.errors.due_on" class="field-error">{{ form.errors.due_on }}</div>
                    </div>

                    <div class="m-field">
                        <label for="account">Income account <RequiredMark /></label>
                        <div class="m-input">
                            <span>
                                <!--
                                  Income accounts only, and the code travels
                                  rather than the label: the charge credits this
                                  account, and an account is identified by its
                                  code in every ledger it appears in.
                                -->
                                <select id="account" v-model="form.account" required>
                                    <option v-for="option in accounts" :key="option.code" :value="option.code">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </span>
                        </div>
                        <div v-if="form.errors.account" class="field-error">{{ form.errors.account }}</div>
                    </div>
                </div>

                <button
                    v-if="canPost"
                    type="submit"
                    class="stack-btn primary"
                    style="width: 100%"
                    :disabled="form.processing"
                    :title="scopeTitle(scope)"
                >
                    <BoardIcon name="plus" :stroke="2" />
                    <span>
                        {{
                            form.processing
                                ? scope === 'unit'
                                    ? 'Posting…'
                                    : 'Drawing the list…'
                                : scope === 'unit'
                                  ? 'Post charge'
                                  : 'Show me who this reaches'
                        }}
                    </span>
                </button>
                <p v-else class="nc-blocked">{{ blockedReason }}</p>

                <ConsoleDialog
                    :open="confirming"
                    :title="askTitle"
                    confirm-label="Post charge"
                    busy-label="Posting…"
                    :busy="form.processing"
                    @confirm="postAsked"
                    @cancel="confirming = false"
                >
                    <p class="nc-ask">{{ askWho }}</p>
                    <p class="nc-ask">{{ askWhat }}</p>
                </ConsoleDialog>

                <!--
                  AUTHORED. The board draws a single-unit charge, so it has no list and no second press. Step two, in
                  the console's dialog (M95 §1; it opened as a panel under the form): the units the charge reaches, the
                  count and the total, and the press that bills all of them in one transaction or none of them. The
                  first lots by reference, then how many more (M11 A2): the full list is held on the server and is
                  exactly what the press posts.
                -->
                <ConsoleDialog
                    :open="bulkOpen && bulkPreview !== null"
                    :title="bulkPreview ? `Post ${bulkPreview.amount_label} to ${bulkPreview.count} unit${bulkPreview.count === 1 ? '' : 's'}?` : ''"
                    size="form"
                    :confirm-label="bulkPreview ? `Post to all ${bulkPreview.count} unit${bulkPreview.count === 1 ? '' : 's'}` : 'Post'"
                    busy-label="Posting…"
                    cancel-label="Not now"
                    :busy="confirmBulk.processing"
                    @confirm="postBulk"
                    @cancel="bulkOpen = false"
                >
                    <template v-if="bulkPreview">
                        <p class="nc-ask">
                            {{ bulkPreview.description }}: {{ bulkPreview.amount_label }} to each unit{{
                                bulkPreview.phase ? ` in ${bulkPreview.phase}` : ' on the register'
                            }}, {{ bulkPreview.total_label }} in total. Each gets its own charge, and all of them post
                            together or none does. Nothing has been posted yet.
                        </p>
                        <div class="bulk-list">
                            <span v-for="row in bulkPreview.units" :key="row.id" class="bulk-chip">{{ row.reference }}</span>
                            <span v-if="(bulkPreview.more ?? 0) > 0" class="bulk-more">
                                and {{ bulkPreview.more.toLocaleString('en-US') }} more: every unit{{
                                    bulkPreview.phase ? ` in ${bulkPreview.phase}` : ' on the register'
                                }} is on this charge.
                            </span>
                        </div>
                    </template>
                </ConsoleDialog>
            </form>

            <div class="preview-panel">
                <div class="preview-head">Ledger preview</div>

                <div style="background: var(--navy-100); border-radius: 12px; padding: 13px">
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            font-size: 12px;
                            color: var(--navy-900);
                            padding: 5px 0;
                        "
                    >
                        <span>Current balance</span>
                        <b>{{ preview(currentBalanceMinor) }}</b>
                    </div>
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            font-size: 12px;
                            color: var(--navy-900);
                            padding: 5px 0;
                        "
                    >
                        <span>+ New charge</span>
                        <b>{{ preview(amountMinor) }}</b>
                    </div>
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            font-size: 13px;
                            font-weight: 700;
                            color: var(--navy-900);
                            padding: 9px 0 0;
                            border-top: 1px solid var(--navy-200);
                            margin-top: 6px;
                        "
                    >
                        <span>New balance</span>
                        <b>{{ preview(currentBalanceMinor + amountMinor) }}</b>
                    </div>
                </div>

                <!--
                  The board's sentence, with its ageing clause made true.
                  It is conditional because the claim it makes is: this estate
                  ages a unit's WHOLE balance by its oldest open charge, so a
                  charge on a unit that already owes something joins that bucket
                  and cannot start a new one — but on a unit that owes nothing
                  there is no bucket to join, and the same sentence would be
                  telling a treasurer their clean unit is 30 days late.
                -->
                <div class="preview-note">
                    <template v-if="notifies">
                        {{ notifies }} will see this on the Dues statement and get a push notification.
                    </template>
                    <template v-else>
                        No resident is registered to {{ unit.reference }}, so nobody is notified. The charge
                        stands against the unit until one is.
                    </template>

                    <template v-if="currentBalanceMinor > 0">
                        It joins {{ unit.reference }}'s existing ageing bucket rather than starting a new one,
                        because a unit's whole balance is aged by its oldest open charge.
                    </template>
                    <template v-else>
                        {{ unit.reference }} owes nothing today, so this charge opens a current-bucket balance.
                    </template>
                </div>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only.
 *
 * The board draws every field as a <div class="m-input"><span>value</span></div>
 * and both the segments and the submit as <div>s. Here they are real controls,
 * and each arrives wearing the browser's own border, background, font and
 * spin buttons. Nothing below introduces a colour, size or weight: the control
 * sits INSIDE the board's own <span>, so the board's `.m-input span` rule
 * supplies its type and `font: inherit` picks it up.
 */
.m-input span {
    /* The board's span wraps a string and needs no width; this one wraps a
     * control that has to fill the pill. */
    flex: 1;
    min-width: 0;
}

.m-input input,
.m-input select {
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
    appearance: none;
    -webkit-appearance: none;
}

/* The board defines its own placeholder colour, on .m-input .placeholder. */
.m-input input::placeholder {
    color: var(--slate-500);
    opacity: 1;
}

/*
 * A date input draws its own calendar button, and the board already draws a
 * calendar in this field. Two would be the browser's decoration on top of the
 * design; the date stays fully typeable and the picker still opens from the
 * keyboard.
 */
.m-input input[type='date']::-webkit-calendar-picker-indicator {
    display: none;
}

/*
 * The focus ring is deliberately NOT removed here, unlike on the sibling form
 * pages. The board marks its active field with .m-input.focused, which is bound
 * to the unit having a value rather than to focus — so with the UA outline gone
 * as well, a keyboard user would have nothing at all telling them which of six
 * fields they are typing into.
 */

/* The family and line height only: `font: inherit` would reset the board's .seg-item size and weight (13 E1, as board 34). */
button.seg-item {
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

button.seg-item[disabled] {
    cursor: not-allowed;
    /* The board draws one weight of segment. Why the other two cannot be
     * pressed is on the title, not in a shade the design does not have. */
    opacity: 1;
}

button.stack-btn {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.stack-btn[disabled] {
    cursor: not-allowed;
}

/* Authored: the board draws one filled-in happy path and has no error state to
 * copy. Kept to the tokens the boards do define, and placed under its own field
 * rather than in a summary, so a refusal is read beside the value that caused
 * it. */
.field-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    margin-top: 5px;
    line-height: 1.45;
}

/* Why a role that may not post sees no Post charge (M96 level 3), in one line where the button would be. */
.nc-blocked {
    margin: 4px 0 0;
    font-size: 12px;
    line-height: 1.5;
    color: var(--slate-600);
}

/* The phase picker and the list, neither of which the board draws. */
.bulk-phase {
    display: flex;
    flex-direction: column;
    gap: 5px;
    margin-bottom: 14px;
}

.bulk-phase label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.bulk-phase select {
    height: 38px;
    border: 1px solid var(--navy-200);
    border-radius: 10px;
    background: var(--white);
    padding: 0 11px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.bulk-list {
    margin-top: 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    max-height: 150px;
    overflow-y: auto;
}

.bulk-chip {
    font-size: 10.5px;
    font-weight: 600;
    color: var(--navy-800);
    background: var(--white);
    border-radius: 7px;
    padding: 3px 7px;
    line-height: 1.5;
}

/* How many lots the chips above do not name (M11 A2): a sentence, not a chip. */
.bulk-more {
    font-size: 10.5px;
    color: var(--slate-500);
    padding: 3px 2px;
    line-height: 1.5;
}

/* Post charge's question (M62 B1): two short lines under its title, set close. */
.nc-ask {
    margin: 0;
}

.nc-ask + .nc-ask {
    margin-top: 6px;
}
</style>
