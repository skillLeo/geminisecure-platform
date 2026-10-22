<script setup>
import { computed, ref, watch } from 'vue'

/**
 * The signed-in person's avatar in a console shell (M12 §4.8): their profile picture when they have one, their two
 * initials when they do not — or when the picture cannot be loaded, so the shell is never left with a broken image.
 *
 * The box is the board's own (`sf-avatar` in the sidebar, `tp-avatar` in the top bar); the picture only fills it.
 */
const props = defineProps({
    user: { type: Object, default: null },
    /** This console's root: '' on Gemini and on an estate's own hostname, `/estate/{key}` locally. */
    root: { type: String, default: '' },
    /** The board's class for the box. */
    box: { type: String, required: true },
})

const failed = ref(false)
watch(() => props.user?.photo_version, () => { failed.value = false })

const initials = computed(() =>
    (props.user?.name ?? '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase(),
)

const src = computed(() => (props.user?.photo_version && !failed.value ? `${props.root}/account/photo?v=${props.user.photo_version}` : null))
</script>

<template>
    <div :class="[box, { 'has-photo': src }]">
        <img v-if="src" :src="src" alt="" class="console-avatar-img" @error="failed = true" />
        <template v-else>{{ initials }}</template>
    </div>
</template>

<style scoped>
.has-photo {
    overflow: hidden;
    padding: 0;
}

.console-avatar-img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: inherit;
}
</style>
