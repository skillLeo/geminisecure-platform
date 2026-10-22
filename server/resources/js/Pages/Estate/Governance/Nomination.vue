<script setup>
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { nominationWord } from '../../../lib/electionWords.js'

/**
 * One nomination and the record behind its decision — board 10's "View" (12 §2,
 * Wave 4).
 *
 * NO BOARD DRAWS THIS SCREEN, and it is not a fidelity target. It is built in
 * board 10's own sheet so the list and the record behind a row read as one.
 *
 * THE SNAPSHOT IS WHAT WAS DECIDED AGAINST. The ageing and the balance are the
 * ones taken when the returning officer ruled, and they do not move when the
 * household pays next week — which is the only way a rejection can still be
 * explained to the member it is about.
 *
 * THE ARREARS FIGURE IS A LEDGER FACT. A role that cannot read Dues & ledger —
 * the Secretary, who runs the election — sees the ageing bucket board 10 already
 * prints in its badge, and a sentence saying where the figure is, not the figure.
 *
 * WHAT THE SYSTEM DOES NOT HOLD, IT SAYS IT DOES NOT HOLD. A proposer's signed
 * form and a seconder's written confirmation are paper this platform has never
 * received, so there is no slot drawn for them.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    nomination: { type: Object, required: true },
})

useWireframe('community-admin-03-elections-nominations-results-and-meetings')

const page = usePage()

/* Rooted the same way board 10's own page roots its links — see Nominations.vue. */
const listHref = computed(() => {
    const cut = page.url.indexOf('/governance')
    const root = cut === -1 ? '' : page.url.slice(0, cut)

    return `${root}/governance/elections/${props.nomination.year}/nominations`
})

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['election'] })
</script>

<template>
    <Head :title="`Nomination of ${nomination.name}`" />

    <EstateConsole :title="`Nomination of ${nomination.name}`" :estate-name="estate.name" active="governance" :back="{ href: listHref, label: 'Back to nominations' }">

        <div class="nm-card">
            <div class="nm-head">
                <div class="nm-avatar">{{ nomination.initials }}</div>
                <div>
                    <div class="nm-name">{{ nomination.name }}</div>
                    <div class="nm-sub">{{ nomination.sub }} &middot; {{ nomination.position }} &middot; {{ nomination.ballot }}</div>
                </div>
                <div class="nm-status" :class="nomination.status">{{ nominationWord(nomination.status, nomination.status_label) }}</div>
            </div>

            <dl class="nm-grid">
                <dt>Proposed by</dt>
                <dd>{{ nomination.nominator }}</dd>
                <dt>Seconded by</dt>
                <dd>{{ nomination.seconder }}</dd>
                <dt>Lodged</dt>
                <dd>{{ nomination.lodged_on ?? 'Not recorded' }}</dd>
                <dt>Decided</dt>
                <dd>
                    <template v-if="nomination.decided_at">
                        {{ nomination.decided_at }} by {{ nomination.decided_by ?? 'an unrecorded officer' }}
                    </template>
                    <template v-else>Not yet. This nomination is waiting on the returning officer.</template>
                </dd>
                <template v-if="nomination.reason">
                    <dt>Reason recorded</dt>
                    <dd>{{ nomination.reason }}</dd>
                </template>
            </dl>
        </div>

        <div v-if="nomination.snapshot" class="nm-card">
            <div class="nm-section">Eligibility, as it stood when this was decided</div>

            <dl class="nm-grid">
                <dt>Checked on</dt>
                <dd>{{ nomination.snapshot.checked_on }}</dd>
                <dt>Arrears ageing</dt>
                <dd>{{ nomination.snapshot.arrears_bucket }}</dd>
                <dt>Balance</dt>
                <dd v-if="nomination.snapshot.arrears">{{ nomination.snapshot.arrears }}</dd>
                <dd v-else-if="nomination.amounts_hidden" class="nm-muted">
                    The figure is on the unit's ledger, which your role does not read. The ageing above is what the
                    decision turned on.
                </dd>
                <dd v-else class="nm-muted">Not recorded</dd>
                <dt>Tenure</dt>
                <dd>{{ nomination.snapshot.tenure }}</dd>
            </dl>

            <p class="nm-note">
                These figures do not change if the household pays afterwards. They are what the decision was taken
                against, and they are kept so that it can be explained later.
            </p>
        </div>

        <p class="nm-note">
            The proposer's signed form and the seconder's written confirmation are paper this platform does not hold.
            The names above are as they were lodged.
        </p>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen. Kept to the tokens the boards define.
 */
.nm-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    padding: 16px 18px;
    margin-bottom: 14px;
    max-width: 760px;
}

.nm-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
}

.nm-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--navy-100);
    color: var(--navy-700);
    font-weight: 700;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
}

.nm-name {
    font-size: 15px;
    font-weight: 700;
    color: var(--navy-900);
}

.nm-sub {
    font-size: 11.5px;
    color: var(--slate-500);
}

.nm-status {
    margin-left: auto;
    font-size: 10.5px;
    font-weight: 700;
    border-radius: 20px;
    padding: 3px 10px;
    background: var(--navy-100);
    color: var(--navy-700);
}

.nm-status.approved {
    background: var(--green-100);
    color: var(--green-700);
}

/* Amber for every state someone still has to act on (Hassam's three weights, 11 Oct). */
.nm-status.pending,
.nm-status.more_info {
    background: var(--amber-100);
    color: var(--amber-700);
}

/* Rejected is muted red: serious, clearly not an emergency. Solid red belongs to an open panic alone. */
.nm-status.rejected {
    background: var(--red-100);
    color: var(--red-700);
}

.nm-section {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    margin-bottom: 10px;
}

.nm-grid {
    display: grid;
    grid-template-columns: 150px 1fr;
    gap: 7px 16px;
    margin: 0;
    font-size: 12px;
}

.nm-grid dt {
    color: var(--slate-500);
    font-weight: 700;
}

.nm-grid dd {
    margin: 0;
    color: var(--navy-900);
}

.nm-muted {
    color: var(--slate-500) !important;
}

.nm-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 10px 0 0;
    max-width: 760px;
}
</style>
