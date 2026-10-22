#!/usr/bin/env node
/**
 * ONE KEY, ONE FINGERPRINT, WRITTEN DOWN ONCE (M33 §2.4).
 *
 *   node tools/signing/check-fingerprints.mjs
 *
 * The release certificates' SHA-256 fingerprints, as release-certificates.json records them, are held to:
 *   - the keystore itself, when it is on this machine (read with keytool; its password is read from signing.properties
 *     into keytool's environment and never printed);
 *   - WHERE_WE_STAND.md in the server checkout (GS_SERVER_CHECKOUT, or ../geminisecure), which records them;
 *   - the newest APK of each app in build/, when there is one, through verify-apk.mjs.
 * What is not here to check is said, and is not a failure; a fingerprint that disagrees is.
 */
import { execFileSync } from 'node:child_process'
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { RECORDED, verifyApk } from './verify-apk.mjs'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const ROOT = path.resolve(HERE, '..', '..')

/** "CC:0B:…" → "cc0b…" */
export const plain = (fingerprint) => fingerprint.replaceAll(':', '').toLowerCase()

/** The SHA-256 of each alias, as keytool prints it for the store, or null when there is no store here. */
export function keystoreFingerprints(propertiesFile = path.join(os.homedir(), '.geminisecure-signing', 'signing.properties')) {
  if (!existsSync(propertiesFile)) return null

  const props = Object.fromEntries(readFileSync(propertiesFile, 'utf8').split(/\r?\n/).filter((l) => l.includes('=') && !l.startsWith('#')).map((l) => [l.slice(0, l.indexOf('=')), l.slice(l.indexOf('=') + 1)]))
  const sdk = process.env.ANDROID_HOME || path.join(process.env.LOCALAPPDATA ?? '', 'Android', 'Sdk')
  const keytool = path.join(sdk, 'jdk', 'bin', process.platform === 'win32' ? 'keytool.exe' : 'keytool')
  const out = {}

  for (const alias of ['guard', 'resident']) {
    const listed = execFileSync(keytool, ['-list', '-v', '-keystore', props.storeFile, '-storetype', 'PKCS12', '-alias', alias, '-storepass:env', 'GS_FINGERPRINT_SECRET'], {
      env: { ...process.env, GS_FINGERPRINT_SECRET: props.storePassword },
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    })
    out[alias] = plain((listed.match(/SHA256:\s*([0-9A-F:]+)/) ?? [])[1] ?? '')
  }

  return out
}

/** The newest APK of each app in build/. */
export function newestApks(build = path.join(ROOT, '..', 'geminisecure-mobile', 'build')) {
  if (!existsSync(build)) return {}
  const out = {}
  for (const [app, prefix] of [['guard', 'GeminiSecure-Guard-'], ['resident', 'GeminiSecure-Resident-']]) {
    const apk = readdirSync(build).filter((f) => f.startsWith(prefix) && f.endsWith('.apk')).sort().pop()
    if (apk) out[app] = path.join(build, apk)
  }
  return out
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  let failed = false
  const say = (ok, line) => {
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${line}`)
    failed ||= !ok
  }

  const store = keystoreFingerprints()
  if (store === null) console.log('SKIP  the keystore is not on this machine (it lives with its owner)')
  else for (const app of ['guard', 'resident']) say(store[app] === RECORDED[app], `the keystore's ${app} key is ${store[app]}, as recorded`)

  const server = process.env.GS_SERVER_CHECKOUT ? path.resolve(process.env.GS_SERVER_CHECKOUT) : path.resolve(ROOT, '..', 'geminisecure')
  const stand = path.join(server, 'WHERE_WE_STAND.md')
  if (!existsSync(stand)) console.log(`SKIP  no WHERE_WE_STAND.md at ${server}`)
  else {
    const text = readFileSync(stand, 'utf8').toLowerCase().replaceAll(':', '')
    for (const app of ['guard', 'resident']) say(text.includes(RECORDED[app]), `WHERE_WE_STAND.md records the ${app} fingerprint`)
  }

  const apks = newestApks()
  for (const app of ['guard', 'resident']) {
    if (!apks[app]) {
      console.log(`SKIP  no ${app} APK in build/`)
      continue
    }
    const { wrong } = verifyApk(apks[app], app)
    say(wrong.length === 0, `${path.basename(apks[app])}: ${wrong.length === 0 ? 'v1, v2 and v3, on the recorded key' : wrong.join('; ')}`)
  }

  process.exit(failed ? 1 : 0)
}
