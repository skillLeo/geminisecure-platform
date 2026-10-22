/**
 * B133 — A PAYSLIP PDF NEVER OUTLIVES ITS SHARE SHEET, EVEN WHEN THE SHEET CANNOT OPEN.
 *
 * The promise on this screen is that a wage is written only to the app's own cache and deleted the moment
 * the share sheet closes. The delete sat after the share, so a sheet that threw left the payslip in the cache
 * until the next sign-out, and the button went back to idle saying nothing.
 */
import { act, renderHook } from '@testing-library/react-native'

const mockDiscard = jest.fn(async (_uri: string) => undefined)
const mockDownload = jest.fn(async () => ({ ok: true, uri: 'file:///cache/payslips/payslip-September-2026.pdf' }))
jest.mock('@gs/core/api', () => ({ downloadFile: () => mockDownload(), discardDownload: (uri: string) => mockDiscard(uri) }))
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select({ network: { online: true } }) }))

const mockShare = jest.fn(async () => undefined)
jest.mock('expo-sharing', () => ({ isAvailableAsync: jest.fn(async () => true), shareAsync: (...args: unknown[]) => mockShare(...(args as [])) }), { virtual: true })

import { SHARE_FAILED, usePayslipPdf } from '../usePayslipPdf'

const SLIP = { id: 42, period_label: 'September 2026' }
const settle = async () => { await act(async () => { for (let i = 0; i < 6; i++) await Promise.resolve() }) }

beforeEach(() => {
  mockDiscard.mockClear()
  mockShare.mockReset()
})

it('the sheet could not open: the file is deleted all the same, and the guard is told', async () => {
  mockShare.mockImplementation(async () => { throw new Error('no activity to handle the intent') })
  const view = await renderHook(() => usePayslipPdf())
  await act(async () => view.result.current.download(SLIP))
  await settle()
  expect(mockDiscard).toHaveBeenCalledWith('file:///cache/payslips/payslip-September-2026.pdf')
  expect(view.result.current.problem).toBe(SHARE_FAILED)
  expect(view.result.current.state).toBe('idle')
  await view.unmount()
})

it('shared: the file is deleted the moment the sheet closes', async () => {
  mockShare.mockImplementation(async () => undefined)
  const view = await renderHook(() => usePayslipPdf())
  await act(async () => view.result.current.download(SLIP))
  await settle()
  expect(mockDiscard).toHaveBeenCalledTimes(1)
  expect(view.result.current.problem).toBeNull()
  expect(view.result.current.state).toBe('done')
  await view.unmount()
})
