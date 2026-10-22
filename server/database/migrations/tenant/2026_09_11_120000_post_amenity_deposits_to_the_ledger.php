<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amenity deposits reach the ledger — the client's ruling on Q-009.
 *
 * WHAT WAS WRONG. A deposit was recorded as a STATE on the booking and raised no
 * journal entry at all. The reasoning was a permission one: moving cash belongs
 * to whoever holds `payments` and `accounting_posting`, and a facilities route
 * holds neither (D-010). The client overruled it in one sentence — cash moved,
 * so the ledger must say so — and they are right. 2200 Resident Deposits Held
 * has been in the chart since board 25 was transcribed, and it read zero while
 * bookings on board 19 said "held": the estate's books disagreeing with its own
 * diary about money it was physically holding.
 *
 * THE THREE ENTRIES, AND ONLY THE THIRD IS EVER INCOME.
 *
 *     taken      Dr 1000 Bank            Cr 2200 Deposits Held
 *     refunded   Dr 2200 Deposits Held   Cr 1000 Bank
 *     forfeited  Dr 2200 Deposits Held   Cr 4100 Amenity Booking Fees
 *
 * A deposit is a LIABILITY while it is held — the estate has somebody else's
 * money and owes it back — and it becomes the estate's own only if it is kept.
 * The refund moves no income because a deposit returned was never earned.
 *
 * IT NEVER TOUCHES RECEIVABLES AND NEVER APPEARS IN DUES. The client said so
 * explicitly and it is the half most easily got wrong: a deposit looks like a
 * charge and is its mirror image. 1200 Dues Receivable is absent from all three
 * entries, and `EstateFacilitiesAmenitiesTest` asserts the unit's receivable
 * does not move by a cent across taking, keeping and returning one.
 *
 * THE PERMISSION QUESTION IS STILL OPEN AND COSTS NOTHING TODAY. There is no
 * HTTP route to any of the three actions: board 19 draws the deposit STATE and
 * no control that changes it, and the booking detail screen that would carry one
 * is not on any approved board. The seeder and the tests are the only callers.
 *
 * When a route does land it must carry `estate.accounting_posting.create`
 * alongside the facilities gate, the way `booking.fee` carries the dues one, or
 * the Property Manager D-010 locks out of the books would be moving cash through
 * a facilities screen. The test file asserts no such route exists yet, so the
 * requirement is enforced rather than remembered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenity_bookings', function (Blueprint $table): void {
            /*
             * The journal this deposit's LAST movement produced.
             *
             * One column rather than three, because a deposit has one state at a
             * time and the reference is how somebody gets from board 19's
             * "$5,000 held" to the entry behind it. The full history is in the
             * ledger, which is append-only and keeps every one of them; this is
             * the pointer to the current position.
             */
            $table->string('deposit_journal_ref', 32)->nullable()->after('deposit_refunded_on');

            /*
             * Required by the service whenever a deposit is kept, and nullable
             * here because the other three states have nothing to explain.
             * Keeping a resident's money is the one deposit act somebody will be
             * asked to justify, and "forfeited" with nothing after it is not an
             * answer a committee can give them.
             */
            $table->string('deposit_forfeit_reason', 255)->nullable()->after('deposit_journal_ref');
        });
    }

    public function down(): void
    {
        Schema::table('amenity_bookings', function (Blueprint $table): void {
            $table->dropColumn(['deposit_journal_ref', 'deposit_forfeit_reason']);
        });
    }
};
