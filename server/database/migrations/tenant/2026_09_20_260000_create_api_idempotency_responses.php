<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE THE ANSWER TO THIS ESTATE'S WRITES IS KEPT.
 *
 * A retried write is answered, not repeated: the first attempt's response is stored
 * and every retry gets it back byte for byte (13 D2). That response is this estate's
 * own content — a report's title and reference, a ticket, a whole uploaded offline
 * queue — and until now it was stored in gs_platform, the central database shared by
 * every client on the platform.
 *
 * It lives here instead. One row per central `api_idempotency_keys.id`, in the
 * database that already holds the record the answer describes, reachable only
 * through this estate's own MySQL user. An estate's answer cannot be read from
 * another estate's connection because the row is not in another estate's database —
 * not because some code remembered to add a `where`.
 *
 * `key_id` is the central row's id and is deliberately NOT a foreign key: the two
 * tables are in different databases, and a constraint across them would need the
 * cross-database grant this platform exists to withhold. `App\Api\IdempotencyStore`
 * writes this row BEFORE it marks the central row answered, so the only order a
 * crash can leave behind is an orphan here — which the prune sweeps — and never a
 * central record whose answer is missing.
 *
 * Pruned by `api:prune-idempotency`, hence the index on `created_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_idempotency_responses')) {
            return;
        }

        Schema::create('api_idempotency_responses', function (Blueprint $table): void {
            $table->id();

            // The central api_idempotency_keys row this is the answer to.
            $table->unsignedBigInteger('key_id')->unique('api_idempotency_responses_key_unique');

            $table->longText('response_body');
            $table->timestamp('created_at')->nullable()->index('api_idempotency_responses_created_at_index');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_responses');
    }
};
