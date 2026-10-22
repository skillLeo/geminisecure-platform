<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'

/**
 * The consoles' one dialog: every confirmation, every "say why" that used to be a browser prompt (24 September 2026),
 * and since M95 every form that used to open on the page under the button that asked for it.
 *
 * WHY NOT window.confirm / window.prompt. The browser's own box says
 * "geminisl.com says", cannot carry the console's type or colours, takes free
 * text where a choice belongs, and cannot show the server's refusal: a prompt
 * that is refused simply vanishes and the page looks as though nothing happened.
 * The client called it unprofessional, and it was also unsafe on a life-safety
 * screen. `ResolveClassificationTest` fails the build if one comes back.
 *
 * WHY FORMS TOO (M95 §1, Hassam, 11 Oct). A form that appeared above or below its list made the page longer and
 * announced nothing: on a tall form nobody could see that anything had happened, and it read as a button that did
 * nothing. One component, written once: nobody writes a second modal.
 *
 * WHAT IT PROMISES
 *   - It opens OVER the page, centred, so it can never render below the fold.
 *   - Focus moves into it (the first field, else Cancel), Tab stays inside it, and focus returns to the button that
 *     opened it when it closes.
 *   - Esc, the backdrop and the corner close control CANCEL; they never confirm.
 *   - `dirty`: if anything has been typed, closing asks first ("Discard this bill?") and nothing typed is ever lost
 *     silently. Esc or the backdrop while it asks go back to the form.
 *   - The title says what it does; the buttons sit at the bottom, always in view: a long form scrolls inside the
 *     dialog, never the page behind it, and never takes the buttons with it.
 *   - `size="form"`: wider, and full screen on a phone rather than a cramped box.
 *   - While the act is being recorded (`busy`) nothing can close it, and the primary button says so; the server's own
 *     sentence (`error`) is shown inside it, and it closes only when the parent says the act succeeded. A field's own
 *     error is the caller's, under its field, inside the slot (M78's standard).
 *
 * RED IS THE OPEN PANIC'S ALONE (Hassam, 11 October 2026, rule 20). The server's refusal (`error`) is an amber box, and
 * a destructive act (`tone="danger"`) is a red OUTLINE on white, so it still reads as dangerous while solid red stays
 * reserved for an open panic: the rule Escalate and High severity follow, so the whole product reads one way.
 *
 * SAVING FROM IT: `submitDialog` (lib/dialogSubmit.js). A refused save keeps the dialog open with what was typed and
 * the server's words under the fields; only a save that went through closes it. Use it for every save from here and
 * nobody has to remember `preserveState` at a call site.
 *
 * The body and any fields are the caller's (the default slot). The buttons are the dialog's, so every dialog in both
 * consoles reads the same way. A form over its list:
 *
 *   <ConsoleDialog :open="adding" title="Add an account" size="form" confirm-label="Add account"
 *                  :busy="form.processing" :dirty="form.isDirty" discard-title="Discard this account?"
 *                  @confirm="save" @cancel="adding = false"> ...fields, each with its error... </ConsoleDialog>
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, required: true },
    /** 'danger' for an irreversible or destructive act (a red outline, never solid red), 'primary' otherwise. */
    tone: { type: String, default: 'primary' },
    confirmLabel: { type: String, required: true },
    busyLabel: { type: String, default: 'Saving…' },
    cancelLabel: { type: String, default: 'Cancel' },
    busy: { type: Boolean, default: false },
    /** Why the primary action cannot be pressed yet, shown as its tooltip; null when it can. */
    confirmBlockedReason: { type: String, default: null },
    /** A refusal that belongs to no single field — the server's sentence. */
    error: { type: String, default: null },
    /** Something has been typed: closing asks first, so it is never thrown away silently (M95 §1.4.5). */
    dirty: { type: Boolean, default: false },
    /** The question asked before what was typed is thrown away: "Discard this bill?". */
    discardTitle: { type: String, default: 'Discard what you typed?' },
    /** 'form' for a form opened over its list (M95 §1): wider, and full screen on a phone. */
    size: { type: String, default: 'default' },
})

const emit = defineEmits(['confirm', 'cancel'])

const panel = ref(null)
const titleId = `console-dialog-${Math.random().toString(36).slice(2, 10)}`
let opener = null

/** Whether the "discard what you typed?" question is showing in place of the buttons. */
const asking = ref(false)

/*
 * OVER THE PAGE WHEREVER THERE IS A PAGE. In a browser the dialog is moved to <body>, so no
 * scrolling container or stacking context can clip it. Where there is no real document to
 * move it into (the console's script tests render into a tree of their own, and so would a
 * server render) it renders in place instead — the same markup, the same behaviour.
 */
const canTeleport = typeof document !== 'undefined' && typeof document.querySelector === 'function'

const inPanel = (selector) => (panel.value && typeof panel.value.querySelectorAll === 'function'
    ? [...panel.value.querySelectorAll(selector)]
    : [])

const focusables = () => inPanel('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
    .filter((el) => !el.hasAttribute('disabled') && el.offsetParent !== null)

const lockScroll = (locked) => {
    if (typeof document !== 'undefined' && document.body?.style) {
        document.body.style.overflow = locked ? 'hidden' : ''
    }
}

/* Esc, the backdrop, the corner and Cancel: with something typed, ask first; while asking, go back to the form. */
const cancel = () => {
    if (props.busy) {
        return
    }

    if (asking.value) {
        asking.value = false

        return
    }

    if (props.dirty) {
        asking.value = true

        return
    }

    emit('cancel')
}

const keepEditing = () => {
    asking.value = false
}

const discard = () => {
    asking.value = false
    emit('cancel')
}

const onKeydown = (event) => {
    if (!props.open) {
        return
    }

    if (event.key === 'Escape') {
        event.preventDefault()
        cancel()

        return
    }

    if (event.key === 'Tab') {
        const items = focusables()

        if (items.length === 0) {
            event.preventDefault()

            return
        }

        const first = items[0]
        const last = items[items.length - 1]

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault()
            last.focus()
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault()
            first.focus()
        }
    }
}

watch(
    () => props.open,
    async (isOpen) => {
        asking.value = false

        if (typeof document === 'undefined') {
            return
        }

        if (isOpen) {
            opener = document.activeElement
            lockScroll(true)
            document.addEventListener('keydown', onKeydown)
            await nextTick()
            // The first field if there is one, else the safe choice: Cancel.
            const first = inPanel('input, select, textarea')[0] ?? inPanel('.cd-cancel')[0]
            first?.focus?.()
        } else {
            lockScroll(false)
            document.removeEventListener('keydown', onKeydown)
            if (opener && typeof opener.focus === 'function') {
                opener.focus()
            }
            opener = null
        }
    },
)

onBeforeUnmount(() => {
    if (typeof document === 'undefined') {
        return
    }

    lockScroll(false)
    document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
    <Teleport to="body" :disabled="!canTeleport">
        <div v-if="open" class="cd-backdrop" :class="{ 'cd-form': size === 'form' }" @mousedown.self="cancel">
            <div
                ref="panel"
                class="cd-panel"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
            >
                <div class="cd-head">
                    <div :id="titleId" class="cd-title">{{ title }}</div>
                    <button type="button" class="cd-close" aria-label="Close" title="Close" :disabled="busy" @click="cancel">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M7 7l10 10M17 7L7 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                <div class="cd-body">
                    <slot />
                </div>

                <div v-if="error" class="cd-error" role="alert">{{ error }}</div>

                <!-- M96 level 2: why the primary button is greyed, on the screen, never only in its tooltip. -->
                <p v-if="confirmBlockedReason && !busy && !asking" class="cd-why">{{ confirmBlockedReason }}</p>

                <!-- M95 §1.4.5: what somebody typed is never thrown away without asking. -->
                <div v-if="asking" class="cd-actions cd-discard" role="group" :aria-label="discardTitle">
                    <span class="cd-discard-q">{{ discardTitle }}</span>
                    <button type="button" class="cd-cancel" @click="keepEditing">Keep editing</button>
                    <button type="button" class="cd-confirm danger" @click="discard">Discard</button>
                </div>
                <div v-else class="cd-actions">
                    <button
                        type="button"
                        class="cd-cancel"
                        :disabled="busy"
                        @click="cancel"
                    >
                        {{ cancelLabel }}
                    </button>
                    <button
                        type="button"
                        class="cd-confirm"
                        :class="tone === 'danger' ? 'danger' : 'primary'"
                        :disabled="busy || !!confirmBlockedReason"
                        :title="confirmBlockedReason ?? undefined"
                        @click="emit('confirm')"
                    >
                        {{ busy ? busyLabel : confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
/*
 * Authored: no board draws a dialog. Every value is a design token, and the
 * backdrop and shadow are the navy the panic overlay already dims the page with,
 * so the two read as one family.
 */
.cd-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: rgba(11, 31, 51, 0.55);
}

/*
 * The head, the body and the buttons in a column: only the body scrolls (M95 §1.4.2 and 3), so a long form never
 * takes its buttons out of view and never scrolls the page behind it.
 */
.cd-panel {
    width: 100%;
    max-width: 520px;
    max-height: calc(100vh - 32px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--white);
    border-radius: 16px;
    box-shadow: 0 24px 60px rgba(11, 31, 51, 0.35);
    font-family: inherit;
}

.cd-form .cd-panel {
    max-width: 640px;
}

.cd-head {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 22px 18px 0 24px;
}

.cd-title {
    flex: 1 1 auto;
    min-width: 0;
    font-size: 18px;
    font-weight: 700;
    line-height: 1.3;
    color: var(--navy-900);
}

.cd-close {
    -webkit-appearance: none;
    appearance: none;
    flex: 0 0 auto;
    width: 32px;
    height: 32px;
    margin-top: -4px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: var(--navy-700);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.cd-close svg {
    width: 17px;
    height: 17px;
    display: block;
}

.cd-close:hover {
    background: var(--navy-100);
}

.cd-close:disabled {
    opacity: 0.55;
    cursor: default;
}

.cd-close:focus-visible {
    outline: 2px solid var(--amber-500);
    outline-offset: 2px;
}

.cd-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    padding: 10px 24px 4px;
    font-size: 13.5px;
    line-height: 1.55;
    color: var(--slate-600);
}

/* Amber, the pair a stated problem wears across the consoles: a refusal is an ordinary error, never the panic's red. */
.cd-error {
    margin: 10px 24px 0;
    padding: 10px 12px;
    border-radius: 10px;
    background: var(--amber-100);
    border: 1px solid var(--amber-500);
    color: var(--navy-900);
    font-size: 13px;
    line-height: 1.45;
}

.cd-why {
    margin: 12px 24px 0;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--slate-600);
    text-align: right;
}

.cd-actions {
    flex: 0 0 auto;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    padding: 18px 24px 22px;
    flex-wrap: wrap;
}

.cd-form .cd-actions {
    border-top: 1px solid var(--navy-100);
}

.cd-discard-q {
    flex: 1 1 auto;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.cd-cancel,
.cd-confirm {
    -webkit-appearance: none;
    appearance: none;
    height: 42px;
    padding: 0 18px;
    border-radius: 10px;
    font-family: inherit;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
}

.cd-cancel {
    background: var(--white);
    color: var(--navy-900);
    border: 1.5px solid var(--navy-200);
}

.cd-confirm {
    border: 0;
    color: var(--white);
}

.cd-confirm.primary {
    background: var(--navy-600);
}

/* A red outline on white: dangerous to read, and never the solid red an open panic wears (rule 20). */
.cd-confirm.danger {
    background: var(--white);
    border: 1.5px solid var(--red-700);
    color: var(--red-700);
}

.cd-confirm.danger:hover:not(:disabled) {
    background: var(--red-100);
}

.cd-cancel:disabled,
.cd-confirm:disabled {
    opacity: 0.55;
    cursor: default;
}

.cd-cancel:focus-visible,
.cd-confirm:focus-visible {
    outline: 2px solid var(--amber-500);
    outline-offset: 2px;
}

/*
 * EVERY FORM IN A DIALOG READS THE SAME (Hassam, 12 Oct: "where you see the modal appears as a form, fix their design...
 * too much css problems, no better inputs... make all professional"). Each page drew its own fields inside the dialog,
 * 32 px boxes on one, the browser's bare ones on the next. The dialog now gives whatever form it holds one field: full
 * width, 42 px, a navy-200 edge and a 10 px corner, a clear label above it, a select with the console's own arrow, an
 * amber ring on focus (amber, never red: rule 20), and a greyed ground when it cannot be typed into. A field's own
 * error stays the caller's, under its field. The selectors are kept low (:where) so a page's deliberate layout, a grid
 * of two columns or a narrow number beside its unit, still wins where it says so.
 */
.cd-panel .cd-body :deep(label) {
    font-size: 12.5px;
    font-weight: 700;
    line-height: 1.35;
    color: var(--navy-900);
}

.cd-panel .cd-body :deep(:where(input:not([type='checkbox'], [type='radio'], [type='file'], [type='range'], [type='color'], [type='hidden']), select, textarea)) {
    box-sizing: border-box;
    width: 100%;
    min-height: 42px;
    margin: 6px 0 0;
    padding: 10px 12px;
    border: 1.5px solid var(--navy-200);
    border-radius: 10px;
    background-color: var(--white);
    color: var(--navy-900);
    font-family: inherit;
    font-size: 13.5px;
    font-weight: 500;
    line-height: 1.4;
    transition:
        border-color 0.15s ease,
        box-shadow 0.15s ease;
}

.cd-panel .cd-body :deep(:where(textarea)) {
    min-height: 96px;
    resize: vertical;
}

.cd-panel .cd-body :deep(:where(select)) {
    -webkit-appearance: none;
    appearance: none;
    padding-right: 38px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23123B6B' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 16px;
}

.cd-panel .cd-body :deep(:where(input, select, textarea)::placeholder) {
    color: var(--slate-400, #94a3b8);
    font-weight: 400;
}

.cd-panel .cd-body :deep(:where(input:not([type='checkbox'], [type='radio']), select, textarea):focus) {
    outline: none;
    border-color: var(--amber-500);
    box-shadow: 0 0 0 3px rgba(255, 182, 39, 0.18);
}

.cd-panel .cd-body :deep(:where(input, select, textarea):is([readonly], :disabled)) {
    background-color: var(--navy-100);
    color: var(--slate-600);
    cursor: default;
}

.cd-panel .cd-body :deep(:where(input[type='checkbox'], input[type='radio'])) {
    width: 16px;
    height: 16px;
    margin: 0 8px 0 0;
    accent-color: var(--navy-600);
    vertical-align: -3px;
}

/* A form's title rule: the head reads apart from the fields under it. */
.cd-form .cd-head {
    padding-bottom: 14px;
    border-bottom: 1px solid var(--navy-100);
}

.cd-form .cd-body {
    padding-top: 16px;
}

@media (max-width: 480px) {
    .cd-actions {
        flex-direction: column-reverse;
        align-items: stretch;
    }

    .cd-cancel,
    .cd-confirm {
        width: 100%;
    }
}

/* M95 §1.4.7: a form on a phone or a small tablet takes the whole screen rather than a cramped box. */
@media (max-width: 640px) {
    .cd-form {
        padding: 0;
    }

    .cd-form .cd-panel {
        max-width: none;
        height: 100%;
        max-height: 100%;
        border-radius: 0;
    }
}
</style>
