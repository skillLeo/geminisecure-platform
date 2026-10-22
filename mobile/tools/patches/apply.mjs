/**
 * Changes this repository makes to a dependency's NATIVE source, applied after every install (root `postinstall`).
 *
 * Each patch names the exact text it replaces. When the text is already patched it is left alone; when neither the
 * original nor the patched text is there (the dependency was upgraded and changed), the install FAILS with the file
 * and the reason — a fix that silently stops applying is worse than a build that stops.
 *
 *   node tools/patches/apply.mjs            apply (postinstall)
 *   node tools/patches/apply.mjs --check    exit 1 unless every patch is in place (gates)
 */

import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

export const PATCHES = [
  {
    id: 'expo-background-task: a headless start must not cancel the run that started it',
    why:
      'The M11 §1.7 airplane test (26 Sep, D-M163). The OS woke the Resident App 2 s after the signal came back; the task ' +
      're-registered as the headless app started, `scheduleWorker` asked whether the worker was already ENQUEUED or ' +
      'RUNNING, and read only the FIRST entry of the unique work — after an APPEND that is an earlier, finished one — so ' +
      'it REPLACED the worker that was running and cancelled it. It now reads the live entry when there is one.',
    file: 'node_modules/expo-background-task/android/src/main/java/expo/modules/backgroundtask/BackgroundTaskScheduler.kt',
    from: '      return workInfos.firstOrNull()\n',
    to:
      '      // GeminiSecure (tools/patches/apply.mjs): the LIVE entry when there is one — after an APPEND the first is a\n' +
      '      // finished run, and reading it made a headless start REPLACE (cancel) the run that started it. The worker\'s\n' +
      '      // own tag is asked as well: on the Guard App the running work was not found under the unique name (26 Sep).\n' +
      '      val live = { info: WorkInfo -> info.state == WorkInfo.State.RUNNING || info.state == WorkInfo.State.ENQUEUED }\n' +
      '      return workInfos.firstOrNull(live)\n' +
      '        ?: workManager.getWorkInfosByTag(BackgroundTaskWork::class.java.name).await().firstOrNull(live)\n' +
      '        ?: workInfos.firstOrNull()\n',
    // The first version of this patch, as installs made before 26 Sep 13:00 applied it: upgraded in place.
    previous:
      '      // GeminiSecure (tools/patches/apply.mjs): the LIVE entry when there is one — after an APPEND the first is a\n' +
      '      // finished run, and reading it made a headless start REPLACE (cancel) the run that started it.\n' +
      '      return workInfos.firstOrNull { it.state == WorkInfo.State.RUNNING || it.state == WorkInfo.State.ENQUEUED } ?: workInfos.firstOrNull()\n',
  },
  {
    id: 'expo-task-manager: an app started headless and then opened keeps receiving its task events',
    why:
      'The M21 §1.2 emulator run (28 Sep, D-M170). Android started the Guard App with no screen (the 15-minute outbox ' +
      'sync; equally a boot, an update, a push), the officer then opened it, and its task manager was filed as the ' +
      'HEADLESS one. Two seconds after the sync finished the headless record was invalidated and that task manager ' +
      'dropped — though the React instance lived on under the screen — so every on-duty position after it waited for ' +
      'a start that never came: nothing was kept for the life of the process while the notification said "location ' +
      'shared". It is now kept when an activity holds the React instance.',
    file: 'node_modules/expo-task-manager/android/src/main/java/expo/modules/taskManager/TaskService.java',
    from: '        sHeadlessTaskManagers.remove(appScopeKey);\n      }\n    }\n  }\n',
    to:
      '        // GeminiSecure (tools/patches/apply.mjs): an app started headless and then OPENED keeps its React instance —\n' +
      '        // the loader destroys it only when no activity holds it — so its task manager is kept for the events still to\n' +
      '        // come. Dropped, every later event waited for a start that never came: on-duty positions stopped for the life\n' +
      '        // of the process while the notification said "location shared" (M21 §1.2).\n' +
      '        WeakReference<TaskManagerInterface> headless = sHeadlessTaskManagers.remove(appScopeKey);\n' +
      '        WeakReference<TaskManagerInterface> current = sTaskManagers.get(appScopeKey);\n' +
      '        if (headless != null && headless.get() != null && (current == null || current.get() == null) && isHostHeldByActivity()) {\n' +
      '          sTaskManagers.put(appScopeKey, headless);\n' +
      '        }\n' +
      '      }\n' +
      '    }\n' +
      '  }\n' +
      '\n' +
      '  private boolean isHostHeldByActivity() {\n' +
      '    Context context = mContextRef.get();\n' +
      '    if (context == null || !(context.getApplicationContext() instanceof ReactApplication)) {\n' +
      '      return false;\n' +
      '    }\n' +
      '    ReactHost host = ((ReactApplication) context.getApplicationContext()).getReactHost();\n' +
      '    return host != null && host.getLifecycleState() != com.facebook.react.common.LifecycleState.BEFORE_CREATE;\n' +
      '  }\n',
  },
  {
    id: 'expo-task-manager: the app in front registers its task manager again',
    why:
      'With the patch above, and for any other way the task service loses it (M21 §1.2, D-M170): each time the app ' +
      'comes forward its task manager is registered again, and what was queued for it meanwhile is delivered then.',
    file: 'node_modules/expo-task-manager/android/src/main/java/expo/modules/taskManager/TaskManagerInternalModule.java',
    from: '  public void onHostResume() {\n    if (!isRunningInHeadlessMode()) {\n',
    to:
      '  public void onHostResume() {\n' +
      '    // GeminiSecure (tools/patches/apply.mjs): the app in front is where events go. Registered again each time it comes\n' +
      '    // forward, so a task manager dropped while it ran is found again and what waited for it is delivered (M21 §1.2).\n' +
      '    if (mTaskService != null) {\n' +
      '      mTaskService.setTaskManager(this, getAppScopeKey(), getAppUrl());\n' +
      '    }\n' +
      '    if (!isRunningInHeadlessMode()) {\n',
  },
  /*
   * NOT PATCHED, ON PURPOSE: expo-location's refusal to start its location service while the app is in the background
   * (M22 §1.1, D-214). Tried and proven wrong on the emulator: Android does let the service start from an update's or a
   * restart's broadcast, but a service started from the background gets NO location ("Foreground service started from
   * background can not have location/camera/microphone access"), so it would show "On duty · location shared" over a
   * phone that records nothing. The Guard App says instead that it needs opening (dutyTracker.remindIfHeadless).
   */
]

function state(patch) {
  const path = join(ROOT, patch.file)
  if (!existsSync(path)) return { path, status: 'missing' }
  const text = readFileSync(path, 'utf8').replace(/\r\n/g, '\n')
  if (text.includes(patch.to)) return { path, status: 'applied', text }
  if (patch.previous && text.includes(patch.previous)) return { path, status: 'original', text: text.replace(patch.previous, patch.from) }
  if (text.includes(patch.from)) return { path, status: 'original', text }
  return { path, status: 'changed', text }
}

const check = process.argv.includes('--check')
let failed = false
for (const patch of PATCHES) {
  const s = state(patch)
  if (s.status === 'applied') {
    console.log(`PATCH ok       ${patch.id}`)
  } else if (s.status === 'original' && !check) {
    writeFileSync(s.path, s.text.replace(patch.from, patch.to))
    console.log(`PATCH applied  ${patch.id}`)
  } else if (s.status === 'missing') {
    // A workspace install without the native dependency (a tools-only checkout) has nothing to patch.
    console.log(`PATCH skipped  ${patch.id} — ${patch.file} is not installed`)
  } else {
    failed = true
    console.error(`PATCH FAILED   ${patch.id}\n  ${patch.file}: ${s.status === 'original' ? 'not applied (run npm install)' : 'the text it replaces is gone — the dependency changed; re-check the fix'}\n  why: ${patch.why}`)
  }
}
// And the R8 rule the same fix depends on (tools/expo-plugins/headlessLoader.cjs): without it no background task's
// JavaScript can start in a release build, in either app. Checked with the patches because it fails the same way —
// silently, on a phone, only when the app is closed.
/*
 * AND EVERY PATCHED MODULE IS BUILT FROM ITS SOURCE (M22, D-217). Expo links most of its modules into an Android build
 * from PREBUILT libraries (each package's `local-maven-repo`), so a patch to a module's Java or Kotlin source changes
 * nothing in the APK unless that module is named in the app's `expo.autolinking.android.buildFromSource`. Found on
 * the emulator, 28 Sep: the APK's dex had none of the patched code, while every check above said "ok".
 */
// With what a patched module needs built beside it: expo-task-manager's build names `project(':unimodules-app-loader')`.
const BUILT_WITH = { 'expo-task-manager': ['unimodules-app-loader'] }
const patchedModules = [...new Set(PATCHES.flatMap((patch) => [patch.file.split('/')[1], ...(BUILT_WITH[patch.file.split('/')[1]] ?? [])]))].sort()
if (check) {
  for (const app of ['resident', 'guard']) {
    const pkg = JSON.parse(readFileSync(join(ROOT, 'apps', app, 'package.json'), 'utf8'))
    const fromSource = pkg.expo?.autolinking?.android?.buildFromSource ?? []
    const missing = patchedModules.filter((name) => !fromSource.includes(name))
    if (missing.length === 0) {
      console.log(`SOURCE ok       ${app}: ${patchedModules.join(', ')} built from source`)
    } else {
      failed = true
      console.error(`SOURCE FAILED   ${app}: ${missing.join(', ')} patched but linked PREBUILT — add to expo.autolinking.android.buildFromSource in apps/${app}/package.json`)
    }
  }
}
if (check) {
  for (const app of ['resident', 'guard']) {
    const config = readFileSync(join(ROOT, 'apps', app, 'app.config.ts'), 'utf8')
    if (/extraProguardRules:\s*HEADLESS_LOADER_KEEP/.test(config) && config.includes("require('../../tools/expo-plugins/headlessLoader.cjs')")) {
      console.log(`KEEP  ok       ${app}: the headless app loader is kept from R8`)
    } else {
      failed = true
      console.error(`KEEP  FAILED   ${app}: app.config.ts does not pass HEADLESS_LOADER_KEEP to expo-build-properties (extraProguardRules)`)
    }
  }
}
if (failed) process.exit(1)
