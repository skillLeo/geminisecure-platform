<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import BrandMark from '../Components/BrandMark.vue'

/**
 * Every error state, as a real page.
 *
 * Cross-cutting rule 13: a state must name what happened and offer the next
 * action. Laravel's bare "403 This action is unauthorized" does neither — it
 * leaves the reader with no explanation and, worse, no way out except editing
 * the URL.
 *
 * The 403 case matters most here. It is usually not a fault: it is the role
 * matrix working, and the reader is signed in as a role that legitimately
 * cannot see this module. Saying so, and naming the role, turns a dead end
 * into information.
 */
const props = defineProps({
    status: { type: Number, required: true },
})

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)

const COPY = {
    403: {
        title: 'Not available to your role',
        body: 'You are signed in, but this page is not part of what your role can see. It is not in your menu, so the link that brought you here was probably saved or shared. Ask your administrator if you need it.',
    },
    404: {
        title: 'Not found',
        body: 'There is nothing at this address. If you followed a link from inside the application, that is worth reporting.',
    },
    419: {
        title: 'Your session expired',
        body: 'You were away long enough that the page went stale. Signing in again will pick up where you left off.',
    },
    429: {
        title: 'Too many requests',
        body: 'Slow down for a moment and try again.',
    },
    500: {
        title: 'Something went wrong on our side',
        body: 'This is a fault, not something you did. It has been recorded.',
    },
    503: {
        title: 'Down for maintenance',
        body: 'The platform is briefly unavailable. Safety functions are unaffected.',
    },
}

const copy = computed(
    () => COPY[props.status] ?? { title: 'Unexpected error', body: 'Something did not work as expected.' },
)

/**
 * Where "back" should go depends on which console this account belongs to.
 * Sending an estate user to the Gemini dashboard produces another 403, which
 * is exactly the loop this page exists to break.
 */
const homeHref = computed(() => (user.value?.is_gemini_staff ? '/dashboard' : '/'))

const signOut = () => router.post('/logout')
</script>

<template>
    <Head :title="copy.title" />

    <div class="error-screen">
        <div class="error-card">
            <div class="error-brand">
                <BrandMark colourway="login" />
                <div>
                    <!-- M24 (D-223): the product's name for the people inside it; the company's for anybody else. -->
                    <div class="eb-title">{{ user ? 'GeminiSecure' : 'Gemini Security Limited' }}</div>
                    <div class="eb-status">Error {{ status }}</div>
                </div>
            </div>

            <h1>{{ copy.title }}</h1>
            <p class="error-body">{{ copy.body }}</p>

            <!--
              Naming the role turns a dead end into information: the reader can
              see immediately that this is the matrix working, not a fault.
            -->
            <div v-if="status === 403 && user" class="error-context">
                <div class="ec-row">
                    <span class="ec-label">Signed in as</span>
                    <span class="ec-value">{{ user.name }}</span>
                </div>
                <div class="ec-row">
                    <span class="ec-label">Role</span>
                    <span class="ec-value">{{ user.role_label }}</span>
                </div>
                <div class="ec-row">
                    <span class="ec-label">Console</span>
                    <span class="ec-value">{{ user.is_gemini_staff ? 'Gemini' : 'Estate' }}</span>
                </div>
            </div>

            <div class="error-actions">
                <Link v-if="user" :href="homeHref" class="btn-primary-sm">
                    <span>Back to your console</span>
                </Link>
                <Link v-else href="/login" class="btn-primary-sm">
                    <span>Sign in</span>
                </Link>

                <button v-if="user" type="button" class="btn-outline-sm" @click="signOut">
                    <span>Sign out</span>
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.error-screen {
    min-height: 100vh;
    background: linear-gradient(150deg, #2d1b69, #0b0a1f);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}

.error-card {
    width: 100%;
    max-width: 460px;
    background: var(--white);
    border-radius: 20px;
    padding: 32px;
}

.error-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 22px;
}

.error-brand :deep(.mark) {
    width: 34px;
    height: 34px;
    display: block;
}

.eb-title {
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    font-weight: 600;
    color: var(--navy-900);
}

.eb-status {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 10.5px;
    font-weight: 600;
    color: var(--slate-500);
}

h1 {
    font-family: 'Poppins', sans-serif;
    font-size: 21px;
    font-weight: 600;
    color: var(--navy-900);
    margin-bottom: 8px;
}

.error-body {
    font-size: 12.5px;
    color: var(--slate-600);
    line-height: 1.65;
    margin-bottom: 20px;
}

.error-context {
    background: var(--navy-100);
    border-radius: 12px;
    padding: 13px 15px;
    margin-bottom: 20px;
}

.ec-row {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    font-size: 12px;
}

.ec-row + .ec-row {
    margin-top: 6px;
}

.ec-label {
    color: var(--slate-500);
}

.ec-value {
    font-weight: 700;
    color: var(--navy-900);
}

.error-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.error-actions :deep(a),
.error-actions button {
    text-decoration: none;
}
</style>
