/**
 * The Resident App's entry. One thing comes before the router: the queue's background task (M11 §1.3). Android can
 * start the app with NO screen — swiped away, then a connection comes back — to send what was saved with no signal,
 * and a task defined anywhere the router loads lazily would not exist then. Defined here, it always does. (The Guard
 * App's index.ts does the same for its own tasks.)
 */
import './src/offline/backgroundSync'
import 'expo-router/entry'
