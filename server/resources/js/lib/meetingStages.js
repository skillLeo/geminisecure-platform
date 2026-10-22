/**
 * A MEETING'S STAGE IN THE VOCABULARY'S WORDS (M96 §2; M98 §3.5, Hassam, 11 Oct): the workflow set and nothing else.
 *
 * The server names eight stages (`Meeting::STAGE_LABELS`). Four have no word of their own in the set, so each reads as
 * the closest, written down for Hassam (M98: "If none fits, write it down and use the closest"): Scheduled reads
 * Confirmed (published, households told the date), Held reads Completed, Minutes published and Archived read Closed
 * (the record is finished; archived was already Closed on the chart of accounts), Postponed reads Pending (not then,
 * no new date yet). The stage key itself, and every colour keyed to it, is unchanged; the server's own label is kept
 * because the Minutes Archive report reads it too.
 */
export const MEETING_STAGE_WORDS = {
    draft: 'Draft',
    scheduled: 'Confirmed',
    in_progress: 'In progress',
    held: 'Completed',
    minutes_published: 'Closed',
    archived: 'Closed',
    postponed: 'Pending',
    cancelled: 'Cancelled',
}

/** The word a stage reads as; a stage this list does not know keeps the server's own label. */
export const meetingStageWord = (stage, fallback = '') => MEETING_STAGE_WORDS[stage] ?? fallback
