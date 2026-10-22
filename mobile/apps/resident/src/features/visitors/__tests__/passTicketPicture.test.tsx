/**
 * THE PICTURE A VISITOR IS SENT, AS DRAWN (M19 §2.2) — PassTicket's own SVG, every element and attribute the phone
 * rasterises, written out as a real SVG file.
 *
 * On the phone `toPng()` hands the same drawing to react-native-svg's native `toDataURL` at 1080 px across. Here the
 * drawing primitives are mapped one for one to SVG's own tags, so the tree the component renders IS an SVG document.
 * With TICKET_SVG_OUT set, the three tickets below are written there, and
 * `docs/evidence/m20/pass-picture.mjs` (web repo) draws each one in Chromium with the app's own font files at 1080 px
 * and measures every line against the ticket's edges.
 */

import { writeFileSync } from 'node:fs'
import path from 'node:path'
import { render, screen } from '@testing-library/react-native'
import { forgetSiteZone, setSiteZone } from '@gs/core/lib'
import { PassTicket, TICKET_PNG_WIDTH } from '../components/PassTicket'
import { TICKET_W, ticketLayout, ticketOf } from '../passTicket'

// `c()` comes from @gs/core, whose kit asks for animation and routing when it loads; neither is drawn here.
jest.mock('react-native-worklets', () => require('react-native-worklets/lib/module/mock'))
jest.mock('react-native-reanimated', () => ({ ...require('react-native-reanimated/mock'), useReducedMotion: () => false }))
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), canGoBack: () => false }, usePathname: () => '/visitors', useFocusEffect: jest.fn() }))

jest.mock('react-native-svg', () => {
  const React = jest.requireActual('react')
  const tag = (name: string) => {
    const Component = React.forwardRef((props: Record<string, unknown>, ref: unknown) => React.createElement(name, { ...props, ref }))
    Component.displayName = name
    return Component
  }
  // The renderer lets a string sit only inside a host named `Text`; it is written back out as SVG's <text>.
  return { __esModule: true, default: tag('svg'), Svg: tag('svg'), Circle: tag('circle'), G: tag('g'), Line: tag('line'), Path: tag('path'), Rect: tag('rect'), Text: tag('Text') }
})

type Node = { type: string; props: Record<string, unknown>; children: (Node | string)[] | null }

const ATTRIBUTE: Record<string, string> = {
  fontFamily: 'font-family', fontSize: 'font-size', letterSpacing: 'letter-spacing', textAnchor: 'text-anchor',
  strokeWidth: 'stroke-width', strokeDasharray: 'stroke-dasharray', viewBox: 'viewBox',
}
const escape = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

/** One rendered node as SVG markup. `width`/`height` on the root are the exported PNG's, as `toPng()` asks for. */
function svgOf(node: Node | string, root = false, height = 0): string {
  if (typeof node === 'string') return escape(node)
  const props = { ...node.props }
  delete props.ref
  if (root) Object.assign(props, { xmlns: 'http://www.w3.org/2000/svg', width: TICKET_PNG_WIDTH, height })
  const attributes = Object.entries(props)
    .filter(([, value]) => value !== undefined && value !== null && typeof value !== 'object' && typeof value !== 'function')
    .map(([key, value]) => `${ATTRIBUTE[key] ?? key}="${escape(String(value))}"`)
    .join(' ')
  const children = (node.children ?? []).map((child) => svgOf(child)).join('')
  const type = node.type === 'Text' ? 'text' : node.type
  return `<${type}${attributes ? ' ' + attributes : ''}>${children}</${type}>`
}

function findSvg(node: Node | string | null): Node | null {
  if (node === null || typeof node === 'string') return null
  if (node.type === 'svg') return node
  for (const child of node.children ?? []) {
    const found = findSvg(child)
    if (found) return found
  }
  return null
}

const TOKEN = `${Buffer.from(JSON.stringify({ v: 1, pid: '0f5c2f5e-8a8b-4b1e-9d52-6c3f3c9a7e21', t: 'phoenixparkvillage1', n: 'Jane Brown', f: 1790505600, u: 1790520000, s: true })).toString('base64url')}.${Buffer.alloc(64, 7).toString('base64url')}`

const CASES = {
  // What the demonstration sends.
  typical: ticketOf({ category: 'single', visitor_name: 'Marcia Brown', valid_from: '2026-09-27T19:00:00Z', valid_to: '2026-09-27T21:00:00Z', code: 'PPV5-2195', token: TOKEN }, 'Phoenix Park Village 1', 'Phase 5 · Lot 1999'),
  // The longest realistic: a long estate name, the odd lot, a double-barrelled name, a pass over a week.
  longest: ticketOf({ category: 'recurring', visitor_name: 'Marie-Antoinette Fitzgerald-Montgomery', valid_from: '2026-09-27T11:00:00Z', valid_to: '2026-10-03T23:00:00Z', code: 'PPV5-2195', token: TOKEN }, "Phoenix Park Village 1 Homeowners' Association", 'Phase 5 · Lot 1999A'),
  // Wide letters: a name written in capitals, which the character count alone would under-measure.
  capitals: ticketOf({ category: 'single', visitor_name: 'WOLFGANG MAXIMILIAN WADSWORTH', valid_from: '2026-09-27T19:00:00Z', valid_to: '2026-09-27T21:00:00Z', code: 'WWMM-8888', token: TOKEN }, 'MOUNTAIN VIEW WEST ESTATE', 'Phase 2 · Lot 1999'),
}

beforeEach(() => setSiteZone('America/Jamaica'))
afterEach(() => forgetSiteZone())

describe('the picture a visitor is sent', () => {
  it.each(Object.entries(CASES))('%s: the drawing is one SVG that holds every line of the ticket', async (name, ticket) => {
    await render(<PassTicket ticket={ticket} width={TICKET_PNG_WIDTH} />)
    const tree = screen.toJSON() as unknown as Node
    const svg = findSvg(tree)
    expect(svg).not.toBeNull()
    const height = Math.round((TICKET_PNG_WIDTH * ticketLayout(ticket).height) / TICKET_W)
    const markup = svgOf(svg as Node, true, height)

    // Every word the ticket carries is in the drawing — the visitor, the window, the clock, the code, the footer.
    // A double-barrelled name may break after its hyphen, so it is looked for piece by piece.
    for (const word of [...ticket.visitor.split(/[\s-]+/), ticket.code, 'Jamaica', 'time', 'gate.', ...ticket.estate.split(' ')]) {
      expect(markup).toContain(escape(word))
    }
    expect(markup).toMatch(/^<svg [^>]*viewBox="0 0 720 \d+"/)

    const out = process.env.TICKET_SVG_OUT
    if (out) writeFileSync(path.join(out, `pass-${name}.svg`), markup)
  })
})
