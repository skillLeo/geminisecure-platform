<script setup>
import { computed, ref } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'
import { toast } from '../../../lib/toasts.js'

/**
 * ONE MANUAL JOURNAL — M84 §3.4, behind FEATURE_MANUAL_JOURNALS.
 *
 * A grid the way an accountant works: the account typed by its code (the list offers every open account that is not a
 * control account), Tab from field to field, and the difference between debits and credits always on screen. Save
 * keeps a draft, balanced or not. Post is the Treasurer's or the Community Super Admin's, only at a difference of zero,
 * and asks first, naming the total and the number of lines. Once posted, the journal is shown and never edited.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    draft: { type: Object, required: true },
    accounts: { type: Array, required: true },
    canEdit: { type: Boolean, required: true },
    canPost: { type: Boolean, required: true },
    editReason: { type: String, required: true },
})

useWireframe('community-admin-07-chart-of-accounts-vendors-bills-and-bank-rec')

const page = usePage()
const base = computed(() => page.url.split('/accounting')[0])

const amountText = (minor) => (minor ? (minor / 100).toFixed(2) : '')
/** "$1,500.00": two decimals, always. */
const money = (minor) => `${minor < 0 ? '-' : ''}$${(Math.abs(minor) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const form = useForm({
    date: props.draft.date,
    reference: props.draft.reference ?? '',
    memo: props.draft.memo ?? '',
    lines: props.draft.lines.map((line) => ({ account: line.account, debit: amountText(line.debit_minor), credit: amountText(line.credit_minor), unit: line.unit ?? '' })),
    bank_line: props.draft.bank_line_id,
})

const posted = computed(() => props.draft.status === 'posted')

/** A typed amount as minor units; anything that is not money counts as nought here (the server says so under it). */
const minor = (typed) => {
    const text = String(typed ?? '').replace(/[,$\s]/g, '')

    return /^\d{1,11}(\.\d{1,2})?$/.test(text) ? Math.round(Number(text) * 100) : 0
}

const debits = computed(() => form.lines.reduce((sum, line) => sum + minor(line.debit), 0))
const credits = computed(() => form.lines.reduce((sum, line) => sum + minor(line.credit), 0))
const difference = computed(() => debits.value - credits.value)
const filledLines = computed(() => form.lines.filter((line) => minor(line.debit) > 0 || minor(line.credit) > 0).length)

const addLine = () => form.lines.push({ account: '', debit: '', credit: '', unit: '' })
const removeLine = (i) => {
    if (form.lines.length > 2) {
        form.lines.splice(i, 1)
    }
}

/*
 * A REFUSAL OF A SAVE IS A MESSAGE TOP RIGHT THAT STAYS UNTIL CLOSED (M95 §2): a draft that posted meanwhile, pressed
 * with no dialog open. It was a red line above the form. A field's refusal still sits under its field, and a refused
 * post is said inside its own question (below).
 */
const refused = (errors, otherwise) => {
    if (errors.post) {
        toast(errors.post, { tone: 'error' })
    } else if (otherwise) {
        toast(Object.values(errors)[0] ?? otherwise, { tone: 'error' })
    }
}

const save = () => {
    // A refused save keeps every line typed: Inertia's post and put keep the page's state by default (core 3.7).
    const options = { preserveScroll: true, onError: (errors) => refused(errors, null) }

    if (props.draft.id) {
        form.put(`${base.value}/accounting/manual-journals/${props.draft.id}`, options)
    } else {
        form.post(`${base.value}/accounting/manual-journals`, options)
    }
}

/** M96 level 2: Remove waits for a third line, and the page says so, not only its tooltip. */
const removeBlocked = computed(() => (form.lines.length <= 2 ? 'Remove works once the journal has more than two lines.' : null))

/** Why Post will not act, in one line, or null when it will. */
const postBlocked = computed(() => {
    if (!props.draft.id) return 'Save the draft first.'
    if (form.isDirty) return 'Save your changes first.'
    if (filledLines.value < 2) return 'A journal needs at least two lines.'
    if (difference.value !== 0) return `Debits and credits differ by ${money(Math.abs(difference.value))}.`

    return null
})

/*
 * POSTING ASKS FIRST, IN THE CONSOLE'S MODAL (M95 §1): the question named the lines and the total in a box that opened
 * under the form. A post cannot be undone, so the question is the danger kind. A refusal is said inside it, in its
 * amber band, and the question stays open with every line as typed (Hassam, 12 October: at 390 px a toast behind an open
 * modal is hidden); it closes when the journal posts, or on Keep it as a draft.
 */
const confirming = ref(false)
const posting = ref(false)
const postError = ref(null)

const askPost = () => {
    postError.value = null
    confirming.value = true
}

const keepDraft = () => {
    confirming.value = false
    postError.value = null
}

const post = () => {
    postError.value = null
    router.post(
        `${base.value}/accounting/manual-journals/${props.draft.id}/post`,
        { confirm_total: (debits.value / 100).toFixed(2), confirm_lines: filledLines.value },
        {
            preserveScroll: true,
            onStart: () => {
                posting.value = true
            },
            onSuccess: () => {
                confirming.value = false
            },
            onError: (errors) => {
                postError.value = errors.post ?? Object.values(errors)[0] ?? 'The journal was not posted.'
            },
            onFinish: () => {
                posting.value = false
            },
        },
    )
}

// An edit page: never re-read under somebody's typing.
useLiveKind('form')
</script>

<template>
    <Head :title="posted ? `Journal ${draft.journal_reference}` : 'Manual journal'" />

    <!-- M95 §3: reached from the list by New journal or a row, so the way back to it, in words. -->
    <EstateConsole
        title="Accounting"
        :estate-name="estate.name"
        active="accounting"
        :back="{ href: `${base}/accounting/manual-journals`, label: 'Back to manual journals' }"
    >
        <h2 class="mj-title">{{ posted ? `Posted as ${draft.journal_reference}` : draft.id ? 'Manual journal (draft)' : 'New manual journal' }}</h2>

        <form @submit.prevent="save">
            <RequiredMark v-if="!posted && canEdit" legend />

            <div class="mj-head">
                <div class="mj-field">
                    <label for="mj-date">Date <RequiredMark /></label>
                    <input id="mj-date" v-model="form.date" type="date" :disabled="posted || !canEdit" required />
                    <div v-if="form.errors.date" class="mj-error">{{ form.errors.date }}</div>
                </div>
                <div class="mj-field">
                    <label for="mj-ref">Reference (optional)</label>
                    <input id="mj-ref" v-model="form.reference" type="text" maxlength="40" placeholder="e.g. Cheque 1042" :disabled="posted || !canEdit" />
                    <div v-if="form.errors.reference" class="mj-error">{{ form.errors.reference }}</div>
                </div>
                <div class="mj-field mj-field--wide">
                    <label for="mj-memo">Memo <RequiredMark /></label>
                    <input id="mj-memo" v-model="form.memo" type="text" maxlength="255" placeholder="e.g. Correct October insurance to the right account" :disabled="posted || !canEdit" required />
                    <div v-if="form.errors.memo" class="mj-error">{{ form.errors.memo }}</div>
                </div>
            </div>

            <datalist id="mj-accounts">
                <option v-for="account in accounts" :key="account.code" :value="account.code">{{ account.label }}</option>
            </datalist>

            <table class="data-table mj-grid">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th class="num-cell">Debit</th>
                        <th class="num-cell">Credit</th>
                        <th>Lot (optional)</th>
                        <th v-if="!posted && canEdit"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, i) in form.lines" :key="i">
                        <td>
                            <input v-model="line.account" list="mj-accounts" class="mj-account" :aria-label="`Account, line ${i + 1}`" placeholder="e.g. 5100":disabled="posted || !canEdit" />
                            <div v-if="form.errors[`lines.${i}.account`]" class="mj-error">{{ form.errors[`lines.${i}.account`] }}</div>
                        </td>
                        <td class="num-cell">
                            <input v-model="line.debit" inputmode="decimal" class="mj-amount" :aria-label="`Debit, line ${i + 1}`" placeholder="0.00" :disabled="posted || !canEdit" />
                            <div v-if="form.errors[`lines.${i}.debit`]" class="mj-error">{{ form.errors[`lines.${i}.debit`] }}</div>
                        </td>
                        <td class="num-cell">
                            <input v-model="line.credit" inputmode="decimal" class="mj-amount" :aria-label="`Credit, line ${i + 1}`" placeholder="0.00" :disabled="posted || !canEdit" />
                            <div v-if="form.errors[`lines.${i}.credit`]" class="mj-error">{{ form.errors[`lines.${i}.credit`] }}</div>
                        </td>
                        <td>
                            <input v-model="line.unit" class="mj-unit" :aria-label="`Lot, line ${i + 1}`" placeholder="e.g. 120":disabled="posted || !canEdit" />
                            <div v-if="form.errors[`lines.${i}.unit`]" class="mj-error">{{ form.errors[`lines.${i}.unit`] }}</div>
                        </td>
                        <td v-if="!posted && canEdit">
                            <button type="button" class="text-link-sm" :disabled="removeBlocked !== null" title="Remove this line." @click="removeLine(i)">Remove</button>
                        </td>
                    </tr>
                    <tr class="mj-totals">
                        <td>Totals</td>
                        <td class="num-cell">{{ money(debits) }}</td>
                        <td class="num-cell">{{ money(credits) }}</td>
                        <td :colspan="!posted && canEdit ? 2 : 1" :class="difference === 0 ? 'mj-balanced' : 'mj-unbalanced'">
                            {{ difference === 0 ? 'Balanced' : `Difference ${money(Math.abs(difference))}` }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- A refusal about the lines as a whole (none sent, too many), under the grid it is about. -->
            <div v-if="form.errors.lines" class="mj-error">{{ form.errors.lines }}</div>

            <!-- M96 level 2: why every Remove in the grid is greyed, under the grid, readable without hovering. -->
            <p v-if="!posted && canEdit && removeBlocked" class="mj-why mj-note">{{ removeBlocked }}</p>

            <!--
              M96 levels. A role that can never write a journal has no Save draft and no Add line (level 3), only why
              the form is read only; Remove and Post, blocked for now, say why on the page (level 2); a role that never
              posts has no Post at all.
            -->
            <div v-if="!posted" class="mj-actions">
                <template v-if="canEdit">
                    <button type="button" class="btn-outline-sm" title="Add a line." @click="addLine">Add line</button>
                    <button type="submit" class="btn-outline-sm" :disabled="form.processing" title="Save the draft.">
                        {{ form.processing ? 'Saving…' : 'Save draft' }}
                    </button>
                </template>
                <span v-else class="mj-why">{{ editReason }}</span>

                <template v-if="canPost">
                    <button type="button" class="btn-primary-sm" :disabled="postBlocked !== null || posting" title="Post this journal." @click="askPost">Post</button>
                    <span v-if="postBlocked" class="mj-why">{{ postBlocked }}</span>
                </template>
                <!-- Never this role's to post (level 3): no button, and who does, in the server's own words. -->
                <span v-else-if="canEdit" class="mj-why">Posting a journal is for the Treasurer or the Community Super Admin.</span>
            </div>
        </form>

        <ConsoleDialog
            :open="confirming"
            :title="`Post ${filledLines} lines totalling ${money(debits)}?`"
            tone="danger"
            confirm-label="Post the journal"
            busy-label="Posting…"
            cancel-label="Keep it as a draft"
            :busy="posting"
            :error="postError"
            @confirm="post"
            @cancel="keepDraft"
        >
            <p style="margin: 0">Once posted it cannot be changed. A mistake is corrected by posting a reversal.</p>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
.mj-title {
    margin: 0 0 12px;
    font-size: 18px;
    color: var(--text-primary);
}

/* Amber, not red: saturated red is kept for panic and life safety alone. */
.mj-error,
.mj-unbalanced {
    color: var(--amber-700);
}

.mj-error {
    margin-top: 4px;
    font-size: 11px;
    font-weight: 600;
}

.mj-head {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 12px;
}

.mj-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    font-weight: 600;
}

.mj-field--wide {
    flex: 1 1 320px;
}

.mj-account {
    width: 140px;
}

.mj-amount {
    width: 120px;
    text-align: right;
}

.mj-unit {
    width: 110px;
}

.mj-totals td {
    font-weight: 700;
}

.mj-balanced {
    color: var(--text-primary);
}

.mj-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin-top: 12px;
}

.mj-why {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-body);
}

.mj-note {
    margin: 6px 0 0;
}

/*
 * GREYED MEANS GREYED (Law 1), as the shared modal greys its own button: seen on the local dev server, a greyed Remove
 * stayed amber like a live link and a greyed Post kept its blue with a label too faint to read.
 */
button[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
}

button.btn-primary-sm[disabled] {
    color: var(--white);
}

button.text-link-sm[disabled] {
    color: var(--slate-500);
}

</style>
