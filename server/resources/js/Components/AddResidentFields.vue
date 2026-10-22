<script setup>
import { usePage } from '@inertiajs/vue3'
import InfoButton from './InfoButton.vue'
import RequiredMark from './RequiredMark.vue'

/**
 * ADD RESIDENT'S FIELDS — board screen community-admin-34 — for its two hosts: the register's modal and the
 * `/residents/new` page (Hassam, 11 Oct: "Add resident ... must open in a modal"). The rules are `useAddResident()`;
 * this draws them. Each refusal sits under its own field, and what belongs to no field is said at the foot.
 *
 * THE BIOMETRIC CONSENT CHECKBOX IS AUTHORED, NOT ON THE BOARD (D-022). It ships off and nothing turns it on for a
 * person but that person: `Residents::enrolBiometrics()` refuses without it, and no estate setting grants it on
 * anybody's behalf.
 *
 * ITS OWN COPY OF THE BOARD'S FIELD STYLES. Board 34's sheet styles these fields on the page; the register wears board
 * 4's sheet, which has none of them, so the modal would have drawn bare browser fields. The rules below are the board's
 * own values.
 */
defineProps({
    /** What `useAddResident()` returns: the form and what it says about it. */
    add: { type: Object, required: true },
    /** The add-resident board: phases, verification, household_choices, biometric_consent, preview. */
    board: { type: Object, required: true },
    /** The modal draws the board's preview line under the fields; the page draws it in its own column. */
    withPreview: { type: Boolean, default: false },
})

const page = usePage()
</script>

<template>
    <!-- Which fields must be filled in, said once above the first and marked on each (Hassam, batch 1 item 3). -->
    <RequiredMark legend />
    <div class="form-sec-head">Unit</div>
    <div class="m-two-col">
        <div class="m-field">
            <label for="phase">Phase <RequiredMark /></label>
            <div class="m-input">
                <span>
                    <select id="phase" v-model="add.form.phase" required>
                        <option v-for="option in board.phases" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </span>
            </div>
            <div v-if="add.form.errors.phase" class="new-resident-error">{{ add.form.errors.phase }}</div>
        </div>

        <div class="m-field">
            <label for="lot">Lot number <RequiredMark /></label>
            <div class="m-input" :class="{ focused: add.form.lot !== '' }">
                <span>
                    <input
                        id="lot"
                        v-model="add.form.lot"
                        type="text"
                        required
                        maxlength="32"
                        list="lot-references"
                        autocomplete="off"
                        :placeholder="add.lotPlaceholder"
                    />
                    <datalist id="lot-references">
                        <option v-for="reference in add.phaseReferences" :key="reference" :value="reference" />
                    </datalist>
                </span>
            </div>
            <div v-if="add.form.errors.lot" class="new-resident-error">{{ add.form.errors.lot }}</div>
            <div v-else-if="add.unmatchedNote" class="new-resident-note">{{ add.unmatchedNote }}</div>
            <div v-if="add.moreNote" class="new-resident-note">{{ add.moreNote }}</div>
        </div>
    </div>

    <!-- M32 F1: an occupied lot asks before anybody is joined to the household on it. -->
    <div v-if="add.occupied" class="occupied-panel" role="group" aria-labelledby="occupied-head">
        <div id="occupied-head" class="form-sec-head">
            {{ add.occupied.unit }} already has {{ add.occupied.households.length === 1 ? 'a household' : `${add.occupied.households.length} households` }}
        </div>
        <div v-for="h in add.occupied.households" :key="h.id" class="occupied-household">
            <div class="occupied-line">
                <span class="occupied-name">{{ h.primary?.name ?? h.name }}</span>
                <span>Primary</span>
                <span v-if="h.primary?.email">{{ h.primary.email }}</span>
                <span v-if="h.sample" class="status-badge draft" title="Sample data.">sample</span>
            </div>
            <div class="occupied-others">(and {{ h.others }} other member{{ h.others === 1 ? '' : 's' }})</div>
        </div>

        <!-- M32 F12: what a primary and a member are, beside the question, outside the words that name the group. -->
        <div style="display: flex; align-items: center">
            <div id="occupied-question" class="occupied-question">What is this person? <RequiredMark /></div>
            <InfoButton topic="householdRoles" />
        </div>
        <div role="radiogroup" aria-labelledby="occupied-question">
            <label v-for="choice in board.household_choices" :key="choice.value" class="occupied-choice">
                <input v-model="add.form.household_choice" type="radio" name="household_choice" :value="choice.value" />
                <span>{{ choice.label }}</span>
            </label>
        </div>
        <template v-if="add.asksWhichHousehold">
            <div id="occupied-which" class="occupied-question">Which household? <RequiredMark /></div>
            <div role="radiogroup" aria-labelledby="occupied-which">
                <label v-for="h in add.occupied.households" :key="h.id" class="occupied-choice">
                    <input v-model="add.form.household_id" type="radio" name="household_id" :value="h.id" />
                    <span>{{ h.name }}<template v-if="h.primary"> · {{ h.primary.name }}</template></span>
                </label>
            </div>
        </template>
        <p class="occupied-balance">{{ add.occupied.balance_line }}</p>
        <div v-if="add.form.errors.household_choice" class="new-resident-error">{{ add.form.errors.household_choice }}</div>
        <div v-if="add.form.errors.household_id" class="new-resident-error">{{ add.form.errors.household_id }}</div>
    </div>

    <!-- "Resident", not "Primary resident": a second person at a home is filed as a member. -->
    <div class="form-sec-head">Resident</div>
    <div class="m-field">
        <label for="full_name">Full name <RequiredMark /></label>
        <div class="m-input" :class="{ focused: add.form.full_name !== '' }">
            <span>
                <input id="full_name" v-model="add.form.full_name" type="text" required maxlength="160" placeholder="e.g. Marcia Brown" />
            </span>
        </div>
        <div v-if="add.form.errors.full_name" class="new-resident-error">{{ add.form.errors.full_name }}</div>
    </div>

    <!-- M18C: phone and email are both asked for: a panic emails the household and the dispatcher dials the number. -->
    <div class="m-two-col">
        <div class="m-field">
            <!-- Required unless "no email" is ticked; the mark follows the tick. -->
            <label for="email">Email <RequiredMark v-if="!add.form.no_email" /></label>
            <div class="m-input">
                <span>
                    <input
                        id="email"
                        v-model="add.form.email"
                        type="email"
                        maxlength="190"
                        placeholder="e.g. marcia.brown@gmail.com"
                        :required="!add.form.no_email"
                        :disabled="add.form.no_email"
                    />
                </span>
            </div>
            <div v-if="add.form.errors.email" class="new-resident-error">{{ add.form.errors.email }}</div>
        </div>

        <div class="m-field">
            <label for="phone">Phone <RequiredMark /></label>
            <div class="m-input">
                <span>
                    <input
                        id="phone"
                        v-model="add.form.phone"
                        type="tel"
                        maxlength="40"
                        :placeholder="`e.g. ${page.props.phoneExample?.local ?? ''}`"
                        required
                    />
                </span>
            </div>
            <div v-if="add.form.errors.phone" class="new-resident-error">{{ add.form.errors.phone }}</div>
        </div>
    </div>
    <label class="new-resident-tick">
        <input v-model="add.form.no_email" type="checkbox" @change="add.form.no_email && (add.form.email = '')" />
        <span>This person has no email; alerts go to the primary resident instead</span>
    </label>

    <div class="form-sec-head">Verification</div>
    <div class="seg" role="radiogroup" aria-label="How this resident's identity is established">
        <button
            v-for="option in board.verification"
            :key="option.value"
            type="button"
            class="seg-item"
            :class="{ active: add.form.verification === option.value }"
            role="radio"
            :aria-checked="add.form.verification === option.value"
            @click="add.form.verification = option.value"
        >
            {{ option.label }}
        </button>
    </div>

    <label class="biometric-consent">
        <input v-model="add.form.biometric_consent" type="checkbox" />
        <span>{{ board.biometric_consent.label }}</span>
    </label>
    <p class="biometric-consent-note">{{ board.biometric_consent.note }}</p>

    <div v-for="[field, message] in add.otherRefusals" :key="field" class="new-resident-error">{{ message }}</div>

    <template v-if="withPreview">
        <div class="form-sec-head">{{ board.preview.head }}</div>
        <p class="add-preview-note">{{ add.previewText }}</p>
    </template>
</template>

<style scoped>
/*
 * THE BOARD'S FIELD RULES (community-admin-09's sheet, values unchanged), so the fields read the same over the register,
 * whose sheet has none of them.
 */
.form-sec-head {
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    color: var(--navy-700);
    font-weight: 600;
    margin: 18px 0 10px;
}

.form-sec-head:first-child {
    margin-top: 0;
}

.m-field {
    margin-bottom: 15px;
}

.m-field label {
    display: block;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--slate-600);
    margin-bottom: 6px;
}

.m-input {
    height: 44px;
    border-radius: 11px;
    background: var(--navy-100);
    display: flex;
    align-items: center;
    padding: 0 13px;
    border: 1.5px solid transparent;
}

.m-input.focused {
    border-color: var(--amber-500);
    background: var(--white);
    box-shadow: 0 0 0 3px rgba(255, 182, 39, 0.15);
}

.m-input span {
    flex: 1;
    min-width: 0;
    font-size: 13px;
    color: var(--navy-900);
    font-weight: 500;
}

.m-two-col {
    display: flex;
    gap: 12px;
}

.m-two-col .m-field {
    flex: 1;
}

.seg {
    display: flex;
    background: var(--navy-100);
    border-radius: 11px;
    padding: 4px;
    margin-bottom: 16px;
}

.seg-item {
    flex: 1;
    text-align: center;
    padding: 9px 4px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    color: var(--slate-600);
}

.seg-item.active {
    background: var(--navy-600);
    color: var(--white);
}

/*
 * Default-removal only (moved from the page): the board draws every field as a <div class="m-input"><span>value</span>
 * </div> and the verification choice as two <div class="seg-item">s. Here they are real controls, each arriving with
 * the browser's own border, background, font and (for the select) its own caret.
 */
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

.m-input input::placeholder {
    color: var(--slate-500);
    opacity: 1;
}

/* The family and line height only: the board's rule supplies the size and weight. */
button.seg-item {
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

button.seg-item.active {
    background: var(--navy-600);
}

/* Hassam's rule 20: saturated red is for panic alone, so a refused field is amber. */
.new-resident-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    margin-top: 5px;
    line-height: 1.45;
}

.new-resident-tick {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-top: 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    cursor: pointer;
}

/* A hint and not a refusal (the server decides), so the error's size in slate. */
.new-resident-note {
    font-size: 11.5px;
    color: var(--slate-500);
    margin-top: 5px;
    line-height: 1.45;
}

.biometric-consent {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--slate-600);
    margin: 4px 0 4px;
    cursor: pointer;
}

.biometric-consent input {
    margin-top: 2px;
}

.biometric-consent-note {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.55;
    margin: 0 0 15px;
}

/* M32 F1: the household already at the lot, and the question. A panel in the form's own border and type scale. */
.occupied-panel {
    border: 1px solid var(--navy-100);
    border-radius: 12px;
    padding: 12px 14px;
    margin: 4px 0 16px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.occupied-household {
    font-size: 12px;
    color: var(--text-body);
}

.occupied-line {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}

.occupied-name {
    font-weight: 700;
    color: var(--text-primary);
}

.occupied-others,
.occupied-balance {
    font-size: 11.5px;
    color: var(--text-body);
    line-height: 1.5;
    margin: 0;
}

.occupied-question {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary);
    margin-top: 6px;
}

.occupied-choice {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 12px;
    color: var(--text-body);
    line-height: 1.5;
    padding: 3px 0;
    cursor: pointer;
}

.occupied-choice input {
    margin-top: 3px;
}

.occupied-balance {
    font-weight: 600;
}

/* The board's preview line, under the fields in the modal (the page draws it in its own column). */
.add-preview-note {
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.6;
    margin: 0;
}

/* M32 F1: the `sample` chip; see the household page (`Show.vue`) for why it is here and not in app.css. */
.status-badge.draft {
    background: var(--navy-100);
    color: var(--navy-700);
}

/* In the modal on a phone, the fields one under another. */
@media (max-width: 640px) {
    .m-two-col {
        flex-direction: column;
        gap: 0;
    }
}
</style>
