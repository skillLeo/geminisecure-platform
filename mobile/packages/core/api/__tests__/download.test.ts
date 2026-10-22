const mockFiles = new Map<string, string>()
const mockCalls: { url: string; to: string; headers: Record<string, string> }[] = []
let mockNext: { status: number; body: string } | 'network' = { status: 200, body: '%PDF' }
let mockWriteFails = false

jest.mock(
  'expo-file-system/legacy',
  () => ({
    cacheDirectory: 'file:///data/app/cache/',
    getInfoAsync: jest.fn(async (uri: string) => ({ exists: mockFiles.has(uri), size: mockFiles.get(uri)?.length ?? 0 })),
    makeDirectoryAsync: jest.fn(async () => undefined),
    downloadAsync: jest.fn(async (url: string, to: string, options: { headers: Record<string, string> }) => {
      mockCalls.push({ url, to, headers: options.headers })
      if (mockNext === 'network') throw new Error('Network request failed')
      mockFiles.set(to, mockNext.body)
      return { status: mockNext.status, uri: to }
    }),
    readAsStringAsync: jest.fn(async (uri: string) => mockFiles.get(uri) ?? ''),
    writeAsStringAsync: jest.fn(async (uri: string, contents: string, options: { encoding: string }) => {
      if (mockWriteFails) throw new Error('No space left on device')
      mockFiles.set(uri, `${options.encoding}:${contents}`)
    }),
    deleteAsync: jest.fn(async (uri: string) => {
      for (const key of [...mockFiles.keys()]) if (key === uri || key.startsWith(uri)) mockFiles.delete(key)
    }),
  }),
  // Not `virtual`: the module is installed, and a virtual mock of one path is shared by every file a worker runs —
  // media.test.ts's own mock of it was answered by this one when the suite ran in one process.
)

import { tokenStorage } from '../../auth/tokenStorage'
import { discardAllDownloads, downloadFile, keepForSharing, safeFileName } from '../download'

process.env.EXPO_PUBLIC_API_URL = 'http://192.168.18.119:8000/api/v1/'

const ask = (online = true) => downloadFile({ path: '/notices/4/attachments/9', folder: 'notice-4-9', name: 'Gate2_Contractor_Notice.pdf', online })
const TARGET = 'file:///data/app/cache/api-downloads/notice-4-9/Gate2_Contractor_Notice.pdf'

beforeEach(async () => {
  mockFiles.clear()
  mockCalls.length = 0
  mockNext = { status: 200, body: '%PDF' }
  mockWriteFails = false
  await tokenStorage.set('resident', 'tok_live')
})

describe('a file the API hands over as itself', () => {
  it('is fetched with the phone\'s token into the cache: the address carries no secret', async () => {
    expect(await ask()).toEqual({ ok: true, uri: TARGET, fromPhone: false })
    expect(mockCalls).toHaveLength(1)
    expect(mockCalls[0]?.url).toBe('http://192.168.18.119:8000/api/v1/notices/4/attachments/9')
    expect(mockCalls[0]?.headers.Authorization).toBe('Bearer tok_live')
    expect(mockCalls[0]?.url).not.toContain('tok_live')
  })

  it('is served from the phone the second time, so it still opens with no connection', async () => {
    await ask()
    expect(await ask(false)).toEqual({ ok: true, uri: TARGET, fromPhone: true })
    expect(mockCalls).toHaveLength(1)
  })

  it('says so when there is no connection and the file is not on the phone, and asks nobody', async () => {
    const result = await ask(false)
    expect(result).toMatchObject({ ok: false, why: 'failed', error: { status: 'network' } })
    expect(mockCalls).toHaveLength(0)
  })

  it('hands back the server\'s own sentence on a refusal, and leaves no JSON behind under a .pdf name', async () => {
    mockNext = { status: 404, body: JSON.stringify({ error: { code: 'not_found', message: 'No attachment for your household with that id.' } }) }
    const result = await ask()
    expect(result).toMatchObject({ ok: false, why: 'failed', error: { status: 404, code: 'not_found', message: 'No attachment for your household with that id.' } })
    expect(mockFiles.has(TARGET)).toBe(false)
  })

  it('never shows a raw body: a refusal that is not the contract\'s shape becomes a plain sentence', async () => {
    mockNext = { status: 500, body: '<html>SQLSTATE[42S02] …</html>' }
    const result = await ask()
    expect(result).toMatchObject({ ok: false, why: 'failed', error: { status: 500, code: 'server_error' } })
    expect(JSON.stringify(result)).not.toContain('SQLSTATE')
    expect(mockFiles.has(TARGET)).toBe(false)
  })

  it('a dropped connection is a network failure, with nothing half-written left behind', async () => {
    mockNext = 'network'
    expect(await ask()).toMatchObject({ ok: false, why: 'failed', error: { status: 'network' } })
    expect(mockFiles.size).toBe(0)
  })

  it('asks nothing of the server when signed out', async () => {
    await tokenStorage.clear('resident')
    expect(await ask()).toMatchObject({ ok: false, why: 'failed', error: { status: 401 } })
    expect(mockCalls).toHaveLength(0)
  })

  it('removes every fetched file at sign-out', async () => {
    await ask()
    await discardAllDownloads()
    expect(mockFiles.size).toBe(0)
  })
})

describe('a file this phone made, for the share sheet (a pass QR picture, R6)', () => {
  const PASS_DIR = 'file:///data/app/cache/api-downloads/pass-share/'

  it('is written from base64 under the downloads folder, as one safe name', async () => {
    const uri = await keepForSharing({ folder: 'pass-share', name: '../visitor-pass-AB12.png', base64: 'iVBORw0K' })
    expect(uri).toBe(`${PASS_DIR}visitor-pass-AB12.png`)
    expect(mockFiles.get(`${PASS_DIR}visitor-pass-AB12.png`)).toBe('base64:iVBORw0K')
  })

  it('holds ONE picture: the next share replaces the last', async () => {
    await keepForSharing({ folder: 'pass-share', name: 'visitor-pass-AAAA.png', base64: 'one' })
    await keepForSharing({ folder: 'pass-share', name: 'visitor-pass-BBBB.png', base64: 'two' })
    expect([...mockFiles.keys()].filter((key) => key.startsWith(PASS_DIR))).toEqual([`${PASS_DIR}visitor-pass-BBBB.png`])
  })

  it('goes at sign-out with every fetched file', async () => {
    await keepForSharing({ folder: 'pass-share', name: 'visitor-pass-AB12.png', base64: 'x' })
    await ask()
    await discardAllDownloads()
    expect(mockFiles.size).toBe(0)
  })

  it('is null, never a half-written file, when the phone cannot write it', async () => {
    mockWriteFails = true
    expect(await keepForSharing({ folder: 'pass-share', name: 'visitor-pass-AB12.png', base64: 'x' })).toBeNull()
    expect(mockFiles.size).toBe(0)
  })
})

describe('safeFileName', () => {
  it('is always ONE path segment', () => {
    expect(safeFileName('Gate2_Contractor_Notice.pdf')).toBe('Gate2_Contractor_Notice.pdf')
    expect(safeFileName('../../etc/passwd')).toBe('passwd')
    expect(safeFileName('..\\uploads\\a "b".pdf')).toBe('a _b_.pdf')
    expect(safeFileName('...')).toBe('file')
    expect(safeFileName('')).toBe('file')
    expect(safeFileName(`${'x'.repeat(300)}.pdf`)).toHaveLength(120)
    expect(safeFileName(`${'x'.repeat(300)}.pdf`).endsWith('.pdf')).toBe(true)
  })
})
