<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A whole-phase or whole-estate charge posts ONCE from the preview it was drawn
 * from (bug hunt B020).
 *
 * The preview is held in the session under a random token, and the post "spent"
 * it by forgetting it — after billing every unit. Two posts carrying the same
 * session (two tabs on one preview, or a request retried while the first was
 * still billing hundreds of units) each read the token before the other forgot
 * it, and the estate was billed twice. Sessions are not a lock.
 *
 * THE DATABASE IS. Every charge a bulk post raises carries the preview's token,
 * and no unit can carry the same token twice. A second post of one preview is
 * refused by the index at its first unit — waiting, if the first post has not
 * committed yet, and then refused — and nothing half-posts, because the whole
 * list is one transaction.
 *
 * NULL FOR EVERY OTHER CHARGE, and MySQL lets any number of rows share NULL in a
 * unique index, so a single charge, a charge run and an amenity fee are
 * untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->string('bulk_token', 40)->nullable()->after('journal_ref');

            $table->unique(['bulk_token', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropUnique(['bulk_token', 'unit_id']);
            $table->dropColumn('bulk_token');
        });
    }
};
