<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ConsoleDialog from './ConsoleDialog.vue'
import RequiredMark from './RequiredMark.vue'

/**
 * AN ELECTION'S LIFE, BESIDE ITS CONTROL ROOM (the user, M56 §3). The control room's own button moves the election
 * forward; these are the acts beside it, drawn only at the stages that allow them (Law 1). "Stop" is never a button:
 * Pause nominations (the control room's close, reversible here by Reopen), Extend, and Cancel are three acts, named
 * plainly. The destructive ones sit apart and confirm by typing the election's name.
 */
const props = defineProps({
    /** `life` from the control room: id, title, stage, nominations, voted, published, archived, cancelled, dates. */
    life: { type: Object, required: true },
    /** `…/governance/ballots/{id}` in whichever shape this environment serves. */
    base: { type: String, required: true },
    canRun: { type: Boolean, default: false },
    canCertify: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
    certifyReason: { type: String, default: 'This needs the Super Admin, the President or the Vice President.' },
})

const emit = defineEmits(['dialog'])

const open = ref(null)
const close = () => {
    open.value = null

    // A refusal read is not standing in the band the next time a dialog opens.
    for (const form of [nominationsForm, reopenForm, votingForm, cancelForm, deleteForm, archiveForm, challengeForm]) {
        form.clearErrors()
    }
}

const stage = computed(() => props.life.stage)
const settled = computed(() => ['certified', 'cancelled'].includes(stage.value))

/*
 * Each act at the stage that allows it, AND for a role that may ever take it (M96 level 3: an act that is never this
 * role's is not drawn at all; the control room's stage chip says where the election is).
 */
const canExtendNominations = computed(() => stage.value === 'nominations_open' && props.canRun)
const canReopenNominations = computed(() => stage.value === 'nominations_closed' && props.canRun)
const canReopenVoting = computed(() => stage.value === 'tally' && props.canCertify)
const canCancel = computed(() => !settled.value && props.canCertify)
const canDelete = computed(() => stage.value === 'draft' && props.life.nominations === 0 && props.canDelete)
const canArchive = computed(() => props.life.published && !props.life.archived && props.canRun)
const canChallenge = computed(() => props.life.published && props.canRun)

const gentle = computed(() => canExtendNominations.value || canReopenNominations.value || canReopenVoting.value || canArchive.value || canChallenge.value)
const any = computed(() => gentle.value || canCancel.value || canDelete.value)

/*
 * A REFUSAL OF A DIALOG'S ACT IS IN THAT DIALOG'S AMBER BAND, AND NEVER A TOAST AS WELL (Hassam, 12 Oct, the final
 * refusal rule, D-235): the dialog stays open with what was typed, and the sentence stays until it is fixed. While one
 * is open the control room is told, and holds its own toast for the same words. No field here draws its own error, so
 * the band says the first.
 */
watch(open, (dialog) => emit('dialog', dialog !== null), { immediate: true })
onBeforeUnmount(() => emit('dialog', false))

const firstError = (form) => Object.values(form.errors)[0] ?? null

const done = (form) => ({ preserveScroll: true, onSuccess: () => { open.value = null; form.reset() } })
const named = (typed) => typed.trim().toLowerCase() === props.life.title.trim().toLowerCase()

const nominationsForm = useForm({ closes_at: props.life.nominations_close_local ?? props.life.default_close_local })
const reopenForm = useForm({ closes_at: props.life.default_close_local })
const votingForm = useForm({ closes_at: props.life.default_close_local, reason: '' })
const cancelForm = useForm({ reason: '', confirm_name: '' })
const deleteForm = useForm({ confirm_name: '' })
const archiveForm = useForm({})
const challengeForm = useForm({ grounds: '' })


const votedLine = computed(() =>
    props.life.voted === 0
        ? 'Nobody has voted yet.'
        : `${props.life.voted} household${props.life.voted === 1 ? ' has' : 's have'} voted. Every ballot is kept and counted for nothing, and they are told why.`
)
</script>

<template>
    <div v-if="any || life.cancelled" class="el-life">
        <p v-if="life.cancelled" class="el-note">
            Cancelled {{ life.cancelled.when }} by {{ life.cancelled.by }}. Reason: {{ life.cancelled.reason }}
        </p>
        <!-- A refusal of an act here is in its dialog's band (D-235); the control room holds its toast meanwhile. -->

        <div v-if="any" class="el-row">
            <button v-if="canExtendNominations" type="button" class="btn-outline-sm" title="Move the nominations close later. It never moves earlier." @click="open = 'extend-nominations'">
                <span>Extend nominations</span>
            </button>
            <button v-if="canReopenNominations" type="button" class="btn-outline-sm" title="Open nominations again, with when they now close." @click="open = 'reopen-nominations'">
                <span>Reopen nominations</span>
            </button>
            <button v-if="canReopenVoting" type="button" class="btn-outline-sm" title="Open the closed poll again, with a reason. Announced and on the record." @click="open = 'reopen-voting'">
                <span>Reopen voting</span>
            </button>
            <button v-if="canArchive" type="button" class="btn-outline-sm" title="Archive this election. Its result stays readable." @click="open = 'archive'">
                <span>Archive</span>
            </button>
            <button v-if="canChallenge" type="button" class="btn-outline-sm" title="Record a formal challenge against the published result. The result itself is never edited." @click="open = 'challenge'">
                <span>Record a challenge</span>
            </button>

            <template v-if="canCancel || canDelete">
                <!-- The divider sets the destructive acts apart from the others; with none before them it stands alone. -->
                <span v-if="gentle" class="el-divider" aria-hidden="true"></span>
                <button v-if="canCancel" type="button" class="el-danger" title="The election does not happen. A reason, and every ballot kept." @click="open = 'cancel'">
                    <span>Cancel election</span>
                </button>
                <button v-if="canDelete" type="button" class="el-danger" title="Delete this draft. Nobody has stood in it." @click="open = 'delete'">
                    <span>Delete draft</span>
                </button>
            </template>
        </div>

        <ConsoleDialog :open="open === 'extend-nominations'" title="Extend nominations" confirm-label="Extend" :busy="nominationsForm.processing" :error="firstError(nominationsForm)" @confirm="nominationsForm.post(`${base}/extend-nominations`, done(nominationsForm))" @cancel="close">
            <RequiredMark legend />
            <label class="el-label" for="el-nom-close">Nominations now close <RequiredMark /></label>
            <input id="el-nom-close" v-model="nominationsForm.closes_at" type="datetime-local" required :min="life.nominations_close_local ?? undefined" class="el-input" />
        </ConsoleDialog>

        <ConsoleDialog :open="open === 'reopen-nominations'" title="Reopen nominations" confirm-label="Reopen" :busy="reopenForm.processing" :error="firstError(reopenForm)" @confirm="reopenForm.post(`${base}/reopen-nominations`, done(reopenForm))" @cancel="close">
            <RequiredMark legend />
            <label class="el-label" for="el-reopen-close">Nominations close again <RequiredMark /></label>
            <input id="el-reopen-close" v-model="reopenForm.closes_at" type="datetime-local" required class="el-input" />
        </ConsoleDialog>

        <ConsoleDialog :open="open === 'reopen-voting'" title="Reopen voting" tone="danger" confirm-label="Reopen voting" :busy="votingForm.processing" :confirm-blocked-reason="votingForm.reason.trim().length < 3 ? 'Say why first.' : null" :error="firstError(votingForm)" @confirm="votingForm.post(`${base}/reopen-voting`, done(votingForm))" @cancel="close">
            <RequiredMark legend />
            <label class="el-label" for="el-vote-reason">Why it reopens (announced and on the record) <RequiredMark /></label>
            <textarea id="el-vote-reason" v-model="votingForm.reason" rows="3" maxlength="500" required class="el-input"></textarea>
            <label class="el-label" for="el-vote-close">Voting closes <RequiredMark /></label>
            <input id="el-vote-close" v-model="votingForm.closes_at" type="datetime-local" required class="el-input" />
            <p class="el-muted">Every ballot already cast stands. Households are told the poll is open again.</p>
        </ConsoleDialog>

        <ConsoleDialog :open="open === 'cancel'" title="Cancel the election" tone="danger" confirm-label="Cancel the election" cancel-label="Keep it" :busy="cancelForm.processing" :confirm-blocked-reason="cancelForm.reason.trim().length < 3 ? 'Say why first.' : !named(cancelForm.confirm_name) ? 'Type the election\'s name to confirm.' : null" :error="firstError(cancelForm)" @confirm="cancelForm.post(`${base}/cancel`, done(cancelForm))" @cancel="close">
            <RequiredMark legend />
            <label class="el-label" for="el-cancel-reason">Why <RequiredMark /></label>
            <textarea id="el-cancel-reason" v-model="cancelForm.reason" rows="3" maxlength="500" required class="el-input"></textarea>
            <label class="el-label" for="el-cancel-name">Type the election's name to confirm: {{ life.title }} <RequiredMark /></label>
            <input id="el-cancel-name" v-model="cancelForm.confirm_name" type="text" required class="el-input" autocomplete="off" />
            <p class="el-muted">{{ votedLine }} A cancelled election is never reopened or deleted.</p>
        </ConsoleDialog>

        <ConsoleDialog :open="open === 'delete'" title="Delete this draft" tone="danger" confirm-label="Delete the draft" cancel-label="Keep it" :busy="deleteForm.processing" :confirm-blocked-reason="!named(deleteForm.confirm_name) ? 'Type the election\'s name to confirm.' : null" :error="firstError(deleteForm)" @confirm="deleteForm.post(`${base}/delete`, done(deleteForm))" @cancel="close">
            <RequiredMark legend />
            <label class="el-label" for="el-delete-name">Type the election's name to confirm: {{ life.title }} <RequiredMark /></label>
            <input id="el-delete-name" v-model="deleteForm.confirm_name" type="text" required class="el-input" autocomplete="off" />
            <p class="el-muted">It is a draft with no nomination, so nobody has stood in it.</p>
        </ConsoleDialog>

        <ConsoleDialog :open="open === 'archive'" title="Archive the election" confirm-label="Archive" :busy="archiveForm.processing" :error="firstError(archiveForm)" @confirm="archiveForm.post(`${base}/archive`, done(archiveForm))" @cancel="close">
            <p class="el-muted">Its published result stays readable in the Resident App.</p>
        </ConsoleDialog>

        <ConsoleDialog :open="open === 'challenge'" title="Record a challenge" confirm-label="Record the challenge" :busy="challengeForm.processing" :confirm-blocked-reason="challengeForm.grounds.trim().length < 10 ? 'Write the grounds first.' : null" :error="firstError(challengeForm)" @confirm="challengeForm.post(`${base}/challenge`, done(challengeForm))" @cancel="close">
            <RequiredMark legend />
            <label class="el-label" for="el-grounds">Grounds: what is disputed, and why <RequiredMark /></label>
            <textarea id="el-grounds" v-model="challengeForm.grounds" rows="4" maxlength="2000" required class="el-input"></textarea>
            <p class="el-muted">The published result is never edited. The challenge is recorded against it, with your name.</p>
        </ConsoleDialog>
    </div>
</template>

<style scoped>
.el-life {
    margin: 0 0 16px;
}

.el-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.el-divider {
    width: 1px;
    height: 24px;
    background: var(--navy-200);
    margin: 0 6px;
}

.el-danger {
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    padding: 7px 12px;
    border-radius: 10px;
    background: var(--white);
    /* A destructive act in a red outline: serious, clearly not an emergency (Hassam's three weights, 11 Oct). */
    color: var(--red-700);
    border: 1px solid var(--red-700);
}

.el-danger[disabled],
button[disabled] {
    cursor: not-allowed;
    opacity: 0.55;
}

.el-note {
    font-size: 12px;
    color: var(--navy-900);
    margin: 0 0 8px;
}

.el-refusal {
    font-size: 12px;
    font-weight: 700;
    color: var(--amber-700);
    margin: 0 0 8px;
}

.el-label {
    display: block;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-900);
    margin: 10px 0 4px;
}

.el-input {
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

.el-muted {
    font-size: 11px;
    color: var(--slate-500);
}
</style>
