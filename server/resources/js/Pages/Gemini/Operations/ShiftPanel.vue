<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import InfoButton from '../../../Components/InfoButton.vue'
import { requestHeaders } from '../../../live/authorize.js'
import { browserZone } from '../../../lib/scheduleTime.js'
import { shiftSpan } from '../../../lib/shiftSpan.js'

/**
 * The shift panel (M30 §5): one panel, over the schedule, from the right — to add a shift, and, opened on one, to
 * change it.
 *
 * Gate, date, start and end in the client's time, who stands it (searched, with everybody who cannot crossed out and
 * why), what he does there, and whether it repeats. As the form changes the server is asked what it would say
 * (`/guards/schedule/check`), and its answer is the list of ticks at the foot: the button is pressed only when every
 * line is a tick. Saving asks the server again; if it refuses, its own sentence appears in the same place.
 *
 * OPENED ON A SHIFT (§5.2) it reads that shift (`GET /guards/schedule/shifts/{id}`): its title, the form as it is, the
 * guard on it first in the picker, a reason asked for in one line when he is replaced (his phone is told), "Take the
 * guard off this shift", "Remove this shift" while it has not started, and its history. A shift being worked or worked,
 * or a role that only reads the schedule, opens it to read.
 *
 * PREVENTING, NOT REFUSING (M35 §3). One line before anything is typed says what may be posted, with the reasons behind
 * an `i`; the date cannot be set before today at the client; an end earlier in the day than the start is the next
 * morning, and the form says so in one quiet line, with the length, as the fields change, on the client's clock and on
 * the viewer's (`lib/shiftSpan.js`); and the ticks below say every other rule before the button can be pressed.
 *
 * THE CLASSIC ROSTER OPENS IT TOO (M35 §2): for any of its clients (`clients`, and the `client` event when another is
 * chosen), and on a shift either at its guard picker or straight at "Remove this shift" (`act`).
 *
 * Built from the console's own pieces: the dialog's backdrop and card, the form controls and buttons of the pages
 * around it. No new colour.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    client: { type: Object, required: true },
    zone: { type: Object, required: true },
    /** The client's gates, in the schedule's order. */
    posts: { type: Array, required: true },
    /** The gate and date to start from: a cell's, or the first gate and a day of the week on screen. */
    start: { type: Object, default: () => ({}) },
    /** The shift to open on; none to add one. */
    shiftId: { type: Number, default: null },
    /** Whether this role may change shifts (`guard_workforce.update`); otherwise the panel only reads. */
    canWrite: { type: Boolean, default: true },
    /** On a screen spanning clients (the classic roster), the ones a shift may be added at: a choice at the top. */
    clients: { type: Array, default: () => [] },
    /** Opened on a shift: 'guard' to start at the guard picker, 'remove' to start at "Remove this shift". */
    act: { type: String, default: null },
})

const emit = defineEmits(['close', 'client'])
const page = usePage()

/* ---- the shift it is opened on ---- */

const existing = ref(null)
const loadFailed = ref(false)
const reason = ref('')
// Why an open shift is open, read by whoever picks it up (M35 §2: the classic roster's own field, kept).
const whyOpen = ref('')
const acting = ref(null)
const actReason = ref('')

const isChange = computed(() => props.shiftId !== null)
const reads = computed(() => isChange.value && (!props.canWrite || !existing.value || existing.value.locked !== null))
const canTimes = computed(() => !isChange.value || (!reads.value && existing.value.can.change_times))
const canGuard = computed(() => !isChange.value || (!reads.value && existing.value.can.change_guard))
const current = computed(() => existing.value?.guard ?? null)
const replacing = computed(() => isChange.value && !reads.value && current.value !== null && form.value.guard_id !== current.value.id)

const title = computed(() => (isChange.value ? existing.value?.title ?? 'Shift' : 'Add a shift'))

const loadShift = async () => {
    loadFailed.value = false
    try {
        const response = await fetch(`/guards/schedule/shifts/${props.shiftId}`, { headers: requestHeaders(), credentials: 'same-origin' })
        existing.value = response.ok ? await response.json() : null
        loadFailed.value = !response.ok
    } catch {
        existing.value = null
        loadFailed.value = true
    }
}

// A patrol start's shift starts a patrol; a gate's stands the gate. Either can be changed.
const dutyAt = (postId) => (props.posts.find((p) => p.id === postId)?.type === 'patrol' ? 'patrol' : 'post')

const blank = () => {
    const postId = props.start.post_id ?? props.posts[0]?.id ?? null

    return {
        post_id: postId,
        date: props.start.date ?? '',
        starts: '06:00',
        ends: '18:00',
        guard_id: null,
        duty: dutyAt(postId),
        repeat: 'once',
        until: props.start.date ?? '',
        days: [],
    }
}

const form = ref(blank())
const search = ref('')
const answer = ref(null)
const checking = ref(false)
const saving = ref(false)
const failed = ref(null)
const panel = ref(null)

const WEEKDAYS = [
    { n: 1, label: 'M', name: 'Monday' },
    { n: 2, label: 'T', name: 'Tuesday' },
    { n: 3, label: 'W', name: 'Wednesday' },
    { n: 4, label: 'T', name: 'Thursday' },
    { n: 5, label: 'F', name: 'Friday' },
    { n: 6, label: 'S', name: 'Saturday' },
    { n: 7, label: 'S', name: 'Sunday' },
]

/* ---- asking the server, as the form changes ---- */

let timer = null
let asked = 0

// What the server is asked: the form, and on a shift being changed, which shift and the reason typed so far.
const question = () => (isChange.value ? { ...form.value, shift_id: props.shiftId, reason: reason.value } : form.value)

// Each change is a new question: until its answer is in, the button waits and any older answer is dropped.
const ask = () => {
    clearTimeout(timer)
    if (reads.value) return
    const mine = ++asked
    checking.value = true
    timer = setTimeout(async () => {
        try {
            const response = await fetch('/guards/schedule/check', {
                method: 'POST',
                headers: requestHeaders({ 'Content-Type': 'application/json' }),
                credentials: 'same-origin',
                body: JSON.stringify(question()),
            })
            const body = response.ok ? await response.json() : null
            if (mine === asked) {
                answer.value = body ?? { ok: false, checks: [{ ok: false, text: 'The checks could not be read. Try again in a moment.' }], guards: [], count: 0 }
            }
        } catch {
            if (mine === asked) {
                answer.value = { ok: false, checks: [{ ok: false, text: 'No connection: the checks could not be read.' }], guards: [], count: 0 }
            }
        } finally {
            if (mine === asked) checking.value = false
        }
    }, 300)
}

let filling = false

watch(form, () => {
    failed.value = null
    ask()
}, { deep: true })

watch(reason, () => {
    if (replacing.value) ask()
})

watch(() => form.value.post_id, (postId, before) => {
    if (!filling && before !== undefined && postId !== before) form.value.duty = dutyAt(postId)
})

// A repeat never ends before its first day: moving the date later carries the end date with it.
watch(() => form.value.date, (date) => {
    if (date && (!form.value.until || form.value.until < date)) form.value.until = date
})

// In a browser the panel is moved to <body>, as the console dialog is; where there is no real document (the script
// tests' own tree) it renders in place.
const canTeleport = typeof document !== 'undefined' && typeof document.querySelector === 'function'

watch(() => [props.open, props.shiftId], async ([isOpen]) => {
    if (!isOpen) return
    search.value = ''
    failed.value = null
    answer.value = null
    reason.value = ''
    whyOpen.value = ''
    acting.value = null
    actReason.value = ''
    existing.value = null

    if (isChange.value) {
        await loadShift()
        // The form as the shift is: set at once, so its own gate's duty is not put back to the gate's default.
        filling = true
        form.value = { ...blank(), ...(existing.value?.form ?? {}), repeat: 'once', until: existing.value?.form?.date ?? '', days: [] }
        await nextTick()
        filling = false
    } else {
        form.value = blank()
    }

    ask()
    await nextTick()

    // Opened from a row's own button (M35 §2): straight to what it was pressed for.
    if (isChange.value && props.act === 'remove' && existing.value?.can?.remove && !reads.value) {
        startActing('remove')
        await nextTick()
        panel.value?.querySelector?.('.sp-confirm input')?.focus?.()

        return
    }

    if (isChange.value && props.act === 'guard' && canGuard.value) {
        panel.value?.querySelector?.('.sp-search')?.focus?.()

        return
    }

    panel.value?.querySelector?.('select:not([disabled]), input:not([disabled])')?.focus?.()
}, { immediate: true })

// Another client chosen at the top (the classic roster): the screen hands the panel that client's gates and clock.
watch(() => props.client?.id, (id, before) => {
    if (props.open && !isChange.value && before !== undefined && id !== before) {
        form.value = { ...blank(), date: form.value.date || blank().date }
        ask()
    }
})

onBeforeUnmount(() => clearTimeout(timer))

/* ---- what the panel shows ---- */

const guards = computed(() => answer.value?.guards ?? [])

const matches = (g) => {
    const q = search.value.trim().toLowerCase()

    // M32 F4: the phone however it is typed — "876 555 0142", "8765550142", "+18765550142" — beside name and number.
    const digits = (s) => String(s ?? '').replace(/\D+/g, '')
    const typed = /[a-z]/.test(q) ? '' : digits(q)
    const asked = typed.length === 11 && typed.startsWith('1') ? typed.slice(1) : typed

    return (
        q === '' ||
        g.name.toLowerCase().includes(q) ||
        (g.employee_number ?? '').toLowerCase().includes(q) ||
        (asked.length >= 4 && digits(g.phone).includes(asked))
    )
}

// The word on a free guard's row: "free", "on it now" for the guard already on it, and whether he asked for it (§9).
const freeWords = (g) => {
    const words = current.value?.id === g.id ? 'on it now' : 'free'

    return g.asked ? `${words} · asked for this shift` : words
}

const freeGuards = computed(() => guards.value.filter((g) => g.free && matches(g)))
const blockedGuards = computed(() => guards.value.filter((g) => !g.free && matches(g)))

const checks = computed(() => answer.value?.checks ?? [])
const allTicks = computed(() => !!answer.value?.ok)
const count = computed(() => answer.value?.count ?? 0)

// On a shift being changed: whether anything differs from the shift as it is.
const FORM_KEYS = ['post_id', 'date', 'starts', 'ends', 'duty', 'guard_id']
const changed = computed(() => isChange.value && existing.value !== null && FORM_KEYS.some((k) => (form.value[k] ?? null) !== (existing.value.form[k] ?? null)))

const buttonLabel = computed(() => {
    if (isChange.value) return saving.value ? 'Saving…' : 'Save changes'

    return saving.value ? 'Adding…' : count.value > 1 ? `Add ${count.value} shifts` : 'Add shift'
})

const canSave = computed(() => allTicks.value && !saving.value && !checking.value && (!isChange.value || changed.value))

// Today at the client, on its own clock (M35 §3): the earliest date the picker offers, as the server counts the past.
const minDate = computed(() => {
    try {
        return new Intl.DateTimeFormat('en-CA', { timeZone: props.zone.estate, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
    } catch {
        return ''
    }
})

/*
 * WHAT THE THREE FIELDS SAY, AS THEY ARE TYPED (M35 §3), worked out here so nobody waits for the server to know: how long
 * it is on the client's clock, and the viewer's ("13 hours, 6:00 PM to 7:00 AM JA"), and — when the end is earlier in
 * the day than the start — one more quiet line saying it ends the next morning ("Ends 21 Oct, 7:00 AM JA — 13 hours").
 */
const spanNow = computed(() => shiftSpan({
    date: form.value.date,
    starts: form.value.starts,
    ends: form.value.ends,
    estateZone: props.zone.estate,
    estateTag: props.zone.estateTag,
    viewerZone: browserZone(),
}))

const durationLine = computed(() => {
    const s = spanNow.value
    if (!s) return ''

    return s.viewerLine ? `${s.durationLine} · ${s.viewerLine}` : s.durationLine
})

const endsLine = computed(() => spanNow.value?.endsLine ?? '')

const toggleDay = (n) => {
    const days = new Set(form.value.days)
    days.has(n) ? days.delete(n) : days.add(n)
    form.value.days = [...days].sort()
}

/* ---- saving ---- */

// One way to the server for every act the panel takes: it closes on success, and shows a refusal in the server's words.
const send = (url, data, refusedAs) => {
    saving.value = true
    router.post(url, data, {
        preserveScroll: true,
        onSuccess: () => {
            if (!page.props.errors?.panel) emit('close')
        },
        onError: (errors) => {
            failed.value = errors.panel ?? Object.values(errors)[0] ?? refusedAs
        },
        onFinish: () => {
            saving.value = false
        },
    })
}

const save = () => {
    if (!canSave.value) return

    if (isChange.value) {
        send(`/guards/schedule/shifts/${props.shiftId}`, { ...form.value, reason: reason.value }, 'That change could not be made.')
    } else {
        send('/guards/schedule/shifts', form.value.guard_id === null ? { ...form.value, reason: whyOpen.value.trim() } : form.value, 'That shift could not be added.')
    }
}

// "Take the guard off this shift" and "Remove this shift": each asks why, in one line, and confirms in one line.
const startActing = (what) => {
    acting.value = what
    actReason.value = ''
    failed.value = null
}

const actReady = computed(() => actReason.value.trim().length >= 3 && !saving.value)

const act = () => {
    if (!actReady.value) return

    const path = acting.value === 'remove' ? 'remove' : 'take-off'
    send(`/guards/schedule/shifts/${props.shiftId}/${path}`, { reason: actReason.value.trim() }, 'That could not be done.')
}

const close = () => {
    if (!saving.value) emit('close')
}

const onKeydown = (event) => {
    if (event.key === 'Escape') close()
}

watch(() => props.open, (isOpen) => {
    if (typeof document === 'undefined') return
    if (isOpen) document.addEventListener('keydown', onKeydown)
    else document.removeEventListener('keydown', onKeydown)
}, { immediate: true })

onBeforeUnmount(() => {
    if (typeof document !== 'undefined') document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
    <Teleport to="body" :disabled="!canTeleport">
        <div v-if="open" class="sp-backdrop" @mousedown.self="close">
            <aside ref="panel" class="sp-panel" role="dialog" aria-modal="true" aria-labelledby="sp-title">
                <div class="sp-head">
                    <div id="sp-title" class="sp-title">{{ title }}</div>
                    <button type="button" class="sp-x" aria-label="Close" @click="close">×</button>
                </div>

                <div class="sp-body">
                    <p v-if="isChange && loadFailed" class="sp-note sp-note-no" role="alert">This shift could not be read. It may have been removed: close this and look at the week again.</p>
                    <p v-if="existing?.locked" class="sp-note">{{ existing.locked }}</p>
                    <p v-else-if="isChange && existing && !canWrite" class="sp-note">Your role can see this shift. Changing it is for a role that plans the workforce.</p>
                    <p v-if="existing?.note" class="sp-note"><strong>Needs a guard.</strong> {{ existing.note }}</p>

                    <template v-if="!isChange || existing">
                    <!-- One line before anything is typed (M35 §3), the reasons behind the i. -->
                    <p v-if="!isChange" class="sp-muted sp-guide">
                        Shifts are posted from today onward, up to 24 hours each. A night shift ending the next morning is fine.
                        <InfoButton topic="shiftRules" />
                    </p>

                    <label v-if="!isChange && clients.length > 1" class="sp-field">
                        <span>Client</span>
                        <select :value="client.id" @change="emit('client', $event.target.value)">
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </label>

                    <label class="sp-field">
                        <span>Gate or patrol start</span>
                        <select v-model.number="form.post_id" :disabled="!canTimes">
                            <option v-for="post in posts" :key="post.id" :value="post.id">
                                {{ post.name }}{{ post.also_called ? ` (${post.also_called})` : '' }}
                            </option>
                        </select>
                    </label>

                    <label class="sp-field">
                        <span>Date</span>
                        <input v-model="form.date" type="date" required :min="canTimes ? minDate : undefined" :disabled="!canTimes" />
                    </label>

                    <div class="sp-pair">
                        <label class="sp-field">
                            <span>Starts ({{ zone.estateTag }})</span>
                            <input v-model="form.starts" type="time" step="900" required :disabled="!canTimes" />
                        </label>
                        <label class="sp-field">
                            <span>Ends ({{ zone.estateTag }})</span>
                            <input v-model="form.ends" type="time" step="900" required :disabled="!canTimes" />
                        </label>
                    </div>
                    <p v-if="durationLine" class="sp-muted">{{ durationLine }}</p>
                    <p v-if="endsLine" class="sp-muted">{{ endsLine }}</p>
                    <p v-if="isChange && existing?.started && !reads" class="sp-muted">It has started, so its gate and times stay as they are. Who stands it can still change.</p>

                    <div v-if="reads" class="sp-field">
                        <span>Who stands it</span>
                        <p class="sp-who">{{ current ? current.name : 'Needs a guard' }}</p>
                    </div>

                    <fieldset v-else class="sp-field sp-picker">
                        <legend>Who stands it</legend>
                        <input v-model="search" type="search" class="sp-search" placeholder="Search a guard" aria-label="Search a guard" :disabled="!canGuard" />
                        <div class="sp-list" role="radiogroup" aria-label="Who stands it">
                            <label class="sp-option">
                                <input v-model="form.guard_id" type="radio" :value="null" name="sp-guard" :disabled="!canGuard" />
                                <span>Leave it open, any guard can ask for it</span>
                            </label>
                            <label v-for="g in freeGuards" :key="g.id" class="sp-option">
                                <input v-model="form.guard_id" type="radio" :value="g.id" name="sp-guard" :disabled="!canGuard" />
                                <span class="sp-name">{{ g.name }}</span>
                                <span class="sp-free">{{ freeWords(g) }}</span>
                            </label>
                            <div v-if="blockedGuards.length" class="sp-divider" aria-hidden="true"></div>
                            <div v-for="g in blockedGuards" :key="g.id" class="sp-option sp-blocked" :title="`${g.name}: ${g.reason}`">
                                <span class="sp-cross" aria-hidden="true">✗</span>
                                <span class="sp-name">{{ g.name }}</span>
                                <span class="sp-why">{{ g.reason }}</span>
                            </div>
                            <p v-if="answer && guards.length === 0" class="sp-muted">This client has no guards yet. Add one in Directory.</p>
                        </div>
                    </fieldset>

                    <label v-if="!isChange && form.guard_id === null" class="sp-field">
                        <span>Why it needs a guard — read by whoever takes it</span>
                        <input v-model="whyOpen" type="text" class="sp-search" maxlength="190" placeholder="Optional, e.g. covering a guard on leave" />
                    </label>

                    <label v-if="replacing" class="sp-field">
                        <span>Why is {{ current.name }} coming off this shift? Their phone is told.</span>
                        <input v-model="reason" type="text" class="sp-search" maxlength="190" placeholder="e.g. swapped with Kemoy, as asked" />
                    </label>

                    <fieldset class="sp-field">
                        <legend>What he does there</legend>
                        <div class="sp-row">
                            <label class="sp-option"><input v-model="form.duty" type="radio" value="post" name="sp-duty" :disabled="!canTimes" /> Stands the gate</label>
                            <label class="sp-option"><input v-model="form.duty" type="radio" value="patrol" name="sp-duty" :disabled="!canTimes" /> Starts a patrol here</label>
                        </div>
                    </fieldset>

                    <fieldset v-if="!isChange" class="sp-field">
                        <legend>Repeat</legend>
                        <label class="sp-option"><input v-model="form.repeat" type="radio" value="once" name="sp-repeat" /> Just this day</label>
                        <label class="sp-option sp-inline">
                            <input v-model="form.repeat" type="radio" value="daily" name="sp-repeat" /> Every day until
                            <input v-model="form.until" type="date" :min="form.date || minDate" :disabled="form.repeat !== 'daily'" aria-label="Every day until" />
                        </label>
                        <label class="sp-option sp-inline">
                            <input v-model="form.repeat" type="radio" value="days" name="sp-repeat" /> These days
                        </label>
                        <div v-if="form.repeat === 'days'" class="sp-days">
                            <button
                                v-for="d in WEEKDAYS"
                                :key="d.n"
                                type="button"
                                class="sp-day"
                                :class="{ on: form.days.includes(d.n) }"
                                :aria-pressed="form.days.includes(d.n)"
                                :aria-label="d.name"
                                @click="toggleDay(d.n)"
                            >
                                {{ d.label }}
                            </button>
                            <span class="sp-muted">until</span>
                            <input v-model="form.until" type="date" :min="form.date || minDate" aria-label="These days until" />
                        </div>
                    </fieldset>

                    <div v-if="isChange && !reads && (existing.can.take_off || existing.can.remove)" class="sp-acts">
                        <button v-if="existing.can.take_off" type="button" class="text-link-sm" @click="startActing('take-off')">Take the guard off this shift</button>
                        <button v-if="existing.can.remove" type="button" class="text-link-sm sp-link-danger" @click="startActing('remove')">Remove this shift</button>
                    </div>

                    <div v-if="isChange && existing.history.length" class="sp-history">
                        <p class="sp-history-title">History</p>
                        <ul>
                            <li v-for="(h, i) in existing.history" :key="i">{{ h.text }} · {{ h.at }} · {{ h.ago }}</li>
                        </ul>
                    </div>
                    </template>
                </div>

                <div class="sp-foot">
                    <!-- Taking the guard off, or removing the shift: why, in one line, and a one-line confirmation. -->
                    <div v-if="acting" class="sp-confirm">
                        <p class="sp-confirm-line">
                            {{ acting === 'remove'
                                ? `Remove ${existing.title.replaceAll(' · ', ', ')}?${current ? ` ${current.name}'s phone is told.` : ''}`
                                : `Take ${current.name} off this shift? It will need a guard, and their phone is told.` }}
                        </p>
                        <input v-model="actReason" type="text" class="sp-search" maxlength="190" placeholder="Why, in a few words" :aria-label="acting === 'remove' ? 'Why this shift is removed' : 'Why they come off this shift'" />
                        <p v-if="failed" class="sp-no sp-failed" role="alert"><span aria-hidden="true">✗</span> {{ failed }}</p>
                        <div class="sp-actions">
                            <button type="button" class="btn-outline-sm" :disabled="saving" @click="acting = null"><span>{{ acting === 'remove' ? 'Keep it' : 'Keep them on' }}</span></button>
                            <button type="button" class="btn-primary-sm" :class="{ 'sp-danger': acting === 'remove' }" :disabled="!actReady" @click="act">
                                <span>{{ acting === 'remove' ? (saving ? 'Removing…' : 'Remove') : saving ? 'Taking them off…' : 'Take them off' }}</span>
                            </button>
                        </div>
                    </div>

                    <template v-else>
                        <!-- The rules (§10), always in sight above the button they hold down: the reason is on screen. -->
                        <ul v-if="!reads" class="sp-checks" aria-live="polite">
                            <li v-if="!answer" class="sp-muted">Checking…</li>
                            <li v-for="(c, i) in checks" :key="i" :class="c.ok ? 'sp-ok' : 'sp-no'">
                                <span aria-hidden="true">{{ c.ok ? '✓' : '✗' }}</span> {{ c.text }}
                            </li>
                            <li v-if="failed" class="sp-no" role="alert"><span aria-hidden="true">✗</span> {{ failed }}</li>
                        </ul>

                        <div class="sp-actions">
                            <button type="button" class="btn-outline-sm" :disabled="saving" @click="close"><span>{{ reads ? 'Close' : 'Cancel' }}</span></button>
                            <button v-if="!reads" type="button" class="btn-primary-sm" :disabled="!canSave" @click="save"><span>{{ buttonLabel }}</span></button>
                        </div>
                    </template>
                </div>
            </aside>
        </div>
    </Teleport>
</template>

<style scoped>
/*
 * The console dialog's backdrop and card (ConsoleDialog.vue), standing at the right; the form controls of the pages
 * around it (Sites.vue's fields). Layout, and those values, only.
 */
.sp-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    justify-content: flex-end;
    background: rgba(11, 31, 51, 0.55);
}

.sp-panel {
    width: 100%;
    max-width: 460px;
    height: 100%;
    display: flex;
    flex-direction: column;
    background: var(--white);
    border-radius: 16px 0 0 16px;
    box-shadow: 0 24px 60px rgba(11, 31, 51, 0.35);
    font-family: inherit;
}

.sp-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 22px 12px;
    border-bottom: 1px solid var(--navy-100);
}

.sp-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-primary);
}

.sp-x {
    border: 0;
    background: none;
    font-size: 22px;
    line-height: 1;
    color: var(--text-body);
    cursor: pointer;
}

.sp-body {
    flex: 1;
    overflow-y: auto;
    padding: 6px 22px 16px;
}

.sp-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin: 12px 0 0;
    padding: 0;
    border: 0;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-body);
}

.sp-field legend {
    padding: 0;
    margin-bottom: 4px;
}

.sp-field input[type='date'],
.sp-field input[type='time'],
.sp-field select,
.sp-search {
    align-items: center;
    width: 100%;
    min-height: 40px;
    border: 1px solid var(--navy-200);
    border-radius: 10px;
    padding: 0 10px;
    background: var(--white);
    color: var(--text-primary);
    font: inherit;
    font-weight: 500;
}

.sp-pair {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.sp-muted {
    margin: 6px 0 0;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-placeholder);
}

/* The one line before anything is typed (M35 §3), set off from the first field by space alone. */
.sp-guide {
    margin-top: 12px;
    line-height: 1.45;
}

.sp-picker .sp-list {
    margin-top: 6px;
    border: 1px solid var(--navy-200);
    border-radius: 10px;
    max-height: 230px;
    overflow-y: auto;
    padding: 4px 0;
}

.sp-option {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 10px;
    font-weight: 500;
    color: var(--text-primary);
}

.sp-inline input[type='date'] {
    width: auto;
    min-height: 34px;
}

.sp-name {
    flex: 1;
    min-width: 0;
}

.sp-free {
    font-size: 11.5px;
    color: var(--success-700);
}

.sp-divider {
    margin: 4px 10px;
    border-top: 1px solid var(--navy-100);
}

.sp-blocked {
    align-items: flex-start;
    flex-wrap: wrap;
    color: var(--text-body);
}

.sp-blocked .sp-name {
    text-decoration: line-through;
}

.sp-cross {
    color: var(--red-700);
}

.sp-why {
    flex-basis: 100%;
    padding-left: 22px;
    font-size: 11.5px;
    color: var(--text-placeholder);
}

.sp-days {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    padding: 4px 10px 6px 34px;
}

.sp-day {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: 1px solid var(--navy-200);
    background: var(--white);
    color: var(--text-primary);
    font: inherit;
    font-weight: 700;
    cursor: pointer;
}

.sp-day.on {
    background: var(--navy-600);
    border-color: var(--navy-600);
    color: var(--white);
}

.sp-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0 12px;
}

/* The foot: the rules and the buttons, never scrolled away. */
.sp-foot {
    padding: 12px 22px 18px;
    border-top: 1px solid var(--navy-100);
}

.sp-checks {
    list-style: none;
    margin: 0 0 12px;
    padding: 10px 12px;
    border-radius: 12px;
    background: var(--navy-100);
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-height: 190px;
    overflow-y: auto;
    font-size: 12px;
    line-height: 1.4;
}

.sp-ok {
    color: var(--text-primary);
}

.sp-ok span {
    color: var(--success-700);
}

.sp-no {
    color: var(--red-700);
    font-weight: 600;
}

.sp-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* A fact about the shift, before the form: why it cannot change, why it needs a guard. */
.sp-note {
    margin: 12px 0 0;
    padding: 9px 12px;
    border-radius: 10px;
    background: var(--navy-100);
    font-size: 12px;
    line-height: 1.45;
    color: var(--text-body);
}

.sp-note-no {
    background: var(--red-100);
    color: var(--red-700);
}

.sp-who {
    margin: 0;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
}

.sp-acts {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 18px;
    margin-top: 16px;
}

/* The classic roster's own text-link button declarations (Roster.vue). */
button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

/* One class more than the board's `.text-link-sm` under its body class, so the danger tone is the one drawn. */
button.text-link-sm.sp-link-danger {
    color: var(--red-700);
}

/* The history, in the muted style (§5.2). */
.sp-history {
    margin-top: 16px;
    padding-top: 10px;
    border-top: 1px solid var(--navy-100);
}

.sp-history-title {
    margin: 0 0 6px;
    font-size: 12px;
    font-weight: 700;
    color: var(--text-body);
}

.sp-history ul {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 11.5px;
    line-height: 1.45;
    color: var(--text-placeholder);
}

.sp-confirm-line {
    margin: 0 0 8px;
    font-size: 12.5px;
    font-weight: 600;
    line-height: 1.45;
    color: var(--text-primary);
}

.sp-confirm {
    font-size: 12.5px;
}

.sp-confirm .sp-search {
    margin-bottom: 10px;
}

.sp-failed {
    margin: 0 0 10px;
    font-size: 12px;
}

/* The console dialog's danger tone (ConsoleDialog.vue), for the one act that cannot be undone here. */
.sp-actions button.sp-danger {
    background: var(--red-700);
    box-shadow: none;
}

/* The classic roster's own button declarations (Roster.vue). */
.sp-actions button {
    font-family: inherit;
    cursor: pointer;
}

.sp-actions button.btn-primary-sm {
    border: 0;
}

/* The one place a button is held down until the server's ticks are in: it must look held down (app.css's 0.55). */
.sp-actions button[disabled] {
    cursor: not-allowed;
    opacity: 0.55;
}
</style>
