/**
 * "Sync runs when connectivity returns even if the app is in the background or closed, using the platform's
 * background task mechanism. The user should open the app and find everything already sent." (M11 §1.3)
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

const mockTasks = { defineTask: jest.fn(), isTaskRegisteredAsync: jest.fn(async () => false) }
const mockBackground = {
  registerTaskAsync: jest.fn(async () => undefined),
  getStatusAsync: jest.fn(async () => 2),
  BackgroundTaskStatus: { Restricted: 1, Available: 2 },
  BackgroundTaskResult: { Success: 1, Failed: 2 },
}
jest.mock('expo-task-manager', () => mockTasks)
jest.mock('expo-background-task', () => mockBackground)

// eslint-disable-next-line import/first
import { defineOutboxTask, OUTBOX_TASK, OUTBOX_TASK_MINUTES, scheduleOutboxTask } from '../backgroundSync'

beforeEach(() => jest.clearAllMocks())

it('defines the task the OS runs, and answers Success only when the queue is empty afterwards', async () => {
  const send = jest.fn(async () => true)
  expect(defineOutboxTask(send)).toBe(true)

  const [name, run] = mockTasks.defineTask.mock.calls[0] as unknown as [string, () => Promise<number>]
  expect(name).toBe(OUTBOX_TASK)
  await expect(run()).resolves.toBe(1)

  send.mockResolvedValueOnce(false)
  await expect(run()).resolves.toBe(2)

  // A send that throws is a failed run, never an uncaught error in a headless start.
  send.mockRejectedValueOnce(new Error('no store'))
  await expect(run()).resolves.toBe(2)
})

it('asks the OS once, at the shortest interval it allows', async () => {
  await expect(scheduleOutboxTask()).resolves.toBe(true)
  expect(mockBackground.registerTaskAsync).toHaveBeenCalledWith(OUTBOX_TASK, { minimumInterval: 15 })
  expect(OUTBOX_TASK_MINUTES).toBe(15)

  mockTasks.isTaskRegisteredAsync.mockResolvedValueOnce(true)
  await expect(scheduleOutboxTask()).resolves.toBe(true)
  expect(mockBackground.registerTaskAsync).toHaveBeenCalledTimes(1)
})

it('says so when the phone does not allow background work, and changes nothing else', async () => {
  mockBackground.getStatusAsync.mockResolvedValueOnce(1)
  await expect(scheduleOutboxTask()).resolves.toBe(false)
  expect(mockBackground.registerTaskAsync).not.toHaveBeenCalled()
})

it('is defined at the top of both apps\' bundles, before the router, so a start with no screen has it', () => {
  const apps = path.join(__dirname, '..', '..', '..', '..', 'apps')
  const resident = readFileSync(path.join(apps, 'resident', 'index.ts'), 'utf8')
  const guard = readFileSync(path.join(apps, 'guard', 'index.ts'), 'utf8')

  expect(resident.indexOf("import './src/offline/backgroundSync'")).toBeGreaterThan(-1)
  expect(resident.indexOf("import './src/offline/backgroundSync'")).toBeLessThan(resident.indexOf("import 'expo-router/entry'"))
  expect(guard.indexOf("import './src/offline/backgroundSync'")).toBeGreaterThan(-1)
  expect(guard.indexOf("import './src/offline/backgroundSync'")).toBeLessThan(guard.indexOf("import 'expo-router/entry'"))
  expect(JSON.parse(readFileSync(path.join(apps, 'resident', 'package.json'), 'utf8')).main).toBe('./index.ts')

  // Woken with no screen, nothing has booted: each task reads the session itself, then sends as a launch does.
  for (const app of ['resident', 'guard']) {
    const task = readFileSync(path.join(apps, app, 'src', 'offline', 'backgroundSync.ts'), 'utf8')
    expect(task).toMatch(new RegExp(`bootSession\\('${app}', store\\.dispatch\\)`))
    expect(task).toContain('await startOfflineRuntime()')
    expect(task).toContain('await drainNow()')
  }
})
