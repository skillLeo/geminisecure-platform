/**
 * EVERY OTHER RECORD ON THE RESIDENT'S PHONE, FROM THE SOCKET (M11 §2; the server's D-175).
 *
 * The server says `{entity}.changed` or `{entity}.removed` — which record, nothing about it — on the channels this
 * account holds (its own, its household's, its community's and its phase's). The phone asks again for every read
 * of that kind a screen is showing, through the same endpoint and allowlist as ever:
 *
 *   dues       the Dues screen and Home's balance: the Treasurer records a payment and both move (the client's
 *              named case), and a charge posted moves them too
 *   ticket     My requests and a request's page: a status change, a note from the office, a closing
 *   notice     Notices, and Home's headline notice
 *   meeting    Meetings, a meeting's page, its minutes
 *   election   Elections: a stage change, a nomination. Never a vote — no screen follows another household's
 *
 * Nothing here writes anything or touches the outbox: a queued report or RSVP keeps its own state.
 */

import type { TagType } from '@gs/core/api'
import { invalidateKind, invalidateRecord, type LiveHandler } from '@gs/core/live'

const refetch = (kind: TagType): LiveHandler => () => invalidateKind(kind)

/*
 * LIVE CHAT (M50 §4.3): an answer from Gemini Security, or "Delivered" on the resident's own message — `chat.changed` on
 * this account's own channel. Only the conversation is read again (its one read, `support.chat.show`), never the rest of
 * the household's reads that share its capability; and only while the chat screen holds it, since reading it is what
 * tells the server the resident was shown the answer.
 */
const chat: LiveHandler = () => invalidateRecord('household', 'support.chat.show')

/** entity → the reads that show it on this app. */
export const RECORD_KINDS: Record<string, TagType[]> = {
  dues: ['dues', 'payments'],
  ticket: ['tickets'],
  notice: ['notices'],
  meeting: ['meetings'],
  election: ['elections'],
}

export const recordHandlers: Record<string, LiveHandler[]> = {
  ...Object.fromEntries(
    Object.entries(RECORD_KINDS).flatMap(([entity, kinds]) => [
      [`${entity}.changed`, kinds.map(refetch)],
      [`${entity}.removed`, kinds.map(refetch)],
    ]),
  ),
  'chat.changed': [chat],
  'chat.removed': [chat],
}
