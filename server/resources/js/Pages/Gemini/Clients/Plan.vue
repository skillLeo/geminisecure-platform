<script setup>
import { computed } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import InfoButton from '../../../Components/InfoButton.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import { useLiveKind } from '../../../composables/useLiveKind'

// Changing a client's plan is a form: nothing may re-read it under the person's choices (M18H §7.2).
useLiveKind('form')

/**
 * Activate or change a client's plan — board screen super-admin-06.
 *
 * THE PROJECTION IS COMPUTED IN THE BROWSER, and that is deliberate: it has to
 * move as the tier, unit count and guard count change, or it is not a
 * projection but a caption. The arithmetic is the same the invoice uses —
 * units × per-unit price, plus guards × the per-guard rate — and the server
 * recomputes it from scratch when the form posts, so nothing is trusted from
 * here.
 *
 * ONE SCREEN, TWO ACTS. An onboarding client is being ACTIVATED and has never
 * billed, so the note says what it will cost once live. A live client is being
 * RE-PRICED, so it says what changes. Telling a live client's account manager
 * their bill starts from zero would be worse than saying nothing.
 *
 * NOTHING BILLS FROM HERE. Activating a plan sets what a client WILL be
 * charged; the act that starts charging is marking onboarding complete, which
 * lives on the client detail screen behind its own checklist. The note says so
 * rather than implying money starts moving on submit.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    plans: { type: Array, required: true },
    current: { type: Object, required: true },
    guard_rate_minor: { type: Number, required: true },
    currency: { type: String, required: true },
    /*
     * The platform's MRR, or absent for a reader confined to their assigned sites:
     * it is a sum over every client, and the server does not work it out for one
     * who may not read the others. The note below then says what it can without it.
     */
    platform_mrr: { type: String, default: null },
    action: { type: String, required: true },
    effective: { type: String, required: true },
})

const form = useForm({
    plan_id: props.current.plan_id ?? props.plans[props.plans.length - 1]?.id ?? null,
    units: props.current.units ?? '',
    guards: props.current.guards ?? 0,
    term_months: props.current.term_months ?? '',
    reason: '',
})

/*
 * M32 F2: A CHANGE TO THE CONTRACTED UNITS SAYS WHY. The number is the ceiling the estate's own console holds its
 * phases and lots to, so a change to it asks for the reason the audit log keeps — and only then, as the server does.
 */
const unitsChanging = computed(
    () => props.current.units !== null && props.current.units !== undefined && Number(form.units) !== Number(props.current.units),
)

const selectedPlan = computed(() => props.plans.find((p) => p.id === form.plan_id) ?? null)

/*
 * Money is formatted here for display only. Every figure is derived from minor
 * units, never from a parsed currency string, and the server recomputes the
 * lot on submit — so a wrong number here is a display bug, never a billing one.
 */
const money = (minor) =>
    new Intl.NumberFormat('en-JM', {
        style: 'currency',
        currency: props.currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100)

const units = computed(() => Number(form.units) || 0)
const guards = computed(() => Number(form.guards) || 0)

const subscriptionMinor = computed(() => units.value * (selectedPlan.value?.price_minor ?? 0))
const addOnMinor = computed(() => guards.value * props.guard_rate_minor)
const totalMinor = computed(() => subscriptionMinor.value + addOnMinor.value)

const submit = () => form.post(`/clients/${props.estate.id}/plan`, { preserveScroll: true })
</script>

<template>
    <Head :title="`${estate.name} — plan`" />

    <GeminiConsole
        :title="`${estate.name} · ${estate.onboarding ? 'activate plan' : 'change plan'}`"
        :back="{ href: `/clients/${estate.id}`, label: `Back to ${estate.name}` }"
    >

        <div class="form-layout">
            <form class="form-panel" @submit.prevent="submit">
                <RequiredMark legend />

                <div class="m-field">
                    <label id="plan-label">Select plan</label>
                </div>

                <!--
                  A radio group, not three divs. Choosing a tier is a single
                  choice among several, which is what a radio group IS — and it
                  gives arrow-key selection and a screen-reader announcement
                  that three click handlers would not.
                -->
                <div class="plan-select-row" role="radiogroup" aria-labelledby="plan-label">
                    <button
                        v-for="plan in plans"
                        :key="plan.id"
                        type="button"
                        role="radio"
                        :aria-checked="form.plan_id === plan.id"
                        class="plan-select"
                        :class="{ selected: form.plan_id === plan.id }"
                        @click="form.plan_id = plan.id"
                    >
                        <div class="ps1">{{ plan.name }}</div>
                        <div class="ps2">{{ plan.price }}</div>
                    </button>
                </div>
                <div v-if="form.errors.plan_id" class="field-error">{{ form.errors.plan_id }}</div>

                <div class="m-two-col">
                    <div class="m-field">
                        <!-- M32 F12: the contracted units are the estate's ceiling; the `i` says so, beside the label, outside it. -->
                        <div style="display: flex; align-items: center; margin-bottom: 6px">
                            <label for="units" style="margin-bottom: 0">Units <RequiredMark /></label>
                            <InfoButton topic="contractedLimit" />
                        </div>
                        <div class="m-input" :class="{ focused: form.units !== '' }">
                            <input id="units" v-model="form.units" type="number" min="1" required />
                        </div>
                        <div v-if="form.errors.units" class="field-error">{{ form.errors.units }}</div>
                    </div>

                    <div class="m-field">
                        <label for="guards">Guards to assign <RequiredMark /></label>
                        <div class="m-input">
                            <input id="guards" v-model="form.guards" type="number" min="0" required />
                        </div>
                        <div v-if="form.errors.guards" class="field-error">{{ form.errors.guards }}</div>
                    </div>
                </div>

                <div v-if="unitsChanging || form.errors.reason" class="m-field">
                    <label for="reason">Reason for the change <RequiredMark /></label>
                    <div class="m-input" :class="{ focused: form.reason !== '' }">
                        <input id="reason" v-model="form.reason" type="text" maxlength="300" required placeholder="e.g. Phase 6 signed with the committee, 300 more units" aria-describedby="reason-hint" />
                    </div>
                    <div id="reason-hint" class="plan-hint">The contracted units change from {{ Number(current.units).toLocaleString('en-US') }} to {{ Number(form.units || 0).toLocaleString('en-US') }}.</div>
                    <div v-if="form.errors.reason" class="field-error">{{ form.errors.reason }}</div>
                </div>

                <div class="m-field">
                    <label for="effective">Effective date</label>
                    <!--
                      Stated, not chosen. A mid-cycle re-price needs proration
                      nobody has specified, so the change lands on the first
                      full cycle and the field says which one rather than
                      offering a date the platform cannot honour.
                    -->
                    <div class="m-input">
                        <input
                            id="effective"
                            type="text"
                            :value="effective"
                            readonly
                            title="A plan change takes effect on the first full billing cycle. Mid-cycle changes would need proration, which is not built."
                        />
                    </div>
                </div>

                <div class="m-field">
                    <label for="term_months">Contract term</label>
                    <div class="m-input">
                        <select id="term_months" v-model="form.term_months">
                            <option value="">Monthly rolling — no committed term</option>
                            <option :value="12">12 months</option>
                            <option :value="24">24 months</option>
                            <option :value="36">36 months</option>
                        </select>
                    </div>
                    <div v-if="form.errors.term_months" class="field-error">{{ form.errors.term_months }}</div>
                </div>

                <button type="submit" class="stack-btn primary" style="width: 100%" :disabled="form.processing">
                    <BoardIcon name="check" :stroke="3" />
                    <span>{{ form.processing ? 'Saving…' : action }}</span>
                </button>
            </form>

            <div class="preview-panel">
                <div class="preview-head">Projected MRR</div>

                <!-- The board's own inline styles: its stylesheet defines no
                     class for this breakdown block. -->
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
                        <span>{{ selectedPlan?.name }} — {{ units }} units × {{ money(selectedPlan?.price_minor ?? 0) }}</span>
                        <b>{{ money(subscriptionMinor) }}</b>
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
                        <span>Security add-on — {{ guards }} × {{ money(guard_rate_minor) }}</span>
                        <b>{{ money(addOnMinor) }}</b>
                    </div>
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            font-size: 13px;
                            font-weight: 700;
                            color: var(--navy-900);
                            padding: 9px 0 0;
                            border-top: 1px solid var(--navy-600);
                            margin-top: 6px;
                        "
                    >
                        <span>Total MRR once active</span>
                        <b>{{ money(totalMinor) }}</b>
                    </div>
                </div>

                <div v-if="estate.onboarding" class="preview-note">
                    This won't bill until {{ estate.name }}'s onboarding is marked complete and the first full cycle
                    begins.
                    <template v-if="platform_mrr">
                        Platform MRR will update from {{ platform_mrr }} to {{ money(totalMinor) }} added at that
                        point.
                    </template>
                    <template v-else>{{ money(totalMinor) }} is added to what the platform bills at that point.</template>
                </div>
                <div v-else class="preview-note">
                    This replaces {{ estate.name }}'s current pricing from the first full billing cycle after the
                    change. Nothing is prorated and nothing is billed today.
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only.
 *
 * The board draws the tier options, the fields and the submit as <div>s. Here
 * they are radio buttons, inputs, a select and a button, each arriving with
 * the browser's own chrome. These rules take those defaults off so the board's
 * .plan-select, .m-input and .stack-btn rules are what is seen.
 */
button.plan-select {
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    text-align: inherit;
    cursor: pointer;
}

/* Written against the bare element so the board's own `.plan-select.selected`
 * border still wins once the scoped attribute is applied. */
.m-input input,
.m-input select {
    flex: 1;
    min-width: 0;
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
    appearance: none;
    -webkit-appearance: none;
}

/* Chrome paints spinners on a number input. The board draws none, and they
 * would sit outside the field's own padding. */
.m-input input[type='number']::-webkit-outer-spin-button,
.m-input input[type='number']::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.m-input input[readonly] {
    cursor: default;
}

button.stack-btn {
    border: 0;
    font: inherit;
    cursor: pointer;
}

/*
 * Field-level errors. The board draws one filled-in happy path and has no
 * error state to copy, so this is authored and kept to the tokens the boards
 * do define. It sits under its own field rather than in a summary, because a
 * message four fields from its cause is one the reader has to hunt for.
 */
/* What a field is about, one quiet line under it (not in its label). */
.plan-hint {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-body);
    margin-top: 6px;
}

.field-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    margin-top: 5px;
    line-height: 1.45;
}

</style>
