/**
 * "Add to calendar" (board 31) — the booking as an event in the phone's own calendar. Nothing is asked of the resident
 * and nothing is read: no calendar permission, no access to what is already in it.
 *
 *   ANDROID     the calendar's own "new event" screen, filled in (the system's insert intent): the resident saves it there,
 *               or changes their mind there. Nothing is added unless they save it.
 *   ELSEWHERE   (an iPhone; an Android phone with no calendar app) the event as a calendar file (.ics), through the share
 *               sheet — "Add to Calendar" on an iPhone, or the calendar app of their choice.
 *   NEITHER     says so: `failed`, and the screen tells the resident in its own words, never a system dialog.
 */

import { Platform } from 'react-native'
import { keepForSharing, safeFileName } from '@gs/core/api'
import { toBase64 } from '@/features/visitors/qrPicture'

export type CalendarEvent = { title: string; startsAt: string; endsAt: string; location: string; notes: string; uid: string }

/** RFC 5545's text: a backslash before a backslash, a semicolon or a comma, and a line break written as one. */
const icsText = (text: string): string => text.replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\r?\n/g, '\\n')

/** "20261024T160000Z" — the instant in UTC, as a calendar file writes it; each calendar shows it on its own clock. */
const icsTime = (iso: string): string => new Date(iso).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '') // phone-clock: RFC 5545 writes an instant in UTC ("Z"); no person reads this text, a calendar app does

/** The event as a calendar file — one event, in UTC, so any calendar shows it on its own clock. */
export function icsOf(e: CalendarEvent, at: Date): string {
  return [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//GeminiSecure//Resident App//EN',
    'BEGIN:VEVENT',
    `UID:${icsText(e.uid)}`,
    `DTSTAMP:${icsTime(at.toISOString())}`,
    `DTSTART:${icsTime(e.startsAt)}`,
    `DTEND:${icsTime(e.endsAt)}`,
    `SUMMARY:${icsText(e.title)}`,
    `LOCATION:${icsText(e.location)}`,
    `DESCRIPTION:${icsText(e.notes)}`,
    'END:VEVENT',
    'END:VCALENDAR',
  ].join('\r\n')
}

/** The text's UTF-8 bytes — the file is written from base64. */
function utf8(text: string): Uint8Array {
  const out: number[] = []
  for (const ch of text) {
    const c = ch.codePointAt(0)!
    if (c < 0x80) out.push(c)
    else if (c < 0x800) out.push(0xc0 | (c >> 6), 0x80 | (c & 63))
    else if (c < 0x10000) out.push(0xe0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63))
    else out.push(0xf0 | (c >> 18), 0x80 | ((c >> 12) & 63), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63))
  }
  return new Uint8Array(out)
}

type Launcher = { startActivityAsync: (action: string, params: { data?: string; extra?: Record<string, string | number | boolean> }) => Promise<unknown> }
type Sharing = { isAvailableAsync: () => Promise<boolean>; shareAsync: (url: string, options?: { mimeType?: string; dialogTitle?: string; UTI?: string }) => Promise<void> }

/** Lazily, each by its own name (the bundler takes no other kind): a build without one is "the next way", never a crash. */
function intentLauncher(): Launcher | null {
  try {
    const mod = require('expo-intent-launcher') as Partial<Launcher> | undefined
    return mod && typeof mod.startActivityAsync === 'function' ? (mod as Launcher) : null
  } catch {
    return null
  }
}

function sharingModule(): Sharing | null {
  try {
    const mod = require('expo-sharing') as Partial<Sharing> | undefined
    return mod && typeof mod.shareAsync === 'function' && typeof mod.isAvailableAsync === 'function' ? (mod as Sharing) : null
  } catch {
    return null
  }
}

export async function addToCalendar(e: CalendarEvent, at: Date): Promise<'opened' | 'shared' | 'failed'> {
  if (Platform.OS === 'web') return 'failed'

  if (Platform.OS === 'android') {
    const launcher = intentLauncher()
    if (launcher) {
      try {
        await launcher.startActivityAsync('android.intent.action.INSERT', {
          data: 'content://com.android.calendar/events',
          extra: { title: e.title, beginTime: new Date(e.startsAt).getTime(), endTime: new Date(e.endsAt).getTime(), eventLocation: e.location, description: e.notes },
        })
        return 'opened'
      } catch {
        // No calendar app takes the insert: the file, as on an iPhone.
      }
    }
  }

  const sharing = sharingModule()
  if (!sharing || !(await sharing.isAvailableAsync().catch(() => false))) return 'failed'
  const file = await keepForSharing({ folder: 'calendar', name: `${safeFileName(e.title)}.ics`, base64: toBase64(utf8(icsOf(e, at))) })
  if (!file) return 'failed'
  try {
    await sharing.shareAsync(file, { mimeType: 'text/calendar', UTI: 'com.apple.ical.ics', dialogTitle: e.title })
    return 'shared'
  } catch {
    return 'failed'
  }
}
