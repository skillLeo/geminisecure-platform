<script setup>
import { Link } from '@inertiajs/vue3'

/**
 * The Billing tab strip — drawn identically on boards 32, 34 and 35.
 *
 * One component rather than three copies, because the strip is the piece of
 * these screens that must be pixel-identical across all of them: the same
 * three tabs, same order, same widths. A reader moving between the screens
 * sees a fixed thing that does not move, and three copies is three places for
 * one to drift.
 *
 * Text sits tight against its tags on purpose: the board's tab is a <div>
 * whose only child is the label, and a stray space either side would widen it.
 */
defineProps({
    active: { type: String, required: true },
})
</script>

<template>
    <div class="subnav">
        <Link href="/billing" class="subnav-item" :class="{ active: active === 'invoices' }">Invoices</Link>
        <Link href="/billing/plans" class="subnav-item" :class="{ active: active === 'plans' }">Plans</Link>
        <Link
            href="/billing/payment-methods"
            class="subnav-item"
            :class="{ active: active === 'payment-methods' }"
        >Payment methods</Link>
    </div>
</template>

<style scoped>
/*
 * The board draws all three tabs as <div>. They are links here, so the UA's
 * underline would show through. .subnav-item states everything else — size,
 * weight, colour, padding, radius — and the board's reset already zeroes
 * margin, so this is the only declaration needed.
 */
a.subnav-item {
    text-decoration: none;
}
</style>
