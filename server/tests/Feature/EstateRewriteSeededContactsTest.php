<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Support\Seeded\Seeding;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| estate:rewrite-seeded-contacts (M35 §5.3)
|--------------------------------------------------------------------------
|
| Every seeded person's address goes to example.com and every number into 555-0100 to 555-0199, keeping the name and
| the way the number was written. Only seeded people: marked, or inside a range the seed recorded before the mark — the
| way production knows them. A real row is never changed, the reviewer's sign-in name is left as it is, nothing is
| written without --confirm, and a second run finds nothing.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    $this->site = FacilitiesFixture::ESTATE;
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false])->save();
    $this->unit = FacilitiesFixture::scratchUnit('Lot 9471');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Rewrite household', 'access_restricted' => false]);
    DB::connection('tenant')->table('residents')->where('household_id', $this->household->id)->delete();

    $person = fn (string $name, string $email, ?string $phone): array => ['household_id' => $this->household->id, 'full_name' => $name, 'relationship' => 'household member', 'is_primary' => false, 'status' => Resident::VERIFIED, 'email' => $email, 'phone' => $phone];

    // Marked as a seeder marks it…
    $this->marked = Seeding::run('phoenix:demo-seed', fn (): Resident => Resident::query()->create($person('Marked Rewrite', 'Marked.Rewrite@email.com', '876-555-0047')));
    $this->already = Seeding::run('phoenix:demo-seed', fn (): Resident => Resident::query()->create($person('Already Rewrite', 'already.rewrite@example.com', '876-555-0101')));
    // …written before the mark, inside the range the seed recorded — production's case…
    $this->legacy = Resident::query()->create($person('Legacy Rewrite', 'legacy.rewrite@email.com', '(876) 555 0223'));
    DB::connection('mysql')->table('sample_data_ranges')->insert([
        'tag' => 'phoenix-demo', 'tenant_id' => $this->site, 'connection' => 'tenant', 'table_name' => 'residents',
        'from_id' => $this->legacy->id - 1, 'to_id' => $this->legacy->id, 'append_only' => false, 'seeded_at' => now(),
    ]);
    // …and a real person, on the very same public mail service.
    $this->real = Resident::query()->create($person('Real Rewrite', 'real.rewrite@email.com', '876-508-8082'));

    $post = Post::query()->firstOrCreate(['tenant_id' => $this->site, 'name' => 'Rewrite Gate'], ['type' => 'gate', 'is_active' => true]);
    $officer = fn (string $no, string $email, string $phone): array => ['full_name' => 'Officer '.$no, 'employee_number' => $no, 'psra_number' => 'PSRA-'.$no, 'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $this->site, 'post_id' => $post->id, 'email' => $email, 'phone' => $phone];
    $this->seededGuard = Seeding::run('company:demo-seed', fn (): Guard => Guard::query()->create($officer('GS-RW-1', 'officer.rewrite@gmail.com', '876-555-2001')));
    $this->realGuard = Guard::query()->create($officer('GS-RW-2', 'officer.real@gmail.com', '876-555-2002'));

    $account = static fn (string $destination, string $name): array => ['tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $destination, 'full_name' => $name, 'status' => ResidentAccount::ACTIVE];
    $this->seededAccount = Seeding::run('phoenix:demo-seed', static fn (): ResidentAccount => ResidentAccount::query()->create($account('account.rewrite@email.com', 'Seeded Account')));
    $this->reviewer = Seeding::run('phoenix:demo-seed', static fn (): ResidentAccount => ResidentAccount::query()->create($account('review.rewrite@demo.geminisecure.app', 'Reviewer Account')));
    $this->reviewer->forceFill(['reviewer' => true])->save();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    DB::connection('tenant')->table('residents')->where('household_id', $this->household->id)->delete();
});

function rwPerson(int $id): array
{
    return (array) DB::connection('tenant')->table('residents')->where('id', $id)->first(['email', 'phone']);
}

it('says every change and makes none without --confirm', function () {
    expect(Artisan::call('estate:rewrite-seeded-contacts', ['--estate' => $this->site]))->toBe(0);
    $said = Artisan::output();

    expect($said)->toContain('residents #'.$this->marked->id)->toContain('Ma***@em***.com → Ma***@example.com')->toContain('876-555-0147')
        ->toContain('residents #'.$this->legacy->id)->toContain('(876) 555 0123')
        // The old value is masked, and the new one too: it keeps the old local part, so printed whole it would print
        // the old address's. And no name — the printout is for pasting into a report.
        ->not->toContain('Marked.Rewrite@')->not->toContain('legacy.rewrite@')
        ->not->toContain('Marked Rewrite')->not->toContain('Legacy Rewrite')
        ->toContain("the app-store reviewer's sign-in name")
        ->toContain('In all: 4 addresses and 3 numbers, on 4 rows.')
        ->toContain('Nothing was changed. Run again with --confirm');

    expect(rwPerson($this->marked->id))->toBe(['email' => 'Marked.Rewrite@email.com', 'phone' => '876-555-0047'])
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'seeded.contacts_rewritten')->count())->toBe(0);
});

it('rewrites only seeded people, keeps each name and each number\'s shape, and a second run changes nothing', function () {
    $this->artisan('estate:rewrite-seeded-contacts', ['--estate' => $this->site, '--confirm' => true])->assertSuccessful();

    expect(rwPerson($this->marked->id))->toBe(['email' => 'Marked.Rewrite@example.com', 'phone' => '876-555-0147'])
        ->and(rwPerson($this->legacy->id))->toBe(['email' => 'legacy.rewrite@example.com', 'phone' => '(876) 555 0123'])
        // Never a real person, even on the same mail service; never a seeded row already reaching nobody.
        ->and(rwPerson($this->real->id))->toBe(['email' => 'real.rewrite@email.com', 'phone' => '876-508-8082'])
        ->and(rwPerson($this->already->id))->toBe(['email' => 'already.rewrite@example.com', 'phone' => '876-555-0101'])
        ->and(Guard::query()->whereKey($this->seededGuard->id)->first(['email', 'phone'])->only(['email', 'phone']))->toBe(['email' => 'officer.rewrite@example.com', 'phone' => '876-555-0101'])
        ->and(Guard::query()->whereKey($this->realGuard->id)->value('email'))->toBe('officer.real@gmail.com')
        ->and(ResidentAccount::query()->whereKey($this->seededAccount->id)->value('destination'))->toBe('account.rewrite@example.com')
        ->and(ResidentAccount::query()->whereKey($this->reviewer->id)->value('destination'))->toBe('review.rewrite@demo.geminisecure.app');

    // One audit entry, naming rows by table and id — never an address.
    $entries = DB::connection('mysql')->table('audit_log')->where('action', 'seeded.contacts_rewritten')->get(['after']);
    expect($entries)->toHaveCount(1)
        ->and(json_decode((string) $entries[0]->after, true)['changes'])->toBe(7)
        ->and((string) $entries[0]->after)->not->toContain('@email.com')->not->toContain('@gmail.com')->not->toContain('@example.com');

    // Run twice: nothing left to change, and nothing written.
    $this->artisan('estate:rewrite-seeded-contacts', ['--estate' => $this->site, '--confirm' => true])
        ->expectsOutputToContain('Nothing to change')
        ->assertSuccessful();

    expect(DB::connection('mysql')->table('audit_log')->where('action', 'seeded.contacts_rewritten')->count())->toBe(1)
        ->and(rwPerson($this->marked->id))->toBe(['email' => 'Marked.Rewrite@example.com', 'phone' => '876-555-0147']);
});
