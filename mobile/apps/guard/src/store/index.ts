import { configureStore } from '@reduxjs/toolkit'
import { useDispatch, useSelector } from 'react-redux'
import { configureApi, coreMiddleware, coreReducers, startCoreListeners } from '@gs/core/api'
import { clearEverything } from '@gs/core/auth'
import { clearNotifications } from '@gs/core/notify'
import { networkReducer, outboxReducer } from '@gs/core/offline'
import { KEEP_FOR_SIGNING_BACK_IN } from '@/features/enrolment/binding'
import { GuardRing } from '@/features/ring/native'
import { KEEP_ON_SESSION_END } from '@/features/tracking/trackingStore'

export const store = configureStore({
  reducer: { ...coreReducers, network: networkReducer, outbox: outboxReducer },
  middleware: (getDefault) => getDefault().concat(coreMiddleware),
})

startCoreListeners(store.dispatch)

// A 401 ends the session, and there are two ways it can: the office or a rebind REVOKED this
// handset's token, or the idle window simply closed (M6, Q-M42 — thirty minutes with nothing
// sent). Either way everything is cleared EXCEPT the offline outbox, which waits for the same
// guard to enrol again — it can be holding a duress alert, and that is never thrown away.
// What is on the lock screen goes too: a banner can carry a message from the control room.
//
// EXCEPT A LIVE RING, WHEN THE IDLE WINDOW MERELY CLOSED (G4). Clearing the tray cancels every notification the app
// has, the call-style ring's included, and the commonest way to get here is exactly a ring: an idle guard taps the
// panic's banner, the app opens, and its first request answers `session_expired`. A guard whose session timed out in
// a pocket must go on being rung (GuardNotifier keeps the push address for the same reason), so on `expired` the
// ring puts back every ring still inside its hold, and every unanswered one silently. `revoked` and sign-out keep the
// full clear; RingBridge also stops and forgets the rings then.
//
// ON-DUTY SHARING STOPS WITH THE SESSION (M11 §3): its duty goes with everything else, and the background task,
// finding none, stops the service by itself. The fixes already taken on the shift are kept with the outbox.
//
// AND THE SESSION IS ALL THAT ENDS (M32 F3). The phone is still this guard's — its key, its install id and its binding
// record live in the secure store, which none of this touches — so the guard signs back in with a fingerprint, and
// what is theirs to keep for that is kept: their setup answers, whose phone it is, the tour they have seen
// (KEEP_FOR_SIGNING_BACK_IN). If the server says the phone was taken out of service, or a code is entered for somebody
// else, openSession.ts starts setup over.
configureApi({
  onSessionRevoked: async (reason) => {
    await clearNotifications()
    if (reason === 'expired') await GuardRing?.restore().catch(() => undefined)
    await clearEverything('guard', store.dispatch, reason, [...KEEP_ON_SESSION_END, ...KEEP_FOR_SIGNING_BACK_IN])
  },
})

export type RootState = ReturnType<typeof store.getState>
export type AppDispatch = typeof store.dispatch

export const useAppDispatch = useDispatch.withTypes<AppDispatch>()
export const useAppSelector = useSelector.withTypes<RootState>()
