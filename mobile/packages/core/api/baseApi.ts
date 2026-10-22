/**
 * The RTK Query root. The endpoints themselves are GENERATED from the server's
 * catalogue (api/generated/*, `npm run api:generate`) and injected into this, so
 * every one of them gets the cache settings below.
 *
 *   keepUnusedDataFor 300          data stays 5 minutes after the last screen using it
 *   refetchOnMountOrArgChange 60   refetch only when the data is older than a minute
 *   refetchOnReconnect true        signal returns → what is on screen refreshes
 *   refetchOnFocus false           turned on per hook for live data only
 *
 * The server has no push and no sockets for handsets: the apps POLL.
 * Polling is set per hook on the screens that are live, never globally.
 * Cache tags are one per server capability (generated/tags.ts).
 *
 * ONE QUESTION, ONE CACHE ENTRY (M11 A4). Every generated query reads its argument as `arg ?? {}`, so
 * `useXQuery()`, `useXQuery(undefined)` and `useXQuery({})` send the server the same request — but RTK
 * Query's default key tells `undefined` and `{}` apart, and so kept two entries and sent that request
 * TWICE: the Resident notifier polled `visitor_passes.index(undefined)` while the Visitors screen held
 * `visitor_passes.index({})`. An argument that says nothing is keyed as no argument.
 */

import { isPlainObject } from '@reduxjs/toolkit'
import { createApi, defaultSerializeQueryArgs, type SerializeQueryArgs } from '@reduxjs/toolkit/query/react'
import { baseQuery } from './baseQuery'
import { TAG_TYPES } from './generated/tags'

export { TAG_TYPES, type TagType } from './generated/tags'

/** `undefined`, `null`, `{}` and an object of nothing but `undefined`s all ask the same thing. */
export const saysNothing = (args: unknown): boolean =>
  args === undefined || args === null || (isPlainObject(args) && Object.values(args as Record<string, unknown>).every((v) => v === undefined))

export const serializeQueryArgs: SerializeQueryArgs<unknown> = ({ queryArgs, endpointDefinition, endpointName }) =>
  defaultSerializeQueryArgs({ queryArgs: saysNothing(queryArgs) ? undefined : queryArgs, endpointDefinition, endpointName })

export const baseApi = createApi({
  reducerPath: 'api',
  baseQuery,
  serializeQueryArgs,
  tagTypes: TAG_TYPES,
  keepUnusedDataFor: 300,
  refetchOnMountOrArgChange: 60,
  refetchOnReconnect: true,
  refetchOnFocus: false,
  endpoints: () => ({}),
})
