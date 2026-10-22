<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'

/**
 * Data & privacy — board screen community-admin-33.
 *
 * EVERY FIELD ON THIS SCREEN IS READ-ONLY, AND THE BOARD AGREES: it draws four values and not one editable input. The
 * board also drew a "Save changes" button, and a control nobody can ever use is not drawn at all (M96 level 3): one line
 * says what the page is instead.
 *
 * THE REASON IT IS READ-ONLY IS NOT "NOT BUILT YET". These four are policy the estate has been TOLD rather than policy it
 * sets: a seven-year retention period matching statutory record-keeping, which roles may export a resident list, what
 * Gemini receives under the security service grant, and how a resident asks for their own data. "Who can export
 * resident lists" is computed from the permission model, so it cannot say one thing while the matrix does another.
 *
 * THE GUARDS' CONSENT: ONE LINE OF ASSURANCE, NEVER A RECORD (Hassam, 12 Oct 2026). A guard's consent is employment
 * data between the guard and Gemini, and the estate is a different company: it may see where a guard is on duty at its
 * own site, which is operational, but not whether that guard agreed to a tracking policy. So the estate reads one line,
 * with no names and no dates, and only ever true (`ConsentAssurance`); the full record is the Gemini console's.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    sections: { type: Array, required: true },
    groups: { type: Array, required: true },
    policy_line: { type: String, required: true },
    /** "All guards on duty at this estate have agreed to position recording.", or what is true instead. */
    guard_consent: { type: String, required: true },
})

useWireframe('community-admin-09-data-privacy-add-resident-and-meetings')

// Nothing here is typed into, and nothing goes stale under the reader: a page of stated policy.
useLiveKind('form')

const state = useScreenState({
    rows: () => props.groups.length,
})

const retry = () => router.reload()
</script>

<template>
    <Head title="Settings" />

    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="2" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Settings is not part of your role’s access"
            body="Data and privacy is kept in Settings, and your role does not include Settings."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="Data and privacy could not be read"
            body="The estate database did not answer. Nothing was changed. Trying again is safe."
            action-label="Try again"
            @action="retry"
        />

        <div v-else class="settings-layout">
            <div class="settings-nav">
                <template v-for="section in sections" :key="section.key">
                    <div v-if="section.active" class="settings-nav-item active" aria-current="page">
                        {{ section.label }}
                    </div>
                    <Link v-else :href="section.href" class="settings-nav-item">
                        {{ section.label }}
                    </Link>
                </template>
            </div>

            <div class="pv-column">
                <div class="form-panel pv-panel">
                    <p class="pv-line">{{ policy_line }}</p>

                    <template v-for="group in groups" :key="group.title">
                        <div class="form-sec-head">{{ group.title }}</div>

                        <div v-for="field in group.fields" :key="field.key" class="m-field">
                            <label>{{ field.label }}</label>
                            <div class="m-input"><span>{{ field.value }}</span></div>
                        </div>
                    </template>
                </div>

                <!-- THE GUARDS' CONSENT: one line of assurance, no names and no dates; the record is Gemini's. -->
                <section class="form-panel pv-panel" aria-labelledby="pv-consent-head">
                    <div id="pv-consent-head" class="form-sec-head pv-first-head">Guard location sharing</div>
                    <p class="pv-assurance">{{ guard_consent }}</p>
                </section>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/* Default removal only: the board draws its nav items as <div>s; here they are anchors. */
a.settings-nav-item {
    text-decoration: none;
    display: block;
}

/* AUTHORED BELOW THIS LINE: the board draws no consent record and no line where its Save was. */

.pv-column {
    display: flex;
    flex-direction: column;
    gap: 16px;
    max-width: 640px;
    min-width: 0;
}

.pv-line {
    margin: 0 0 4px;
    padding: 9px 13px;
    border-radius: 10px;
    background: var(--navy-100);
    color: var(--navy-700);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.5;
}

.pv-first-head {
    margin-top: 0;
}

.pv-assurance {
    margin: 0;
    font-size: 12.5px;
    color: var(--navy-900);
    line-height: 1.55;
}

/*
 * ON A PHONE (checked at 390 px with the new shell, 12 Oct). Below 760 px the section list sits above the page as one
 * row that scrolls sideways, and the page takes the full width. Doubled classes outrank the board's own rules.
 */
@media (max-width: 760px) {
    .settings-layout.settings-layout {
        grid-template-columns: minmax(0, 1fr);
        gap: 14px;
    }

    .settings-nav.settings-nav {
        flex-direction: row;
        overflow-x: auto;
    }

    .settings-nav-item.settings-nav-item {
        flex: 0 0 auto;
        width: auto;
        white-space: nowrap;
    }
}
</style>
