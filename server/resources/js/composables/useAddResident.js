import { computed, onBeforeUnmount, reactive, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

/**
 * ADD RESIDENT'S FORM, WRITTEN ONCE FOR ITS TWO HOSTS — board screen community-admin-34.
 *
 * Hassam, 11 Oct (the client's clicking): "Add resident opens the form inline on the page. It must open in a modal."
 * The register opens it in the console's modal now (`Residents/Index.vue`); `/residents/new` still serves the same form
 * as a page for the links that name it (`Residents/New.vue`). One set of rules, here; one set of fields,
 * `Components/AddResidentFields.vue`. Nothing below changed in moving out of New.vue but where the board comes from and
 * how the register is asked.
 *
 * THE UNIT IS NOT CREATED HERE. `Residents::add()` looks up the phase and lot against the estate's own units and
 * refuses when neither exists, so the form posts the phase and the lot as typed and the server decides whether they
 * resolve.
 *
 * AND IT ASKS THE REGISTER, NOT A COPY OF IT (M11 A2). As the phase and the unit change, it asks the server again (a
 * partial reload of the board's `lot`), which answers with the unit `add()` itself would file at and a few of the
 * phase's references to suggest. An answer is used only while it is the answer to what the fields say now; a late
 * answer to an earlier keystroke is set aside and asked again. Which lookup an answer is for is told by a number the
 * form gives each one, never by comparing the server's reading of the field with the field (see `answered`). It never
 * refuses on its own: the server decides when the form is posted.
 *
 * @param {object} options
 * @param {() => object|null} options.board  the add-resident board (`Residents::newResidentBoard()` with canCreate and
 *        blockedReason), or null while the register has not been asked for it yet
 * @param {string[]} options.only  the prop a lookup re-reads: `lot` on the page, `addResident` on the register
 * @param {{ phase: string, lot: string }} options.params  the names the phase and the unit are asked under
 * @param {() => boolean} options.active  whether the form is in front of somebody (the modal open), so asking makes sense
 * @param {() => string} options.postTo  where the resident is posted: the register's own address
 * @param {object} options.postOptions  added to the post (the router's posts keep the page's state by default, so a
 *        refused save keeps the modal and what was typed)
 */
export function useAddResident({ board, only, params = { phase: 'phase', lot: 'lot' }, active = () => true, postTo, postOptions = {} }) {
    const EMPTY_LOT = { phase: '', typed: '', resolved: null, suggestions: [], more: false, placeholder: null, question: null }

    const lot = computed(() => board()?.lot ?? EMPTY_LOT)
    const phases = computed(() => board()?.phases ?? [])

    /** Where the form was left, when a page is reloaded or a refused post comes back to it, if the select offers it. */
    const startingPhase = () => (phases.value.some((option) => option.value === lot.value.phase) ? lot.value.phase : (phases.value[0]?.value ?? ''))

    const freshFields = () => ({
        phase: startingPhase(),
        lot: lot.value.typed ?? '',
        full_name: '',
        email: '',
        phone: '',
        // M18C §3.2: an email, or this ticked, never a blank. Alerts for a person with none go to the primary resident.
        no_email: false,
        verification: board()?.default_verification ?? '',
        biometric_consent: board()?.biometric_consent?.default ?? false,
        // M32 F1: what this person is at a lot that already has a household: asked, never assumed.
        household_choice: '',
        // And which household, at a lot holding more than one with people in it.
        household_id: null,
    })

    const form = useForm(freshFields())

    /** The form as the modal opens on it each time: nothing typed, so closing it asks nothing. */
    const start = (over = {}) => {
        Object.assign(form, { ...freshFields(), ...over })
        form.clearErrors()
        form.defaults?.()
    }

    /** The fields as a lookup reads them: the phase chosen and the unit typed, trimmed. */
    const fieldsNow = () => ({ phase: form.phase, lot: form.lot.trim() })
    const sameFields = (a, b) => a.phase === b.phase && a.lot === b.lot

    /*
     * WHICH LOOKUP AN ANSWER IS FOR. Every lookup is numbered; the number goes to the server as a header and comes back
     * in the answer as `lot.question`; and the form keeps what the fields said when it sent each one. So an answer is
     * compared with the form's own record of its question, never the server's reading of the field with the field.
     * Those two do not trim alike: Laravel's TrimStrings strips a zero-width space, a left-to-right mark, a soft hyphen
     * or a word joiner off the ends, JavaScript's trim() keeps all four, and "Lot 47" pasted from a spreadsheet with one
     * of them never matched the server's "Lot 47".
     *
     * An answer with no number (the one the form opened on, or a refused post coming back) is about the phase and the
     * unit it names itself.
     */
    const QUESTION_HEADER = 'X-Lot-Question'
    const QUESTIONS_KEPT = 50
    const questions = new Map()
    let questionsSent = 0

    const answered = computed(() => questions.get(lot.value.question) ?? { phase: lot.value.phase, lot: (lot.value.typed ?? '').trim() })

    /** Is the register's answer about the phase chosen now, and, for the unit, about what is typed now? */
    const answerIsForPhase = computed(() => answered.value.phase === form.phase)
    const answerIsCurrent = computed(() => sameFields(answered.value, fieldsNow()))

    /*
     * ASK THE REGISTER, A PAUSE AFTER THE TYPING STOPS. One question per pause, not per keystroke; and when the answer
     * in front of the form is not about what the fields say, the question is asked again, so the last answer shown is
     * always the answer to the last thing typed. NEVER MORE THAN `ASKS_PER_FIELDS` TIMES FOR FIELDS THAT HAVE NOT
     * CHANGED: whatever else keeps an answer from matching, it cannot become a request every quarter of a second.
     */
    const ASK_AFTER_MS = 250
    const ASKS_PER_FIELDS = 3
    let asking = null
    let lastAsked = null
    let timesAsked = 0

    const ask = () => {
        if (!active() || board() === null || answerIsCurrent.value) {
            return
        }

        const fields = fieldsNow()

        if (lastAsked !== null && sameFields(lastAsked, fields)) {
            if (timesAsked >= ASKS_PER_FIELDS) {
                return
            }

            timesAsked += 1
        } else {
            lastAsked = fields
            timesAsked = 1
        }

        questionsSent += 1
        questions.set(questionsSent, fields)
        questions.delete(questionsSent - QUESTIONS_KEPT)

        router.reload({
            only,
            data: { [params.phase]: fields.phase || undefined, [params.lot]: fields.lot || undefined },
            headers: { [QUESTION_HEADER]: String(questionsSent) },
            preserveScroll: true,
            replace: true,
        })
    }

    const askSoon = () => {
        clearTimeout(asking)
        asking = setTimeout(ask, ASK_AFTER_MS)
    }

    // The fields changing, and an answer landing, are the two things that can leave the form without the answer to what it says.
    watch(() => [form.phase, form.lot.trim(), lot.value], () => {
        if (active() && board() !== null && !answerIsCurrent.value) {
            askSoon()
        }
    })

    /** Ask at once when the form opens on an answer the fields do not match (a phase the select does not offer). */
    const askIfStale = () => {
        if (active() && board() !== null && !answerIsCurrent.value) {
            askSoon()
        }
    }

    askIfStale()

    onBeforeUnmount(() => clearTimeout(asking))

    /** The phase's references to suggest, from the register's last answer about this phase. */
    const phaseReferences = computed(() => (answerIsForPhase.value ? lot.value.suggestions : []))

    /**
     * The unit what is typed names in the chosen phase, or null: the register's own answer (`Residents::unitTyped()`,
     * the lookup `add()` files with), used only while it is the answer to what is typed now. Cosmetic only: the field
     * posts what was typed and the server decides.
     */
    const resolvedUnit = computed(() => (form.lot.trim() !== '' && answerIsCurrent.value ? lot.value.resolved : null))

    /** Said under the field when what is typed names no unit in this phase, once the register has said so. */
    const unmatchedNote = computed(() =>
        form.lot.trim() !== '' && answerIsCurrent.value && lot.value.resolved === null && lot.value.placeholder !== null
            ? `No unit “${lot.value.typed}” is on ${form.phase}’s list. Type the unit as the estate writes it.`
            : null
    )

    /** Said when the phase holds more matches than were suggested, so an unlisted unit reads as "keep typing". */
    const moreNote = computed(() =>
        form.lot.trim() !== '' && answerIsCurrent.value && lot.value.more && lot.value.resolved === null
            ? `The first ${lot.value.suggestions.length} matches are suggested. Type more of the unit to narrow them.`
            : null
    )

    /** The board's placeholder, or this phase's own first unit where the estate does not write "Lot". */
    // An example, said as one ("e.g. Lot 1"), never a value that reads as typed (Hassam, batch 1 item 3).
    const lotPlaceholder = computed(() => `e.g. ${(answerIsForPhase.value ? lot.value.placeholder : null) ?? '112'}`)

    /** The board's preview note, with `{first}` and `{lot}` filled from what is typed. */
    const previewText = computed(() => {
        const first = form.full_name.trim().split(/\s+/)[0] || 'This resident'
        const unit = resolvedUnit.value ?? 'their unit'

        return (board()?.preview?.template ?? '').replace('{first}', first).replace('{lot}', unit)
    })

    /*
     * M32 F1: THE HOUSEHOLD ALREADY AT THE LOT, while the register's answer is about the lot typed now. Nothing is joined
     * to it silently: the form asks what this person is, and the save waits for the answer.
     */
    const occupied = computed(() => (form.lot.trim() !== '' && answerIsCurrent.value ? lot.value.occupied ?? null : null))

    watch(occupied, (now) => {
        if (now === null) {
            form.household_choice = ''
        }

        // A household chosen at another lot is never carried to this one.
        if (now === null || !now.households.some((h) => h.id === form.household_id)) {
            form.household_id = null
        }
    })

    /** A member or a new primary joins one household; where the lot holds more than one, the form asks which. */
    const asksWhichHousehold = computed(
        () => occupied.value !== null && occupied.value.households.length > 1 && ['member', 'primary'].includes(form.household_choice)
    )

    /**
     * WHAT IS STILL TO BE TYPED, in one line, or null (Law 1; found by Accounting's walk, 12 Oct: Add resident was live
     * with nothing typed, and every press of it was refused). What the server requires of every person: the phase and
     * the lot, a full name, a phone, and an email or the tick that says there is none.
     */
    const missingLine = computed(() => {
        const missing = [
            form.phase === '' ? 'the phase' : null,
            form.lot.trim() === '' ? 'the lot' : null,
            form.full_name.trim() === '' ? 'their full name' : null,
            form.phone.trim() === '' ? 'a phone number' : null,
            !form.no_email && form.email.trim() === '' ? 'an email (or tick that they have none)' : null,
        ].filter((part) => part !== null)

        if (missing.length === 0) {
            return null
        }

        const list = missing.length === 1 ? missing[0] : `${missing.slice(0, -1).join(', ')} and ${missing.at(-1)}`

        return `Enter ${list} first.`
    })

    const submitBlockedBy = computed(() => {
        if (!board()?.canCreate) {
            return board()?.blockedReason ?? null
        }

        // The lot's own question first: it is about what was typed, and the answer changes what else is asked.
        if (occupied.value !== null && form.household_choice === '') {
            return `${occupied.value.unit} already has a household. Choose what this person is before saving.`
        }

        if (asksWhichHousehold.value && form.household_id === null) {
            return `${occupied.value.unit} has ${occupied.value.households.length} households. Choose which one this person joins.`
        }

        if (missingLine.value !== null) {
            return missingLine.value
        }

        /*
         * NOTHING ENABLED REFUSES WHEN PRESSED (Law 1; seen on the local app, 12 Oct). "No email" ticked with "Resident
         * verifies in the app" still chosen was a live Add resident that the server refused: an invite needs an address.
         * The save waits, and says why, until the officer vouches for them.
         */
        return form.no_email && form.verification === 'invite' ? 'Somebody with no email cannot be sent an invite. Choose Mark verified now.' : null
    })

    /*
     * EVERY REFUSAL IS SAID (M80 §2.1, EST-B-03a). What is not drawn under its own field is said above Save.
     */
    const drawnUnderTheirField = computed(() => new Set(['phase', 'lot', 'full_name', 'email', 'phone', ...(occupied.value ? ['household_choice', 'household_id'] : [])]))
    const otherRefusals = computed(() => Object.entries(form.errors).filter(([field]) => !drawnUnderTheirField.value.has(field)))

    const submit = () => {
        /*
         * A form submits on Enter from any field and a disabled button does not stop it. The route is gated on
         * `estate.residents.create` and the service refuses a lot the estate does not have, so this is only about not
         * sending a resident that is going to bounce.
         */
        if (submitBlockedBy.value !== null) {
            return
        }

        form.post(postTo(), { preserveScroll: true, ...postOptions })
    }

    return reactive({
        form,
        start,
        askIfStale,
        phaseReferences,
        resolvedUnit,
        unmatchedNote,
        moreNote,
        lotPlaceholder,
        previewText,
        occupied,
        asksWhichHousehold,
        submitBlockedBy,
        otherRefusals,
        submit,
    })
}
