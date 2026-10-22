/**
 * THE PASS AS A TICKET (M12 §2.8) — the words on the picture, and proof that the code drawn on it is the pass.
 *
 * The picture itself is drawn natively (react-native-svg's toDataURL), so what is proved here is everything it is
 * drawn FROM: the estate, the lot, the visitor, the day and the window on the estate's clock; and that the QR path
 * the ticket draws, rasterised, decodes back to the pass's own signed token — with modules large enough, at the
 * exported width, for a gate scanner reading a photo of a phone.
 */

import jsQR from 'jsqr'
import { forgetSiteZone, setSiteZone } from '@gs/core/lib'
import { TICKET_FOOTER, ticketLayout, ticketOf, wrap } from '../passTicket'
import { QUIET, qrModules, qrPathOf } from '../qrPicture'

// A real token's shape: base64url(payload).base64url(signature) — about as long as the server's.
const TOKEN = `${Buffer.from(JSON.stringify({ v: 1, pid: '0f5c2f5e-8a8b-4b1e-9d52-6c3f3c9a7e21', t: 'facilitiestest', n: 'Jane Brown', f: 1790505600, u: 1790520000, s: true })).toString('base64url')}.${Buffer.alloc(64, 7).toString('base64url')}`

const pass = {
  category: 'single' as const,
  visitor_name: '  Jane   Brown ',
  valid_from: '2026-09-27T19:00:00Z', // 2:00 PM in Kingston
  valid_to: '2026-09-27T23:00:00Z', // 6:00 PM
  code: 'PPV2-4471',
  token: TOKEN,
}

beforeEach(() => setSiteZone('America/Jamaica'))
afterEach(() => forgetSiteZone())

describe('the words on the ticket', () => {
  it('estate, lot, visitor, the day and the window: on the estate\'s clock, naming no clock (M18F §2.1, M98)', () => {
    const t = ticketOf(pass, 'Phoenix Park Village 1', 'Phase 2 · Lot 47')
    expect(t).toMatchObject({
      estate: 'Phoenix Park Village 1',
      lot: 'Phase 2 · Lot 47',
      kind: 'Single visit',
      visitor: 'Jane Brown',
      // The date in the one form (M98), and no clock named: everything on a pass is the estate's time (Hassam).
      day: '27 Sep 2026',
      window: '2:00 PM – 6:00 PM',
      when: '27 Sep 2026 · 2:00 PM – 6:00 PM',
      code: 'PPV2-4471',
      footer: TICKET_FOOTER,
    })
    expect(TICKET_FOOTER).toBe('Show this at the gate. The guard scans the code, or types it in.')
    expect(t.label).toBe('Visitor pass for Jane Brown at Phoenix Park Village 1, Phase 2 · Lot 47. 27 Sep 2026 · 2:00 PM – 6:00 PM. Gate code PPV2-4471.')
  })

  it('a pass over several days gives both ends with their dates', () => {
    const t = ticketOf({ ...pass, category: 'recurring', valid_to: '2026-10-03T23:00:00Z' }, 'Phoenix Park Village 1', null)
    expect(t).toMatchObject({ day: '27 Sep 2026 – 3 Oct 2026', window: '27 Sep 2026, 2:00 PM to 3 Oct 2026, 6:00 PM', when: '27 Sep 2026, 2:00 PM to 3 Oct 2026, 6:00 PM', lot: null, kind: 'Recurring pass' })
  })

  it('is the ESTATE\'s clock even when the estate is somewhere else', () => {
    const t = ticketOf(pass, 'Hudson Yards', 'Lot 1', 'America/New_York')
    expect(t).toMatchObject({ window: '3:00 PM – 7:00 PM', when: '27 Sep 2026 · 3:00 PM – 7:00 PM' })
  })

  it('names no clock and carries no tag, at any estate (Hassam, M98: "drop Jamaica time")', () => {
    for (const zone of ['America/Jamaica', 'America/New_York']) {
      for (const one of [ticketOf(pass, 'Phoenix Park Village 1', 'Lot 47', zone), ticketOf({ ...pass, valid_to: '2026-10-03T23:00:00Z' }, 'Phoenix Park Village 1', null, zone)]) {
        for (const text of [one.when, one.label]) expect(/Jamaica|New York|[A-Z]\w* time\b|\b(JA|NY)\b/.test(text)).toBe(false)
      }
    }
  })
})

/*
 * M18F §5 — NOTHING IS CUT. The footer used to be one line wider than the ticket and ran off both ends, on the screen and
 * in the picture visitors were sent. Every line now breaks on its words, and the ticket grows to hold them.
 */
describe('nothing on the ticket is cut', () => {
  it('breaks a long line on its words, never with an ellipsis, and keeps every word', () => {
    const long = "Phoenix Park Village 1 Homeowners' Association"
    const lines = wrap(long, 20)
    expect(lines).toEqual(['Phoenix Park Village', "1 Homeowners'", 'Association'])
    expect(lines.join(' ')).toBe(long)
    expect(lines.every((line) => line.length <= 20)).toBe(true)
    expect(lines.join('')).not.toContain('…')
  })

  it('breaks a single word longer than the line where it must: a name typed with no spaces', () => {
    expect(wrap('Abcdefghijklmnopqrstuvwxyz', 10)).toEqual(['Abcdefghij', 'klmnopqrst', 'uvwxyz'])
  })

  it('breaks a double-barrelled name after its hyphen, never inside a name (M20 §1, M19 §2.2)', () => {
    // It came out as "Fitzgerald-Montgome" / "ry" on the picture a visitor is sent.
    expect(wrap('Marie-Antoinette Fitzgerald-Montgomery', 19)).toEqual(['Marie-Antoinette', 'Fitzgerald-', 'Montgomery'])
    expect(wrap('Christopher-Alexander Montgomery-Fitzgerald', 19)).toEqual(['Christopher-', 'Alexander', 'Montgomery-', 'Fitzgerald'])
    // A hyphenated word that fits on its line stays whole, and one short enough to follow on does.
    expect(wrap('Jean-Paul Brown', 19)).toEqual(['Jean-Paul Brown'])
    expect(wrap('Ann Smith-Wellington-Hayes', 19)).toEqual(['Ann Smith-', 'Wellington-Hayes'])
  })

  it('grows the ticket to hold the longest realistic content, and keeps every line inside the text column', () => {
    const ordinary = ticketLayout(ticketOf(pass, 'Phoenix Park Village 1', 'Phase 2 · Lot 47'))
    const longest = ticketLayout(ticketOf({ ...pass, category: 'contractor', visitor_name: 'Christopher-Alexander Montgomery-Fitzgerald', valid_to: '2026-10-25T23:00:00Z' }, "Phoenix Park Village 1 Homeowners' Association", 'Phase 12 · Lot 1047 Mahogany Crescent'))
    expect(longest.height).toBeGreaterThan(ordinary.height)
    for (const block of [longest.estate, longest.lot!, longest.visitor, longest.when, longest.footer]) expect(block.lines.length).toBeGreaterThanOrEqual(1)
    // Each face's characters per line, at its size, fit the 600-unit column (Poppins Bold 44 ≈ 27 a char; Inter 30 ≈ 17).
    expect(Math.max(...longest.estate.lines.map((l) => l.length)) * 27).toBeLessThanOrEqual(600)
    expect(Math.max(...longest.visitor.lines.map((l) => l.length)) * 28).toBeLessThanOrEqual(600)
    expect(Math.max(...longest.when.lines.map((l) => l.length)) * 17.5).toBeLessThanOrEqual(600)
    expect(Math.max(...longest.footer.lines.map((l) => l.length)) * 12.5).toBeLessThanOrEqual(640)
    // The footer, the line that used to run off both ends, is two whole lines.
    expect(ordinary.footer.lines).toEqual(['Show this at the gate. The guard scans the', 'code, or types it in.'])
  })
})

describe('the code on the ticket IS the pass', () => {
  /** The ticket's own path, rasterised the way the SVG fills it: every `M x y h w v1 h-w z` is one row of modules. */
  function rasterise(pathD: string, count: number, px: number): { data: Uint8ClampedArray; size: number } {
    const size = count * px
    const data = new Uint8ClampedArray(size * size * 4).fill(255)
    for (const m of pathD.matchAll(/M(\d+) (\d+)h(\d+)v1h-\d+z/g)) {
      const [x, y, w] = [Number(m[1]), Number(m[2]), Number(m[3])]
      for (let yy = y * px; yy < (y + 1) * px; yy++) {
        for (let xx = x * px; xx < (x + w) * px; xx++) {
          const i = (yy * size + xx) * 4
          data[i] = 11; data[i + 1] = 31; data[i + 2] = 51 // navy-900, as drawn
        }
      }
    }
    return { data, size }
  }

  it('draws exactly the dark modules of the code, inside the full quiet zone', () => {
    const modules = qrModules(TOKEN)
    const { d, count } = qrPathOf(TOKEN)
    expect(count).toBe(modules.length + QUIET * 2)
    const drawn = new Set<string>()
    for (const m of d.matchAll(/M(\d+) (\d+)h(\d+)/g)) for (let k = 0; k < Number(m[3]); k++) drawn.add(`${Number(m[1]) + k - QUIET},${Number(m[2]) - QUIET}`)
    const dark = modules.flatMap((row, y) => row.flatMap((on, x) => (on ? [`${x},${y}`] : [])))
    expect([...drawn].sort()).toEqual(dark.sort())
  })

  it('decodes back to the pass\'s own signed token', () => {
    const { d, count } = qrPathOf(TOKEN)
    const { data, size } = rasterise(d, count, 6)
    const read = jsQR(data, size, size)
    expect(read?.data).toBe(TOKEN)
  })

  it('each module is at least 6 pixels in the exported picture: room for a messenger to re-compress it', () => {
    const { count } = qrPathOf(TOKEN)
    // 480 of 720 across, exported 1080 across.
    expect(((480 / count) * 1080) / 720).toBeGreaterThanOrEqual(6)
  })
})
