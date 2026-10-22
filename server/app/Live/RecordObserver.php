<?php

declare(strict_types=1);

namespace App\Live;

use App\Live\Events\RecordLive;
use App\Models\DispatchMessage;
use App\Models\Estate\Account;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\AmenitySlot;
use App\Models\Estate\Ballot;
use App\Models\Estate\BankReconciliation;
use App\Models\Estate\BankStatementLine;
use App\Models\Estate\Bill;
use App\Models\Estate\BillPayment;
use App\Models\Estate\Charge;
use App\Models\Estate\Employee;
use App\Models\Estate\Household;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use App\Models\Estate\MeetingMinutes;
use App\Models\Estate\Nomination;
use App\Models\Estate\Notice;
use App\Models\Estate\Payment;
use App\Models\Estate\PayrollException;
use App\Models\Estate\PayrollRun as EstatePayrollRun;
use App\Models\Estate\PayrollRunLine;
use App\Models\Estate\Phase;
use App\Models\Estate\Resident;
use App\Models\Estate\StatutoryFiling as EstateFiling;
use App\Models\Estate\TicketActivity;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\Estate\Vendor;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\Invoice;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\ResidentAccount;
use App\Models\SecurityIncident;
use App\Models\Shift;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Throwable;

/**
 * EVERY WRITE TO A RECORD A SCREEN SHOWS, TOLD TO THOSE SCREENS (M11 §2; D-175).
 *
 * One Eloquent observer, registered for the models whose screens were not yet live (AppServiceProvider). A save or
 * a delete — inside the write's own transaction when it has one, since Eloquent fires these there — records a
 * `{entity}.changed` / `{entity}.removed` reference (RecordLive) to the channels of the screens that show that
 * record, per DESIGN.md §2.5–§2.16:
 *
 *   incident   the Gemini workforce feed and the incident's own page, the estate's Security page, the filing guard
 *   shift      the Gemini workforce and dispatch feeds (roster, coverage, the live map), the estate's Security page
 *              ("on site now"), the guard, and the estate's guards (colleagues, open shifts)
 *   message    the guard's thread, the Gemini dispatch feed
 *   request    the guard, the Gemini workforce feed
 *   payroll    each guard of an approved run (their payslips), the Gemini payroll feed
 *   ticket     the estate's Facilities page and the ticket's own, the household, and — a report happening now — the
 *              estate's guards
 *   dues       the estate's money pages (dues, ledger, the unit's ledger, receipts) and the household (Dues, Home)
 *   notice     the estate's Governance page, and the community or the phase it was posted to
 *   meeting    the estate's Governance page and the meeting's own, and its audience
 *   election   the estate's Governance page and the ballot's own, and the community — never on a vote cast:
 *              BallotReceipt is not observed, and turnout is not a record anybody's screen follows (§2.11)
 *   resident   a person, a household or a unit claim: the estate's Residents pages and the unit's own (M18H §6),
 *              named by the unit — the register's pages are keyed on the lot
 *   account    a Resident App sign-in whose standing changed (linked, revoked, deleted, restored): the Residents pages
 *
 * AND THE SCREENS THAT STILL SAID "THIS SCREEN DOES NOT UPDATE BY ITSELF" (M18H §7.2):
 *   booking    an amenity booking (and its household), an amenity or its slots: Facilities → Bookings
 *   structure  a lot or a phase: Structure, and the Residents pages that list them
 *   accounting a vendor, a bill or its payment, an account, a reconciliation or its statement lines: Accounting
 *   staff      the estate's own payroll — an employee, a run and its lines, an exception, a filing: Payroll
 *   client     an estate as Gemini's client (its row, its subscription): the Gemini Clients pages
 *   invoice    Gemini's invoice to an estate: Gemini Billing, and the estate's own Settings → Billing
 *   officer    a guard's record — hired, suspended, their licence: Gemini Guards, Compliance, Sites
 *   setting    who may sign in to an estate's console (an assignment): Settings → Users
 *
 * A sign-in's every save (its last code, its phone) is not a change anybody's screen shows, so an account tells only
 * when its standing did.
 *
 * Raw-table writes (standing orders, incident media) are told where they happen, with RecordLive::tell.
 *
 * NEVER IN THE WAY OF THE WRITE. Anything that goes wrong building the channels is reported, not thrown, outside
 * local and testing — a record that saved is never failed by the screens that follow it.
 */
final class RecordObserver
{
    /** @var list<class-string<Model>> */
    public const MODELS = [
        SecurityIncident::class, Shift::class, DispatchMessage::class, GuardRequest::class, PayrollRun::class,
        MaintenanceTicket::class, TicketActivity::class, Charge::class, Payment::class,
        Notice::class, Meeting::class, MeetingMinutes::class, Ballot::class, Nomination::class,
        Resident::class, Household::class, UnitClaim::class, ResidentAccount::class,
        AmenityBooking::class, Amenity::class, AmenitySlot::class, Unit::class, Phase::class,
        Vendor::class, Bill::class, BillPayment::class, Account::class, BankReconciliation::class, BankStatementLine::class,
        Employee::class, EstatePayrollRun::class, PayrollRunLine::class, PayrollException::class, EstateFiling::class,
        Tenant::class, Subscription::class, Invoice::class, Guard::class, EstateAssignment::class,
    ];

    /** The columns whose change is a change of an account's standing — what the Residents pages draw of it. */
    private const ACCOUNT_STANDING = ['status', 'resident_id', 'unit_id', 'full_name', 'deleted_at', 'anonymised_at', 'revoked_at'];

    public function saved(Model $model): void
    {
        $this->tell($model, 'changed');
    }

    public function deleted(Model $model): void
    {
        $this->tell($model, 'removed');
    }

    private function tell(Model $model, string $verb): void
    {
        try {
            foreach ($this->plan($model, $verb) as [$entity, $id, $tenant, $channels, $as]) {
                RecordLive::tell($entity, $id, $tenant, $channels, $as, (bool) $model->getAttribute('is_simulated'));
            }
        } catch (Throwable $e) {
            if (app()->environment('local', 'testing')) {
                throw $e;
            }

            report($e);
        }
    }

    /**
     * What to tell, as [entity, id, tenant, channels, verb] — one entry per tenant whose channels it names.
     *
     * @return list<array{0: string, 1: int|string, 2: string, 3: list<string>, 4: string}>
     */
    private function plan(Model $model, string $verb): array
    {
        $estate = static fn (): ?string => tenant()?->getTenantKey() === null ? null : (string) tenant()->getTenantKey();

        return match (true) {
            $model instanceof SecurityIncident => $this->incident($model, $verb),
            $model instanceof Shift => $this->shift($model, $verb),
            $model instanceof DispatchMessage => self::guardAnd('message', (int) $model->getKey(), (string) $model->tenant_id, (int) $model->guard_id, [...Channels::gemini('dispatch', (string) $model->tenant_id)], $verb),
            $model instanceof GuardRequest => self::guardAnd('request', (int) $model->getKey(), (string) $model->tenant_id, (int) $model->guard_id, [...Channels::gemini('workforce', (string) $model->tenant_id)], $verb),
            $model instanceof PayrollRun => $this->payroll($model),
            $model instanceof MaintenanceTicket => ($t = $estate()) === null ? [] : [$this->ticket($model, $t, $verb)],
            $model instanceof TicketActivity => ($t = $estate()) === null || ($ticket = MaintenanceTicket::query()->find($model->maintenance_ticket_id)) === null ? [] : [$this->ticket($ticket, $t, 'changed')],
            $model instanceof Charge, $model instanceof Payment => ($t = $estate()) === null ? [] : [$this->dues($model, $t)],
            $model instanceof Notice => ($t = $estate()) === null ? [] : [['notice', (int) $model->getKey(), $t, [Channels::estateFeed($t, 'governance'), ...self::audience($t, (string) $model->audience_scope, $model->audience_phase)], $verb]],
            $model instanceof Meeting => ($t = $estate()) === null ? [] : [['meeting', (int) $model->getKey(), $t, [Channels::estateFeed($t, 'governance'), Channels::estateRecord($t, 'governance', 'meeting', (int) $model->getKey()), ...self::audience($t, (string) $model->audience_scope, $model->phase)], $verb]],
            $model instanceof MeetingMinutes => ($t = $estate()) === null ? [] : [['meeting', (int) $model->meeting_id, $t, [Channels::estateFeed($t, 'governance'), Channels::estateRecord($t, 'governance', 'meeting', (int) $model->meeting_id), Channels::community($t)], 'changed']],
            $model instanceof Ballot => ($t = $estate()) === null ? [] : [['election', (int) $model->getKey(), $t, [Channels::estateFeed($t, 'governance'), Channels::estateRecord($t, 'governance', 'ballot', (int) $model->getKey()), Channels::community($t)], $verb]],
            $model instanceof Nomination => ($t = $estate()) === null ? [] : [['election', (int) $model->ballot_id, $t, [Channels::estateFeed($t, 'governance'), Channels::estateRecord($t, 'governance', 'ballot', (int) $model->ballot_id), Channels::estateRecord($t, 'governance', 'nomination', (int) $model->getKey()), Channels::community($t)], 'changed']],
            $model instanceof Resident => ($t = $estate()) === null ? [] : [self::register($t, Household::query()->whereKey($model->household_id)->value('unit_id'))],
            $model instanceof Household => ($t = $estate()) === null ? [] : [self::register($t, $model->unit_id)],
            $model instanceof UnitClaim => ($t = $estate()) === null ? [] : [self::register($t, $model->unit_id)],
            // Just created (an insert syncs no changes), its standing changed, or gone. `wasRecentlyCreated` alone stays
            // true on the instance for every later save, and would tell each sign-in's `verified_at` as a change.
            $model instanceof ResidentAccount => ! self::carried($model->tenant_id) || ! (($model->wasRecentlyCreated && $model->getChanges() === []) || $model->wasChanged(self::ACCOUNT_STANDING) || $verb === 'removed')
                ? []
                : [['account', (int) $model->getKey(), (string) $model->tenant_id, [Channels::estateFeed((string) $model->tenant_id, 'residents')], $verb]],

            // M18H §7.2 — estate records, told with the estate open.
            $model instanceof AmenityBooking => ($t = $estate()) === null ? [] : [['booking', (int) $model->getKey(), $t, [Channels::estateFeed($t, 'facilities'), ...self::householdsOf($t, (int) $model->unit_id)], $verb]],
            $model instanceof Amenity, $model instanceof AmenitySlot => ($t = $estate()) === null ? [] : [['booking', (int) $model->getKey(), $t, [Channels::estateFeed($t, 'facilities')], 'changed']],
            $model instanceof Unit, $model instanceof Phase => ($t = $estate()) === null ? [] : [['structure', (int) $model->getKey(), $t, [Channels::estateFeed($t, 'structure'), Channels::estateFeed($t, 'residents')], 'changed']],
            $model instanceof Vendor, $model instanceof Bill, $model instanceof BillPayment, $model instanceof Account, $model instanceof BankReconciliation, $model instanceof BankStatementLine
                => ($t = $estate()) === null ? [] : [['accounting', (int) $model->getKey(), $t, [Channels::estateFeed($t, 'accounting')], 'changed']],
            $model instanceof Employee, $model instanceof EstatePayrollRun, $model instanceof PayrollRunLine, $model instanceof PayrollException, $model instanceof EstateFiling
                => ($t = $estate()) === null ? [] : [['staff', (int) $model->getKey(), $t, [Channels::estateFeed($t, 'payroll')], 'changed']],

            // Central records about one estate — told only for an estate whose key the channel grammar carries.
            $model instanceof Tenant => ! self::carried($model->getKey()) ? [] : [['client', 0, (string) $model->getKey(), Channels::gemini('clients', (string) $model->getKey()), 'changed']],
            $model instanceof Subscription => ! self::carried($model->tenant_id) ? [] : [['client', (int) $model->getKey(), (string) $model->tenant_id, [...Channels::gemini('clients', (string) $model->tenant_id), ...Channels::gemini('billing', (string) $model->tenant_id)], 'changed']],
            $model instanceof Invoice => ! self::carried($model->tenant_id) ? [] : [['invoice', (int) $model->getKey(), (string) $model->tenant_id, [...Channels::gemini('billing', (string) $model->tenant_id), Channels::estateFeed((string) $model->tenant_id, 'billing')], $verb]],
            $model instanceof Guard => ! self::carried($model->tenant_id) ? [] : [['officer', (int) $model->getKey(), (string) $model->tenant_id, Channels::gemini('workforce', (string) $model->tenant_id), $verb]],
            $model instanceof EstateAssignment => ! self::carried($model->tenant_id) ? [] : [['setting', (int) $model->getKey(), (string) $model->tenant_id, [Channels::estateFeed((string) $model->tenant_id, 'settings')], $verb]],
            default => [],
        };
    }

    /**
     * Whether an estate key is one the channel grammar carries (Channels::tenant). Every provisioned estate's is; an id
     * the grammar refuses names no channel anybody could hold, so there is nobody to tell — as LiveShell treats it.
     */
    private static function carried(mixed $tenantId): bool
    {
        if (! is_string($tenantId) || $tenantId === '') {
            return false;
        }

        try {
            Channels::estateFeed($tenantId, 'console');

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * The register changed at a unit: the Residents pages, and the unit's own when there is one. Always `changed` — a
     * person removed from a household is a household that changed, and the unit's page is still there.
     *
     * @return array{0: string, 1: int|string, 2: string, 3: list<string>, 4: string}
     */
    private static function register(string $tenant, mixed $unitId): array
    {
        $unit = is_numeric($unitId) ? (int) $unitId : 0;

        return ['resident', $unit, $tenant, [
            Channels::estateFeed($tenant, 'residents'),
            ...($unit > 0 ? [Channels::estateRecord($tenant, 'residents', 'unit', $unit)] : []),
        ], 'changed'];
    }

    /** @return list<array{0: string, 1: int|string, 2: string, 3: list<string>, 4: string}> */
    private function incident(SecurityIncident $incident, string $verb): array
    {
        $tenant = (string) $incident->tenant_id;
        $id = (int) $incident->getKey();
        $channels = [...Channels::gemini('workforce', $tenant), Channels::estateFeed($tenant, 'security')];

        if (! Channels::isDemonstrationEstate($tenant)) {
            $channels[] = Channels::geminiRecord($tenant, 'workforce', 'incident', $id);
        }

        return self::guardAnd('incident', $id, $tenant, $incident->guard_id === null ? null : (int) $incident->guard_id, $channels, $verb);
    }

    /** @return list<array{0: string, 1: int|string, 2: string, 3: list<string>, 4: string}> */
    private function shift(Shift $shift, string $verb): array
    {
        $tenant = (string) $shift->tenant_id;

        return self::guardAnd('shift', (int) $shift->getKey(), $tenant, (int) $shift->guard_id, [
            ...Channels::gemini('workforce', $tenant),
            ...Channels::gemini('dispatch', $tenant),
            Channels::estateFeed($tenant, 'security'),
            Channels::estateGuards($tenant),
        ], $verb);
    }

    /**
     * Only a change of STATUS reaches the guards: an approved run puts payslips in front of them. One event per guard.
     *
     * @return list<array{0: string, 1: int|string, 2: string, 3: list<string>, 4: string}>
     */
    private function payroll(PayrollRun $run): array
    {
        if (! $run->wasChanged('status') && ! $run->wasRecentlyCreated) {
            return [];
        }

        $plan = [];

        foreach (Payslip::query()->where('payroll_run_id', $run->getKey())->whereNotNull('tenant_id')->get(['guard_id', 'tenant_id']) as $slip) {
            $tenant = (string) $slip->tenant_id;
            $plan[] = ['payroll', (int) $run->getKey(), $tenant, [Channels::guard($tenant, (int) $slip->guard_id), ...Channels::gemini('payroll', $tenant)], 'changed'];
        }

        return $plan;
    }

    /** @return array{0: string, 1: int|string, 2: string, 3: list<string>, 4: string} */
    private function ticket(MaintenanceTicket $ticket, string $tenant, string $verb): array
    {
        $channels = [
            Channels::estateFeed($tenant, 'facilities'),
            Channels::estateRecord($tenant, 'facilities', 'ticket', (int) $ticket->getKey()),
            ...($ticket->unit_id === null ? [] : self::householdsOf($tenant, (int) $ticket->unit_id)),
        ];

        // A report of something happening now reaches the officers on duty, and so does its closing.
        if ((bool) $ticket->happening_now) {
            $channels[] = Channels::estateGuards($tenant);
        }

        return ['ticket', (int) $ticket->getKey(), $tenant, $channels, $verb];
    }

    /** @return array{0: string, 1: int|string, 2: string, 3: list<string>, 4: string} */
    private function dues(Charge|Payment $row, string $tenant): array
    {
        $unit = (int) $row->unit_id;
        $channels = [
            Channels::estateFeed($tenant, 'dues'),
            Channels::estateFeed($tenant, 'ledger'),
            Channels::estateRecord($tenant, 'ledger', 'unit', $unit),
        ];

        if ($row instanceof Payment) {
            $channels[] = Channels::estateFeed($tenant, 'payments');
        }

        $channels = [...$channels, ...self::householdsOf($tenant, $unit)];

        // Named by the UNIT: every screen that follows dues follows a unit's position, never one charge.
        return ['dues', $unit, $tenant, $channels, 'changed'];
    }

    /**
     * EVERY household on the unit (M12 §5.4). A household's tickets and its dues are the UNIT's
     * (the app reads both by the unit), and nothing stops a unit holding two households. Telling one
     * of them, whichever row came back first with no order, left the other's screens standing.
     *
     * @return list<string>
     */
    private static function householdsOf(string $tenant, int $unit): array
    {
        return Household::query()->where('unit_id', $unit)->orderBy('id')->pluck('id')
            ->map(static fn (int|string $id): string => Channels::household($tenant, (int) $id))
            ->all();
    }

    /**
     * A notice or meeting for the whole estate reaches its community; one for a phase reaches that phase.
     *
     * @return list<string>
     */
    private static function audience(string $tenant, string $scope, mixed $phase): array
    {
        if ($scope === 'phase' && is_string($phase) && $phase !== '') {
            try {
                return [Channels::phase($tenant, ChannelName::phaseKey($phase))];
            } catch (Throwable) {
                return [Channels::community($tenant)];
            }
        }

        return [Channels::community($tenant)];
    }

    /**
     * The record's channels, plus the guard's own — on the guard's OWN estate's stream when that differs (an
     * officer posted across clients): an event names only its own estate's channels, so that is a second one.
     *
     * @param  list<string>  $channels
     * @return list<array{0: string, 1: int|string, 2: string, 3: list<string>, 4: string}>
     */
    private static function guardAnd(string $entity, int $id, string $tenant, ?int $guardId, array $channels, string $verb): array
    {
        $plan = [];
        $guardTenant = $guardId === null ? null : Guard::query()->whereKey($guardId)->value('tenant_id');

        if ($guardId !== null && $guardTenant !== null && (string) $guardTenant === $tenant) {
            $channels[] = Channels::guard($tenant, $guardId);
        }

        $plan[] = [$entity, $id, $tenant, $channels, $verb];

        if ($guardId !== null && $guardTenant !== null && (string) $guardTenant !== $tenant) {
            $plan[] = [$entity, $id, (string) $guardTenant, [Channels::guard((string) $guardTenant, $guardId)], $verb];
        }

        return $plan;
    }
}
