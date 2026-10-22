<?php

declare(strict_types=1);

namespace App\Support;

/**
 * THE WORDS AN OFFICER ANSWERED, VERSION BY VERSION (Hassam, 12 October 2026: "the guard's consent record, showing what
 * was agreed and when").
 *
 * The Guard App shows the location policy before anything is shared (apps/guard/src/features/tracking/trackingPolicy.ts,
 * `POLICY`), and the server kept only its version (`config('tracking.policy_version')`): a consent row said WHEN and
 * WHICH VERSION, never WHAT. These are those words as the app showed them. The current version is pinned to the app's
 * own by a contract test beside the mobile checkout (TrackingPolicyWordsTest); the earlier one is copied from the app's
 * history (91073af, 25 September), which is the record of what an officer saw. A new version goes here, in the app and
 * in config/tracking.php together.
 *
 * KEPT AS SHOWN, DASHES AND ALL. The 2026-10-03 words carry em dashes, which the console's own words never do (Law 3):
 * they are a quotation of what an officer read before answering, and a record that tidied them would no longer be one.
 *
 * 2026-10-12 (Hassam, 12 October, agreed word for word by Security, Governance and Residents): two sections change,
 * in one version so every officer agrees once. "Who sees it" states the general rule (at some sites the estate's own
 * management also sees where the officer is) and promises that the app says, before each shift, whether it applies
 * there; estates are never named in the policy, so a contract that changes needs no new version. It opens with who at
 * GeminiSecure sees it, "GeminiSecure’s own staff", since the client ruled that everyone with the live map keeps it (the
 * Admin Assistant included), so "the control room and your supervisors" was no longer true. "What is recorded"
 * says the cadence the setting is bound to, 45 to 90 seconds, chosen by the estate within it. Held here ahead of the
 * version in force: it is shown, and answered, from the day config/tracking.php and the phone's own words move to it
 * together.
 */
final class TrackingPolicyWords
{
    /**
     * `{days}` is the platform's retention, filled in as the app fills it from the server.
     *
     * @var array<string, array{title: string, lead: string, sections: list<array{heading: string, body: string}>, agree: string, refuse: string}>
     */
    public const VERSIONS = [
        '2026-10-03' => [
            'title' => 'Sharing your location while on duty',
            'lead' => 'GeminiSecure shares your location with the control room while you are on duty, so dispatch can send the nearest officer to an alarm and find you if you need help.',
            'sections' => [
                ['heading' => 'What is recorded', 'body' => 'Your phone’s location, how accurate it is, the time, and your battery level — about once a minute while you move, less often while you stand still or your battery is low, and each time you clock in, scan a gate pass, scan a checkpoint, raise a duress alert or clock out.'],
                ['heading' => 'Only during your shift', 'body' => 'Sharing starts when you clock in and stops when you clock out. While you are on duty it carries on in the background and when the app is closed, and your phone shows a notification that says so the whole time. Off duty, nothing is recorded and the server refuses anything a phone sends.'],
                ['heading' => 'Who sees it', 'body' => 'The GeminiSecure control room and your supervisors. The community you guard sees how many officers are on site and their names — not where you are standing — unless its contract says otherwise.'],
                ['heading' => 'How long it is kept', 'body' => 'Positions are deleted after {days} days.'],
                ['heading' => 'Raising a concern', 'body' => 'Speak to your supervisor, or write to the GeminiSecure office. Saying no is recorded as your answer and does not stop you working: the app goes on as before, and the control room sees “position not shared”. You can change your answer later under More → Location sharing.'],
            ],
            'agree' => 'I agree — share my location on duty',
            'refuse' => 'No — do not share my location',
        ],
        '2026-10-10' => [
            'title' => 'Sharing your location while on duty',
            'lead' => 'GeminiSecure Guard collects your location to send the nearest officer to an alarm, to find you if you raise a duress alert, and to show the control room who is on post: even when the app is closed or not in use, but only from clock-in to clock-out.',
            'sections' => [
                ['heading' => 'What is recorded', 'body' => 'Your phone’s location, how accurate it is, the time, and your battery level: about once a minute while you move, less often while you stand still or your battery is low, and each time you clock in, scan a gate pass, scan a checkpoint, raise a duress alert or clock out.'],
                ['heading' => 'Only during your shift', 'body' => 'Sharing starts when you clock in and stops when you clock out. While you are on duty it carries on in the background and when the app is closed, and your phone shows a notification that says so the whole time. Off duty, nothing is recorded and the server refuses anything a phone sends.'],
                ['heading' => 'Who sees it', 'body' => 'The GeminiSecure control room and your supervisors. The community you guard sees how many officers are on site and their names, not where you are standing, unless its contract says otherwise.'],
                ['heading' => 'How long it is kept', 'body' => 'Positions are deleted after {days} days.'],
                ['heading' => 'Raising a concern', 'body' => 'Speak to your supervisor, or write to the GeminiSecure office. Saying no is recorded as your answer and does not stop you working: the app goes on as before, and the control room sees “position not shared”. You can change your answer later under More → Location sharing.'],
            ],
            'agree' => 'I agree: share my location on duty',
            'refuse' => 'No: do not share my location',
        ],
        '2026-10-12' => [
            'title' => 'Sharing your location while on duty',
            'lead' => 'GeminiSecure Guard collects your location to send the nearest officer to an alarm, to find you if you raise a duress alert, and to show the control room who is on post: even when the app is closed or not in use, but only from clock-in to clock-out.',
            'sections' => [
                ['heading' => 'What is recorded', 'body' => 'Your phone’s location, how accurate it is, the time, and your battery level: every 45 to 90 seconds while you move, less often while you stand still or your battery is low, and each time you clock in, scan a gate pass, scan a checkpoint, raise a duress alert or clock out. The estate where you are on duty chooses how often, within those limits.'],
                ['heading' => 'Only during your shift', 'body' => 'Sharing starts when you clock in and stops when you clock out. While you are on duty it carries on in the background and when the app is closed, and your phone shows a notification that says so the whole time. Off duty, nothing is recorded and the server refuses anything a phone sends.'],
                ['heading' => 'Who sees it', 'body' => 'GeminiSecure’s own staff can see where you are while you are on duty. The community you guard sees how many officers are on site and their names. At some sites, the estate’s own management can also see where you are while you are on duty. Your app will tell you, before each shift, whether that applies at the site you are working.'],
                ['heading' => 'How long it is kept', 'body' => 'Positions are deleted after {days} days.'],
                ['heading' => 'Raising a concern', 'body' => 'Speak to your supervisor, or write to the GeminiSecure office. Saying no is recorded as your answer and does not stop you working: the app goes on as before, and the control room sees “position not shared”. You can change your answer later under More → Location sharing.'],
            ],
            'agree' => 'I agree: share my location on duty',
            'refuse' => 'No: do not share my location',
        ],
    ];

    /**
     * One version's words with the retention filled in as the app fills it, or null for a version this server never
     * held the words of.
     *
     * @return array{title: string, lead: string, sections: list<array{heading: string, body: string}>, agree: string, refuse: string}|null
     */
    public static function of(string $version, int $retentionDays): ?array
    {
        $words = self::VERSIONS[$version] ?? null;

        if ($words === null) {
            return null;
        }

        $words['sections'] = array_map(
            static fn (array $section): array => ['heading' => $section['heading'], 'body' => str_replace('{days}', (string) $retentionDays, $section['body'])],
            $words['sections'],
        );

        return $words;
    }
}
