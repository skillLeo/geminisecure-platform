<script setup>
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import RecentEmails from '../../../Components/RecentEmails.vue'
import { useLiveKind } from '../../../composables/useLiveKind'

/*
 * A LOG, READ AS OF A MOMENT (M18H §7.2). Every email the platform sends is written to it; telling every open screen
 * about each one would double the work of every send, so the page says "As of …" and Refresh reads it again.
 */
useLiveKind('snapshot')

/**
 * EMAILS SENT (M18G §7) — for the Director and the Operations Manager, beside the access and audit log they both read.
 * Authored: no board draws it. "I never got the code" should take one screen, not a terminal.
 */
defineProps({
    sent: { type: Array, required: true },
    address: { type: String, default: '' },
    /** The log as the shared table reads it (M32 F5), a page at a time. */
    table: { type: Object, default: null },
})
</script>

<template>
    <Head title="Emails sent" />

    <GeminiConsole title="Access &amp; audit log" board="super-admin-08-access-and-audit-log">
        <Link href="/audit" class="text-link-sm">← Access &amp; audit log</Link>
        <RecentEmails :sent="sent" :address="address" :table="table" path="/audit/emails" />
    </GeminiConsole>
</template>
