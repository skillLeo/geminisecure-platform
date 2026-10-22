<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where Gemini's journal references come from (B085).
 *
 * A reference runs per prefix and month — INV-2026-09-0001, -0002, CN-2026-09-0001
 * — and `PlatformLedger` used to take the next one by reading the month's last
 * reference from `platform_journals` and retrying on a collision. The retry could
 * never work: both callers post inside a transaction of their own, and under
 * REPEATABLE READ that transaction goes on reading the snapshot it took, so every
 * attempt chose the same reference again. Two operators posting in the same month
 * at the same moment left the second with a 500.
 *
 * The books themselves cannot be locked to take a number — `platform_journals`
 * withholds UPDATE from the application, and a locking read needs it — so the
 * number lives here, one row per stem, bumped by an upsert. The upsert locks the
 * row and always works from its latest committed value, whatever the caller's
 * snapshot says: the second poster waits for the first to commit and takes the
 * next number, and a posting that rolls back gives its number back.
 *
 * MUTABLE, NOT APPEND-ONLY (`GrantedTables::CENTRAL_MUTABLE`). It is updated on
 * every posting, and it holds no money and no history — only the last number
 * handed out per stem. The books it numbers keep both of their locks.
 *
 * SEEDED FROM THE BOOKS, so the first posting after this migration continues a
 * month's numbers rather than starting them again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_journal_sequences', function (Blueprint $table) {
            // "INV-2026-09-": the prefix and the month, exactly as a reference begins.
            $table->string('stem', 40)->primary();
            $table->unsignedInteger('last_number');
            $table->timestamps();
        });

        /** @var array<string, int> $last */
        $last = [];

        foreach (DB::table('platform_journals')->pluck('reference') as $reference) {
            if (preg_match('/^(.+-\d{4}-\d{2}-)(\d+)$/', (string) $reference, $parts) === 1) {
                $last[$parts[1]] = max($last[$parts[1]] ?? 0, (int) $parts[2]);
            }
        }

        foreach ($last as $stem => $number) {
            DB::table('platform_journal_sequences')->insert([
                'stem' => $stem,
                'last_number' => $number,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_journal_sequences');
    }
};
