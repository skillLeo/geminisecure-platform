<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A HOUSEHOLD'S PETS (M50 §5.3d) — the Profile card is "Vehicles & pets", and the board draws a pet beside the cars;
 * pets did not exist anywhere. A name and a kind (dog, cat, bird, fish, rabbit, other), added, changed and removed
 * by the household's Members in the Resident App, as its vehicles are.
 *
 *   household_pets   one pet of one household — never read at the gate, never shown to a guard
 *
 * Marked like every table a seeder may write (`seeded_at`, `seeded_by`; SeededTables), and retired with the
 * household's other sample rows on launch day.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('household_pets')) {
            return;
        }

        Schema::create('household_pets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('kind', 16);
            $table->unsignedBigInteger('added_by_account_id')->nullable();
            $table->timestamps();
            $table->timestamp('seeded_at')->nullable();
            $table->string('seeded_by', 64)->nullable();

            $table->index('household_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_pets');
    }
};
