/**
 * THE QUEUE, SENT WITH THE APP CLOSED (M11 §1.3: "Sync runs when connectivity returns even if the app is in the
 * background or closed, using the platform's background task mechanism. The user should open the app and find
 * everything already sent.").
 *
 * The platform's own mechanism, `expo-background-task`: on Android a WorkManager job that runs only while the phone
 * is CONNECTED (its constraint), so the OS wakes the app — with no screen, even after it was swiped away — once a
 * connection is back, at most every fifteen minutes; on iOS a BGTaskScheduler task, run when iOS chooses.
 *
 *   defineOutboxTask   at the TOP OF THE BUNDLE (each app's index.ts), because a phone woken with no screen loads
 *                      the bundle but no route: a task defined anywhere the router loads lazily would not exist then.
 *   scheduleOutboxTask when a session starts. Idempotent; a phone or build without the module says so and nothing
 *                      else changes — the foreground triggers (reconnect, app to front, the timer) still send.
 *
 * WHAT IT CANNOT DO, and must not try to: send for a session the server has already closed. A resident's session
 * ends after thirty minutes with nothing sent (M6, Q-M42 — a phone left somewhere). A task woken after that is
 * answered `session_expired`; the queue is kept exactly as a foreground send keeps it, and goes when the person is
 * back in. Security's window is not widened for convenience.
 */

import { Platform } from 'react-native'

export const OUTBOX_TASK = 'gs-outbox-sync'

/** The shortest interval either OS allows. */
export const OUTBOX_TASK_MINUTES = 15

type TaskManagerLike = {
  defineTask: (name: string, run: () => Promise<unknown>) => void
  isTaskRegisteredAsync: (name: string) => Promise<boolean>
}
type BackgroundTaskLike = {
  registerTaskAsync: (name: string, options: { minimumInterval: number }) => Promise<void>
  getStatusAsync: () => Promise<number>
  BackgroundTaskStatus: { Available: number }
  BackgroundTaskResult: { Success: number; Failed: number }
}

function modules(): { tasks: TaskManagerLike; background: BackgroundTaskLike } | null {
  if (Platform.OS === 'web') return null
  try {
    /* eslint-disable @typescript-eslint/no-require-imports -- optional native modules: a build without them runs as before */
    const tasks = require('expo-task-manager') as TaskManagerLike
    const background = require('expo-background-task') as BackgroundTaskLike
    /* eslint-enable @typescript-eslint/no-require-imports */
    return typeof tasks.defineTask === 'function' && typeof background.registerTaskAsync === 'function' ? { tasks, background } : null
  } catch {
    return null
  }
}

/**
 * What the OS runs when it wakes the app to send. `send` answers whether the queue is empty afterwards (true: Success,
 * so the OS keeps its normal interval; false: Failed, so it may try again sooner).
 */
export function defineOutboxTask(send: () => Promise<boolean>): boolean {
  const m = modules()
  if (!m) return false
  try {
    m.tasks.defineTask(OUTBOX_TASK, async () => {
      try {
        return (await send()) ? m.background.BackgroundTaskResult.Success : m.background.BackgroundTaskResult.Failed
      } catch {
        return m.background.BackgroundTaskResult.Failed
      }
    })
    return true
  } catch {
    return false
  }
}

/** Asked for once a session is running. True when the OS will wake the app to send. */
export async function scheduleOutboxTask(): Promise<boolean> {
  const m = modules()
  if (!m) return false
  try {
    if ((await m.background.getStatusAsync()) !== m.background.BackgroundTaskStatus.Available) return false
    if (!(await m.tasks.isTaskRegisteredAsync(OUTBOX_TASK))) await m.background.registerTaskAsync(OUTBOX_TASK, { minimumInterval: OUTBOX_TASK_MINUTES })
    return true
  } catch {
    return false
  }
}
