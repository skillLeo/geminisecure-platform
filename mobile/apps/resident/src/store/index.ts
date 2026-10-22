import { configureStore } from '@reduxjs/toolkit'
import { useDispatch, useSelector } from 'react-redux'
import { configureApi, coreMiddleware, coreReducers, startCoreListeners } from '@gs/core/api'
import { clearEverything } from '@gs/core/auth'
import { clearNotifications } from '@gs/core/notify'
import { networkReducer, outboxReducer } from '@gs/core/offline'
import { PANIC_RECORD_STORAGE_KEY } from '@/features/panic/panicRecord'
import { DRAFTS_STORAGE_KEY } from '@/features/visitors/draftStore'
import { TOUR_STORAGE_KEY } from '@/tour/tourKey'
import { PHONE_CHOICES } from './phoneChoices'

export const store = configureStore({
  reducer: { ...coreReducers, network: networkReducer, outbox: outboxReducer },
  middleware: (getDefault) => getDefault().concat(coreMiddleware),
})

startCoreListeners(store.dispatch)

// A 401 ends the session, and there are two ways it can: this account was signed in elsewhere or the
// estate withdrew access (REVOKED), or the idle window closed by itself (M6, Q-M42 — thirty minutes
// with nothing sent). Either way everything is cleared EXCEPT what the resident saved with no signal,
// which waits for the same account to sign in again.
// What is on the lock screen goes too: a banner can name a visitor or a notice.
// A held panic's record stays with the queue it describes (B118): Log In says it has not gone, and after the
// same account signs in again the panic screen shows it going. So do the visitor-pass drafts, which send themselves
// like the queue (D-M160): each records whose it is, and the next session keeps only its own (draftStore.ts).
// And the first-run tour's record (M32 F11): seen once is seen, so the thirty-minute window closing never brings the
// five cards back at the next sign-in. It names an estate and an account and nothing else; a chosen sign-out clears it.
// (The account's own record, on the server, is what keeps it from coming back after THAT — src/once/once.ts.)
// And the resident's own choices on this phone (M47 §3, phoneChoices.ts): text size, notifications, the estate Log in
// opens on, the permissions explained.
configureApi({ onSessionRevoked: async (reason) => { await clearNotifications(); await clearEverything('resident', store.dispatch, reason, [PANIC_RECORD_STORAGE_KEY, DRAFTS_STORAGE_KEY, TOUR_STORAGE_KEY, ...PHONE_CHOICES]) } })

export type RootState = ReturnType<typeof store.getState>
export type AppDispatch = typeof store.dispatch

export const useAppDispatch = useDispatch.withTypes<AppDispatch>()
export const useAppSelector = useSelector.withTypes<RootState>()
