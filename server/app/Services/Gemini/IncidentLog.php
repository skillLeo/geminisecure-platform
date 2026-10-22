<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\Guard;
use App\Models\SecurityIncident;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Estate\EstateSecurity;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Logging an incident and closing one — board 27's "Log incident" and "View"
 * (12 §2, Wave 2 item 27).
 *
 * AN INCIDENT RECORD IS EVIDENCE. An insurer, a client and the PSRA all read
 * it, so the intake is structured rather than a free-text box: which client,
 * when it happened, what kind of thing it was, how serious, what happened, and
 * — where there was one — the guard on post. Every field but the guard is
 * required, and a record that cannot say when or where is refused rather than
 * kept as half a record.
 *
 * WHO LOGGED IT IS STORED BY NAME AS WELL AS BY ID, and the guard's name beside
 * the guard's id, for the migration's reason: people leave; the record that
 * they were involved cannot leave with them.
 *
 * A RESOLUTION CLOSES AN INCIDENT, AND IS FINAL. The two are written together —
 * an incident marked resolved with no account of what was done reads the same
 * as an open one to whoever comes to it later — and nothing reopens or rewrites
 * a closed incident. Something that recurs is a new incident, with its own date.
 */
final class IncidentLog
{
    /** The board's three badges. */
    public const SEVERITIES = ['low' => 'Low', 'med' => 'Medium', 'high' => 'High'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $fields
     */
    public function log(array $fields, User $by): SecurityIncident
    {
        $tenantId = (string) ($fields['tenant_id'] ?? '');
        $estate = Tenant::query()->find($tenantId);

        if ($estate === null || ! $by->canAccessEstate($tenantId)) {
            throw new DomainException('That client is not one this role can log an incident against.');
        }

        $kind = trim((string) ($fields['kind'] ?? ''));
        $detail = trim((string) ($fields['detail'] ?? ''));
        $severity = (string) ($fields['severity'] ?? '');

        if ($kind === '') {
            throw new DomainException('Say what kind of incident it was — "Attempted unauthorized access", "Equipment fault — barrier arm sensor".');
        }

        if (! array_key_exists($severity, self::SEVERITIES)) {
            throw new DomainException('Choose a severity: low, medium or high.');
        }

        if (mb_strlen($detail) < 20) {
            throw new DomainException('Describe what happened. An incident record is read by an insurer and the PSRA, and a line of shorthand cannot be explained to either.');
        }

        // Typed on the clock AT THE ESTATE it happened at; kept as the UTC moment it means (M4 §3.1).
        $typed = trim((string) ($fields['occurred_at'] ?? ''));
        $occurredAt = $typed === '' ? Carbon::now() : SiteTime::parse($typed, (string) $estate->getTenantKey());

        if ($occurredAt->greaterThan(Carbon::now()->addMinutes(5))) {
            throw new DomainException('An incident is logged after it happens. That time is in the future.');
        }

        $guard = null;

        if (($fields['guard_id'] ?? null) !== null && $fields['guard_id'] !== '') {
            $guard = Guard::query()->find((int) $fields['guard_id']);

            if ($guard === null || ($guard->tenant_id !== null && ! $by->canAccessEstate((string) $guard->tenant_id))) {
                throw new DomainException('That guard is not one this role covers.');
            }
        }

        return DB::connection('mysql')->transaction(function () use ($estate, $guard, $kind, $detail, $severity, $occurredAt, $by): SecurityIncident {
            $incident = SecurityIncident::query()->create([
                'tenant_id' => $estate->getTenantKey(),
                'guard_id' => $guard?->id,
                'guard_name' => $guard?->full_name,
                'kind' => mb_substr($kind, 0, 160),
                'detail' => $detail,
                'severity' => $severity,
                'status' => SecurityIncident::OPEN,
                'occurred_at' => $occurredAt,
                'logged_by' => $by->getKey(),
                'logged_by_name' => $by->name,
            ]);

            $this->audit->record(
                action: 'operations.incident_logged',
                entityType: 'SecurityIncident',
                entityId: (string) $incident->id,
                after: [
                    'kind' => $incident->kind,
                    'severity' => $incident->severity,
                    'occurred_at' => SiteTime::iso($incident->occurred_at),
                    'guard' => $incident->guard_name,
                ],
                tenantId: (string) $estate->getTenantKey(),
            );

            return $incident;
        });
    }

    /** Close an incident with what was done about it. Final. */
    public function resolve(SecurityIncident $incident, string $resolution, User $by): SecurityIncident
    {
        if (! $by->canAccessEstate((string) $incident->tenant_id)) {
            throw new DomainException('That incident is not at a client this role covers.');
        }

        if ($incident->status === SecurityIncident::RESOLVED) {
            throw new DomainException('This incident was closed on '.($incident->closed_at?->format('M j, Y') ?? 'an unrecorded date').'. A closed incident is not rewritten — if it has happened again, log it again.');
        }

        $resolution = trim($resolution);

        if (mb_strlen($resolution) < 10) {
            throw new DomainException('Record what was done. A resolution is what closes an incident, and "resolved" with nothing after it reads the same as open to whoever comes to it later.');
        }

        $incident->forceFill([
            'status' => SecurityIncident::RESOLVED,
            'resolution' => $resolution,
            'closed_at' => Carbon::now(),
        ])->save();

        $this->audit->record(
            action: 'operations.incident_resolved',
            entityType: 'SecurityIncident',
            entityId: (string) $incident->id,
            before: ['status' => SecurityIncident::OPEN],
            after: ['status' => SecurityIncident::RESOLVED, 'resolution' => $resolution, 'closed_by' => $by->name],
            tenantId: (string) $incident->tenant_id,
        );

        return $incident;
    }

    /**
     * One incident, as the View screen reads it. Null outside the viewer's scope,
     * the same as for an id that does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $incidentId, User $viewer): ?array
    {
        $incident = SecurityIncident::query()->with('estate')->find($incidentId);

        if ($incident === null || ! $viewer->canAccessEstate((string) $incident->tenant_id)) {
            return null;
        }

        return [
            'id' => $incident->id,
            'estate' => (string) ($incident->estate->name ?? $incident->tenant_id),
            // In words, never the stored code ("property" reads Estate property), as every incident screen reads it.
            'kind' => EstateSecurity::incidentKindLabel((string) $incident->kind),
            'detail' => $incident->detail,
            'severity' => $incident->severity,
            'severity_label' => self::SEVERITIES[$incident->severity] ?? 'Low',
            'status' => $incident->status === SecurityIncident::RESOLVED ? 'closed' : 'open',
            // The vocabulary's words (M98 §3.5): an incident nobody has closed waits on somebody, so Action required.
            'status_label' => $incident->status === SecurityIncident::RESOLVED ? 'Resolved' : 'Action required',
            // Every time on the estate's clock, named where that is not Gemini's own (M11 §4.1). `created_at`
            // is the framework's UTC column, and printed as it was it put an evening's logging on the next day.
            'occurred_at' => SiteTime::stamp($incident->occurred_at, (string) $incident->tenant_id, 'l, F j, Y · g:i A'),
            'guard' => $incident->guard_name ?? 'No guard recorded',
            'logged_by' => $incident->logged_by_name ?? 'Not recorded',
            'logged_at' => $incident->created_at === null ? null : SiteTime::stamp($incident->created_at, (string) $incident->tenant_id, 'M j, Y g:i A'),
            'resolution' => $incident->resolution,
            'closed_at' => $incident->closed_at === null ? null : SiteTime::stamp($incident->closed_at, (string) $incident->tenant_id, 'M j, Y g:i A'),
        ];
    }
}
