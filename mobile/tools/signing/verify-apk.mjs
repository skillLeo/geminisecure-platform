#!/usr/bin/env node
/**
 * IS THIS APK SIGNED AS A GEMINISECURE RELEASE? (M33 §2)
 *
 *   node tools/signing/verify-apk.mjs <path/to/app.apk> --app guard|resident
 *
 * Runs `apksigner verify -v --print-certs --min-sdk-version 23` (see VERIFY_AS_FOR) and refuses the APK unless it
 * verifies; carries v1, v2 AND v3 signatures; has
 * one signer; that signer is not an Android debug certificate (known by the certificate, not by any file name); and it
 * is the certificate tools/signing/release-certificates.json records for that app. Prints what apksigner said that
 * matters, and exits 1 on the first thing wrong.
 */
import { execFileSync } from 'node:child_process'
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = path.dirname(fileURLToPath(import.meta.url))

export const RECORDED = JSON.parse(readFileSync(path.join(HERE, 'release-certificates.json'), 'utf8'))

/** What `apksigner verify -v --print-certs` printed, as facts. */
export function parseApksigner(output) {
  const flag = (scheme) => {
    const m = output.match(new RegExp(`Verified using ${scheme} scheme[^:]*:\\s*(true|false)`))
    return m ? m[1] === 'true' : null
  }
  const signers = [...output.matchAll(/Signer #(\d+) certificate DN: (.+)/g)].map((m) => ({
    n: Number(m[1]),
    dn: m[2].trim(),
    sha256: (output.match(new RegExp(`Signer #${m[1]} certificate SHA-256 digest: ([0-9a-f]{64})`)) ?? [])[1] ?? null,
    keySize: Number((output.match(new RegExp(`Signer #${m[1]} key size \\(bits\\): (\\d+)`)) ?? [])[1] ?? 0),
  }))

  return {
    verifies: /^Verifies$/m.test(output),
    v1: flag('v1'),
    v2: flag('v2'),
    v3: flag('v3'),
    signers,
  }
}

/**
 * The verdict on one APK.
 *
 * @returns {string[]} what is wrong — empty when it is a GeminiSecure release of this app
 */
export function judge(facts, app, recorded = RECORDED) {
  const wrong = []

  if (!facts.verifies) wrong.push('apksigner does not verify it')
  for (const scheme of ['v1', 'v2', 'v3']) {
    if (facts[scheme] !== true) wrong.push(`no ${scheme} signature (apksigner: ${facts[scheme]})`)
  }
  if (facts.signers.length !== 1) wrong.push(`${facts.signers.length} signers, where a release has one`)

  for (const signer of facts.signers) {
    if (/CN=Android Debug/.test(signer.dn) || recorded.debug.includes(signer.sha256)) {
      wrong.push(`signed with an Android DEBUG certificate (${signer.dn}, SHA-256 ${signer.sha256})`)
    } else if (signer.sha256 !== recorded[app]) {
      wrong.push(`signed with ${signer.sha256}, and release-certificates.json records ${recorded[app]} for ${app}`)
    }
  }

  return wrong
}

/** The newest build-tools' apksigner, and the Android SDK's own Java when JAVA_HOME is not set. */
function apksigner() {
  const sdk = process.env.ANDROID_HOME || path.join(process.env.LOCALAPPDATA ?? '', 'Android', 'Sdk')
  const tools = path.join(sdk, 'build-tools')
  const newest = readdirSync(tools).sort((a, b) => a.localeCompare(b, undefined, { numeric: true })).pop()
  const bin = path.join(tools, newest, process.platform === 'win32' ? 'apksigner.bat' : 'apksigner')
  const env = { ...process.env }
  if (!env.JAVA_HOME && existsSync(path.join(sdk, 'jdk'))) env.JAVA_HOME = path.join(sdk, 'jdk')

  return { bin, env }
}

/**
 * `apksigner verify` checks only the schemes the APK's own minSdk needs, and Android 7 (API 24) and later never read
 * v1: asked as it is, it prints "v1 … false" for an APK that carries a JAR signature. Told to verify as for Android 6
 * (`--min-sdk-version 23`), it checks v1 too — which is the question M33 §2 asks.
 */
export const VERIFY_AS_FOR = '23'

export function verifyApk(apk, app) {
  const { bin, env } = apksigner()
  let output
  try {
    output = execFileSync(bin, ['verify', '-v', '--print-certs', '--min-sdk-version', VERIFY_AS_FOR, apk], { env, encoding: 'utf8', shell: process.platform === 'win32', stdio: ['ignore', 'pipe', 'pipe'] })
  } catch (error) {
    output = `${error.stdout ?? ''}\n${error.stderr ?? ''}`
  }

  return { output, facts: parseApksigner(output), wrong: judge(parseApksigner(output), app) }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const apk = process.argv[2]
  const app = process.argv[process.argv.indexOf('--app') + 1]

  if (!apk || !existsSync(apk) || !['guard', 'resident'].includes(app)) {
    console.error('usage: node tools/signing/verify-apk.mjs <app.apk> --app guard|resident')
    process.exit(2)
  }

  const { facts, wrong } = verifyApk(apk, app)
  console.log(`${path.basename(apk)} · v1 ${facts.v1} · v2 ${facts.v2} · v3 ${facts.v3} · ${facts.signers.map((s) => `${s.dn} · SHA-256 ${s.sha256} · RSA ${s.keySize}`).join(' | ')}`)

  if (wrong.length > 0) {
    for (const line of wrong) console.log(`FAIL  ${line}`)
    process.exit(1)
  }

  console.log(`PASS  a GeminiSecure release of the ${app} app, on its own key`)
}
