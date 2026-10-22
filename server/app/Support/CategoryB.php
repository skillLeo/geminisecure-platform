<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Category B — every web screen whose primary dataset originates on a mobile device (13 C3).
 *
 * THE DEFINITION, as ruled: "any screen whose primary dataset originates on a
 * mobile device". Made testable here as: the records a screen exists to show are
 * written by a `/api/v1` endpoint a handset calls — built today, or specified for
 * the Guard App and Resident App in 13 D2/D3. A screen that merely mentions a
 * device figure beside records typed in a console is not Category B, and each
 * such near miss is listed in `EXCLUDED` with its reason, so the boundary is a
 * decision on the record rather than an omission.
 *
 * EVERY SCREEN HERE RENDERS `SourceBadge`, computed from its own rows.
 * `CategoryBTest` asserts both directions: each listed page renders the badge,
 * and no page renders it without being listed. `docs/CATEGORY_B.md` is the same
 * list for people.
 */
final class CategoryB
{
    /**
     * Inertia component => what it shows, and who writes it.
     *
     * @var array<string, array{source: string, board: string|null, records: string, written_by: string}>
     */
    public const SCREENS = [
        // Guard App
        'Gemini/Dispatch/Map' => ['source' => SourceBadge::GUARD, 'board' => 'super-admin-12', 'records' => 'duress_alerts, shifts', 'written_by' => 'POST /alerts, POST /shifts/{id}/clock-in'],
        'Gemini/Dispatch/Coverage' => ['source' => SourceBadge::GUARD, 'board' => 'super-admin-13', 'records' => 'shifts (clock-ins)', 'written_by' => 'POST /shifts/{id}/clock-in, /clock-out'],
        'Gemini/Dispatch/Alerts' => ['source' => SourceBadge::GUARD, 'board' => 'super-admin-14', 'records' => 'duress_alerts', 'written_by' => 'POST /alerts, POST /duress'],
        'Gemini/Dispatch/Alertness' => ['source' => SourceBadge::GUARD, 'board' => 'super-admin-15', 'records' => 'alertness_checks, checkpoint_scans', 'written_by' => 'POST /alertness/{id}/respond, POST /checkpoints/{id}/scan'],
        'Gemini/Dispatch/Requests' => ['source' => SourceBadge::GUARD, 'board' => 'super-admin-16', 'records' => 'guard_requests', 'written_by' => 'POST /requests'],
        'Gemini/Dispatch/RequestHistory' => ['source' => SourceBadge::GUARD, 'board' => null, 'records' => 'guard_requests (decided)', 'written_by' => 'POST /requests'],
        'Gemini/Dispatch/Alert' => ['source' => SourceBadge::GUARD, 'board' => 'super-admin-17', 'records' => 'one duress_alert', 'written_by' => 'POST /alerts, POST /duress'],
        'Gemini/Dispatch/Chat' => ['source' => SourceBadge::RESIDENT, 'board' => null, 'records' => 'support_chats, support_chat_messages', 'written_by' => 'POST /support/chat/messages'],
        'Gemini/Operations/GateActivity' => ['source' => SourceBadge::GUARD, 'board' => 'super-admin-26', 'records' => 'gate_events', 'written_by' => 'POST /gate-events, /gate/entry, /gate/exit, /gate/override'],
        'Gemini/Operations/Incidents' => ['source' => SourceBadge::GUARD, 'board' => 'super-admin-27', 'records' => 'security_incidents', 'written_by' => 'POST /incidents'],
        'Gemini/Operations/Incident' => ['source' => SourceBadge::GUARD, 'board' => null, 'records' => 'one security_incident', 'written_by' => 'POST /incidents, POST /incidents/{id}/media'],

        /*
         * THE ESTATE'S OWN SECURITY SCREEN (M10 §2), and a Guard App screen although it sits in
         * the Estate Console. What it shows is the same central records the dispatch screens show,
         * scoped to one estate — panics, incidents, gate scans — and every one of them is written
         * by a handset. §2 gave it the badge and never listed it, so this test failed on the first
         * run after it landed: a screen claiming "Live from Guard App" that nobody had classified.
         */
        'Estate/Security/Index' => ['source' => SourceBadge::GUARD, 'board' => null, 'records' => 'duress_alerts, security_incidents, gate_events', 'written_by' => 'POST /alerts, POST /incidents, POST /gate-events'],

        // Resident App
        'Estate/Governance/ControlRoom' => ['source' => SourceBadge::RESIDENT, 'board' => 'community-admin-09', 'records' => 'ballot_receipts (turnout)', 'written_by' => 'POST /elections/{id}/ballot'],
        'Estate/Governance/Results' => ['source' => SourceBadge::RESIDENT, 'board' => 'community-admin-11', 'records' => 'ballot_receipts, ballot_marks', 'written_by' => 'POST /elections/{id}/ballot'],
        'Estate/Facilities/Maintenance' => ['source' => SourceBadge::RESIDENT, 'board' => 'community-admin-17', 'records' => 'maintenance_tickets', 'written_by' => 'POST /tickets'],
        'Estate/Facilities/Ticket' => ['source' => SourceBadge::RESIDENT, 'board' => 'community-admin-18', 'records' => 'one maintenance_ticket', 'written_by' => 'POST /tickets, POST /tickets/{id}/media'],
        'Estate/Facilities/Bookings' => ['source' => SourceBadge::RESIDENT, 'board' => 'community-admin-19', 'records' => 'amenity_bookings', 'written_by' => 'POST /bookings'],
        'Estate/Facilities/Booking' => ['source' => SourceBadge::RESIDENT, 'board' => null, 'records' => 'one amenity_booking', 'written_by' => 'POST /bookings, POST /bookings/{id}/cancel'],
        'Estate/Residents/Claims' => ['source' => SourceBadge::RESIDENT, 'board' => 'community-admin-31', 'records' => 'unit_claims', 'written_by' => 'POST /auth/claim-unit'],
    ];

    /**
     * Screens that show something a device produced and are NOT Category B, and why.
     *
     * @var array<string, string>
     */
    public const EXCLUDED = [
        'Gemini/Dashboard' => 'A platform overview: clients, revenue and counts. The alert tile links to the queue, which carries the badge.',
        'Gemini/Reports/ClientHealth' => 'A roll-up of adoption percentages computed from device events, not the events. Each capability\'s own screen carries the badge.',
        'Gemini/Reports/Utilisation' => 'Contracted against deployed guards from the workforce record; no device row is on it.',
        'Gemini/Guards/Show' => 'The guard\'s HR record — licence, post, standing. The shifts and scans behind it are on the dispatch screens.',
        'Gemini/Clients/Guards' => 'Deployment from the workforce record.',
        'Gemini/Operations/Roster' => 'Shifts as rostered by a supervisor in the console. Clock-ins are on the coverage board.',
        'Gemini/Operations/StandingOrders' => 'Orders written in the console. The acknowledgement count on a set is secondary to the text.',
        'Gemini/Operations/StandingOrder' => 'One order set\'s text, written in the console, with acknowledgements beneath it.',
        'Gemini/Simulator/Index' => 'The producer of simulated device data, not a view of it.',
        'Estate/Dashboard' => 'An estate overview led by dues and notices. Its tiles link to the screens that carry the badge.',
        'Estate/Reports/Show' => 'The shell for seven reports, one of which (security incidents) is device-derived; a badge on the shell would mislabel the other six.',
        'Estate/Governance/Nominations' => 'Nominations are lodged with the estate office; no nominations endpoint is specified for the Resident App.',
        'Estate/Governance/Notices' => 'Written in the console and sent to residents. Read receipts are secondary.',
        'Estate/Residents/Show' => 'The household\'s register entry, maintained by the estate.',
    ];
}
