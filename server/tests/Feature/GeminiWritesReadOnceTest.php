<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Invoice;
use App\Models\Post;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use App\Services\Gemini\ClientGuardAssignment;
use App\Services\Gemini\InvoiceActions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;
use Tests\Support\QueryBudget;

/*
|--------------------------------------------------------------------------
| Two Gemini writes that asked once per person — M11 A2
|--------------------------------------------------------------------------
|
| "Resend to client" asked each of the estate's officers, one at a time, whether they may
| read the invoice: their roles and their direct permissions, two queries an officer. And
| saving a client's guard roster wrote every officer's posting with a statement of its own.
| The officers' roles and permissions are loaded together now, and a roster is written one
| statement per post.
|
| The oracles are the answers as they were: the resend goes to exactly the accounts a fresh
| `can()` admits, one at a time, and the roster leaves every officer at exactly the estate
| and post the screen posted. Neither asks more of more people.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->client = 'm11writes'.random_int(100000, 999999);
    $this->estate = null;

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->client, 'name' => 'Writes Estate', 'receipt_prefix' => 'W'.substr($this->client, -5), 'status' => 'active',
        'demo' => 0, 'data' => '{}', 'provisioned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** An estate account holding one role at this file's client. */
function writesOfficer(string $roleName, string $tenantId): User
{
    $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active']);
    $user->syncRoles([$roleName]);

    EstateAssignment::query()->create([
        'user_id' => $user->id, 'tenant_id' => $tenantId,
        'role_id' => Role::query()->where('name', $roleName)->firstOrFail()->id, 'is_active' => true,
    ]);

    return $user;
}

it('resends an invoice to exactly the officers a fresh permission check admits, asking the same of nine officers as of three', function (): void {
    Notification::fake();
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $invoice = Invoice::query()->create([
        'tenant_id' => $this->client, 'reference' => 'W'.substr($this->client, -5).'-INV-202609', 'period' => 'Sep 2026',
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'subtotal_minor' => 171_000_00, 'total_minor' => 171_000_00,
        'currency' => 'JMD', 'due_on' => '2026-09-15', 'status' => 'issued',
    ]);

    // Who may read it (Super Admin, President) and who may not (Secretary), in each round.
    $round = function (): void {
        foreach ([Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT, Role::SECRETARY] as $role) {
            writesOfficer($role, $this->client);
        }
    };

    $count = function () use ($invoice, $director): array {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(InvoiceActions::class)->resend($invoice->id, $director); // warms the permission cache, as any earlier request does

        return QueryBudget::measure(fn () => app(InvoiceActions::class)->resend($invoice->id, $director));
    };

    $round();
    $three = $count();

    $round();
    $round();
    $nine = $count();

    // The oracle: every assignment's account, asked on its own, the way the resend used to ask.
    $expected = EstateAssignment::query()->where('tenant_id', $this->client)->where('is_active', true)->get()
        ->map(static fn (EstateAssignment $a): ?User => User::query()->find($a->user_id))
        ->filter(static fn (?User $u): bool => $u !== null && $u->console === Console::Estate && $u->status === 'active' && trim((string) $u->email) !== '' && $u->can(InvoiceActions::RECIPIENT_PERMISSION))
        ->map(static fn (User $u): string => (string) $u->email)
        ->unique()->sort()->values()->all();

    expect(collect($nine['result'])->sort()->values()->all())->toBe($expected)
        ->and(count($expected))->toBe(6)
        ->and($nine['count'])->toBe($three['count']);

    Notification::assertSentTo(User::query()->whereIn('email', $expected)->get(), InvoiceIssued::class);
});

it('writes a guard roster one statement per post, leaving every officer where the screen posted them, for twelve officers as for six', function (): void {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $estate = Tenant::query()->findOrFail($this->client);

    $posts = [
        Post::query()->create(['tenant_id' => $this->client, 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true])->id,
        Post::query()->create(['tenant_id' => $this->client, 'name' => 'Patrol', 'type' => 'patrol', 'is_active' => true])->id,
    ];

    $officers = static fn (int $count): array => array_map(static fn (int $n): int => Guard::query()->create([
        'full_name' => 'Writes Officer '.$n.' '.uniqid(), 'employee_number' => 'GS-WR-'.uniqid(), 'psra_number' => 'PSRA-WR-'.uniqid(),
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => null,
    ])->id, range(1, $count));

    // Every third officer is posted to no particular post: "on the roster, post to be decided".
    $roster = static fn (array $ids): array => array_map(static fn (int $id, int $i): array => ['guard_id' => $id, 'post_id' => $i % 3 === 2 ? null : $posts[$i % 2]], $ids, array_keys($ids));

    // A roster already standing, so each counted save has officers before it as well as after:
    // an eager load over an empty roster asks nothing, and would make the first save one cheaper.
    $standing = $roster($officers(3));
    app(ClientGuardAssignment::class)->save($estate, $standing, $director);

    // Three more, then six more, every officer already posted kept exactly where they are.
    $three = [...$standing, ...$roster($officers(3))];
    $firstSave = QueryBudget::measure(fn () => app(ClientGuardAssignment::class)->save($estate, $three, $director));

    $nine = [...$three, ...$roster($officers(6))];
    $secondSave = QueryBudget::measure(fn () => app(ClientGuardAssignment::class)->save($estate, $nine, $director));

    // The oracle: the screen's own lines, officer by officer.
    foreach ($nine as $line) {
        $guard = Guard::query()->findOrFail($line['guard_id']);

        expect((string) $guard->tenant_id)->toBe($this->client)
            ->and($guard->post_id === null ? null : (int) $guard->post_id)->toBe($line['post_id']);
    }

    $postingWrites = static fn (array $m): int => count(array_filter($m['statements'], static fn (array $s): bool => str_starts_with(strtolower(ltrim($s['sql'])), 'update `guards` set `tenant_id`')));

    expect($postingWrites($firstSave))->toBe(3)
        ->and($postingWrites($secondSave))->toBe(3)
        ->and($secondSave['count'])->toBe($firstSave['count']);
});
