<?php

declare(strict_types=1);

use App\Models\Role;
use App\Services\Dispatch\RequestInbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\QueryBudget;
use Tests\Support\ScaleFixture;
use Tests\Support\ScaleVolume;

/*
|--------------------------------------------------------------------------
| The request inbox works out every leave balance on a page from one read — M11 A2
|--------------------------------------------------------------------------
|
| "Balance after: 6 days remaining" is the fact a dispatcher decides a vacation request
| on, and every vacation row asked the database for its own guard's approved days: sixty
| queries on an inbox at twelve clients. The balances are now summed once, grouped by
| guard, for the guards on the page.
|
| Two oracles. The old per-guard sum, written out here, must give every guard on the page
| the same days the grouped read does. And the sentences themselves are checked against
| balances worked out by hand, across the cases the sum has to get right: approved and
| pending, this year and last, vacation and sick, and a subject in capitals.
|
*/

beforeEach(function (): void {
    ScaleFixture::platform();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

/** The sum each vacation row used to ask for its own guard, before M11. */
function leaveTakenByTheOldQuery(int $guardId): int
{
    return (int) DB::connection('mysql')
        ->table('guard_requests')
        ->where('guard_id', $guardId)
        ->where('status', 'approved')
        ->where(static function ($subjects): void {
            $subjects->orWhereRaw('LOWER(subject) = ?', ['vacation']);
        })
        ->whereYear('starts_on', now()->year)
        ->selectRaw('COALESCE(SUM(DATEDIFF(COALESCE(ends_on, starts_on), starts_on) + 1), 0) as days')
        ->value('days');
}

/**
 * Guards at a client of their own, with the leave history each case needs.
 *
 * @return array{tenant: string, guards: array<string, int>}
 */
function leaveWorld(): array
{
    $db = DB::connection('mysql');
    $now = Carbon::now();
    $tag = (string) random_int(100000, 999999);
    $tenant = 'm11leave'.$tag;
    $year = $now->copy()->startOfYear();

    $db->table('tenants')->insert([
        'id' => $tenant, 'name' => 'Leave Estate '.$tag, 'status' => 'active', 'demo' => 0, 'data' => '{}',
        'provisioned_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $guards = [];
    foreach (['taken five' => 14, 'none taken' => 10, 'overdrawn' => 3] as $label => $entitlement) {
        $guards[$label] = (int) $db->table('guards')->insertGetId([
            'full_name' => 'Leave Officer '.$tag.' '.ucfirst($label), 'employee_number' => 'GS-LV-'.$tag.'-'.$entitlement,
            'psra_number' => 'PSRA-LV-'.$tag.'-'.$entitlement, 'psra_expires_on' => $now->copy()->addYear()->toDateString(),
            'employment_type' => 'full_time', 'status' => 'active', 'reviewer' => false, 'tenant_id' => $tenant,
            'leave_entitlement_days' => $entitlement, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $request = static function (int $guard, string $subject, string $status, Carbon $from, int $days, bool $certificate = false) use ($db, $tenant, $now): void {
        $db->table('guard_requests')->insert([
            'tenant_id' => $tenant, 'guard_id' => $guard, 'kind' => 'leave', 'subject' => $subject,
            'starts_on' => $from->toDateString(), 'ends_on' => $from->copy()->addDays($days - 1)->toDateString(),
            'reason' => 'Leave', 'certificate_attached' => $certificate, 'status' => $status,
            'decided_at' => $status === 'pending' ? null : $now->copy()->subDays(2), 'decision_note' => null,
            'created_at' => $now->copy()->subDays(3), 'updated_at' => $now,
        ]);
    };

    // Five days of vacation approved this year (three, and two written in capitals); last
    // year's, a sick week and a denied request are not taken from this year's entitlement.
    $request($guards['taken five'], 'Vacation', 'approved', $year->copy()->addDays(10), 3);
    $request($guards['taken five'], 'VACATION', 'approved', $year->copy()->addDays(40), 2);
    $request($guards['taken five'], 'Vacation', 'approved', $year->copy()->subMonths(2), 6);
    $request($guards['taken five'], 'Sick', 'approved', $year->copy()->addDays(60), 5, certificate: true);
    $request($guards['taken five'], 'Vacation', 'denied', $year->copy()->addDays(70), 4);
    $request($guards['taken five'], 'Vacation', 'pending', $now->copy()->addDays(20), 4);

    $request($guards['none taken'], 'Vacation', 'pending', $now->copy()->addDays(25), 2);
    $request($guards['none taken'], 'Sick', 'pending', $now->copy()->addDays(1), 1);

    $request($guards['overdrawn'], 'Vacation', 'approved', $year->copy()->addDays(5), 3);
    $request($guards['overdrawn'], 'Vacation', 'pending', $now->copy()->addDays(30), 2);

    return ['tenant' => $tenant, 'guards' => $guards];
}

it('gives every guard on the page the balance the per-row sum gave, and says it in the same words', function (): void {
    $w = leaveWorld();
    $director = ScaleVolume::geminiUser(Role::DIRECTOR);
    $inbox = app(RequestInbox::class);

    $leave = collect($inbox->forViewer($director)['leave']);
    $mine = static fn (string $name): array => $leave->filter(static fn (array $r): bool => str_contains($r['title'], $name))->pluck('detail')->values()->all();

    $tag = substr($w['tenant'], 8);

    expect($mine('Taken five'))->toBe([$leave->firstWhere('title', 'Leave Officer '.$tag.' Taken five — Vacation')['detail']])
        ->and($mine('Taken five')[0])->toEndWith('4 days · Balance after: 5 days remaining')
        ->and($mine('None taken'))->toHaveCount(2)
        ->and($leave->firstWhere('title', 'Leave Officer '.$tag.' None taken — Vacation')['detail'])->toEndWith('2 days · Balance after: 8 days remaining')
        ->and($leave->firstWhere('title', 'Leave Officer '.$tag.' None taken — Sick')['detail'])->toEndWith('1 day · No medical certificate')
        ->and($leave->firstWhere('title', 'Leave Officer '.$tag.' Overdrawn — Vacation')['detail'])->toEndWith('2 days · Balance after: 0 days remaining');

    // The grouped read against the old per-guard sum, for every guard it answers for — the
    // inbox's own page and the decided history's.
    $grouped = new ReflectionMethod($inbox, 'leaveTakenBy');
    $pendingRows = DB::connection('mysql')->table('guard_requests')->where('tenant_id', $w['tenant'])->get(['guard_id', 'kind', 'subject']);
    $taken = $grouped->invoke($inbox, $pendingRows);

    foreach ($w['guards'] as $guardId) {
        expect($taken[$guardId] ?? 0)->toBe(leaveTakenByTheOldQuery($guardId));
    }

    // A guard with nothing approved is absent — nought days — and the order of a GROUP BY is
    // not a promise, so the keys are sorted before they are compared.
    ksort($taken);
    expect($taken)->toBe([$w['guards']['taken five'] => 5, $w['guards']['overdrawn'] => 3]);

    // The history's balances are the same sentences, from the same sum.
    $history = collect($inbox->history($director)['rows'])->filter(static fn (array $r): bool => str_contains($r['title'], 'Leave Officer '.$tag));
    expect($history->firstWhere('title', 'Leave Officer '.$tag.' Overdrawn — Vacation')['detail'])->toEndWith('3 days · Balance after: 0 days remaining');
});

/** `$count` more officers at one new client, each with a vacation pending and one approved. */
function officersOnLeave(int $count): void
{
    $db = DB::connection('mysql');
    $now = Carbon::now();
    $tag = (string) random_int(100000, 999999);
    $tenant = 'm11onleave'.$tag;

    $db->table('tenants')->insert([
        'id' => $tenant, 'name' => 'On Leave Estate '.$tag, 'status' => 'active', 'demo' => 0, 'data' => '{}',
        'provisioned_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);

    for ($n = 1; $n <= $count; $n++) {
        $guard = (int) $db->table('guards')->insertGetId([
            'full_name' => 'On Leave '.$tag.' '.$n, 'employee_number' => 'GS-OL-'.$tag.'-'.$n, 'psra_number' => 'PSRA-OL-'.$tag.'-'.$n,
            'employment_type' => 'full_time', 'status' => 'active', 'reviewer' => false, 'tenant_id' => $tenant,
            'leave_entitlement_days' => 14, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (['pending' => $now->copy()->addDays(30 + $n), 'approved' => $now->copy()->startOfYear()->addDays($n)] as $status => $from) {
            $db->table('guard_requests')->insert([
                'tenant_id' => $tenant, 'guard_id' => $guard, 'kind' => 'leave', 'subject' => 'Vacation',
                'starts_on' => $from->toDateString(), 'ends_on' => $from->copy()->addDays(1)->toDateString(), 'reason' => 'Leave',
                'status' => $status, 'decided_at' => $status === 'approved' ? $now->copy()->subMinutes($n) : null,
                'created_at' => $now->copy()->subDays(2), 'updated_at' => $now,
            ]);
        }
    }
}

it('sums the balances once for the whole inbox, however many vacation rows it holds', function (): void {
    $director = ScaleVolume::geminiUser(Role::DIRECTOR);
    $inbox = app(RequestInbox::class);

    $sums = static fn (array $m): int => count(array_filter(
        $m['statements'],
        static fn (array $s): bool => str_contains($s['sql'], 'SUM(DATEDIFF('),
    ));

    // Each read once to load what the viewer brings (their roles), then counted from a clean
    // start: every estate's zone is read afresh, as on a new request, so the second client
    // made below costs the same as the first.
    $count = static function (callable $read): array {
        $read();
        QueryBudget::asANewRequest();

        return QueryBudget::measure($read);
    };

    officersOnLeave(30);
    $inboxBefore = $count(fn () => $inbox->forViewer($director));
    $historyBefore = $count(fn () => $inbox->history($director));

    // Thirty more officers, each with a vacation pending and one approved this year — the
    // newest decisions, so the history's first page is theirs.
    officersOnLeave(30);
    $inboxAfter = $count(fn () => $inbox->forViewer($director));
    $historyAfter = $count(fn () => $inbox->history($director));

    expect(count($inboxAfter['result']['leave']))->toBe(count($inboxBefore['result']['leave']) + 30)
        ->and(count($inboxBefore['result']['leave']))->toBeGreaterThanOrEqual(30)
        // One sum for every vacation row in the inbox, thirty of them or sixty.
        ->and($sums($inboxBefore))->toBe(1)
        ->and($sums($inboxAfter))->toBe(1)
        ->and($inboxAfter['count'])->toBe($inboxBefore['count'])
        // And one for a page of decided vacations.
        ->and($sums($historyBefore))->toBe(1)
        ->and($sums($historyAfter))->toBe(1)
        ->and($historyAfter['count'])->toBe($historyBefore['count']);
});
