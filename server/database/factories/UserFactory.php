<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Console;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Default state.
     *
     * `status` defaults to 'invited' to match the model, because accounts are
     * issued and only become usable when the invitation is accepted. A test
     * that needs a working account must say so with ->active(), which keeps
     * that step visible rather than assumed.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),

            /*
             * AN ADDRESS NO OTHER TEST CAN HAVE. `fake()->unique()` is unique only
             * per app instance, and every test boots a fresh one, so a random
             * address could repeat a user an earlier file committed to the shared
             * central database, and the insert died on users_email_unique under
             * some shuffled orders only. A random suffix makes a repeat
             * practically impossible, and the address still reads like a person's.
             */
            'email' => Str::lower(Str::before(fake()->safeEmail(), '@')).'.'.Str::lower(Str::random(10)).'@example.com',
            'email_verified_at' => now(),

            /*
             * THE ALERT ADDRESS IS PROVEN BY DEFAULT (M10 §1.4), and that is not a
             * loosening of the gate. Hundreds of tests make a user in order to test
             * something that is not the gate — a ledger, a ballot, a dispatch queue —
             * and every one of them would otherwise be testing the verification page
             * instead. A test about the gate says so with ->unverifiedContact(),
             * which keeps that step visible rather than assumed, the same way
             * ->active() does for status.
             *
             * A closure so it follows an `email` a test passes in, rather than
             * proving a different address from the one the account signs in with.
             */
            'contact_email' => fn (array $attributes): string => mb_strtolower((string) $attributes['email']),
            'contact_email_verified_at' => now(),
            // Hashed by the model's `password` => 'hashed' cast.
            'password' => 'password',
            'console' => Console::Estate->value,
            'status' => 'invited',
            'remember_token' => Str::random(10),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function geminiStaff(): static
    {
        return $this->state(fn () => [
            'console' => Console::Gemini->value,
            'status' => 'active',
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    /**
     * An account that has not proven an alert address — what every real account
     * that existed before M10 §1.4 looks like, and what meets the contact gate.
     */
    public function unverifiedContact(): static
    {
        return $this->state(fn () => [
            'contact_email' => null,
            'contact_email_verified_at' => null,
        ]);
    }
}
