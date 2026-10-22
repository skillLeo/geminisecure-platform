<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Api\AppMatrix;

/**
 * Every notification type a phone understands — the server's copy of the mobile
 * repo's `packages/core/notify/links.ts` (M4 §3.2).
 *
 * A PUSH CARRIES A TYPE AND AN ID, NEVER A URL. The phone decides the screen from
 * its own table, so nothing the server (or anybody forging a push) sends can open
 * anything but one of the app's own screens. A type that is not in this table is
 * refused HERE, before it is queued — the phone would ignore it anyway.
 *
 * Each type says:
 *
 *   app        which app's phones receive it
 *   category   the switch on the phone's notification settings that governs it.
 *              `null` CANNOT BE SWITCHED OFF — safety and access never depend on a
 *              preference, and the server ignores a mute for it
 *   channel    the Android channel id. The phone creates exactly these channels at
 *              start-up (`packages/core/notify/links.ts` `CHANNELS`); an id the
 *              phone does not have would fall back to a silent default
 *   urgent     high priority on the `safety` channel, never coalesced, and queued where the
 *              worker takes it first (`push.urgent_queue`)
 *
 * `PushTypesTest` reads links.ts and fails if the two tables disagree.
 */
final class PushTypes
{
    /**
     * The loudest channel the app can make: the highest importance Android allows, its own
     * vibration, and readable on the lock screen without unlocking.
     *
     * DO NOT DISTURB. Android honours a channel's Do Not Disturb bypass only for an app holding
     * Notification Policy Access. The Resident App does not request it. THE GUARD APP DOES
     * (mobile `apps/guard/app.config.ts` `permissions`), and creates this channel with the
     * bypass on for itself only — so this used to say neither app requests it, which stopped
     * being true when the Guard App began to. A phone that has not granted it, or is in a
     * bed-time mode, can still silence a panic on this channel; the alert is recorded, reaches
     * the control room and is waiting when the app is opened. The call-style ring (`site_ring`)
     * does not ride on this channel: the Guard App's ring module rings on its own alarm channel
     * and says, in its readiness report, whether that bypass is on. On iOS an urgent push is
     * sent `time-sensitive`, and both apps carry the entitlement for it.
     *
     * THE ID STAYS `safety`. `channel()` returns it on every urgent push and the phone creates it
     * at start-up under that exact name; renaming it on one side only would send every panic to a
     * channel the handset never made, which Android quietly demotes to the app's default. If the
     * permission is ever granted it takes a NEW id, shipped on both sides in one release.
     */
    public const SAFETY = 'safety';

    /** Anything with no switch of its own that is not an emergency. */
    public const GENERAL = 'general';

    /** @var array<string, array{app: string, category: string|null, urgent: bool}> */
    public const TYPES = [
        // Guard App
        'site_alert' => ['app' => AppMatrix::GUARD, 'category' => null, 'urgent' => true],
        'duress_update' => ['app' => AppMatrix::GUARD, 'category' => null, 'urgent' => true],
        'alertness_check' => ['app' => AppMatrix::GUARD, 'category' => null, 'urgent' => true],
        'message' => ['app' => AppMatrix::GUARD, 'category' => 'messages', 'urgent' => false],
        'broadcast' => ['app' => AppMatrix::GUARD, 'category' => 'messages', 'urgent' => false],
        'order_published' => ['app' => AppMatrix::GUARD, 'category' => 'orders', 'urgent' => false],
        'shift_changed' => ['app' => AppMatrix::GUARD, 'category' => 'shifts', 'urgent' => false],
        'shift_open' => ['app' => AppMatrix::GUARD, 'category' => 'shifts', 'urgent' => false],
        'claim_decided' => ['app' => AppMatrix::GUARD, 'category' => 'shifts', 'urgent' => false],
        'request_decided' => ['app' => AppMatrix::GUARD, 'category' => 'requests', 'urgent' => false],
        'payslip_ready' => ['app' => AppMatrix::GUARD, 'category' => 'payslips', 'urgent' => false],

        /*
         * A resident says something is happening NOW at the site (M4 §3.5). It cannot
         * be switched off — it is site safety, not a preference — and it is NOT
         * urgent: a report is not a panic, the app tells the resident so in those
         * words, and a handset that bypassed Do Not Disturb for one would be saying
         * something the resident's own screen does not.
         */
        'resident_report' => ['app' => AppMatrix::GUARD, 'category' => null, 'urgent' => false],

        /*
         * THE CALL-STYLE RING (the guard ring §6.4). `site_ring` is data-only and is woken
         * by the Guard App's own ring module; `ring_stop` tells every rung phone to stop, and
         * its visible form is an ordinary notice on the `general` channel. Neither is ever
         * sent through `PushNotifier` — `SendRingPush` builds them — but both are here so
         * the phone's `links.ts` and this table still agree type for type, and a tapped one
         * opens `/alert/{id}` like today's `site_alert`. Neither can be muted.
         */
        'site_ring' => ['app' => AppMatrix::GUARD, 'category' => null, 'urgent' => true],
        'ring_stop' => ['app' => AppMatrix::GUARD, 'category' => null, 'urgent' => false],

        /*
         * THE OFFICER'S OWN POSITIONS STOPPED (M22 §1.1): nothing recorded for ten minutes on a shift they agreed to
         * share (`tracking:watch`), once per gap. It cannot be muted — the notice on their screen was saying something
         * untrue — and it is not urgent. Opening it brings the app forward, which is also what restarts sharing.
         */
        'tracking_gap' => ['app' => AppMatrix::GUARD, 'category' => null, 'urgent' => false],

        // Resident App
        'approval_request' => ['app' => AppMatrix::RESIDENT, 'category' => null, 'urgent' => true],
        'panic_update' => ['app' => AppMatrix::RESIDENT, 'category' => null, 'urgent' => true],
        'claim_approved' => ['app' => AppMatrix::RESIDENT, 'category' => null, 'urgent' => false],
        'visitor_admitted' => ['app' => AppMatrix::RESIDENT, 'category' => 'visitors', 'urgent' => false],
        'notice' => ['app' => AppMatrix::RESIDENT, 'category' => 'notices', 'urgent' => false],
        'ticket_update' => ['app' => AppMatrix::RESIDENT, 'category' => 'maintenance', 'urgent' => false],
        'payment_recorded' => ['app' => AppMatrix::RESIDENT, 'category' => 'payments', 'urgent' => false],
        'dues_reminder' => ['app' => AppMatrix::RESIDENT, 'category' => 'payments', 'urgent' => false],
        'meeting_reminder' => ['app' => AppMatrix::RESIDENT, 'category' => 'meetings', 'urgent' => false],
        'election_open' => ['app' => AppMatrix::RESIDENT, 'category' => 'voting', 'urgent' => false],
        'election_results' => ['app' => AppMatrix::RESIDENT, 'category' => 'voting', 'urgent' => false],
        'booking_decided' => ['app' => AppMatrix::RESIDENT, 'category' => 'bookings', 'urgent' => false],

        /*
         * GEMINI SECURITY ANSWERED ON LIVE CHAT (M50 §4.3). The answer the resident asked for, so no switch holds it back;
         * not urgent — Live chat is not for emergencies, and the screen says so.
         */
        'support_reply' => ['app' => AppMatrix::RESIDENT, 'category' => null, 'urgent' => false],

        // A Helper or a Dependent came in or went out on their own e-Pass (8 Oct 2026), to whom the estate's rule says.
        'household_entry' => ['app' => AppMatrix::RESIDENT, 'category' => 'visitors', 'urgent' => false],
    ];

    public static function known(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function app(string $type): string
    {
        return self::TYPES[$type]['app'];
    }

    /** The phone's switch for this type, or null when it cannot be switched off. */
    public static function category(string $type): ?string
    {
        return self::TYPES[$type]['category'];
    }

    public static function urgent(string $type): bool
    {
        return self::TYPES[$type]['urgent'];
    }

    /** The Android channel: `safety` for an emergency, the category's own otherwise. */
    public static function channel(string $type): string
    {
        return self::urgent($type) ? self::SAFETY : (self::category($type) ?? self::GENERAL);
    }

    /**
     * The categories an app's phone MAY switch off — what `push.register` accepts
     * in `muted`. Anything else a phone sends (a locked category, a name from a
     * newer build) is dropped, never stored: a mute the server does not know
     * cannot silence anything.
     *
     * @return list<string>
     */
    public static function mutable(string $app): array
    {
        $categories = [];

        foreach (self::TYPES as $type) {
            if ($type['app'] === $app && $type['category'] !== null) {
                $categories[$type['category']] = true;
            }
        }

        return array_keys($categories);
    }
}
