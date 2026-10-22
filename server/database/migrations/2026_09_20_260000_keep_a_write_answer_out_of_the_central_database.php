<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE ANSWER TO AN ESTATE'S WRITE STOPS LIVING IN THE PLATFORM DATABASE.
 *
 * `api_idempotency_keys` is central, and it has to be: the key a handset chose is
 * unique per (principal, key) across the whole platform, so one key can never be
 * replayed into a second estate, and the "first attempt is still running" lock has
 * to be one lock, not one per database.
 *
 * But the row also carried `response_body` — the FULL JSON of every write this API
 * has ever answered. A resident's security report is its title and its reference; a
 * `sync.batch` reply is the body of every operation in the queue. All of it sat in
 * gs_platform, the one database this product's core claim says an estate's data is
 * never in, with no prune and no time limit.
 *
 * So the record is split. The KEY stays here — identity, route, request hash, status
 * code — and none of that is estate content. The BODY moves to
 * `api_idempotency_responses` in the estate's own database (the tenant migration of
 * the same date), and this row only says where it went.
 *
 *   tenant_id    the estate whose database holds the body, or null when no estate
 *                was open. Checked on every replay: a record made under one estate
 *                is never answered under another.
 *   body_store   `tenant` (the estate's own database) or `central` (this row's
 *                `response_body`, ENCRYPTED with the application key — the path for
 *                a write with no estate open, which no endpoint takes today).
 *
 * `response_body` is kept, not dropped: nothing here is destructive, records written
 * before this migration are still replayable from it, and it is the column the
 * central fallback uses. `App\Api\IdempotencyStore` decides which of the two to read
 * from `body_store`, and treats a row with neither as the record it is: one whose
 * answer is gone, which is never re-run.
 *
 * AND THE INDEX THE PRUNE NEEDS. The table had one index — the unique key on
 * (tokenable_type, tokenable_id, idempotency_key) — which is no use at all for
 * "everything older than thirty days". `api:prune-idempotency` reads `created_at`,
 * and without this that read is a full scan of every write the platform has ever
 * answered, with the delete behind it taking locks across the whole table while
 * handsets are trying to insert into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_idempotency_keys')) {
            return;
        }

        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            if (! Schema::hasColumn('api_idempotency_keys', 'tenant_id')) {
                $table->string('tenant_id', 64)->nullable()->after('tokenable_id');
            }

            if (! Schema::hasColumn('api_idempotency_keys', 'body_store')) {
                $table->string('body_store', 8)->nullable()->after('status_code');
            }

            if (! Schema::hasIndex('api_idempotency_keys', 'api_idempotency_created_at_index')) {
                $table->index('created_at', 'api_idempotency_created_at_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('api_idempotency_keys')) {
            return;
        }

        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            if (Schema::hasIndex('api_idempotency_keys', 'api_idempotency_created_at_index')) {
                $table->dropIndex('api_idempotency_created_at_index');
            }

            foreach (['tenant_id', 'body_store'] as $column) {
                if (Schema::hasColumn('api_idempotency_keys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
