/**
 * `@gs/core/live` — every screen live, on both apps, with no refresh (M11 Part C, DESIGN §8).
 *
 * The frozen entry points (§12.1): configureLive, startLive, stopLive, onLiveEvent, useLiveStatus,
 * patchEverywhere, LiveStatusBanner, and (Amendment 1) registerDurableStore and useLiveFallback.
 */

export { configureLive, onLiveEvent, registeredTypes, type AccessChange, type AccessEffect, type HandlerOptions, type LiveApp, type LiveEventContext, type LiveHandler, type LiveOptions, type LiveStore } from './configure'
export { startLive, stopLive, refreshLive, nudgeLive, refreshLiveConfig, isLiveStarted, liveChannels, readConfig, readGrants, LiveHttpError, type LiveConfig, type LiveHttp } from './client'
export { useLiveStatus, getLiveStatus, type LiveBanner, type LiveState, type LiveStatus, type LifecycleEnvironment } from './lifecycle'
export { patchEverywhere, invalidateKind, invalidateRecord, perRecord, resyncMounted, type LiveQueryName, type PatchOptions } from './rtk'
export { registerDurableStore, revalidateDurableStores, type DurableStore, type RevalidateContext, type RevalidateReason } from './durable'
export { useLiveFallback, downCadenceMs } from './fallback'
export { ACCESS_CHANGED, NUDGE, RESYNC, isSequenced, type LiveAllowlist, type LiveEnvelope, type LiveMode, type SequencedEnvelope, type Stream } from './envelope'
export { pusherSocket, type LiveSocket, type SocketEvents, type SocketFactory, type SocketSettings, type PusherConstructor } from './socket'
export { LiveStatusBanner } from '../ui/LiveStatusBanner'
