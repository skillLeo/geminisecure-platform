# The ring's classes are reached from the manifest and from Expo's generated module list; release builds shrink
# (app.config.ts enableProguardInReleaseBuilds), so none of them may be renamed or removed.
-keep class jm.geminisecure.guardring.** { *; }
