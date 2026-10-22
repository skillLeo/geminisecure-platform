/**
 * GATE — INVARIANT 14: raw camera frames never leave the device.
 *
 * The scanners (a visitor's QR at the gate, a patrol checkpoint tag) use the camera ONLY to read a code:
 * the OS hands the app the decoded text, and the text is all that is kept. In a scan path there is
 * therefore no still, no recording, no frame processor, no base64 of an image, no upload and no save:
 *
 *   takePictureAsync · takePicture · recordAsync · record( · onPictureSaved · base64 · FormData ·
 *   FileSystem.upload · MediaLibrary · CameraRoll · frameProcessor · useFrameProcessor
 *
 * A photo a guard or resident CHOOSES to attach (an incident, a maintenance ticket) goes through the
 * image PICKER, shrunk and stripped of EXIF — that is a different path, outside the scanners, and is
 * checked by its own tests.
 *
 *   node tools/gates/camera-frames.mjs
 */

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const FORBIDDEN = /\b(takePictureAsync|takePicture|recordAsync|onPictureSaved|useFrameProcessor|frameProcessor|CameraRoll|MediaLibrary|FormData)\b|\.record\(|base64|FileSystem\.upload|uploadAsync/

const walk = (dir) => (existsSync(dir) ? readdirSync(dir).flatMap((name) => {
  if (name === 'node_modules' || name === 'lifted' || name === '__tests__') return []
  const full = path.join(dir, name)
  return statSync(full).isDirectory() ? walk(full) : /\.tsx?$/.test(name) ? [full] : []
}) : [])

// A scan path is any file that mounts the camera.
const USES_CAMERA = /from\s+['"]expo-camera['"]|<CameraView\b|useCameraPermissions/
const files = [...walk(path.join(ROOT, 'apps')), ...walk(path.join(ROOT, 'packages', 'core'))]
const scanners = files.filter((f) => USES_CAMERA.test(readFileSync(f, 'utf8')))

const offenders = []
for (const file of scanners) {
  const rel = path.relative(ROOT, file).replace(/\\/g, '/')
  readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
    if (/^\s*(\*|\/\/|\/\*)/.test(line)) return
    const code = line.replace(/\/\/.*$/, '')
    const hit = FORBIDDEN.exec(code)
    if (hit) offenders.push(`${rel}:${i + 1}  ${hit[0]}  — a scanner reads a code; it never keeps or sends a frame`)
  })
}

if (scanners.length === 0) offenders.push('no file mounts the camera — the scanners moved; fix this gate so it still guards them')

if (offenders.length > 0) {
  console.log(`GATE camera-frames · FAIL (${offenders.length}):`)
  for (const o of offenders) console.log(`  ${o}`)
  process.exit(1)
}
console.log(`GATE camera-frames · ${scanners.length} scanner file(s) read codes only: no still, recording, frame processor, base64 or upload`)
