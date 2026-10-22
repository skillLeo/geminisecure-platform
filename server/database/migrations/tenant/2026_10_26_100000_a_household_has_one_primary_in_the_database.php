<?php

declare(strict_types=1);

use App\Services\Estate\OnePrimaryIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ONE HOUSEHOLD, ONE PRIMARY — IN THE DATABASE (M47 §14a). The column everywhere; the unique index wherever no
 * household holds two current primaries. An estate that still does keeps every row exactly as it is: it gets the index
 * from the repair's real run (`residents:repair-primaries --household= --keep=`), or from the next `estates:migrate`
 * after it. Choosing who stays primary is a person's decision, never a migration's — and this never fails the deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table): void {
            // VIRTUAL, not stored: MySQL refuses a STORED generated column whose base column (household_id) carries a
            // cascading foreign key (error 1215). A virtual one with a unique index is allowed and refuses the same pair.
            $table->unsignedBigInteger(OnePrimaryIndex::COLUMN)->nullable()->virtualAs(OnePrimaryIndex::EXPRESSION)->after('removed_by_resident_id');
        });

        OnePrimaryIndex::ensure(DB::getDefaultConnection());
    }

    public function down(): void
    {
        OnePrimaryIndex::drop(DB::getDefaultConnection());

        Schema::table('residents', function (Blueprint $table): void {
            $table->dropColumn(OnePrimaryIndex::COLUMN);
        });
    }
};
