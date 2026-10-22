<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Support\Seeded\Seeding;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Seeders\WritesAStrangersContacts;
use Tests\Support\EstateScaleFixture;
use Tests\Support\FacilitiesFixture;
use Tests\Support\SeederContacts;

/*
|--------------------------------------------------------------------------
| No seeder writes an address or a number anybody could receive (M35 §5.2, layer 3)
|--------------------------------------------------------------------------
|
| The demonstration seed gave invented residents addresses on email.com, a live public mail service, and the platform
| emailed one of them twice about a panic. This file is the build failing the moment a seeder does that again: an
| address on a domain that is not reserved for examples, or a number outside 555-0100 to 555-0199. Read twice — in
| every seeder's source, and in the rows the seeders write when they run.
|
*/

it('finds no address a mailbox could receive and no number a phone could answer in any seeder\'s source', function () {
    $sources = SeederContacts::sources();

    // The walk found the seeders, the factories and the seeding commands, not an empty directory.
    expect(count($sources))->toBeGreaterThan(25)
        ->and(implode("\n", $sources))->toContain('ResidentsSeeder.php')->toContain('DemoSeed.php')->toContain('CompanyDemoSeed.php')->toContain('UserFactory.php');

    $found = [];
    foreach ($sources as $file) {
        array_push($found, ...SeederContacts::inSource((string) file_get_contents($file), $file));
    }

    expect($found)->toBe([], "A seeder writes an address or a number somebody could receive:\n".implode("\n", $found));
});

it('finds none in the rows the seeders write: an estate at production size, and the company seed\'s officers', function () {
    EstateScaleFixture::boot();
    DB::connection('mysql')->beginTransaction();

    try {
        EstateScaleFixture::enter();
        $this->artisan('company:demo-seed', ['--estate' => EstateScaleFixture::ESTATE])->assertSuccessful()->run();
        EstateScaleFixture::boot();

        $found = SeederContacts::inRows(EstateScaleFixture::ESTATE);

        expect($found)->toBe([], "Rows a seeder wrote that somebody could receive:\n".implode("\n", $found))
            // And the rows were there to be read: every seeded household's people, and the company seed's officers.
            ->and(DB::connection('tenant')->table('residents')->whereNotNull('seeded_at')->whereNotNull('phone')->count())->toBeGreaterThan(400)
            ->and(DB::connection('mysql')->table('guards')->where('tenant_id', EstateScaleFixture::ESTATE)->whereNotNull('seeded_at')->whereNotNull('phone')->count())->toBeGreaterThan(0);
    } finally {
        DB::connection('mysql')->rollBack();
    }
});

it('fails a seeder that writes anything@gmail.com, in its source and in what it wrote', function () {
    $file = base_path('tests/Fixtures/Seeders/WritesAStrangersContacts.php');

    expect(implode("\n", SeederContacts::inSource((string) file_get_contents($file), $file)))
        ->toContain('an address on gmail.com, which can receive mail');

    FacilitiesFixture::boot();
    DB::connection('tenant')->beginTransaction();

    try {
        sfcHousehold();
        Seeding::run('db:seed', static fn () => (new WritesAStrangersContacts)->run());

        expect(implode("\n", SeederContacts::inRows(FacilitiesFixture::ESTATE)))->toContain('email on gmail.com');
    } finally {
        DB::connection('tenant')->rollBack();
    }
});

/** A household for the wrong seeder to write into, inside the test's transaction. */
function sfcHousehold(): void
{
    Household::query()->create(['unit_id' => FacilitiesFixture::scratchUnit('Lot 9481')->id, 'name' => 'Seeder check household', 'access_restricted' => false]);
}

it('fails a seeder that writes a number a phone could answer, and passes the fictional block however it is written', function () {
    $file = base_path('tests/Fixtures/Seeders/WritesAStrangersContacts.php');

    expect(implode("\n", SeederContacts::inSource((string) file_get_contents($file), $file)))
        ->toContain('the number 876 508 8082 can be dialled');

    $judged = static fn (string $code): array => SeederContacts::inSource('<?php '.$code, 'Example.php');

    // A 555 number outside the fictional block, and a number finished in code, are both refused…
    expect($judged("\$a = ['phone' => '876-555-0234'];"))->toHaveCount(1)
        ->and($judged("\$a = ['phone' => '876-555-'.str_pad((string) \$n, 4, '0', STR_PAD_LEFT)];"))->toHaveCount(1)
        ->and($judged("\$a = ['phone' => '+1 876 508 8082'];"))->toHaveCount(1)
        // …and the fictional block passes, written any way a person writes a number.
        ->and($judged("\$a = ['876-555-0147', '(876) 555 0100', '+1 876 555 0199', '1-658-555-0123'];"))->toBe([])
        // A Faker number is refused; a Faker example address is not.
        ->and($judged('$a = fake()->phoneNumber(); $b = fake()->safeEmail();'))->toHaveCount(1);

    FacilitiesFixture::boot();
    DB::connection('tenant')->beginTransaction();

    try {
        sfcHousehold();
        Seeding::run('db:seed', static fn () => (new WritesAStrangersContacts)->run());

        expect(implode("\n", SeederContacts::inRows(FacilitiesFixture::ESTATE)))->toContain('phone can be dialled');
    } finally {
        DB::connection('tenant')->rollBack();
    }
});
