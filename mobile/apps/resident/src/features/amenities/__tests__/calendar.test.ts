/**
 * "Add to calendar" (board 31, M47 §10) ADDS IT — the phone's own new-event screen, filled in, on Android; a calendar
 * file through the share sheet where there is none; and "failed" said, never a dead button.
 */

import { Platform } from 'react-native'

const mockStart = jest.fn(async (_action: string, _params: unknown) => ({ resultCode: 0 }))
jest.mock('expo-intent-launcher', () => ({ startActivityAsync: (action: string, params: unknown) => mockStart(action, params) }), { virtual: true })
const mockShare = jest.fn(async (_url: string, _options: unknown) => undefined)
let mockCanShare = true
jest.mock('expo-sharing', () => ({ isAvailableAsync: async () => mockCanShare, shareAsync: (url: string, options: unknown) => mockShare(url, options) }))
const mockKept: { name: string; base64: string }[] = []
jest.mock('@gs/core/api', () => ({
  keepForSharing: async (input: { folder: string; name: string; base64: string }) => (mockKept.push(input), `file:///cache/${input.name}`),
  safeFileName: (name: string) => name.replace(/[^A-Za-z0-9._-]+/g, '-'),
}))

import { addToCalendar, icsOf, type CalendarEvent } from '../calendar'

const EVENT: CalendarEvent = {
  title: 'Gazebo booking',
  startsAt: '2026-10-24T16:00:00+00:00',
  endsAt: '2026-10-24T18:00:00+00:00',
  location: 'Gazebo, Phase 2, Phoenix Park Village 1',
  notes: 'Confirmation code GZ-2610-A4. Show the QR in the app at the gate on the day.',
  uid: 'GZ-2610-A4@geminisecure',
}
const AT = new Date('2026-10-14T15:00:00Z')

const setPlatform = (os: 'android' | 'ios') => Object.defineProperty(Platform, 'OS', { get: () => os, configurable: true })

beforeEach(() => {
  mockStart.mockClear()
  mockShare.mockClear()
  mockKept.length = 0
  mockCanShare = true
})

it('writes one event in UTC, its words escaped as a calendar file needs them', () => {
  const ics = icsOf(EVENT, AT)
  expect(ics.split('\r\n')).toEqual([
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//GeminiSecure//Resident App//EN',
    'BEGIN:VEVENT',
    'UID:GZ-2610-A4@geminisecure',
    'DTSTAMP:20261014T150000Z',
    'DTSTART:20261024T160000Z',
    'DTEND:20261024T180000Z',
    'SUMMARY:Gazebo booking',
    'LOCATION:Gazebo\\, Phase 2\\, Phoenix Park Village 1',
    'DESCRIPTION:Confirmation code GZ-2610-A4. Show the QR in the app at the gate on the day.',
    'END:VEVENT',
    'END:VCALENDAR',
  ])
})

it('on Android, opens the calendar’s own new-event screen, filled in: nothing is added unless the resident saves it', async () => {
  setPlatform('android')
  expect(await addToCalendar(EVENT, AT)).toBe('opened')
  expect(mockStart).toHaveBeenCalledWith('android.intent.action.INSERT', {
    data: 'content://com.android.calendar/events',
    extra: { title: 'Gazebo booking', beginTime: Date.parse(EVENT.startsAt), endTime: Date.parse(EVENT.endsAt), eventLocation: EVENT.location, description: EVENT.notes },
  })
  expect(mockShare).not.toHaveBeenCalled()
})

it('with no calendar app to take it, or on an iPhone, shares the event as a calendar file', async () => {
  setPlatform('android')
  mockStart.mockRejectedValueOnce(new Error('No Activity found'))
  expect(await addToCalendar(EVENT, AT)).toBe('shared')
  expect(mockKept[0]?.name).toBe('Gazebo-booking.ics')
  expect(mockShare).toHaveBeenCalledWith('file:///cache/Gazebo-booking.ics', { mimeType: 'text/calendar', UTI: 'com.apple.ical.ics', dialogTitle: 'Gazebo booking' })

  setPlatform('ios')
  mockShare.mockClear()
  expect(await addToCalendar(EVENT, AT)).toBe('shared')
  expect(mockStart).toHaveBeenCalledTimes(1)
})

it('says it failed when there is no way at all: the screen says so in its own words', async () => {
  setPlatform('ios')
  mockCanShare = false
  expect(await addToCalendar(EVENT, AT)).toBe('failed')
})
