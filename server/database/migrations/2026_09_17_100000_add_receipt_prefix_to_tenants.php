<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The receipt prefix, on the estate record (13 A1).
 *
 * WHY THIS REPLACES THE SUBDOMAIN. The first reading of 12 §1 took the prefix
 * from the estate's subdomain, uppercased, and printed `PHOENIXPARK-R-04471`
 * where the ruling's own example read `PPV-R-00001`. The rule was ambiguous and
 * has been restated: the prefix is a fact about the estate, at most six
 * characters, set at provisioning and immutable once the first receipt is
 * issued. It lives here, beside the estate's name, because it is chosen with the
 * name and not derived from a hostname.
 *
 * UNIQUE ACROSS THE PLATFORM. A receipt number is quoted back to Gemini staff
 * with no estate beside it, and two estates printing `PG-R-00012` would make
 * that sentence ambiguous. MySQL allows many NULLs under a unique index, so an
 * estate whose prefix is unset does not collide with another.
 *
 * THE BACKFILL. Phoenix Park and Ocean View take the ruled `PPV` and `OVG`.
 * Every other estate takes the initials of its name — the same suggestion
 * `ReceiptPrefix::suggest()` makes at provisioning, written out here rather than
 * called, so this migration reads the same in a year as it does today. A
 * collision takes a digit.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const RULED = [
        'phoenixpark' => 'PPV',
        'oceanview' => 'OVG',
    ];

    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('receipt_prefix', 6)->nullable()->unique()->after('name');
        });

        $taken = [];

        $estates = DB::table('tenants')->select(['id', 'name'])->orderBy('id')->get();

        // The ruled two first, so neither can be taken by a suggestion.
        foreach ($estates as $estate) {
            if (isset(self::RULED[$estate->id])) {
                $taken[] = self::RULED[$estate->id];
            }
        }

        foreach ($estates as $estate) {
            $prefix = self::RULED[$estate->id] ?? $this->suggest((string) ($estate->name ?? $estate->id), $taken);

            if (! isset(self::RULED[$estate->id])) {
                $taken[] = $prefix;
            }

            DB::table('tenants')->where('id', $estate->id)->update(['receipt_prefix' => $prefix]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['receipt_prefix']);
            $table->dropColumn('receipt_prefix');
        });
    }

    /** @param  list<string>  $taken */
    private function suggest(string $name, array $taken): string
    {
        preg_match_all('/[A-Za-z]+/', $name, $words);

        $base = strtoupper(implode('', array_map(static fn (string $word): string => $word[0], $words[0])));
        $base = substr($base === '' ? 'E' : $base, 0, 6);

        $candidate = $base;
        $n = 2;

        while (in_array($candidate, $taken, true)) {
            $suffix = (string) $n++;
            $candidate = substr($base, 0, 6 - strlen($suffix)).$suffix;
        }

        return $candidate;
    }
};
