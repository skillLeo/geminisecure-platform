import { inflateSync } from 'zlib'
import { QUIET, crc32, qrLayout, qrModules, qrPng, toBase64, zlibStored } from '../qrPicture'

// A signed pass token is about this long (payload + signature); the picture must carry it whole.
const TOKEN = `gs1.${'eyJwIjoiUFAtMDAxMjMiLCJ1IjoiQS0xMiIsImYiOjE3NTg4NzM2MDAsInQiOjE3NTg5MTY4MDB9'}.${'q3Zx8YbW1rJ0mT5nK2pL9sD4fG7hV6cX0aB1eR8tU3iO5yW2zQ4'}`

type Png = { width: number; height: number; bitDepth: number; colourType: number; interlace: number; data: Buffer; crcsOk: boolean }

function readPng(bytes: Uint8Array): Png {
  const buf = Buffer.from(bytes)
  expect([...buf.subarray(0, 8)]).toEqual([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])
  let at = 8
  let header: Buffer | null = null
  const idat: Buffer[] = []
  let crcsOk = true
  let ended = false
  while (at < buf.length) {
    const len = buf.readUInt32BE(at)
    const type = buf.subarray(at + 4, at + 8).toString('ascii')
    const data = buf.subarray(at + 8, at + 8 + len)
    const crc = buf.readUInt32BE(at + 8 + len)
    if (crc32(new Uint8Array(buf.subarray(at + 4, at + 8 + len))) !== crc) crcsOk = false
    if (type === 'IHDR') header = data
    if (type === 'IDAT') idat.push(data)
    if (type === 'IEND') ended = true
    at += 12 + len
  }
  expect(ended).toBe(true)
  expect(at).toBe(buf.length)
  if (!header) throw new Error('no IHDR')
  return {
    width: header.readUInt32BE(0),
    height: header.readUInt32BE(4),
    bitDepth: header[8]!,
    colourType: header[9]!,
    interlace: header[12]!,
    // inflateSync checks the zlib header and the Adler-32 as well: a wrong one throws.
    data: inflateSync(Buffer.concat(idat)),
    crcsOk,
  }
}

describe('a pass QR code as a picture (R6)', () => {
  it('writes the checksums the format asks for', () => {
    // The CRC-32 of "IEND" is fixed by the PNG specification.
    expect(crc32(new Uint8Array([0x49, 0x45, 0x4e, 0x44]))).toBe(0xae426082)
  })

  it('is a valid one-bit greyscale PNG, square, about 720 px', () => {
    const png = readPng(qrPng(TOKEN))
    expect(png.crcsOk).toBe(true)
    expect(png.width).toBe(png.height)
    expect(png.bitDepth).toBe(1)
    expect(png.colourType).toBe(0)
    expect(png.interlace).toBe(0)
    expect(png.width).toBeGreaterThanOrEqual(600)
    expect(png.width).toBeLessThanOrEqual(840)
  })

  it('draws exactly the code the screen draws, module for module, with a white quiet zone', () => {
    const modules = qrModules(TOKEN)
    const n = modules.length
    const { count, scale, size } = qrLayout(n)
    const png = readPng(qrPng(TOKEN))
    expect(png.width).toBe(size)
    const rowBytes = Math.ceil(size / 8)
    expect(png.data.length).toBe((rowBytes + 1) * size)

    const white = (x: number, y: number) => {
      const row = y * (rowBytes + 1)
      expect(png.data[row]).toBe(0) // filter: none
      return (png.data[row + 1 + (x >> 3)]! & (0x80 >> (x & 7))) !== 0
    }
    let checked = 0
    for (let my = 0; my < count; my++) {
      for (let mx = 0; mx < count; mx++) {
        const inCode = my >= QUIET && my < QUIET + n && mx >= QUIET && mx < QUIET + n
        const dark = inCode && modules[my - QUIET]![mx - QUIET]!
        // The centre of the module, and a corner of it: a whole module is one colour.
        const cx = mx * scale + (scale >> 1)
        const cy = my * scale + (scale >> 1)
        expect(white(cx, cy)).toBe(!dark)
        expect(white(mx * scale, my * scale + scale - 1)).toBe(!dark)
        checked++
      }
    }
    expect(checked).toBe(count * count)
  })

  it('keeps a longer token about the same size: the modules shrink, the picture does not grow', () => {
    const long = TOKEN.repeat(4)
    const png = readPng(qrPng(long))
    const { size } = qrLayout(qrModules(long).length)
    expect(png.width).toBe(size)
    expect(png.data.length).toBe((Math.ceil(size / 8) + 1) * size)
    expect(size).toBeLessThanOrEqual(840)
  })

  it('splits image data past 65 535 bytes into stored blocks that inflate back exactly', () => {
    for (const length of [0, 1, 65535, 65536, 200_003]) {
      const raw = new Uint8Array(length)
      for (let i = 0; i < length; i++) raw[i] = (i * 7919) & 0xff
      expect(Buffer.compare(inflateSync(Buffer.from(zlibStored(raw))), Buffer.from(raw))).toBe(0)
    }
  })

  it('base64 matches the standard encoding at every padding length', () => {
    for (const bytes of [new Uint8Array([]), new Uint8Array([1]), new Uint8Array([1, 2]), new Uint8Array([1, 2, 3]), new Uint8Array([255, 0, 128, 7])]) {
      expect(toBase64(bytes)).toBe(Buffer.from(bytes).toString('base64'))
    }
    const png = qrPng(TOKEN)
    expect(toBase64(png)).toBe(Buffer.from(png).toString('base64'))
  })
})
