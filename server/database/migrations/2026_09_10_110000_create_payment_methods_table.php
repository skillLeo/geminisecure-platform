<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a client settles their platform invoices.
 *
 * D-023 makes this necessary rather than optional: manual payment recording is
 * the day-one path, and a payment cannot be recorded against a client without
 * knowing where it came from. "NCB Jamaica ····7712" is what an operator
 * matches a bank statement line against, and without it reconciliation is
 * somebody's memory.
 *
 * NO CARD DATA, AND NO ROOM FOR ANY. There is no PAN column, no expiry, no
 * CVV, and no token — `last_four` is four characters and `institution` is a
 * bank's name. When card capture arrives it arrives behind the PaymentGateway
 * interface with a gateway-side token, and the token lives with the gateway.
 * A schema that cannot hold a card number cannot leak one.
 *
 * A client with no row is a real state the billing board draws: "Not yet on
 * file · Needed before go-live". That is read from the absence of a row rather
 * than from a placeholder row, so an estate mid-onboarding is genuinely
 * distinguishable from one whose details were entered and then cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            /*
             * bank_transfer today, and the only value D-023 permits. Card
             * capture is behind an unimplemented interface, so a `card` row
             * would be a promise the platform cannot keep.
             */
            $table->string('kind', 32)->default('bank_transfer');

            // "NCB Jamaica", "Scotiabank".
            $table->string('institution', 120);

            // Four characters. Not an integer: leading zeros are significant
            // on an account number and an integer eats them.
            $table->string('last_four', 4);

            /*
             * One default per client, enforced in the application rather than
             * by a partial index, because MySQL has none and a unique index on
             * (tenant_id, is_default) would permit exactly one NON-default row
             * as well.
             */
            $table->boolean('is_default')->default(true);

            // active | superseded. A method that was used on a past invoice is
            // never deleted, because that invoice's settlement refers to it.
            $table->string('status', 24)->default('active');

            $table->timestamps();

            $table->index(['tenant_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
