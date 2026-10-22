<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `dashboard_snapshots` — the dashboard's estate-wide tiles, kept, with the moment they
 * were computed (M11 A2).
 *
 * "Dashboard tiles that count or sum across the whole estate must not compute on every page
 * load. Cache them with a sensible TTL, or maintain them incrementally, and show when the
 * figure was computed." The outstanding-dues tile and the arrears-by-phase bars are the whole
 * receivable summed and grouped — 23,792 journal lines at Phoenix Park — and every committee
 * member opening the console paid for that read. See `App\Services\Estate\DashboardTiles`.
 *
 * IN THE ESTATE'S OWN DATABASE, NOT THE CACHE. Two reasons, and the second decides it. The
 * estate's figures belong beside the ledger they are read from, so no estate's snapshot can be
 * served to another by construction rather than by a cache key. And stancl's cache bootstrapper
 * tags every cache call made inside an estate, which the `database` store the development
 * machines run cannot do — a tile cached through the cache would have been an exception page
 * locally and a working screen in production, which is the wrong way round to find out.
 *
 * ONE ROW PER PART, keyed `structure` (units, occupancy, open maintenance) and `position` (what
 * the estate is owed, by phase). Two, not one, so that a viewer who may not read the estate's
 * position (M10 §5) is served the structure without the position being read or computed for
 * them — the same guarantee the dashboard has always made.
 *
 * `version` is the stamp of the data the figures were computed from — counts and high-water
 * marks of the tables they read — so a figure is recomputed the moment a charge, a payment or a
 * new lot changes it, and `computed_at` bounds how old it can be otherwise.
 *
 * MUTABLE (App\Support\GrantedTables::ESTATE_MUTABLE): a snapshot is overwritten when it is
 * recomputed. It holds nothing that is not derived from the ledger and the register, and can be
 * deleted at any time — the next dashboard load computes it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('version', 191);
            $table->json('payload');
            $table->timestamp('computed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_snapshots');
    }
};
