/**
 * THE GUARD APP'S OWN PRIVACY POLICY AND TERMS (M24 §5.2, D-223), on the officer's phone and nowhere else.
 *
 * The client's rule: no resident may learn there is a guard app. So the website's policy and terms are the Resident
 * App's alone, and the Guard App's are compiled in here: they read with no signal, before enrolment, and they are
 * never at a public address. Each sentence says what the app really does today, checked against the code it
 * describes: on duty sharing (features/tracking/trackingPolicy.ts and the server's config/tracking.php, 90 days),
 * the alertness check-ins (features/alertness), the phone's own identity check and the supervisor's PIN
 * (features/shift/override.ts). When one of those changes, this text changes with it.
 *
 * DRAFT until the company's lawyer has read it, exactly as the website's pages are (config/legal.php `status`).
 * Turning DRAFT off is the one edit that makes it final.
 */

import { SUPPORT_CONTACT, supportAddress } from '@gs/core'

export const DRAFT = true

export const DRAFT_NOTE = `Draft. This describes accurately what the app does with your information, but its wording has not yet been reviewed by ${SUPPORT_CONTACT.company}'s lawyer. The final text will replace it.`

export type GuardDocument = {
  title: string
  updated: string
  intro: readonly string[]
  sections: readonly { heading: string; paragraphs: readonly string[] }[]
}

const company = SUPPORT_CONTACT.company

const CONTACT = {
  heading: 'Contact',
  paragraphs: [`Speak to your supervisor, or write to ${company} at ${SUPPORT_CONTACT.email}.`, `By post: ${company}, ${supportAddress()}.`],
}

export const GUARD_PRIVACY: GuardDocument = {
  title: 'Privacy Policy',
  updated: '1 October 2026',
  intro: [
    `This policy covers the GeminiSecure Guard app, which ${company} gives to its security officers. ${company} is your employer and is responsible for your information.`,
    'The app shows no advertising and contains no analytics trackers. We do not sell personal information.',
  ],
  sections: [
    {
      heading: 'What the app collects, and why',
      paragraphs: [
        'Your name, service number and post, so the app can show who is on duty. Your supervisors and the control room see them. The community you protect sees the name of the officer on duty.',
        'The make of your phone, an identifier for this installation of the app, and a notification token. The app works for one officer on one phone, so nobody else can act as you.',
        'The gate records you make: passes checked, visitors let in or turned away, and the type of ID a visitor showed. They are the community\'s security record, and your supervisors, the control room and the estate office see them.',
        'Your incident reports, the photos you attach, your patrol records and your messages with the control room. Your supervisors and the control room see them, and the estate office sees the incidents on its own community.',
        'Your leave and equipment requests, so they can be dealt with. Your supervisors and the office see them.',
        `The app also shows you your hours and payslips. They are your employment records, and ${company} holds them as your employer.`,
      ],
    },
    {
      heading: 'Your location at set moments',
      paragraphs: [
        'When you clock in, scan a checkpoint on patrol, raise a duress alert and clock out, the app records where you are. When you report an incident, you can choose to add where you are.',
        'This confirms you are at your post, records where something happened, and lets the control room find you if you need help. At clock in and clock out the app also records whether the phone says its location is simulated.',
      ],
    },
    {
      heading: 'Your location on duty, only if you agree',
      paragraphs: [
        'From the moment you clock in until you clock out, the app can share your location with the control room, even when the app is closed. This lets the control room send the nearest officer to an alarm and find you if you raise a duress alert. Your phone shows a notification the whole time it is shared.',
        'It is recorded about once a minute while you move, and less often while you stand still or your battery is low. Off duty, nothing is recorded, and the server refuses anything a phone sends.',
        'The control room and your supervisors see it. The community you protect sees how many officers are on site and their names, not where you are standing.',
        'Positions are deleted after 90 days.',
        'You decide. Saying no does not stop you working, and you can change your answer at any time under More, then Location sharing.',
      ],
    },
    {
      heading: 'Checking it is you at clock in',
      paragraphs: [
        'The fingerprint or face check is done by your phone. No image, template or score leaves the phone, and the app stores none.',
        'If the check cannot be done, a supervisor can start your shift with their PIN. That is recorded and reported.',
      ],
    },
    {
      heading: 'Alertness check-ins',
      paragraphs: [
        'Only if you agree to them separately. The app records whether and when you answered a check during a shift, and your supervisors see it. You can withdraw at any time under More, then Alertness check-ins.',
      ],
    },
    {
      heading: 'Camera',
      paragraphs: [
        'The camera reads pass codes and checkpoint codes, and takes the photos you choose to add to a report. While it reads codes it keeps no picture: nothing is saved, recorded or sent.',
      ],
    },
    {
      heading: 'How long we keep it',
      paragraphs: [
        'Your account details, until your account is closed.',
        'Gate, patrol, incident and alert records, for up to 7 years, as the community\'s security record.',
        'Your on duty positions, for 90 days.',
        'Your hours, payslips and deductions, for as long as employment and tax law requires.',
      ],
    },
    {
      heading: 'Who we share it with',
      paragraphs: [
        'Your supervisors, the control room and the estate office, as set out above.',
        'The companies that run the system for us: hosting, email, and the notification services of Apple, Google and Expo, which deliver notifications to your phone. A notification says what kind of thing happened.',
        'The authorities, when the law requires it.',
        'We do not sell personal information, and we do not share it for advertising.',
      ],
    },
    {
      heading: 'Keeping it safe',
      paragraphs: [
        'Information travels encrypted. The app\'s sign in key is kept in your phone\'s secure storage. Each community\'s records are kept in a separate database, and staff access is by role and is logged.',
      ],
    },
    {
      heading: 'Your rights',
      paragraphs: [
        `Under Jamaica's Data Protection Act you may ask to see the personal information held about you, to have it corrected, and to have it deleted where the law does not require it to be kept. Ask your supervisor or ${company}. You may also complain to the Office of the Information Commissioner.`,
      ],
    },
    {
      heading: 'Closing your account',
      paragraphs: [
        `Your account is issued by ${company}. To close it, speak to your supervisor or the office. You can remove the app from your phone at any time under More, then Delete my account.`,
      ],
    },
    {
      heading: 'Changes',
      paragraphs: ['If this policy changes in a way that matters, the app will tell you. Anything that needs your agreement will ask for it again.'],
    },
    CONTACT,
  ],
}

export const GUARD_TERMS: GuardDocument = {
  title: 'Terms of Use',
  updated: '1 October 2026',
  intro: [`These terms cover the GeminiSecure Guard app, which ${company} gives to its security officers for their work. By using the app you agree to them.`],
  sections: [
    {
      heading: 'What the app is for',
      paragraphs: [
        'The app is a work tool. It is for clocking in and out, checking passes and visitors, patrols, reporting incidents and keeping in touch with the control room.',
      ],
    },
    {
      heading: 'It is not an emergency service',
      paragraphs: [
        'A duress alert reaches the control room, your supervisors and the other officers on duty. It does not contact the police, the fire service or an ambulance. In an emergency, call 119 for the police and 110 for fire or an ambulance.',
        'Alerts depend on your phone, its battery and your network, and can be delayed or fail.',
      ],
    },
    {
      heading: 'Your phone and your code',
      paragraphs: [
        'Your enrolment code is for you alone, and the app works for one officer on one phone. Do not let anyone else use it.',
        'If your phone is lost or stolen, tell your supervisor at once, so it can be stopped.',
      ],
    },
    {
      heading: 'Using it properly',
      paragraphs: [
        'What you record must be true. Do not make a false report or a false alert, do not let anyone in who should not be there, and do not try to get at information that is not part of your work.',
        'What you record becomes part of the community\'s security record and of your employer\'s records.',
      ],
    },
    {
      heading: 'Your employment',
      paragraphs: [`These terms sit alongside your contract of employment and ${company}'s rules. Where they differ, your contract comes first.`],
    },
    {
      heading: 'When there is no signal',
      paragraphs: [
        'Much of the app works without a connection and sends what you did when the connection returns. The app always says when something has not been sent yet. We work to keep the system running but cannot promise it will never fail.',
      ],
    },
    {
      heading: 'Ending your use',
      paragraphs: ['When you leave, or when your supervisor ends your access, the app stops working on every phone at once.'],
    },
    {
      heading: 'Law',
      paragraphs: ['These terms are governed by the laws of Jamaica.'],
    },
    CONTACT,
  ],
}

export const GUARD_DOCUMENTS = { privacy: GUARD_PRIVACY, terms: GUARD_TERMS } as const

export type GuardDocumentKey = keyof typeof GUARD_DOCUMENTS

/** Where each document opens inside the app. Both are open to a phone with no session (SessionGate). */
export const GUARD_DOCUMENT_ROUTES: Record<GuardDocumentKey, string> = { privacy: '/privacy-policy', terms: '/terms' }
