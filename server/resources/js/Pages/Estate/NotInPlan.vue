<script setup>
import { computed } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../Layouts/EstateConsole.vue'
import EmptyState from '../../Components/EmptyState.vue'
import { useWireframe } from '../../composables/useWireframe'
import { useLiveKind } from '../../composables/useLiveKind'

/**
 * A module this estate's plan leaves out, reached by its address (Hassam's closing order, 12 Oct 2026: "a deep link to
 * one shows a clean 'not included in your plan' page, never a stack trace").
 *
 * Drawn by `EnsureEstatePlanIncludes` in place of the module's page, with a 403. The sidebar no longer offers the
 * module (`EstateNavigation`, `PlanGate`), so whoever is here followed a bookmark or a link from before the plan
 * changed; the page says what the plan is, that the module is not in it, and who can change that, and takes them to
 * the dashboard. Nothing about the module itself is shown.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** The module, as the sidebar printed it: "Payroll & HR". */
    module: { type: String, required: true },
    /** The estate's plan, as Gemini Security names it: "Standard". Empty for a module that is switched off. */
    planName: { type: String, default: '' },
    /*
     * A module switched off on the platform for now (the audit log, `estate_features.audit_log`) rather than left out of
     * the plan: the same clean page, saying that instead (Hassam, 12 Oct: never the bare "Not found" page).
     */
    switchedOff: { type: Boolean, default: false },
})

/*
 * The estate boards' stylesheet, which the shell is drawn with: every estate page names a board, and a page that named
 * none drew the shell's own mark across the whole screen. The settings board, the one the plan is read on.
 */
useWireframe('community-admin-06-settings-estate-profile-users-and-roles')

/*
 * WHAT THIS PAGE IS, FOR THE LIVE LINE (LiveStatusCoverageTest): nothing on it goes stale, so it is no live page and no
 * report. Without a kind the shell would say "This screen does not update by itself" over a page with nothing to update.
 */
useLiveKind('form')

const page = usePage()

/** The dashboard, from the sidebar the shell was sent, so it is right on an estate's own hostname and locally. */
const dashboard = computed(() => (page.props.estateNav ?? []).find((item) => item.key === 'dashboard')?.href ?? '/')

const title = computed(() => (props.switchedOff ? `${props.module} is switched off` : `${props.module} is not included in your plan`))

const body = computed(() =>
    props.switchedOff
        ? `${props.module} is switched off for ${props.estate.name} for now. Gemini Security switches it on; nothing here has been changed or removed.`
        : `${props.estate.name} is on the ${props.planName} plan, which does not include ${props.module}. Gemini Security adds a module to a plan against the subscription; nothing here has been changed or removed.`,
)
</script>

<template>
    <Head :title="module" />

    <EstateConsole :title="module" :estate-name="estate.name">
        <EmptyState
            variant="denied"
            :title="title"
            :body="body"
            action-label="Go to the dashboard"
            @action="router.visit(dashboard)"
        />
    </EstateConsole>
</template>
