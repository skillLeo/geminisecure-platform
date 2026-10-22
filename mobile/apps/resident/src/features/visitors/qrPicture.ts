/**
 * A pass's QR code as a PICTURE — a PNG the share sheet hands to WhatsApp, Messages or e-mail, so the visitor gets
 * the code itself and not only the words about it (the client's report R6, 26 Sep: "the resident is only able to
 * send 'Hi, you are expected…'"). Drawn here from the signed token the phone already holds, exactly as PassQr draws
 * it on screen — no server, no connection, no native module.
 *
 *   SCANNABLE  black on white, the standard four-module quiet zone, about 720 px square: the most a gate scanner
 *              can ask of a photo of a photo. The same error correction as the screen ('M').
 *   PNG        not GIF — a messenger turns a GIF into a video. Written plainly: one bit per pixel, deflate's
 *              "stored" blocks (no compression library — a pass's picture is some 50–70 KB), and the two
 *              checksums the format asks for (CRC-32 per chunk, Adler-32 over the image data).
 */

import qrcode from 'qrcode-generator'

/** Modules of white around the code: the QR standard's own quiet zone. */
export const QUIET = 4
/** Roughly how wide the picture is, in pixels; each module is a whole number of pixels. */
const TARGET_PX = 720

/** The code's modules, row by row: true is dark. The same code PassQr draws. */
export function qrModules(token: string): boolean[][] {
  const qr = qrcode(0, 'M')
  qr.addData(token)
  qr.make()
  const n = qr.getModuleCount()
  return Array.from({ length: n }, (_, row) => Array.from({ length: n }, (_, col) => qr.isDark(row, col)))
}

/**
 * The code as one SVG path in module units, the quiet zone included (`count` across): the ticket (M12 §2.8) draws
 * it at whatever size it has room for, and every dark run of a row is one rectangle.
 */
export function qrPathOf(token: string): { d: string; count: number } {
  const modules = qrModules(token)
  const n = modules.length
  let d = ''
  for (let row = 0; row < n; row++) {
    let run = 0
    for (let col = 0; col <= n; col++) {
      const dark = col < n && modules[row]![col]!
      if (dark) run++
      else if (run > 0) {
        d += `M${col - run + QUIET} ${row + QUIET}h${run}v1h-${run}z`
        run = 0
      }
    }
  }
  return { d, count: n + QUIET * 2 }
}

/** How the picture is laid out: modules across (with the quiet zone), pixels per module, pixels across. */
export function qrLayout(moduleCount: number): { count: number; scale: number; size: number } {
  const count = moduleCount + QUIET * 2
  const scale = Math.max(4, Math.round(TARGET_PX / count))
  return { count, scale, size: count * scale }
}

/* ---- the checksums ---------------------------------------------------------------------------------- */

const CRC_TABLE = (() => {
  const table = new Uint32Array(256)
  for (let n = 0; n < 256; n++) {
    let c = n
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    table[n] = c >>> 0
  }
  return table
})()

export function crc32(bytes: Uint8Array): number {
  let c = 0xffffffff
  for (let i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]!) & 0xff]! ^ (c >>> 8)
  return (c ^ 0xffffffff) >>> 0
}

function adler32(bytes: Uint8Array): number {
  let a = 1
  let b = 0
  for (let i = 0; i < bytes.length; i++) {
    a = (a + bytes[i]!) % 65521
    b = (b + a) % 65521
  }
  return ((b << 16) | a) >>> 0
}

/* ---- the file --------------------------------------------------------------------------------------- */

const u32 = (n: number) => [(n >>> 24) & 0xff, (n >>> 16) & 0xff, (n >>> 8) & 0xff, n & 0xff]

function chunk(type: string, data: Uint8Array): Uint8Array {
  const body = new Uint8Array(4 + data.length)
  for (let i = 0; i < 4; i++) body[i] = type.charCodeAt(i)
  body.set(data, 4)
  const out = new Uint8Array(4 + body.length + 4)
  out.set(u32(data.length), 0)
  out.set(body, 4)
  out.set(u32(crc32(body)), 4 + body.length)
  return out
}

/** A zlib stream of "stored" (uncompressed) deflate blocks — each at most 65 535 bytes. */
export function zlibStored(raw: Uint8Array): Uint8Array {
  const MAX = 65535
  const blocks = Math.max(1, Math.ceil(raw.length / MAX))
  const out = new Uint8Array(2 + blocks * 5 + raw.length + 4)
  out[0] = 0x78 // deflate, 32 KB window
  out[1] = 0x01 // no dictionary, fastest; (0x7801 % 31 === 0)
  let at = 2
  for (let block = 0; block < blocks; block++) {
    const start = block * MAX
    const len = Math.min(MAX, raw.length - start)
    out[at++] = block === blocks - 1 ? 1 : 0
    out[at++] = len & 0xff
    out[at++] = (len >>> 8) & 0xff
    out[at++] = ~len & 0xff
    out[at++] = (~len >>> 8) & 0xff
    out.set(raw.subarray(start, start + len), at)
    at += len
  }
  out.set(u32(adler32(raw)), at)
  return out
}

const SIGNATURE = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]

/** The pass's QR as the bytes of a PNG file. */
export function qrPng(token: string): Uint8Array {
  const modules = qrModules(token)
  const n = modules.length
  const { scale, size } = qrLayout(n)
  const rowBytes = Math.ceil(size / 8)

  // One filter byte (0: none) per row, then the row's pixels, eight to a byte, most significant first. In a
  // one-bit greyscale PNG a 1 is white — so everything starts dark and the light pixels are set.
  const raw = new Uint8Array((rowBytes + 1) * size)
  for (let y = 0; y < size; y++) {
    const my = Math.floor(y / scale) - QUIET
    const row = y * (rowBytes + 1)
    for (let x = 0; x < size; x++) {
      const mx = Math.floor(x / scale) - QUIET
      const dark = my >= 0 && my < n && mx >= 0 && mx < n && modules[my]![mx]!
      if (!dark) raw[row + 1 + (x >> 3)]! |= 0x80 >> (x & 7)
    }
  }

  const header = new Uint8Array([...u32(size), ...u32(size), 1, 0, 0, 0, 0]) // 1 bit, greyscale, no interlace
  const parts = [new Uint8Array(SIGNATURE), chunk('IHDR', header), chunk('IDAT', zlibStored(raw)), chunk('IEND', new Uint8Array(0))]
  const out = new Uint8Array(parts.reduce((sum, part) => sum + part.length, 0))
  let at = 0
  for (const part of parts) {
    out.set(part, at)
    at += part.length
  }
  return out
}

const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'

/** Base64, by hand: the file module writes a picture from it, and a phone's JS engine is not promised `btoa`. */
export function toBase64(bytes: Uint8Array): string {
  let out = ''
  for (let i = 0; i < bytes.length; i += 3) {
    const a = bytes[i]!
    const b = i + 1 < bytes.length ? bytes[i + 1]! : 0
    const c = i + 2 < bytes.length ? bytes[i + 2]! : 0
    out += B64[a >> 2]! + B64[((a & 3) << 4) | (b >> 4)]!
    out += i + 1 < bytes.length ? B64[((b & 15) << 2) | (c >> 6)]! : '='
    out += i + 2 < bytes.length ? B64[c & 63]! : '='
  }
  return out
}
