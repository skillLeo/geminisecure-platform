/**
 * THE HEADER IS NEVER UNDER THE STATUS BAR (M18E).
 *
 * Both apps' shared headers used to be `insets.top + 46` tall with their content pinned 16 from the bottom: a 34-point
 * button in 30 points of room, so it rose into the status bar — on the client's phone the red panic button sat under
 * the clock. Rendered here with a tall status bar (48) and a notch at each side (landscape): the inset is added ONCE,
 * as padding on the header itself; the row beneath it is 56 tall with everything centred; the title is the thing that
 * truncates; and the buttons never shrink.
 */

import { StyleSheet } from 'react-native'
import { render, screen } from '@testing-library/react-native'
import { AppBar } from '../guard/AppBar'
import { APP_BAR_ROW, ResidentAppBar } from '../resident/ResidentAppBar'

jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light', Medium: 'medium' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 48, right: 30, bottom: 34, left: 30 }) }))
jest.mock('expo-router', () => ({ router: { back: jest.fn(), replace: jest.fn(), push: jest.fn(), canGoBack: () => true } }))
jest.mock('expo-status-bar', () => ({ StatusBar: () => null }))

jest.setTimeout(30_000)

const flat = (node: { props: { style?: unknown } }) => StyleSheet.flatten(node.props.style as never) as Record<string, unknown>

/** Walk up from a node to the first ancestor whose style says `key`. */
function ancestorWith(node: { parent: unknown; props: { style?: unknown } } | null, key: string): Record<string, unknown> | null {
  let at = node
  while (at) {
    const style = at.props?.style ? flat(at) : {}
    if (style[key] !== undefined) return style
    at = at.parent as typeof node
  }
  return null
}

describe('the Resident App header', () => {
  it('adds the status bar once, as padding, and centres a 56-point row beneath it', async () => {
    await render(<ResidentAppBar title="Invite visitor" />)
    const back = screen.getByRole('button', { name: 'Back' })
    const row = ancestorWith(back as never, 'minHeight')
    expect(row).toMatchObject({ minHeight: APP_BAR_ROW, alignItems: 'center', flexDirection: 'row' })
    const bar = ancestorWith(back as never, 'paddingTop')
    expect(bar).toMatchObject({ paddingTop: 48, paddingLeft: 44, paddingRight: 44 })
    // No fixed height any more: a fixed height is what let the content rise into the status bar.
    expect(bar).not.toHaveProperty('height')
  })

  it('draws its buttons whole, 40 points, inside the 56-point row, never shrunk, and the title gives way', async () => {
    await render(<ResidentAppBar title="A title long enough to need truncating on the narrowest phone there is" />)
    const back = flat(screen.getByRole('button', { name: 'Back' }) as never)
    expect(back).toMatchObject({ width: 40, height: 40, flexShrink: 0 })
    expect((back.width as number) + 2 * 8).toBeLessThanOrEqual(APP_BAR_ROW)
    const title = screen.getByRole('heading')
    expect(title.props.numberOfLines).toBe(1)
    expect(title.props.ellipsizeMode).toBe('tail')
  })

  // M47 U1–U3: no panic shield, no "Estate time" line and no `i` in any header — asked for or not.
  it('draws nothing the design does not: no panic shield, no estate clock, no i', async () => {
    await render(<ResidentAppBar title="Book an amenity" panic info={{ title: 'Booking an amenity', lines: ['A line.'] }} />)
    expect(screen.queryByRole('button', { name: 'Panic alert' })).toBeNull()
    expect(screen.queryByTestId('info-button')).toBeNull()
    expect(screen.queryByText(/Estate time/)).toBeNull()
  })
})

describe('the Guard App header', () => {
  it('adds the status bar once, as padding, and centres a 56-point row beneath it', async () => {
    await render(<AppBar title="Standing orders" />)
    const back = screen.getByRole('button', { name: 'Back' })
    expect(ancestorWith(back as never, 'minHeight')).toMatchObject({ minHeight: 56, alignItems: 'center' })
    expect(ancestorWith(back as never, 'paddingTop')).toMatchObject({ paddingTop: 48, paddingLeft: 44, paddingRight: 44 })
    expect(flat(back as never)).toMatchObject({ width: 40, height: 40, flexShrink: 0 })
  })
})
