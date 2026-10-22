/**
 * LIVE CHAT WITH GEMINI SECURITY (board resident-app-39's "Live chat"; M50 §4.3) — the screen's model.
 *
 * The conversation is the server's (`support.chat.show`), read again whenever it moves — the live `chat.changed` on this
 * account's own channel (features/live/handlers/records.ts), and every fifteen seconds while the screen is open for when
 * the socket is not up — with what is still on its way from the phone drawn under it (liveChat.ts).
 *
 * A MESSAGE GOES THROUGH THE QUEUE: at once with a connection; with none it waits on the phone and goes the moment there
 * is one, never dropped. Its `client_key` makes a message the queue sends twice one row. A demonstration session sends
 * nothing and says why.
 *
 * READING IT IS BEING SHOWN IT. The server marks Gemini Security's answers "Delivered" when this screen reads the
 * conversation, so only this screen reads it; Help & support's row takes the reply time from the copy kept here
 * (`chatRowLine`, `readOfflineCache`) and never asks the server itself.
 */

import { useCallback, useMemo, useRef, useState } from 'react'
import { useFocusEffect } from 'expo-router'
import { isFidelity, readOfflineCache, useOfflineCache } from '@gs/core'
import type { Api } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { newUuid } from '@gs/core/lib'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { offer, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { CHAT_ENDPOINT, CHAT_MAX, chatIntro, chatRowLine, chatThreadOf, type ChatItem } from './liveChat'

/** Where the conversation is kept on the phone: this screen's copy, and Help & support's reply time. */
export const CHAT_CACHE = 'support.chat'

const POLL_MS = 15_000

export type LiveChatModel = {
  status: 'loading' | 'ready' | 'error'
  errorMessage: string | null
  items: ChatItem[]
  /** Who answers, how fast when that is measured, and what this is not for. */
  intro: string
  /** Gemini Security marked it resolved; the next message opens it again. */
  resolved: boolean
  draft: string
  setDraft: (text: string) => void
  canSend: boolean
  send: () => void
  retry: (opId: string) => void
  problem: string | null
  clearProblem: () => void
  refresh: () => void
}

/** Help & support's "Live chat" line, from the copy this screen kept — never a read of its own (see above). */
export function useChatRowLine(): string {
  const kept = isFidelity ? undefined : readOfflineCache<Api.SupportChatShowResponse>(CHAT_CACHE)
  return chatRowLine(kept?.typical_reply_minutes)
}

export function useLiveChat(): LiveChatModel {
  const online = useAppSelector((s) => s.network.online)
  const queue = useAppSelector((s) => s.outbox.ops)
  const refusal = useDemoRefusal()

  // Asked again every fifteen seconds only while the screen is in front.
  const [focused, setFocused] = useState(false)
  useFocusEffect(
    useCallback(() => {
      setFocused(true)
      return () => setFocused(false)
    }, []),
  )

  const read = residentApi.useSupportChatShowQuery(undefined, { skip: isFidelity, pollingInterval: focused ? POLL_MS : 0, refetchOnMountOrArgChange: true })
  const chat = useOfflineCache<Api.SupportChatShowResponse>(CHAT_CACHE, read.data)

  const items = useMemo(
    () => (isFidelity ? [] : chatThreadOf(chat?.messages, outbox.all(), online)),
    // The queue's own acts carry the words (the store's view of it does not): `queue` changing is what is on its way changing.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [queue, chat, online],
  )

  const [draft, setDraft] = useState('')
  const [problem, setProblem] = useState<string | null>(null)
  const sending = useRef(false)

  const queueMessage = useCallback(async (words: string): Promise<boolean> => {
    const text = words.trim().slice(0, CHAT_MAX)
    if (isFidelity || text === '' || sending.current) return false
    if (refusal) {
      setProblem(refusal)
      return false
    }
    sending.current = true
    setProblem(null)
    try {
      const op = await submit({ endpoint: CHAT_ENDPOINT, body: { body: text, client_key: newUuid() }, label: 'Message to Gemini Security', kind: 'message' })
      // Not behind the rest of the queue: a question goes on its own.
      void offer(op.id).catch(() => undefined)
      return true
    } catch (e) {
      setProblem(e instanceof Error ? e.message : 'This message could not be kept on the phone. Try again.')
      return false
    } finally {
      sending.current = false
    }
  }, [refusal])

  const send = useCallback(() => {
    void queueMessage(draft).then((kept) => { if (kept) setDraft('') })
  }, [draft, queueMessage])

  const retry = useCallback((opId: string) => {
    const op = outbox.all().find((o) => o.id === opId)
    const words = typeof op?.body?.body === 'string' ? op.body.body : ''
    if (!op || words === '') return
    void outbox.dismiss(opId).then(() => queueMessage(words))
  }, [queueMessage])

  const refetch = read.refetch
  const refresh = useCallback(() => { if (!isFidelity) void refetch() }, [refetch])

  // Loading is never an error, and never an empty conversation: a copy kept on the phone, or the server's answer, first.
  const status: LiveChatModel['status'] = isFidelity || chat !== undefined ? 'ready' : read.error ? 'error' : 'loading'
  const errorMessage = read.error && 'message' in read.error && typeof read.error.message === 'string' ? read.error.message : null

  return {
    status,
    errorMessage: status === 'error' ? (errorMessage ?? 'The conversation could not be loaded.') : null,
    items,
    intro: chatIntro(chat?.typical_reply_minutes),
    resolved: chat?.open === false,
    draft,
    setDraft: (text: string) => setDraft(text.slice(0, CHAT_MAX)),
    canSend: draft.trim() !== '',
    send,
    retry,
    problem,
    clearProblem: () => setProblem(null),
    refresh,
  }
}
