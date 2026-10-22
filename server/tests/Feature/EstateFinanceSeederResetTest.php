<?php

declare(strict_types=1);

use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The estate reset clears every row that points at what it clears
|--------------------------------------------------------------------------
|
| `EstateFinanceSeeder::resetLedger` TRUNCATEs with the foreign keys off, and
| TRUNCATE resets AUTO_INCREMENT. A child table left out of the list is not
| refused: its rows keep their parent's old id, and the rebuilt estate gives
| that id to a different lot. The full suite found one (M13): a hardship flag
| that outlived its lot sat on the next lot to take the number, and then
| refused that lot's deletion in `UnitSearchAtEstateScaleTest`.
|
| So the list is checked against the estate's REAL foreign keys, read from a
| database built by the tenant migrations, and a table added tomorrow is
| caught the day it is added.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
});

/** @return list<array{child: string, parent: string}> */
function resetForeignKeys(): array
{
    return DB::connection('mysql_owner')->table('information_schema.KEY_COLUMN_USAGE')
        ->where('TABLE_SCHEMA', FacilitiesFixture::database())
        ->whereNotNull('REFERENCED_TABLE_NAME')
        ->whereColumn('TABLE_NAME', '!=', 'REFERENCED_TABLE_NAME')
        ->get(['TABLE_NAME as child', 'REFERENCED_TABLE_NAME as parent'])
        ->map(fn (object $key): array => ['child' => (string) $key->child, 'parent' => (string) $key->parent])
        ->unique(fn (array $key): string => $key['child'].'>'.$key['parent'])
        ->values()
        ->all();
}

it('clears every table keyed on a table it clears', function (): void {
    $cleared = EstateFinanceSeeder::RESET_TABLES;

    $left = collect(resetForeignKeys())
        ->filter(fn (array $key): bool => in_array($key['parent'], $cleared, true) && ! in_array($key['child'], $cleared, true))
        ->map(fn (array $key): string => $key['child'].' → '.$key['parent'])
        ->values()
        ->all();

    expect($left)->toBe([]);
});

it('clears children before their parents, and names only tables the estate has', function (): void {
    $cleared = EstateFinanceSeeder::RESET_TABLES;
    $position = array_flip($cleared);

    $tables = DB::connection('mysql_owner')->table('information_schema.TABLES')
        ->where('TABLE_SCHEMA', FacilitiesFixture::database())
        ->pluck('TABLE_NAME')
        ->map(fn (mixed $name): string => (string) $name)
        ->all();

    $backwards = collect(resetForeignKeys())
        ->filter(fn (array $key): bool => isset($position[$key['child']], $position[$key['parent']]) && $position[$key['child']] > $position[$key['parent']])
        ->map(fn (array $key): string => $key['child'].' after '.$key['parent'])
        ->values()
        ->all();

    expect(array_values(array_diff($cleared, $tables)))->toBe([])
        ->and($cleared)->toBe(array_values(array_unique($cleared)))
        ->and($backwards)->toBe([]);
});
