/**
 * THE RELEASE KEY (M33 §2): signed with it, refused without it, never committed.
 *
 *   npm run test:signing
 *
 *   - the build: the plugin points the release build type at the GeminiSecure key with v1, v2 and v3, writes nothing
 *     secret, and refuses a debug certificate by the CERTIFICATE — not by what the file is called;
 *   - the APK: apksigner's word is read, and an APK on the debug key, or missing a scheme, or on another key, fails;
 *   - the repository: a keystore staged to be committed fails the gate, wherever it is and whatever its name.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { judge, parseApksigner, RECORDED, VERIFY_AS_FOR } from '../verify-apk.mjs'
import { secretsAmong } from '../../gates/no-keystore.mjs'

const require = createRequire(import.meta.url)
const { applyReleaseSigning, DEBUG_CERTIFICATES, MARK } = require('../../expo-plugins/withReleaseSigning.cjs')
const GATE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', 'gates', 'no-keystore.mjs')

/** The part of Expo's app/build.gradle template the plugin edits, as `expo prebuild` writes it for SDK 56. */
const TEMPLATE = `def jscFlavor = 'io.github.react-native-community:jsc-android:2026004.+'

android {
    ndkVersion rootProject.ext.ndkVersion
    namespace 'jm.geminisecure.guard'
    signingConfigs {
        debug {
            storeFile file('debug.keystore')
            storePassword 'android'
            keyAlias 'androiddebugkey'
            keyPassword 'android'
        }
    }
    buildTypes {
        debug {
            signingConfig signingConfigs.debug
        }
        release {
            // Caution! In production, you need to generate your own keystore file.
            // see https://reactnative.dev/docs/signed-apk-android.
            signingConfig signingConfigs.debug
            def enableShrinkResources = findProperty('android.enableShrinkResourcesInReleaseBuilds') ?: 'false'
            shrinkResources enableShrinkResources.toBoolean()
        }
    }
}
`

/** What apksigner printed for GeminiSecure-Guard-20261005.apk, the last APK on the debug key. */
const DEBUG_APK = `Verifies
Verified using v1 scheme (JAR signing): false
Verified using v2 scheme (APK Signature Scheme v2): true
Verified using v3 scheme (APK Signature Scheme v3): false
Verified using v3.1 scheme (APK Signature Scheme v3.1): false
Verified using v4 scheme (APK Signature Scheme v4): false
Number of signers: 1
Signer #1 certificate DN: CN=Android Debug, OU=Android, O=Unknown, L=Unknown, ST=Unknown, C=US
Signer #1 certificate SHA-256 digest: fac61745dc0903786fb9ede62a962b399f7348f0bb6f899b8332667591033b9c
Signer #1 key algorithm: RSA
Signer #1 key size (bits): 2048
`

const releaseOutput = (sha256, { v1 = true, v2 = true, v3 = true, dn = RECORDED.dn } = {}) => `Verifies
Verified using v1 scheme (JAR signing): ${v1}
Verified using v2 scheme (APK Signature Scheme v2): ${v2}
Verified using v3 scheme (APK Signature Scheme v3): ${v3}
Number of signers: 1
Signer #1 certificate DN: ${dn}
Signer #1 certificate SHA-256 digest: ${sha256}
Signer #1 key algorithm: RSA
Signer #1 key size (bits): 4096
`

describe('the build signs a release with the GeminiSecure key, and refuses anything else', () => {
  const guard = applyReleaseSigning(TEMPLATE, 'guard')

  it('points the release build type at the release key, and leaves the debug build as it was', () => {
    const buildTypes = guard.slice(guard.indexOf('buildTypes {'))
    const release = buildTypes.slice(buildTypes.indexOf('release {'))
    assert.match(release, /signingConfig signingConfigs\.release/)
    assert.doesNotMatch(release.slice(0, release.indexOf('shrinkResources')), /signingConfigs\.debug/)
    assert.match(buildTypes.slice(0, buildTypes.indexOf('release {')), /signingConfig signingConfigs\.debug/)
  })

  it('signs with v1, v2 and v3, with the key named for each app — v1 by apksigner, which the Android plugin leaves out at minSdk 24', () => {
    for (const scheme of ['enableV1Signing true', 'enableV2Signing true', 'enableV3Signing true']) assert.ok(guard.includes(scheme), scheme)
    assert.match(guard, /tasks\.register\('gsSignV1V2V3'\)/)
    assert.match(guard, /'--v1-signing-enabled', 'true', '--v2-signing-enabled', 'true', '--v3-signing-enabled', 'true'/)
    assert.match(guard, /finalizedBy 'gsSignV1V2V3'/)
    // The password reaches apksigner through its environment, never its command line.
    assert.match(guard, /'--ks-pass', 'env:GS_APKSIGNER_SECRET', '--key-pass', 'env:GS_APKSIGNER_SECRET'/)
    assert.match(guard, /builder\.environment\(\)\.put\('GS_APKSIGNER_SECRET', s\.storePassword\)/)
    assert.doesNotMatch(guard, /command = \[[^\]]*storePassword/)
    assert.match(guard, /alias: 'guard'/)
    assert.match(applyReleaseSigning(TEMPLATE, 'resident'), /alias: 'resident'/)
    assert.throws(() => applyReleaseSigning(TEMPLATE, 'android'), /guard or resident/)
  })

  it('writes nothing secret: the key and its password are read when Gradle runs, from the environment or a file outside the repository', () => {
    // The only password literals are the template's own public 'android' for the debug key.
    assert.deepEqual(guard.match(/(store|key)Password\s+['"][^'"]*['"]/g), ["storePassword 'android'", "keyPassword 'android'"])
    assert.match(guard, /storePassword gsSigning\.storePassword/)
    for (const source of ["System.getenv('GS_SIGNING_PROPERTIES')", '/.geminisecure-signing/signing.properties', "pick('GS_KEYSTORE_FILE', 'storeFile')", "pick('GS_KEYSTORE_PASSWORD', 'storePassword')", "pick('GS_KEY_PASSWORD', 'keyPassword')"]) {
      assert.ok(guard.includes(source), source)
    }
    assert.doesNotMatch(guard, /(println|logger\.\w+|GradleException)\([^)]*(storePassword|keyPassword)/)
  })

  it('refuses a debug CERTIFICATE before anything is signed, whatever the file is called', () => {
    assert.match(guard, /tasks\.register\('gsCheckReleaseKey'\)/)
    assert.match(guard, /certificate\.subjectX500Principal\.name/)
    assert.match(guard, /subject\.contains\('CN=Android Debug'\)/)
    assert.ok(guard.includes(`'${DEBUG_CERTIFICATES[0]}'`), 'the template debug certificate is named')
    assert.match(guard, /'validateSigningRelease', 'packageRelease', 'bundleRelease', 'assembleRelease'/)
    assert.match(guard, /No GeminiSecure release key/)
    // …and by the recorded certificate: another non-debug key is refused too.
    assert.match(guard, /release-certificates\.json/)
  })

  it('is applied once, and refuses a template it does not recognise rather than leave a release on the debug key', () => {
    assert.equal(applyReleaseSigning(guard, 'guard'), guard)
    assert.equal(guard.split(MARK).length - 1, 2)
    assert.throws(() => applyReleaseSigning(TEMPLATE.replace('signingConfig signingConfigs.debug\n            def', 'def'), 'guard'), /nothing was changed/)
    assert.throws(() => applyReleaseSigning('// no android block', 'guard'), /no `android \{` block/)
  })
})

describe('an APK is a release only when apksigner says so', () => {
  it('fails the last APK on the debug key: no v1, no v3, and the debug certificate', () => {
    const wrong = judge(parseApksigner(DEBUG_APK), 'guard')
    assert.ok(wrong.some((w) => /DEBUG certificate/.test(w)), wrong.join('; '))
    assert.ok(wrong.some((w) => /no v1/.test(w)))
    assert.ok(wrong.some((w) => /no v3/.test(w)))
  })

  it('passes v1, v2 and v3 on the recorded key, for that app only', () => {
    assert.deepEqual(judge(parseApksigner(releaseOutput(RECORDED.guard)), 'guard'), [])
    assert.deepEqual(judge(parseApksigner(releaseOutput(RECORDED.resident)), 'resident'), [])
    assert.ok(judge(parseApksigner(releaseOutput(RECORDED.resident)), 'guard').some((w) => /records/.test(w)), 'the resident key is not the guard key')
  })

  it('fails a scheme missing, another key, or the debug certificate under any name', () => {
    for (const scheme of ['v1', 'v2', 'v3']) {
      assert.ok(judge(parseApksigner(releaseOutput(RECORDED.guard, { [scheme]: false })), 'guard').some((w) => w.includes(`no ${scheme}`)), scheme)
    }
    assert.ok(judge(parseApksigner(releaseOutput('0'.repeat(64))), 'guard').some((w) => /records/.test(w)))
    assert.ok(judge(parseApksigner(releaseOutput(DEBUG_CERTIFICATES[0], { dn: 'CN=GeminiSecure, O=Gemini Security Limited' })), 'guard').some((w) => /DEBUG/.test(w)), 'a debug key renamed is still the debug key')
  })

  it('asks apksigner to verify as for Android 6, so v1 is checked at all (an APK at minSdk 24 reads "v1 false" otherwise)', () => {
    assert.equal(VERIFY_AS_FOR, '23')
  })

  it('records two different certificates, and neither is the debug one', () => {
    assert.match(RECORDED.guard, /^[0-9a-f]{64}$/)
    assert.match(RECORDED.resident, /^[0-9a-f]{64}$/)
    assert.notEqual(RECORDED.guard, RECORDED.resident)
    assert.ok(!RECORDED.debug.includes(RECORDED.guard) && !RECORDED.debug.includes(RECORDED.resident))
  })
})

describe('no signing key is ever committed', () => {
  it('knows a keystore and its password file by name, wherever they are', () => {
    assert.deepEqual(secretsAmong(['apps/guard/release.jks', 'x/upload.KEYSTORE', 'a.p12', 'b.pfx', 'tools/signing/signing.properties', 'keystore.properties', 'apps/guard/app.config.ts', 'tools/signing/verify-apk.mjs']), ['apps/guard/release.jks', 'x/upload.KEYSTORE', 'a.p12', 'b.pfx', 'tools/signing/signing.properties', 'keystore.properties'])
  })

  it('fails the gate when a keystore is staged, and passes when none is', () => {
    const repo = mkdtempSync(path.join(os.tmpdir(), 'gs-m33-gate-'))
    const git = (...args) => execFileSync('git', args, { cwd: repo, stdio: 'ignore' })
    const gate = () => {
      try {
        execFileSync(process.execPath, [GATE], { cwd: repo, stdio: 'pipe' })
        return 0
      } catch (error) {
        return error.status
      }
    }

    try {
      git('init', '-q')
      writeFileSync(path.join(repo, 'README.md'), 'nothing secret\n')
      git('add', 'README.md')
      assert.equal(gate(), 0, 'a repository with no key passes')

      writeFileSync(path.join(repo, 'renamed.keystore'), 'not really a key\n')
      git('add', 'renamed.keystore')
      assert.equal(gate(), 1, 'a staged keystore fails')
    } finally {
      rmSync(repo, { recursive: true, force: true })
    }
  })
})
