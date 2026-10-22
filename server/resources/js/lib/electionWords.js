/**
 * AN ELECTION'S STAGE AND A NOMINATION'S STATE IN THE VOCABULARY'S WORDS (M96 §2; M98 §3.5, Hassam, 11 Oct): the
 * workflow set and nothing else, as `meetingStages.js` does for a meeting.
 *
 * The server names nine stages for a ballot (`Ballot::STAGES`, which the stepper prints as its steps) and four states
 * for a nomination (`Nomination::statusLabel()`). Each badge reads as the closest word in the set, written down for
 * Hassam (M98: "If none fits, write it down and use the closest"): a running election reads In progress, and the
 * stepper beside it says which step; candidate vetting and the tally read Under review, because somebody is checking
 * them; a closed poll not yet counted reads Pending; a certified result reads Completed. An approved nomination reads
 * Confirmed, and one asked for more reads Action required. The keys, and every colour keyed to them, are unchanged; the
 * server's own labels stay, because the stepper and the reports read them.
 */
export const BALLOT_STAGE_WORDS = {
    draft: 'Draft',
    nominations_open: 'In progress',
    nominations_closed: 'In progress',
    candidate_vetting: 'Under review',
    campaign_period: 'In progress',
    voting_open: 'In progress',
    voting_closed: 'Pending',
    tally: 'Under review',
    certified: 'Completed',
    cancelled: 'Cancelled',
}

export const NOMINATION_WORDS = {
    pending: 'Pending',
    approved: 'Confirmed',
    rejected: 'Rejected',
    more_info: 'Action required',
}

/** The word a ballot's stage reads as; a stage this list does not know keeps the server's own label. */
export const ballotStageWord = (stage, fallback = '') => BALLOT_STAGE_WORDS[stage] ?? fallback

/** The word a nomination's state reads as; a state this list does not know keeps the server's own label. */
export const nominationWord = (status, fallback = '') => NOMINATION_WORDS[status] ?? fallback

/**
 * THE STEPPER'S STEPS, AND A PAPER'S STAGE IN A SENTENCE, in sentence case (Security's dev-server note, 12 Oct: the
 * stepper kept the server's capitals, "Nominations Open", "Certified & Published"). The server's labels stay as they
 * are, because its refusals and reports name them.
 */
export const STEP_WORDS = {
    draft: 'Draft',
    nominations_open: 'Nominations open',
    nominations_closed: 'Nominations closed',
    candidate_vetting: 'Candidate vetting',
    campaign_period: 'Campaign period',
    voting_open: 'Voting open',
    voting_closed: 'Voting closed',
    tally: 'Tally',
    certified: 'Certified and published',
    cancelled: 'Cancelled',
}

/** A step's words; a stage this list does not know keeps the server's own label. */
export const stepWord = (stage, fallback = '') => STEP_WORDS[stage] ?? fallback

/**
 * The other papers of a year's election, each with its stage, for one line under what a page shows: "Ballot B (Phase
 * Leadership), nominations open; Mobile check, voting open". Empty when the year has one paper.
 */
export const otherPapers = (papers) =>
    papers.length < 2
        ? ''
        : papers
              .filter((paper) => !paper.shown)
              .map((paper) => `${paper.title}, ${stepWord(paper.stage, paper.stage).toLowerCase()}`)
              .join('; ')
