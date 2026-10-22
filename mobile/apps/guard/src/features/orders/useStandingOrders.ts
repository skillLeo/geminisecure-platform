/**
 * Everything the two standing-orders screens decide — boards guard-app-11 (the orders in
 * force, and their acknowledgement) and guard-app-16 (order history: the guard's proof of
 * what they were instructed, and when).
 *
 *   WHICH SITE      the current shift's (`shifts.me.current` → shift.site.id). Never typed, never chosen.
 *   OFFLINE         the orders' text is cached and read with no signal; an acknowledgement is captured on
 *                   the phone first (`submit()`), sent when it can be, with the time it actually happened.
 *   BY VERSION ID   the guard acknowledges the version they READ. A revision published meanwhile has a
 *                   different id, so the server refuses the old one (409 orders_changed): the refusal is
 *                   shown in the server's words, the new version is fetched, and reading starts again.
 *   READ FIRST      nothing can be ticked or sent until the guard has scrolled to the end of the text;
 *                   then the box must be ticked; only then does the button send.
 *   NO DEAD CONTROLS  when nothing needs acknowledging the orders are read-only with "You are up to date".
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { fidelityScreenId, isFidelity, useOfflineCache } from '@gs/core'
import { isApiError, type Api } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { OUTBOX_LABEL, type OutboxOp } from '@gs/core/offline'
import { ordersFixtures } from '@/fidelity/orders'
import { drain, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { acknowledgedLabel, displayTitle, effectiveLabel, parseOrdersBody, type OrderEntry } from './ordersText'

type Orders = Api.OrdersCurrentResponse
/** The server answers `version_id: null` for a set whose version row is missing; the generated type does not say so. */
type Item = Omit<Orders['items'][number], 'version_id'> & { version_id: number | string | null }

export const SCROLL_FIRST = 'Scroll to the end of the orders first.'
export const TICK_FIRST = 'Tick the box to confirm you have read the orders.'

/** How long the screen waits for the server's answer before moving on — the act is already saved on the phone. */
const ANSWER_WAIT_MS = 4000

type Status = 'loading' | 'no_shift' | 'denied' | 'error' | 'ready'

const sentence = (error: unknown): string | null => (isApiError(error) ? error.message : null)
const same = (p: number | string | null | undefined, q: number | string | null | undefined) => p !== null && p !== undefined && q !== null && q !== undefined && String(p) === String(q)

/* ---- the data both screens stand on ------------------------------------------------ */

function useOrdersData() {
  const fixture = isFidelity ? ordersFixtures[fidelityScreenId() ?? ''] ?? null : null
  const online = useAppSelector((s) => s.network.online)

  const shiftQuery = guardApi.useShiftsMeCurrentQuery(undefined, { skip: !!fixture })
  const cachedShift = useOfflineCache('shift.current', shiftQuery.data)
  const shift = fixture ? fixture.shift : (cachedShift?.shift ?? null)
  const siteId = shift?.site.id
  const canAsk = !fixture && siteId !== undefined

  const ordersQuery = guardApi.useOrdersCurrentQuery({ site: siteId ?? '' }, { skip: !canAsk })
  const cachedOrders = useOfflineCache('orders.current', ordersQuery.data)
  // One handset, one site — but a cached answer for another site is never shown as this one's.
  const orders: Orders | null = fixture ? fixture.orders : cachedOrders && same(cachedOrders.site_id, siteId) ? cachedOrders : null

  const [refreshing, setRefreshing] = useState(false)
  const refetchShift = shiftQuery.refetch
  const refetchOrders = ordersQuery.refetch
  const refresh = useCallback(async () => {
    if (fixture) return
    setRefreshing(true)
    try {
      await refetchShift()
      if (canAsk) await refetchOrders()
    } finally {
      setRefreshing(false)
    }
  }, [fixture, canAsk, refetchShift, refetchOrders])

  const ordersError = canAsk ? ordersQuery.error : undefined
  const denied = isApiError(ordersError) && ordersError.status === 403
  const status: Status = denied
    ? 'denied'
    : orders
      ? 'ready'
      : cachedShift !== undefined && shift === null
        ? 'no_shift'
        : shiftQuery.error && cachedShift === undefined
          ? 'error'
          : ordersError
            ? 'error'
            : 'loading'

  const stale: string | null =
    fixture || !orders
      ? null
      : !online
        ? "No signal: these are the orders saved on this phone. Anything you acknowledge is kept here and sent when you're back online."
        : ordersError || (shiftQuery.error && ordersQuery.data === undefined)
          ? "A newer version couldn't be checked for just now: these are the orders saved on this phone. Pull down to try again."
          : null

  return {
    fixture,
    online,
    shift,
    orders,
    status,
    errorMessage: sentence(ordersError) ?? sentence(shiftQuery.error),
    stale,
    refreshing,
    refresh,
    canAsk,
    refetchOrders,
  }
}

/* ---- guard-app-11 · Standing Orders ------------------------------------------------ */

export type DrawnSection = { key: string; heading: string | null; caption: string | null; entries: OrderEntry[] }

export type AckState =
  /** A version is waiting for this guard: the checkbox and the button are drawn. */
  | { state: 'needed' }
  /** Acknowledged on this phone, not yet answered by the server. */
  | { state: 'queued'; title: string; detail: string }
  /** Nothing to acknowledge — read-only. */
  | { state: 'up_to_date'; detail: string }

export type StandingOrdersModel = {
  status: Status
  errorMessage: string | null
  bannerTitle: string
  bannerDetail: string
  sections: DrawnSection[]
  stale: string | null
  /** The server refused an earlier acknowledgement (orders revised, not this guard's post, licence lapsed) — its own words. */
  refusal: { title: string; body: string } | null
  ack: AckState
  /** Changes whenever WHAT is to be acknowledged changes; the screen starts the read again. */
  versionKey: string
  readToEnd: boolean
  checked: boolean
  tickMissing: boolean
  busy: boolean
  refreshing: boolean
  markReadToEnd: () => void
  toggleChecked: () => void
  acknowledge: () => void
  refresh: () => void
}

const isWaiting = (op: OutboxOp) => op.state === 'pending' || op.state === 'syncing'
/** The version id an acknowledgement was captured FOR. */
const opVersion = (op: OutboxOp): number | string | null => {
  const version = op.params.version
  return typeof version === 'number' || typeof version === 'string' ? version : null
}

export function useStandingOrders(onDone: () => void): StandingOrdersModel {
  const d = useOrdersData()
  const { fixture, online, shift, orders, canAsk, refetchOrders } = d
  const queue = useAppSelector((s) => s.outbox.ops)

  // The store mirrors the queue without its params; the version each acknowledgement was FOR is on the op itself.
  const ackOps = useMemo(() => (fixture ? [] : outbox.all().filter((op) => op.endpoint === 'orders.acknowledge')), [fixture, queue]) // eslint-disable-line react-hooks/exhaustive-deps

  const items = useMemo<Item[]>(() => orders?.items ?? [], [orders])
  const opsFor = useCallback((item: Item) => ackOps.filter((op) => same(opVersion(op), item.version_id)), [ackOps])

  const unsigned = useMemo(() => items.filter((i) => i.requires_acknowledgement && i.acknowledged_at === null), [items])
  const toAcknowledge = useMemo(() => unsigned.filter((i) => i.version_id !== null && !opsFor(i).some((op) => op.state !== 'refused')), [unsigned, opsFor])
  const waiting = useMemo(() => unsigned.flatMap((i) => opsFor(i).filter(isWaiting)), [unsigned, opsFor])
  const versionKey = toAcknowledge.map((i) => String(i.version_id)).join(',')

  const [readToEnd, setReadToEnd] = useState(fixture ? fixture.readToEnd : false)
  const [checked, setChecked] = useState(false)
  const [tickMissing, setTickMissing] = useState(false)
  const [busy, setBusy] = useState(false)

  // A different version to acknowledge is a different text: the read, and the tick, start again.
  useEffect(() => {
    if (fixture) return
    setReadToEnd(false)
    setChecked(false)
    setTickMissing(false)
  }, [fixture, versionKey])

  // A refusal means the text on screen may be the old one. Ask again, once per refusal, when there is signal.
  const refusedOps = useMemo(() => ackOps.filter((op) => op.state === 'refused'), [ackOps])
  const asked = useRef(new Set<string>())
  useEffect(() => {
    if (!online || !canAsk) return
    const fresh = refusedOps.filter((op) => !asked.current.has(op.id))
    if (fresh.length === 0) return
    fresh.forEach((op) => asked.current.add(op.id))
    void refetchOrders()
  }, [refusedOps, online, canAsk, refetchOrders])

  const postName = shift?.post.name ?? ''
  const siteName = shift?.site.name ?? null

  const acknowledge = useCallback(async () => {
    if (busy || !readToEnd || toAcknowledge.length === 0) return
    if (!checked) return setTickMissing(true)
    if (fixture) return // a board's data is never sent anywhere
    setBusy(true)
    try {
      const sent: string[] = []
      for (const item of toAcknowledge) {
        if (item.version_id === null) continue
        // Captured on the phone FIRST, with the time it happened; sent now if there is signal, later if not.
        const op = await submit({ endpoint: 'orders.acknowledge', params: { version: item.version_id }, label: `Standing orders acknowledged · ${postName || displayTitle(item.title, siteName)}`, kind: 'orders' })
        sent.push(op.id)
      }
      if (online) {
        // With signal the answer is usually a moment away. A refusal keeps the guard HERE, in front of the new text.
        await Promise.race([drain(), new Promise<void>((resolve) => setTimeout(resolve, ANSWER_WAIT_MS))])
        if (outbox.all().some((op) => sent.includes(op.id) && op.state === 'refused')) return
      }
      onDone()
    } finally {
      setBusy(false)
    }
  }, [fixture, busy, readToEnd, checked, toAcknowledge, postName, siteName, online, onDone])

  /* ---- what is drawn ---- */

  const primary: Item | null = toAcknowledge[0] ?? unsigned[0] ?? items.find((i) => i.requires_acknowledgement) ?? items[0] ?? null
  const primaryWaiting = primary ? opsFor(primary).find(isWaiting) : undefined

  const sections = useMemo<DrawnSection[]>(() => {
    const ordered = primary ? [primary, ...items.filter((i) => i !== primary)] : items
    return ordered.flatMap((item) => {
      const title = displayTitle(item.title, siteName)
      const parsed = parseOrdersBody(item.body)
      const blocks = parsed.length > 0 ? parsed : [{ heading: null, entries: [] }]
      const headedByTitle = blocks[0]!.heading === null
      const meta = `Version ${item.version} · Effective ${effectiveLabel(item.effective_on)}${
        !item.requires_acknowledgement ? '' : item.acknowledged_at ? ` · acknowledged ${acknowledgedLabel(item.acknowledged_at)}` : ' · needs your acknowledgement'
      }`
      return blocks.map((block, i) => ({
        key: `${item.set_id}:${item.version}:${i}`,
        heading: i === 0 && headedByTitle ? title : block.heading,
        // The banner speaks for the first set; every other set says which version it is, under its first heading.
        caption: i === 0 && item !== primary ? (headedByTitle ? meta : `${title} · ${meta}`) : null,
        entries: block.entries,
      }))
    })
  }, [items, primary, siteName])

  const effective = primary ? `Effective ${effectiveLabel(primary.effective_on)}` : ''
  const bannerTitle = primary ? `${primary.requires_acknowledgement && postName ? postName : displayTitle(primary.title, siteName)} · Version ${primary.version}` : ''
  const bannerDetail = !primary
    ? ''
    : !primary.requires_acknowledgement
      ? effective
      : primary.acknowledged_at
        ? `${effective} · acknowledged ${acknowledgedLabel(primary.acknowledged_at)}`
        : primaryWaiting
          ? `${effective} · acknowledgement saved on this phone`
          : `${effective} · you must acknowledge to stay on post`

  const latestRefusal = refusedOps[refusedOps.length - 1]
  const refusal =
    latestRefusal && toAcknowledge.length > 0
      ? {
          title: 'Your acknowledgement was not accepted',
          body: `${latestRefusal.error?.message ?? 'The server did not accept it.'}${
            toAcknowledge.some((i) => same(opVersion(latestRefusal), i.version_id)) ? '' : ' The version now in force is shown below: read it, then acknowledge it.'
          }`,
        }
      : null

  const lastSigned = items.filter((i) => i.requires_acknowledgement && i.acknowledged_at !== null)[0]
  const stuck = unsigned.find((i) => i.version_id === null)
  // Confirmed by the server a moment ago; the list on screen has simply not been re-read yet.
  const received = unsigned.some((i) => opsFor(i).some((op) => op.state === 'confirmed'))
  const ack: AckState =
    toAcknowledge.length > 0
      ? { state: 'needed' }
      : waiting.length > 0
        ? { state: 'queued', title: 'Acknowledgement saved on this phone', detail: `${OUTBOX_LABEL[waiting[0]!.state]}: ${online ? 'it is being sent now.' : "it will be sent the moment you're back online."}` }
        : stuck
          ? { state: 'up_to_date', detail: `Version ${stuck.version} cannot be acknowledged from the app yet. Tell your supervisor: nothing is needed from you here.` }
          : { state: 'up_to_date', detail: received ? 'Your acknowledgement has been received.' : lastSigned?.acknowledged_at ? `Version ${lastSigned.version} acknowledged ${acknowledgedLabel(lastSigned.acknowledged_at)}.` : 'Nothing here needs your acknowledgement.' }

  const markReadToEnd = useCallback(() => setReadToEnd(true), [])
  const toggleChecked = useCallback(() => {
    setTickMissing(false)
    setChecked((on) => !on)
  }, [])

  return {
    status: d.status,
    errorMessage: d.errorMessage,
    bannerTitle,
    bannerDetail,
    sections,
    stale: d.stale,
    refusal,
    ack,
    versionKey,
    readToEnd,
    checked,
    tickMissing,
    busy,
    refreshing: d.refreshing,
    markReadToEnd,
    toggleChecked,
    acknowledge: () => void acknowledge(),
    refresh: () => void d.refresh(),
  }
}

/* ---- guard-app-16 · Order History -------------------------------------------------- */

export type HistoryRow = {
  key: string
  title: string
  detail: string
  /** The version in force: drawn with the amber tile and the "Current" tag; opens the orders. */
  current: boolean
  /** Why an earlier version's line does not open its text. */
  explain: string
}

export type HistoryGroup = { key: 'post' | 'other'; label: string; rows: HistoryRow[] }

export type OrderHistoryModel = {
  status: Status
  errorMessage: string | null
  title: string
  groups: HistoryGroup[]
  stale: string | null
  refreshing: boolean
  refresh: () => void
}

export function useOrderHistory(): OrderHistoryModel {
  const d = useOrdersData()
  const { shift, orders } = d

  const groups = useMemo<HistoryGroup[]>(() => {
    if (!orders) return []
    const items: Item[] = orders.items
    const siteName = shift?.site.name ?? null
    const postSets = items.filter((i) => i.requires_acknowledgement)
    const inPost = (setId: number | string) => postSets.some((i) => same(i.set_id, setId))
    const severalSets = new Set(postSets.map((i) => String(i.set_id))).size > 1
    const isCurrent = (setId: number | string, version: number | string) => items.some((i) => same(i.set_id, setId) && same(i.version, version))

    // The version in force that this guard has NOT yet acknowledged still belongs at the top of the post's list.
    const pending: HistoryRow[] = postSets
      .filter((i) => i.acknowledged_at === null)
      .map((i) => ({ key: `pending:${i.set_id}:${i.version}`, title: severalSets ? `${displayTitle(i.title, siteName)}: Version ${i.version}` : `Version ${i.version}`, detail: 'Not acknowledged yet', current: true, explain: '' }))

    const signed = orders.history.map((h, n) => {
      const post = inPost(h.set_id)
      const name = displayTitle(h.title, siteName)
      const when = acknowledgedLabel(h.acknowledged_at)
      return {
        post,
        row: {
          key: `${h.set_id}:${h.version}:${h.acknowledged_at}:${n}`,
          title: post && !severalSets ? `Version ${h.version}` : `${name}: Version ${h.version}`,
          detail: `Acknowledged ${when}`,
          current: isCurrent(h.set_id, h.version),
          explain: post
            ? `Only the orders in force are kept on this handset. This line is your record of acknowledging version ${h.version}: ${when}. Ask your supervisor if you need its text.`
            : `${name} orders are read on a handset posted there. This line is your record of acknowledging version ${h.version}: ${when}.`,
        } satisfies HistoryRow,
      }
    })

    const built: HistoryGroup[] = [
      { key: 'post', label: 'All versions', rows: [...pending, ...signed.filter((x) => x.post).map((x) => x.row)] },
      { key: 'other', label: 'Other posts you cover', rows: signed.filter((x) => !x.post).map((x) => x.row) },
    ]
    return built.filter((g) => g.rows.length > 0)
  }, [orders, shift])

  return {
    status: d.status,
    errorMessage: d.errorMessage,
    title: shift ? `Order history: ${shift.post.name}` : 'Order history',
    groups,
    stale: d.stale,
    refreshing: d.refreshing,
    refresh: () => void d.refresh(),
  }
}
