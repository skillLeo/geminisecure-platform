<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ConsoleDialog from './ConsoleDialog.vue'
import RequiredMark from './RequiredMark.vue'

/**
 * ADD A CANDIDATE (the user, M56 §3.3): "the person (searched from residents), the position, the nominator, the
 * seconder, and a statement." Lodged pending, and decided on the nominations screen like any other nomination. Each
 * person is chosen from the estate's verified residents by name or lot, so a name is never typed twice two ways.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    /** The ballots taking nominations now, with their seats: [{ ballot_id, title, positions: [{ id, name }] }]. */
    ballots: { type: Array, required: true },
    /** `…/governance` in whichever shape this environment serves. */
    root: { type: String, required: true },
})

const emit = defineEmits(['close'])

const firstSeat = () => (props.ballots[0]?.positions[0] ? `${props.ballots[0].ballot_id}:${props.ballots[0].positions[0].id}` : '')

const seat = ref(firstSeat())

const form = useForm({ position_id: null, candidate_id: null, nominator_id: null, seconder_id: null, statement: '' })

/* ---- the three pickers: who, searched by name or lot ---- */
const roles = [
    { key: 'candidate', label: 'Candidate' },
    { key: 'nominator', label: 'Proposed by' },
    { key: 'seconder', label: 'Seconded by' },
]

const pick = reactive({
    candidate: { q: '', results: [], chosen: null },
    nominator: { q: '', results: [], chosen: null },
    seconder: { q: '', results: [], chosen: null },
})

const timers = {}

const search = (key) => {
    const entry = pick[key]
    entry.chosen = null
    clearTimeout(timers[key])

    if (entry.q.trim().length < 2) {
        entry.results = []

        return
    }

    timers[key] = setTimeout(async () => {
        try {
            const response = await fetch(`${props.root}/residents/search?q=${encodeURIComponent(entry.q.trim())}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            entry.results = response.ok ? (await response.json()).items : []
        } catch {
            entry.results = []
        }
    }, 250)
}

const choose = (key, person) => {
    pick[key].chosen = person
    pick[key].q = `${person.name} · ${person.lot}`
    pick[key].results = []
}

const missing = computed(() => {
    if (seat.value === '') {
        return 'Choose the position.'
    }

    const unchosen = roles.find((role) => pick[role.key].chosen === null)

    if (unchosen) {
        return `Choose ${unchosen.key === 'candidate' ? 'the candidate' : unchosen.key === 'nominator' ? 'who proposes them' : 'who seconds them'} from the residents.`
    }

    const ids = roles.map((role) => pick[role.key].chosen.id)

    return new Set(ids).size === 3 ? null : 'The candidate, the proposer and the seconder are three different people.'
})

const ballotOf = computed(() => Number(seat.value.split(':')[0] ?? 0))

const submit = () => {
    if (missing.value !== null) {
        return
    }

    form.position_id = Number(seat.value.split(':')[1])
    form.candidate_id = pick.candidate.chosen.id
    form.nominator_id = pick.nominator.chosen.id
    form.seconder_id = pick.seconder.chosen.id

    form.post(`${props.root}/ballots/${ballotOf.value}/nominations`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            roles.forEach((role) => Object.assign(pick[role.key], { q: '', results: [], chosen: null }))
            emit('close')
        },
    })
}

/*
 * A refusal is in the dialog's amber band, never a toast as well (Hassam, 12 Oct, the final refusal rule, D-235): the
 * dialog keeps the people picked, and the sentence stays until it is put right.
 */
const refusal = computed(() => form.errors.nomination ?? Object.values(form.errors)[0] ?? null)

watch(() => props.open, (now) => {
    if (now && seat.value === '') {
        seat.value = firstSeat()
    }
})
</script>

<template>
    <ConsoleDialog
        :open="open"
        title="Add a candidate"
        confirm-label="Add the candidate"
        :busy="form.processing"
        :confirm-blocked-reason="missing"
        :error="refusal"
        @confirm="submit"
        @cancel="emit('close')"
    >
        <!-- What the mark means, once, above the first field (batch 1 item 3). -->
        <RequiredMark legend />
        <label class="ac-label" for="ac-seat">Position <RequiredMark /></label>
        <select id="ac-seat" v-model="seat" class="ac-input" required>
            <optgroup v-for="ballot in ballots" :key="ballot.ballot_id" :label="ballot.title">
                <option v-for="position in ballot.positions" :key="position.id" :value="`${ballot.ballot_id}:${position.id}`">{{ position.name }}</option>
            </optgroup>
        </select>

        <div v-for="role in roles" :key="role.key" class="ac-picker">
            <label class="ac-label" :for="`ac-${role.key}`">{{ role.label }} <RequiredMark /></label>
            <input :id="`ac-${role.key}`" v-model="pick[role.key].q" type="text" class="ac-input" required placeholder="e.g. a name, or Lot 12" autocomplete="off" @input="search(role.key)" />
            <ul v-if="pick[role.key].results.length" class="ac-results" role="listbox">
                <li v-for="person in pick[role.key].results" :key="person.id">
                    <button type="button" class="ac-result" @click="choose(role.key, person)">
                        {{ person.name }} <span class="ac-muted">· {{ person.lot }}{{ person.phase ? ` · ${person.phase}` : '' }}</span>
                    </button>
                </li>
            </ul>
        </div>

        <label class="ac-label" for="ac-statement">The candidate's statement (optional)</label>
        <textarea id="ac-statement" v-model="form.statement" rows="3" maxlength="1000" class="ac-input"></textarea>
        <p class="ac-muted">The nomination is added as pending. Approve or reject it on this screen, as any other.</p>
    </ConsoleDialog>
</template>

<style scoped>
.ac-label {
    display: block;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-900);
    margin: 10px 0 4px;
}

.ac-input {
    width: 100%;
    box-sizing: border-box;
    font: inherit;
    font-size: 12.5px;
    border: 1px solid var(--navy-200);
    border-radius: 10px;
    padding: 8px 10px;
    color: var(--navy-900);
    background: var(--white);
}

.ac-picker {
    position: relative;
}

.ac-results {
    list-style: none;
    margin: 4px 0 0;
    padding: 4px;
    border: 1px solid var(--navy-100);
    border-radius: 10px;
    background: var(--white);
    max-height: 180px;
    overflow-y: auto;
}

.ac-result {
    width: 100%;
    text-align: left;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
    background: none;
    border: 0;
    padding: 6px 8px;
    border-radius: 8px;
    cursor: pointer;
}

.ac-result:hover,
.ac-result:focus {
    background: var(--navy-100);
}

.ac-muted {
    font-size: 11px;
    color: var(--slate-500);
}
</style>
