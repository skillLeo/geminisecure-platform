/**
 * THE R8 RULE THAT LETS A BACKGROUND TASK RUN AT ALL IN A RELEASE BUILD (M11 §1.3, D-M163).
 *
 * expo-task-manager starts a background task's JavaScript through the "headless app loader" the manifest names:
 *
 *   <meta-data android:name="org.unimodules.core.AppLoader#react-native-headless"
 *              android:value="expo.modules.adapters.react.apploader.RNHeadlessAppLoader" />
 *
 * It is found BY NAME, at run time. expo-modules-core keeps classes marked @DoNotStrip, but this one marks only its
 * constructor, so R8 (enableProguardInReleaseBuilds) removed the class. The M11 §1.7 airplane test (26 Sep) caught it:
 * the OS woke the Resident App 2 s after the signal came back, and the task died with
 * `ClassNotFoundException: expo.modules.adapters.react.apploader.RNHeadlessAppLoader` — nothing was sent with the app
 * closed, in either app, on any release build. Debug builds never shrink, which is why nothing showed it before.
 *
 * Kept by name, with the no-argument constructor the loader is made with. Both apps' app.config.ts pass it to
 * expo-build-properties as `extraProguardRules`.
 */
module.exports = {
  HEADLESS_LOADER_KEEP: '-keep class expo.modules.adapters.react.apploader.RNHeadlessAppLoader { <init>(); }',
}
