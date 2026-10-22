/**
 * The Guard App's entry. Two things come before the router: the on-duty position task (M11 §3) and the queue's
 * background task (M11 §1.3). Android can start the app with NO screen — the app swiped away mid-shift, the
 * foreground service still running, or a connection coming back — to hand it a position or to send what was saved
 * with no signal, and a task defined anywhere the router loads lazily would not exist then. Defined here, they
 * always do.
 */
import './src/features/tracking/dutyTracker'
import './src/offline/backgroundSync'
import 'expo-router/entry'
