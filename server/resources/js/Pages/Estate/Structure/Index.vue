<script setup>
import { computed, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import InfoButton from '../../../Components/InfoButton.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { toast } from '../../../lib/toasts.js'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * Estate structure — board screen community-admin-03.
 *
 * EVERY FIGURE ON A CARD BUT TWO IS DERIVED. `Residents::structureBoard()` runs
 * one GROUP BY over `units` for the unit count, the occupied count, the vacant
 * count and the occupancy bar; only the block count and the officer count are
 * stored columns, because nothing else in this database can answer those. This
 * page does no arithmetic of its own — every number it prints already arrived
 * formatted (`tag`, `foot`, `occupancy_pct`), because a page that reached past
 * the payload to recompute one would be a second place for it to disagree.
 *
 * BOTH WRITES ARE BUILT (12 §2, Wave 1). Adding a phase decides its blocks and
 * its lot range together and creates every lot in one transaction. Importing a
 * unit list is two presses: a preview that validates every row and shows each
 * problem against its line, and a commit that is offered only when no row has
 * one — a bad file is rejected whole. All three affordances the board draws
 * (`Import CSV`, `Add phase`, `Add another phase`) sit on the same
 * `estate.estate_structure.create` gate, and the inert twin carries the reason.
 *
 * CONTENT RESIDUAL, RECORDED IN D-056 — Phase 5 draws 70 units, 78 occupied and
 * 0 vacant, which is arithmetic no estate can produce (occupied cannot exceed
 * the unit count). `EstateFinanceSeeder` clamps this estate's Phase 5 to 70 / 70
 * / 0, so this page reads 70 occupied and a 100% bar rather than the board's 78
 * — reproducing the seeded truth rather than the board's uncorrected figure, per
 * D-044.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** One card per phase, in ascending `sequence` — the board's own order. */
    phases: { type: Array, required: true },

    /*
     * `totals` and `unplaced_units` are declared and not rendered. Board 3 draws
     * no estate-wide total of its own — that KPI belongs to the dashboard, which
     * is what `structureBoard()`'s doc comment ties them to — and an undeclared
     * prop on this fragment's root would fall through as a stray HTML attribute
     * rather than be silently ignored. Phoenix Park's five cards sum to exactly
     * 450, so `unplaced_units` is 0 today; it exists so a card list that quietly
     * summed to less than the estate would have somewhere to say so, on the
     * screen that gets built to show it.
     */
    totals: { type: Object, required: true },
    unplaced_units: { type: Number, required: true },

    canCreate: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    /** The contract's units and the estate's (D-176), or null while there is no contract figure. */
    contract: { type: Object, default: null },
    /** M32 F2: the three numbers, the phases holding the units in use, whether a phase may be added at all, and the sentence. */
    standing: { type: Object, required: true },
    /** M32 F6: the lot numbers in use and free, the next free block for the blocks typed, and one line about a typed range. */
    lots: { type: Object, required: true },
    /** The previewed import between its two presses, or null. */
    preview: { type: Object, default: null },
})

/** A count of units as the card reads it: "2,000 units", "1 unit". */
const unitsText = (n) => `${Number(n).toLocaleString('en-US')} unit${Number(n) === 1 ? '' : 's'}`

/**
 * M32 F2: a full estate takes no new phase, and every control that would start one says why instead. M57.5 §4.1: nor
 * does an estate built from a site plan, whose phases and lots come from the plan (`standing.plan_refusal`). M78 §3:
 * the reason is one short line from the server (`standing.add_refusal`, "No lots left.").
 */
const mayAdd = computed(() => props.canCreate && !props.standing.full && !props.standing.plan_refusal)
const addTitle = computed(() => {
    if (!props.canCreate) {
        return props.blockedReason
    }

    return props.standing.add_refusal ?? 'Add a phase with its blocks and lots.'
})

/*
 * Which board's stylesheet this page wears. Boards 3, 4 and 38 all live in the
 * first Community Admin sheet, and naming the wrong one — or none — renders this
 * screen with no board CSS at all.
 */
useWireframe('community-admin-01-login-dashboard-structure-and-residents')

// A lot or a phase added, or a household moving in, re-reads the structure and its occupancy (M18H §7.2).
useLiveReload({ entities: ['structure', 'resident'] })

const page = usePage()

/**
 * Five of the six. This screen carries no search and no filter — the board
 * draws none, and none of the domain_needs imply one — so `empty-filtered`
 * cannot occur from real data; the composable still lets `?_state=` force it in
 * local, and it must still say something true when it does.
 */
const state = useScreenState({
    rows: () => props.phases.length,
})

const retry = () => router.reload()

/**
 * Where this console is rooted — everything before this screen's own `/estate`.
 *
 * THE LAST ONE, NOT THE FIRST. Production serves this screen at `/estate`; local
 * serves every estate under `/estate/{key}`, so this screen is
 * `/estate/{key}/estate` and the FIRST `/estate` is the estate prefix, not the
 * screen. Cut there, the root came out empty and "Add phase" and the import
 * posted to `/estate/phases` — a route that does not exist — on every estate
 * served locally. The query string (`?import=…`) is set aside first.
 */
const root = computed(() => {
    const path = page.url.split('?')[0]
    const cut = path.lastIndexOf('/estate')

    return cut === -1 ? '' : path.slice(0, cut)
})

/* ------------------------------------------------------------------ */
/* add phase */
/* ------------------------------------------------------------------ */

const adding = ref(false)

const phaseForm = useForm({
    name: '',
    block_count: 1,
    lot_from: '',
    lot_to: '',
    street: '',
})

phaseForm.transform((data) => ({
    ...data,
    lot_from: data.lot_from === '' ? null : Number(data.lot_from),
    lot_to: data.lot_to === '' ? null : Number(data.lot_to),
}))

/** What the range will create, read back before the press. */
const lotsPreview = computed(() => {
    const from = Number(phaseForm.lot_from)
    const to = Number(phaseForm.lot_to)

    if (phaseForm.lot_from === '' && phaseForm.lot_to === '') {
        return 'No lots yet. They can be imported under this phase later.'
    }

    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 1 || to < from) {
        return 'A lot range runs upward from 1, with both ends filled in.'
    }

    return `${to - from + 1} lot${to - from === 0 ? '' : 's'}, Lot ${from} to Lot ${to}, all vacant until a household is filed at each.`
})

/*
 * M32 F6: WHICH LOTS ARE FREE, ASKED AS THE FORM IS TYPED. The page sends the blocks and the two ends and the server
 * answers with the next free block and one line about the range (`EstateStructure::lotPanel`). An answer is shown
 * only while it is about the numbers on the screen: one that lands after another key was pressed is not.
 */
const ASK_AFTER_MS = 350
let asking = null

const askLots = () => {
    clearTimeout(asking)
    asking = setTimeout(() => {
        router.reload({
            only: ['lots'],
            data: {
                blocks: Number(phaseForm.block_count) || 1,
                from: phaseForm.lot_from === '' ? undefined : phaseForm.lot_from,
                to: phaseForm.lot_to === '' ? undefined : phaseForm.lot_to,
            },
            preserveScroll: true,
            preserveState: true,
        })
    }, ASK_AFTER_MS)
}

watch(() => [phaseForm.block_count, phaseForm.lot_from, phaseForm.lot_to], askLots)

/** The server's line about the typed range, while it is about the numbers on the screen. */
const rangeLine = computed(() => {
    const check = props.lots.check

    if (!check || phaseForm.lot_from === '' || phaseForm.lot_to === '') {
        return null
    }

    return Number(check.from) === Number(phaseForm.lot_from) && Number(check.to) === Number(phaseForm.lot_to) ? check : null
})

/** The suggestion, while it is for the blocks on the screen. */
const suggestion = computed(() => (Number(props.lots.blocks) === (Number(phaseForm.block_count) || 1) ? props.lots : null))

const useSuggested = () => {
    if (!suggestion.value?.suggested) {
        return
    }

    phaseForm.lot_from = String(suggestion.value.suggested.from)
    phaseForm.lot_to = String(suggestion.value.suggested.to)
}

/*
 * ADD PHASE, IMPORT AND THE IMPORT'S PREVIEW OPEN IN THE CONSOLE'S MODAL (M95 §1, M98 sweep). Each was a panel pushed
 * into the page above the phase cards. A refusal of a form in a modal is said in it, under its field; the import's own
 * refusal ("That preview has expired") is a toast that stays.
 */
const openAdding = () => {
    if (!mayAdd.value) {
        return
    }

    adding.value = true
    importing.value = false
    phaseForm.clearErrors()
}

const closeAdding = () => {
    adding.value = false
    phaseForm.reset()
    phaseForm.clearErrors()
}

const submitPhase = () => {
    if (!mayAdd.value || phaseForm.name.trim() === '') {
        return
    }

    phaseForm.post(`${root.value}/estate/phases`, {
        preserveScroll: true,
        onSuccess: () => closeAdding(),
    })
}

/* ------------------------------------------------------------------ */
/* import CSV: choose the file, preview it, then commit */
/* ------------------------------------------------------------------ */

const importing = ref(false)

const openImport = () => {
    if (!props.canCreate) {
        return
    }

    importing.value = true
    adding.value = false
    importForm.clearErrors()
}

const importForm = useForm({ file: null })

const fileChosen = (event) => {
    importForm.file = event.target.files?.[0] ?? null
}

/*
 * A REFUSAL OF AN OPEN DIALOG IS SAID INSIDE IT (Hassam, 12 Oct): the file dialog's band for a refused preview
 * (`importForm.errors.import`), the preview dialog's for a refused import; never a toast while a dialog is open. Only an
 * import whose preview has gone (it expired, so its dialog closed with it) is a toast: no dialog is open to hold it.
 */
const submitPreview = () => {
    if (!props.canCreate || importForm.file === null) {
        return
    }

    importForm.post(`${root.value}/estate/import/preview`, { forceFormData: true })
}

const committing = ref(false)
const commitRefusal = ref(null)

const commitImport = () => {
    if (!props.canCreate || !props.preview?.valid) {
        return
    }

    committing.value = true
    commitRefusal.value = null

    router.post(`${root.value}/estate/import/commit`, { token: props.preview.token }, {
        onError: (errors) => {
            const refusal = errors.import ?? Object.values(errors)[0] ?? 'Nothing was imported. Please try again.'

            if (props.preview !== null) {
                commitRefusal.value = refusal
            } else {
                toast(refusal, { tone: 'error' })
            }
        },
        onFinish: () => (committing.value = false),
    })
}

/** The preview modal's title: the file and how many rows it holds. */
const previewTitle = computed(() =>
    props.preview === null ? '' : `${props.preview.file}: ${props.preview.row_count} row${props.preview.row_count === 1 ? '' : 's'}`
)

const discardPreview = () => {
    router.visit(`${root.value}/estate`)
}
</script>

<template>
    <Head title="Estate structure" />

    <EstateConsole title="Estate structure" :estate-name="estate.name" active="estate_structure">
        <!--
          M96 levels (M98 pass): a role that can never change the estate's layout has no Import CSV, no Add phase and no
          add tile at all (level 3; they were greyed with the reason on hover only). A full estate, or one built from a
          site plan, keeps the greyed Add phase, and the add tile says why on the screen (level 2, M78 §3).
        -->
        <template #actions>
            <button
                v-if="canCreate"
                type="button"
                class="btn-outline-sm"
                :title="canCreate ? 'Import a unit list from a CSV file.' : blockedReason"
                @click="openImport"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path
                        d="M12 16V4m0 0L8 8m4-4l4 4M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
                <span>Import CSV</span>
            </button>

            <!-- M32 F2: on a full estate the button is off. M78 §3: the sentence that stood beside it is gone; the figures say it. -->
            <button
                v-if="canCreate"
                type="button"
                class="btn-primary-sm"
                :disabled="!mayAdd"
                :title="addTitle"
                @click="openAdding"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add phase</span>
            </button>
        </template>

        <!-- M32 F2: where the estate stands, before anybody starts. M78 §3: four figures in Hassam's words, and under them only what needs saying. -->
        <section class="str-panel" aria-label="Units allowed and in use">
            <dl class="str-figures">
                <div>
                    <dt>Allowed</dt>
                    <dd>{{ standing.contracted === null ? 'Not set' : unitsText(standing.contracted) }}</dd>
                </div>
                <div>
                    <dt>On the register</dt>
                    <dd>{{ unitsText(standing.register) }}</dd>
                </div>
                <!-- M57.5 §4.1: what the site plan has, said beside the figure allowed and never in place of it. -->
                <div v-if="standing.built !== null">
                    <dt>On the site plan</dt>
                    <dd>{{ unitsText(standing.built) }}</dd>
                </div>
                <div>
                    <dt>Left</dt>
                    <dd>{{ standing.left === null ? 'No limit' : unitsText(standing.left) }}</dd>
                </div>
            </dl>
            <p v-if="standing.sentence" class="str-standing-line">{{ standing.sentence }}</p>
            <!-- The lots off the site plan are not named here (Hassam, 12 Oct): the two figures above already differ. -->
        </section>

        <!-- Add a phase, in the console's modal (M95 §1): it was a panel pushed in above the phase cards. -->
        <ConsoleDialog
            :open="adding"
            title="Add a phase"
            size="form"
            confirm-label="Add phase"
            busy-label="Adding…"
            :busy="phaseForm.processing"
            :dirty="phaseForm.isDirty === true"
            discard-title="Discard this phase?"
            :confirm-blocked-reason="phaseForm.name.trim() === '' ? 'Name the phase.' : null"
            @confirm="submitPhase"
            @cancel="closeAdding"
        >
        <form class="str-modal" @submit.prevent="submitPhase">
            <RequiredMark legend />
            <div class="str-fields">
                <div class="str-field">
                    <label for="ph-name">Name <RequiredMark /></label>
                    <input id="ph-name" v-model="phaseForm.name" type="text" required maxlength="32" placeholder="e.g. Phase 6" />
                    <div v-if="phaseForm.errors.name" class="str-error">{{ phaseForm.errors.name }}</div>
                </div>
                <div class="str-field">
                    <label for="ph-blocks">Blocks <RequiredMark /></label>
                    <input id="ph-blocks" v-model.number="phaseForm.block_count" type="number" min="0" max="200" required />
                    <div v-if="phaseForm.errors.block_count" class="str-error">{{ phaseForm.errors.block_count }}</div>
                </div>
            </div>

            <!-- M32 F6: which lot numbers the estate has, read from it before a number is typed. -->
            <div class="str-lots" aria-label="This estate's lots">
                <!--
                  The lots ⓘ stays (every topic stands on a page, tests/js/info-buttons.test.mjs). Its words still name the
                  contracted units: infoTopics.js and InfoButton.vue are pinned with the classic Gemini pages
                  (ClassicWorkforcePagesAreUnchangedTest, never re-baselined, M81 §1.2.5), so the words wait on Hassam.
                  This form never opens on a plan-built estate such as Phoenix Park, where Add phase is off.
                -->
                <div class="str-head">This estate's lots <InfoButton topic="lotRanges" /></div>
                <dl class="str-lot-lines">
                    <div>
                        <dt>In use</dt>
                        <dd>{{ lots.used_label }}</dd>
                    </div>
                    <div>
                        <dt>Free</dt>
                        <dd>{{ lots.free_label }}</dd>
                    </div>
                    <div>
                        <dt>Suggested</dt>
                        <dd>
                            {{ suggestion ? suggestion.suggested_label : 'Working it out for these blocks…' }}
                            <button
                                v-if="suggestion?.suggested"
                                type="button"
                                class="btn-outline-sm str-use"
                                title="Put the suggested range in Lots from and to."
                                @click="useSuggested"
                            >
                                <span>Use these</span>
                            </button>
                        </dd>
                    </div>
                </dl>
                <p v-if="lots.others" class="str-note">{{ lots.others }}</p>
            </div>

            <div class="str-fields">
                <div class="str-field">
                    <label for="ph-from">Lots from (optional)</label>
                    <input id="ph-from" v-model="phaseForm.lot_from" type="number" min="1" placeholder="e.g. 451" />
                    <div v-if="phaseForm.errors.lot_from" class="str-error">{{ phaseForm.errors.lot_from }}</div>
                </div>
                <div class="str-field">
                    <label for="ph-to">to (optional)</label>
                    <input id="ph-to" v-model="phaseForm.lot_to" type="number" min="1" placeholder="e.g. 520" />
                    <div v-if="phaseForm.errors.lot_to" class="str-error">{{ phaseForm.errors.lot_to }}</div>
                </div>
                <div class="str-field str-field--wide">
                    <label for="ph-street">Street, where the lots have one: put on every lot in the range (optional)</label>
                    <input id="ph-street" v-model="phaseForm.street" type="text" maxlength="120" placeholder="e.g. Hillside Drive" />
                    <div v-if="phaseForm.errors.street" class="str-error">{{ phaseForm.errors.street }}</div>
                </div>
            </div>

            <!-- The range typed, said as it is typed: all free, taken by which phase, or past the contract. -->
            <p class="str-note" :class="{ 'str-line-bad': rangeLine && !rangeLine.ok }" aria-live="polite">{{ rangeLine ? rangeLine.line : lotsPreview }}</p>
        </form>
        </ConsoleDialog>

        <!-- Import a unit list, in the console's modal (M95 §1): the file first, then its preview. -->
        <ConsoleDialog
            :open="importing && preview === null"
            title="Import a unit list"
            size="form"
            confirm-label="Preview"
            busy-label="Checking…"
            :busy="importForm.processing"
            :confirm-blocked-reason="importForm.file === null ? 'Choose a CSV file.' : null"
            :error="importForm.errors.import ?? null"
            @confirm="submitPreview"
            @cancel="importing = false"
        >
            <form class="str-modal" @submit.prevent="submitPreview">
                <p class="str-note">
                    Columns: <strong>reference, phase, street, type, status</strong>. Reference and phase are required. Type is
                    residential or commercial; status is occupied or vacant.
                </p>
                <!-- M32 F6: the same lots, for lots added to a phase that already exists. -->
                <dl class="str-lot-lines">
                    <div>
                        <dt>In use</dt>
                        <dd>{{ lots.used_label }}</dd>
                    </div>
                    <div>
                        <dt>Free</dt>
                        <dd>{{ lots.free_label }}</dd>
                    </div>
                    <div v-if="standing.left !== null">
                        <dt>Room</dt>
                        <dd>{{ standing.sentence }}</dd>
                    </div>
                </dl>
                <RequiredMark legend />
                <div class="str-fields">
                    <div class="str-field str-field--wide">
                        <label for="imp-file">File <RequiredMark /></label>
                        <input id="imp-file" type="file" accept=".csv,text/csv" required @change="fileChosen" />
                    </div>
                </div>
                <div v-if="importForm.errors.file" class="str-error">{{ importForm.errors.file }}</div>
                <p class="str-note">Preview checks every row and shows it. Nothing is written yet.</p>
            </form>
        </ConsoleDialog>

        <!-- The import's preview, in the console's modal: Import writes it, Discard throws it away. -->
        <ConsoleDialog
            :open="preview !== null"
            :title="previewTitle"
            size="form"
            :confirm-label="preview ? `Import ${preview.row_count} unit${preview.row_count === 1 ? '' : 's'}` : 'Import'"
            busy-label="Importing…"
            cancel-label="Discard"
            :busy="committing"
            :confirm-blocked-reason="preview && !preview.valid ? 'The file has rows that fail. Fix it and preview it again: a list is imported whole or not at all.' : null"
            :error="commitRefusal"
            @confirm="commitImport"
            @cancel="discardPreview"
        >
        <div v-if="preview !== null" class="str-modal">
            <div class="str-head">
                <template v-if="preview.valid">Every row passes.</template>
                <template v-else>{{ preview.errors.length }} problem{{ preview.errors.length === 1 ? '' : 's' }}. Nothing will be imported until the file passes whole.</template>
            </div>

            <p v-if="preview.new_phases.length > 0" class="str-note">
                New phase{{ preview.new_phases.length === 1 ? '' : 's' }} the file names, created on import:
                {{ preview.new_phases.join(', ') }}.
            </p>

            <!--
              M25 §2.3, M27 §1.3: what Import will do to every phase, before it does it — every block of lots a phase has,
              its count, and the total — so it reads line by line against the map. The rows below stop at 200.
            -->
            <table v-if="(preview.by_phase ?? []).length > 0" class="data-table str-preview" aria-label="What the import will do, phase by phase">
                <thead>
                    <tr><th>Phase</th><th>Homes</th><th>Which lots</th><th>New or existing</th></tr>
                </thead>
                <tbody>
                    <tr v-for="phase in preview.by_phase" :key="phase.phase">
                        <td>{{ phase.phase }}</td>
                        <td>{{ phase.count.toLocaleString('en-US') }}</td>
                        <td>{{ phase.lots }}</td>
                        <td>{{ phase.new ? 'New phase' : 'Existing phase' }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th>Total</th>
                        <th>{{ preview.row_count.toLocaleString('en-US') }}</th>
                        <th colspan="2">
                            {{ preview.by_phase.length }} phase{{ preview.by_phase.length === 1 ? '' : 's' }}<template v-if="contract">
                                · after Import, {{ (contract.units + preview.row_count).toLocaleString('en-US') }} of {{ contract.contracted.toLocaleString('en-US') }} units allowed</template>
                        </th>
                    </tr>
                </tfoot>
            </table>

            <ul v-if="preview.errors.length > 0" class="str-errors">
                <li v-for="(problem, i) in preview.errors" :key="i">Line {{ problem.line }}: {{ problem.message }}</li>
            </ul>

            <table v-if="preview.rows.length > 0" class="data-table str-preview">
                <thead>
                    <tr><th>Line</th><th>Reference</th><th>Phase</th><th>Street</th><th>Type</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <tr v-for="row in preview.rows" :key="row.line">
                        <td>{{ row.line }}</td>
                        <td>{{ row.reference }}</td>
                        <td>{{ row.phase }}</td>
                        <td>{{ row.street ?? '' }}</td>
                        <td>{{ row.type }}</td>
                        <td>{{ row.status }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="preview.row_count > preview.rows.length" class="str-note">
                The first {{ preview.rows.length }} of {{ preview.row_count }} rows are shown. All {{ preview.row_count }} were checked.
            </p>
        </div>
        </ConsoleDialog>

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="2" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Estate structure is not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The estate's layout could not be read"
            body="Nothing has changed. Please try again."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="This estate has no phases yet"
            body="Add a phase above, or import the unit list."
        />

        <div v-else class="phase-grid str-phase-grid">
            <div v-for="phase in phases" :key="phase.id" class="phase-card">
                <div class="ph-top">
                    <div class="ph-name">{{ phase.name }}</div>
                    <div class="phase-tag">{{ phase.tag }}</div>
                </div>
                <!-- M57.5 §4.1: the phase's run of lots from the site plan. -->
                <div v-if="phase.lots" class="str-figure-note">{{ phase.lots }}</div>
                <div class="ph-stats">
                    <div class="ph-stat">
                        <div class="psv">{{ phase.occupied }}</div>
                        <div class="psl">OCCUPIED</div>
                    </div>
                    <div class="ph-stat">
                        <div class="psv">{{ phase.vacant }}</div>
                        <div class="psl">VACANT</div>
                    </div>
                </div>
                <div class="occ-bar"><i :style="{ width: phase.occupancy_pct + '%' }"></i></div>
                <div class="ph-foot">{{ phase.foot }}</div>
                <!-- M57.5 §4.3: the plan's blocks in this phase, each a letter in THIS phase, with its units and lots. -->
                <details v-if="phase.blocks && phase.blocks.length" class="str-blocks">
                    <summary>Show blocks</summary>
                    <ul>
                        <li v-for="block in phase.blocks" :key="block.letter">
                            Block {{ block.letter }}, {{ block.units }} unit{{ block.units === 1 ? '' : 's' }}: lots {{ block.lots }}
                        </li>
                    </ul>
                </details>
            </div>

            <!--
              The dashed placeholder. The board draws it as a bare div because a
              mockup has no disabled state to show; here it is the same control
              as the topbar's "Add phase", wrapped around the board's own icon
              and caption.
            -->
            <button
                v-if="canCreate"
                type="button"
                class="phase-card"
                style="
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border-style: dashed;
                    border-color: var(--navy-200);
                    background: none;
                "
                :disabled="!mayAdd"
                :title="addTitle"
                @click="openAdding"
            >
                <span style="display: flex; flex-direction: column; align-items: center; gap: 8px">
                    <span class="kpi-icon" style="width: 44px; height: 44px">
                        <svg viewBox="0 0 24 24" fill="none" style="width: 20px; height: 20px; display: block">
                            <path
                                d="M12 5v14M5 12h14"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />
                        </svg>
                    </span>
                    <span class="str-add-caption" style="font-size: 12px; color: var(--slate-500); font-weight: 700">Add another phase</span>
                    <!-- M78 §3: when nothing can be added the tile is visibly off and says why in one line; the figures above say the rest. -->
                    <span v-if="standing.add_refusal" style="font-size: 11px; color: var(--text-body); max-width: 220px">{{ standing.add_refusal }}</span>
                </span>
            </button>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its two topbar actions and its dashed placeholder as <div>s;
 * here they are three buttons, and a real <button> arrives wearing the
 * browser's own font, a border and buttonface grey. .btn-outline-sm and
 * .btn-primary-sm already supply everything visible for the first two.
 *
 * THE DASHED CARD IS THE ONE ELEMENT HERE THAT NEEDS A BORDER RESET NAMED
 * CAREFULLY: .phase-card DOES draw a border (1px solid navy-100) and the board
 * overrides it inline to a dashed navy-200 edge, which is reproduced on the
 * template above via the same inline style rather than removed here — see
 * D-045. Only the button chrome underneath that border is reset.
 */
button {
    font-family: inherit;
}

button.btn-outline-sm,
button.btn-primary-sm {
    border: 0;
    cursor: pointer;
}

button.phase-card {
    text-align: center;
    width: 100%;
    cursor: pointer;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a layout nobody is changing, so it
 * has no panel, no preview, no flash and no refusal. Kept to the tokens the
 * boards define and the shapes they already use.
 */
.str-flash,
.str-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.str-flash {
    background: var(--success-100);
    color: var(--success-700);
}

.str-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.str-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.str-panel form {
    display: flex;
    flex-direction: column;
    gap: 11px;
}

/* A form or the preview inside the console's modal (M95 §1): the panel's own rhythm, without a panel round it. */
.str-modal {
    display: flex;
    flex-direction: column;
    gap: 11px;
}

/* A two-line label (Street) no longer pushes its field below the others in its row (seen on the dev server). */
.str-modal .str-fields {
    align-items: end;
}

/* M96 level 2: a greyed Add phase looks greyed; the board's primary button had no disabled look, so it read as live. */
button.btn-primary-sm[disabled] {
    opacity: 0.55;
    box-shadow: none;
}

/* In the modal on a phone, the fields one under another; and the phase cards one under another (the board's three
   columns made the page wider than a phone, seen at 390 px on the dev server). */
@media (max-width: 640px) {
    .phase-grid.str-phase-grid {
        grid-template-columns: 1fr;
    }

    .str-fields {
        grid-template-columns: 1fr;
    }

    .str-field--wide {
        grid-column: auto;
    }
}

.str-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.5;
}

.str-fields {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 11px;
}

.str-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.str-field--wide {
    grid-column: span 2;
}

.str-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.str-field input {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

/*
 * The file chooser, whole (Hassam's demo, 11 Oct, held for this page's pass): the fixed 34px height clipped its
 * Choose File button. Its own height now, and the button's own padding.
 */
.str-field input[type='file'] {
    height: auto;
    min-height: 34px;
    padding: 4px 6px;
    line-height: normal;
}

.str-field input[type='file']::file-selector-button {
    padding: 5px 12px;
}

.str-note {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0;
    max-width: 820px;
}

/* Hassam's rule 20: saturated red is for panic alone; a refused field, a failing row and a taken range are amber. */
.str-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

.str-errors {
    margin: 0;
    padding: 10px 14px 10px 28px;
    background: var(--amber-100);
    color: var(--amber-700);
    border-radius: 10px;
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.6;
    max-height: 220px;
    overflow: auto;
}

.str-preview {
    max-height: 320px;
    display: block;
    overflow: auto;
}

.str-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

/* M32 F2: the contract's three numbers, in the panel's own shape. */
.str-figures {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 11px;
    margin: 0;
}

.str-figures dt,
.str-lot-lines dt {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--text-placeholder);
    line-height: 1.5;
}

.str-figures dd {
    margin: 2px 0 0;
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.4;
}

.str-figure-note {
    display: block;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--text-body);
}

.str-standing-line {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-body);
    line-height: 1.5;
    margin: 0;
}

/* M57.5 §4.3: a phase's blocks from the site plan, folded under its foot line. */
.str-blocks {
    margin-top: 8px;
    font-size: 11.5px;
    color: var(--text-body);
}

.str-blocks summary {
    cursor: pointer;
    font-weight: 600;
}

.str-blocks ul {
    margin: 6px 0 0;
    padding-left: 16px;
    line-height: 1.5;
}

/* M78 §3: the add tile, when nothing can be added, looks it; its one line of reason stays readable. */
button.phase-card[disabled] .kpi-icon,
button.phase-card[disabled] .str-add-caption {
    opacity: 0.45;
}

/* M32 F6: which lots are free, read before a number is typed. */
.str-lots {
    display: flex;
    flex-direction: column;
    gap: 6px;
    border-top: 1px solid var(--navy-100);
    border-bottom: 1px solid var(--navy-100);
    padding: 10px 0;
}

.str-lot-lines {
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.str-lot-lines > div {
    display: grid;
    grid-template-columns: 84px minmax(0, 1fr);
    gap: 10px;
    align-items: baseline;
}

.str-lot-lines dd {
    margin: 0;
    font-size: 12px;
    color: var(--text-body);
    line-height: 1.5;
}

button.str-use {
    margin-left: 8px;
    vertical-align: middle;
}

.str-line-bad {
    color: var(--amber-700);
    font-weight: 600;
}
</style>
