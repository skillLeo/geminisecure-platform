<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let `push_coalescing` be pruned by age (M4 §3.2, review finding).
 *
 * The table carries one row per (recipient, type, record) and is updated in place,
 * never removed: every notice, ticket, payment, meeting and election, once per
 * household, for the life of the estate. Its only index is the unique key on the
 * five key columns, which is no use for "everything older than a week" — that read
 * is a full scan, and the delete behind it would take row locks across the whole
 * table while the sender is trying to claim a window.
 *
 * So: one index on `last_pushed_at`. `push:prune-coalescing` (scheduled daily) uses
 * it, and nothing else changes — no column, no row, no existing index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('push_coalescing')) {
            return;
        }

        Schema::table('push_coalescing', function (Blueprint $table): void {
            if (! $this->hasIndex('push_coalescing_last_pushed_at_index')) {
                $table->index('last_pushed_at', 'push_coalescing_last_pushed_at_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('push_coalescing') || ! $this->hasIndex('push_coalescing_last_pushed_at_index')) {
            return;
        }

        Schema::table('push_coalescing', function (Blueprint $table): void {
            $table->dropIndex('push_coalescing_last_pushed_at_index');
        });
    }

    /** Idempotent either way: the index may already be there on a database built from a later dump. */
    private function hasIndex(string $name): bool
    {
        return Schema::hasIndex('push_coalescing', $name);
    }
};
