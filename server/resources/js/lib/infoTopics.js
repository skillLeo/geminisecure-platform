/**
 * EVERY `i` IN BOTH CONSOLES, ONE FILE, ONE KEY PER TOPIC (M32 F12).
 *
 * What a rule is and what it is for, in two or three sentences, and what happens next where that helps — not a
 * manual. A sentence is corrected here and nowhere else; a page names its topic by key
 * (`<InfoButton topic="contractedLimit" />`). Every key is opened and closed by `tests/js/info-buttons.test.mjs`.
 *
 * Each says what the server does, as it does it:
 *   delinquency      RestrictionPolicy (D-024): eligible at 90 days overdue, restricted once 14 days' written notice has
 *                    run, guests, visitors, deliveries and contractors only; a resident's own entry and emergency
 *                    vehicles never; an agreed plan, while met, lifts it. The guard's only words: GATE_SENTENCE.
 *   contractedLimit  EstateStructure::fullRefusal() and overContract() (F2); raised on the client's Plan, with a reason.
 *   lotRanges        EstateStructure::lotPanel() (F6): a lot number is used once in an estate, in one phase.
 *   geofence         ClockInFence and SupervisorOverride (M12 §1): the gate's circle, else the estate's; outside it, a
 *                    supervisor's PIN, flagged on the shift and reported to the Head of Security with the distance.
 *   journals         GrantedTables::ESTATE_APPEND_ONLY and the journals' triggers: no UPDATE, no DELETE.
 *   householdRoles   AccessLevels and MeController::primaryOnly(): only the primary adds, invites and removes people.
 *   shiftRules       ShiftPlanner::assess() and Roster::checkWindow() (M35 §3): from today at the estate, up to 24 hours,
 *                    an end earlier in the day than the start is the next morning, and a gate or a guard on one shift at
 *                    a time.
 */
export const INFO = {
    delinquency: {
        title: 'The arrears restriction',
        lines: [
            'A household 90 days behind on its dues, once 14 days\' written notice has run, has its guests, deliveries and contractors held at the gate. The guard is told only "Access restricted — contact management", never an amount.',
            'A resident\'s own entry, and emergency and medical vehicles, are never held.',
        ],
        next: 'An agreed payment plan lifts it while every instalment is met. A missed instalment puts it back.',
    },
    contractedLimit: {
        title: 'Contracted units',
        lines: [
            'How many units this estate\'s contract with GeminiSecure covers. No phase and no lot can be added past it: the server refuses it, whatever a form says.',
            'Raising it is a change to the contract, made by GeminiSecure on the client\'s Plan, and the reason is recorded.',
        ],
    },
    lotRanges: {
        title: 'Lots and phases',
        lines: [
            'A lot number is used once in an estate, in one phase. A new phase takes a run of free lots, and this panel shows which are in use and which are free before anything is typed.',
            'A run that overlaps another phase, or goes past the contracted units, is refused before anything is saved.',
        ],
    },
    geofence: {
        title: 'How close a guard must be',
        lines: [
            'A guard clocks in at a gate only from inside its circle, measured by the phone and judged again by the server. A gate with no location falls back to the estate\'s own area.',
            'Outside it, the shift starts only with a supervisor\'s PIN, which is recorded on the shift and reported to the Head of Security with the distance.',
        ],
    },
    journals: {
        title: 'Entries are never changed',
        lines: [
            'Every amount the estate records — a charge, a payment, a bill — is posted as a journal entry, and a posted entry is never edited or deleted: the database itself refuses both.',
            'A mistake is put right by another entry beside it, so the record of what happened, and when, stays whole.',
        ],
    },
    householdRoles: {
        title: 'Primary and member',
        lines: [
            'Every household has one primary resident. In the Resident App only the primary adds, invites and removes the household\'s people; members are its other adults, and use everything else — passes, dues, voting.',
            'Only the estate office hands a household to a new primary.',
        ],
    },
    shiftRules: {
        title: 'When a shift can be posted',
        lines: [
            'A shift is posted forward from today at the estate, never into the past: a shift for last Tuesday would invent a gap nobody could have filled, and the coverage board would report the estate uncovered on a night it was covered.',
            'It runs up to 24 hours, and one that ends earlier in the day than it starts ends the next morning — a night shift — as the form says.',
            'A gate holds one shift of each kind at a time and a guard stands one at a time, and either clash is shown before anything is saved.',
        ],
    },
}
