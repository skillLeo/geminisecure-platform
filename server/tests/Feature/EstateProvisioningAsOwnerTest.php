<?php

declare(strict_types=1);

use App\Jobs\Tenancy\ApplyAppendOnlyGrants;
use App\Models\Tenant;
use App\Services\Tenancy\EstateProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| A NEW estate provisions all the way through (M7)
|--------------------------------------------------------------------------
|
| Every other suite builds its estate by hand, as the schema owner, so none of them
| ever ran the real pipeline — and the real pipeline had stopped working: the
| package's own migration step ran as the estate's user, which holds no UPDATE
| until the grants go on AFTER the migrations, and the first backfill migration
| failed with "UPDATE command denied". Found by provisioning a probe estate on the
| development server while preparing the production deployment.
|
| This provisions a real estate through `EstateProvisioner` — its own database, its
| own MySQL user — and holds the result to the promise: every estate migration ran,
| the estate's user may change ordinary rows, and it still may not change a journal.
|
*/

it('provisions a new estate end to end, and leaves its own user as restricted as ever', function () {
    $key = 'provtest'.Str::lower(Str::random(6));
    $prefix = 'P'.Str::upper(Str::random(4));
    $prefix = preg_replace('/[^A-Z0-9]/', 'X', $prefix);

    try {
        $estate = app(EstateProvisioner::class)->provision($key, 'Provisioning Probe', 'onboarding', $prefix);

        $database = $estate->database()->getName();
        $user = (string) $estate->database()->getUsername();

        // Every estate migration is recorded as run.
        $files = collect(glob(database_path('migrations/tenant/*.php')))->map(static fn ($f) => basename((string) $f, '.php'))->sort()->values();
        $ran = collect(DB::connection('mysql_owner')->select("SELECT migration FROM `{$database}`.`migrations`"))->pluck('migration')->sort()->values();

        expect($user)->not->toBe('')
            ->and($ran->all())->toBe($files->all());

        // The estate's own user: ordinary tables writable, the ledger not.
        $grants = collect(DB::connection('mysql_owner')->select("SHOW GRANTS FOR `{$user}`@`%`"))
            ->map(static fn ($row) => (string) array_values((array) $row)[0]);

        $on = static fn (string $table) => $grants->first(static fn (string $g) => str_contains($g, "`{$database}`.`{$table}`"));

        expect($on('accounts'))->toContain('UPDATE')->toContain('DELETE');

        foreach (ApplyAppendOnlyGrants::APPEND_ONLY_TABLES as $table) {
            expect((string) $on($table))->not->toContain('UPDATE');
        }

        // And nothing database-wide gives UPDATE back.
        expect($grants->first(static fn (string $g) => str_contains($g, "ON `{$database}`.*") && str_contains($g, 'UPDATE')))->toBeNull();
    } finally {
        Tenant::query()->find($key)?->delete();
    }

    expect(DB::connection('mysql_owner')->select('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [config('tenancy.database.prefix').$key]))->toBe([]);
});
