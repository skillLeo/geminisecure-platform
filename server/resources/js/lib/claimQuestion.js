/**
 * WHAT ANSWERING A GUARD'S REQUEST FOR A SHIFT TELLS, SAID BEFORE IT IS SENT (DR-075, the register's Part 7).
 *
 * A guard asks for a shift that needs a guard from the Guard App, and the Schedule and the classic Roster answer it
 * through the same two routes. Both answers reach a phone: Approve puts the guard on the shift and tells them, and every
 * other guard who asked for the same shift is told no (`ShiftClaims::approve`); Decline tells that one guard no. A
 * control that starts contact confirms first, naming who is told and how, so both screens ask, in these words. The
 * other guards are read from the requests the server sent, by their shift.
 *
 * In the Schedule's words (M30 §9): a guard "asks for" a shift; no "claim", no "open shift".
 *
 *   Approve, with one other guard on the same shift:
 *     title  "Put Kemar Blake on Main Gate, Tue 20 Oct, 7:00 AM – 5:00 PM JA?"
 *     lines  "Kemar Blake is told in the Guard App that the shift is theirs."
 *            "Andre Campbell, who also asked for it, is told no."
 *            "Nobody else is told."
 *
 * @param {{id: number, shift_id: number, guard: string, post: string, window: string}} claim
 * @param {'approve'|'decline'} verb
 * @param {Array<{id: number, shift_id: number, guard: string}>} waiting  every request the screen was sent
 * @returns {{title: string, lines: string[], confirm: string, tone: string}}
 */
export function claimQuestion(claim, verb, waiting = []) {
    const shift = `${claim.post}, ${claim.window}`

    if (verb === 'decline') {
        return {
            title: `Decline ${claim.guard} for ${shift}?`,
            lines: [
                `${claim.guard} is told no in the Guard App. The shift still needs a guard, and any other guard can ask for it.`,
                'Nobody else is told.',
            ],
            confirm: 'Decline',
            tone: 'danger',
        }
    }

    const others = waiting.filter((other) => other.shift_id === claim.shift_id && other.id !== claim.id).map((other) => other.guard)

    const toldNo =
        others.length === 0
            ? []
            : [others.length === 1 ? `${others[0]}, who also asked for it, is told no.` : `The ${others.length} other guards who asked for it, ${listed(others)}, are told no.`]

    return {
        title: `Put ${claim.guard} on ${shift}?`,
        lines: [`${claim.guard} is told in the Guard App that the shift is theirs.`, ...toldNo, 'Nobody else is told.'],
        confirm: 'Approve',
        tone: 'primary',
    }
}

/** "Andre Campbell, Ricardo Shaw and Omar Brown". */
function listed(names) {
    return names.length === 1 ? names[0] : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`
}
