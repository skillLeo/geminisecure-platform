/**
 * Expo config plugin — give Gradle enough memory to finish a release build.
 *
 * Expo's Android template ships
 *
 *     org.gradle.jvmargs=-Xmx2048m -XX:MaxMetaspaceSize=512m
 *
 * and R8, on React Native 0.86 with this many modules, does not fit in 512 MB of Metaspace. The
 * way it fails is the problem: nothing says "out of memory". R8 writes its outputs, the build
 * prints nothing more, and the daemon then spends twenty minutes at forty per cent of one core
 * collecting garbage it cannot free. `app/build` stops changing. No task line appears. The only
 * place the truth is written is ~/.gradle/daemon/<version>/daemon-<pid>.out.log:
 *
 *     Caused by: java.lang.OutOfMemoryError: Metaspace
 *
 * That cost most of an afternoon on 23 September 2026, with a client waiting for an APK, and it
 * would have cost it again on the next machine — `expo prebuild` regenerates gradle.properties,
 * so editing the generated file fixes one build and nothing else. This is the fix that survives.
 *
 * THE NUMBERS. 6 GB of heap and 2 GB of Metaspace: comfortably more than the build needs rather
 * than the least that works, because the failure mode is a silent twenty-minute stall and the
 * cost of over-allocating is some memory on a machine that is doing nothing else. EAS builders
 * have their own settings and are unaffected by this file.
 *
 * `enableJetifier` is deliberately NOT set here. It is unrelated to memory and turning it on to
 * "help" would slow every build for a problem this project does not have.
 */

const { withGradleProperties } = require('expo/config-plugins')

const JVM_ARGS = '-Xmx6144m -XX:MaxMetaspaceSize=2048m'

module.exports = function withGradleMemory(config) {
  return withGradleProperties(config, (mod) => {
    const properties = mod.modResults.filter(
      (item) => !(item.type === 'property' && item.key === 'org.gradle.jvmargs'),
    )

    properties.push({ type: 'property', key: 'org.gradle.jvmargs', value: JVM_ARGS })

    mod.modResults = properties

    return mod
  })
}

module.exports.JVM_ARGS = JVM_ARGS
