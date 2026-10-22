<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { Link } from '@inertiajs/vue3'

/**
 * A ROW'S ACTIONS, IN ONE MENU (the user, M56 §4). Everything that can be done to the row now, and nothing else (Law 1:
 * what cannot be done is not drawn): the ordinary acts first, the destructive ones apart after a divider and in red.
 * Each item opens the row's own page with that act's dialog already open, where it confirms, says how many households
 * it tells and, for a destructive act, asks for the name typed. The routes refuse whatever the stage does not allow.
 * An item with `method: 'post'` is an act that tells nobody and needs no question ("Move to 16 Oct", M70 §1.1): it posts
 * from the menu itself.
 */
const props = defineProps({
    /** What the menu is for, read out with the button: "Actions for AGM 2026". */
    label: { type: String, required: true },
    /** `{ key, label, href, title, danger, method? }`, in the order drawn. */
    items: { type: Array, required: true },
})

/** A posting item is a button, as Inertia's Link draws one; every other item is a link. */
const linkProps = (item) =>
    item.method && item.method !== 'get' ? { method: item.method, as: 'button', type: 'button', preserveScroll: true } : {}

const open = ref(false)
const root = ref(null)

const ordinary = computed(() => props.items.filter((item) => !item.danger))
const destructive = computed(() => props.items.filter((item) => item.danger))

const close = () => {
    open.value = false
}

const onOutside = (event) => {
    if (root.value && !root.value.contains(event.target)) {
        close()
    }
}

const onKey = (event) => {
    if (event.key === 'Escape') {
        close()
    }
}

const toggle = () => {
    open.value = !open.value

    if (open.value) {
        document.addEventListener('mousedown', onOutside)
        document.addEventListener('keydown', onKey)
    } else {
        document.removeEventListener('mousedown', onOutside)
        document.removeEventListener('keydown', onKey)
    }
}

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onOutside)
    document.removeEventListener('keydown', onKey)
})
</script>

<template>
    <div v-if="items.length" ref="root" class="row-menu">
        <button type="button" class="row-menu-button" :aria-label="`Actions for ${label}`" :aria-expanded="open" aria-haspopup="menu" :title="`What can be done to ${label} now`" @click="toggle">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
        </button>
        <div v-if="open" class="row-menu-list" role="menu">
            <Link v-for="item in ordinary" :key="item.key" :href="item.href" v-bind="linkProps(item)" class="row-menu-item" role="menuitem" :title="item.title" @click="close">
                {{ item.label }}
            </Link>
            <template v-if="destructive.length">
                <div v-if="ordinary.length" class="row-menu-divider" role="separator"></div>
                <Link v-for="item in destructive" :key="item.key" :href="item.href" v-bind="linkProps(item)" class="row-menu-item danger" role="menuitem" :title="item.title" @click="close">
                    {{ item.label }}
                </Link>
            </template>
        </div>
    </div>
</template>

<style scoped>
.row-menu {
    position: relative;
    display: inline-block;
}
.row-menu-button {
    width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--navy-100, #dbe3f0);
    border-radius: 8px;
    background: var(--white, #fff);
    color: var(--navy-700, #1e3a5f);
    cursor: pointer;
}
.row-menu-button svg {
    width: 16px;
    height: 16px;
}
.row-menu-button:hover,
.row-menu-button[aria-expanded='true'] {
    background: var(--navy-50, #f1f5fb);
}
.row-menu-list {
    position: absolute;
    right: 0;
    top: calc(100% + 4px);
    z-index: 30;
    min-width: 200px;
    padding: 6px;
    background: var(--white, #fff);
    border: 1px solid var(--navy-100, #dbe3f0);
    border-radius: 12px;
    box-shadow: 0 12px 28px rgba(15, 35, 70, 0.14);
}
.row-menu-item {
    display: block;
    width: 100%;
    border: 0;
    background: none;
    font: inherit;
    text-align: left;
    cursor: pointer;
    padding: 8px 10px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--navy-800, #13294b);
    text-decoration: none;
    white-space: nowrap;
}
.row-menu-item:hover,
.row-menu-item:focus-visible {
    background: var(--navy-50, #f1f5fb);
}
.row-menu-item.danger {
    color: var(--red-600, #b91c1c);
}
.row-menu-item.danger:hover,
.row-menu-item.danger:focus-visible {
    background: rgba(185, 28, 28, 0.08);
}
.row-menu-divider {
    height: 1px;
    margin: 6px 4px;
    background: var(--navy-100, #dbe3f0);
}
</style>
