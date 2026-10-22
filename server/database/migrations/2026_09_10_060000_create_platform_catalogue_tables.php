<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commercial catalogue behind Platform settings - CENTRAL, in gs_platform.
 *
 * Three things the boards for screens 42, 43 and 44 read and the schema had no
 * home for. Each is added because the DOMAIN needs it, not because a screen
 * wanted a number to print:
 *
 *   platform_rates            what the platform charges that is NOT priced per
 *                             unit of a tier. `plans` prices a subscription per
 *                             household; a deployed guard is billed per guard
 *                             and sits on top of any tier, so it cannot be a
 *                             column on `plans` without saying a guard costs a
 *                             different amount depending on the estate's tier.
 *
 *   package_features          what a commercial package can include, as a
 *   plan_features             catalogue, and which plan includes which. The
 *                             build spec requires this twice over: screen 43 is
 *                             "a preview of the resulting navigation for an
 *                             estate on that package", and screen 6 is "a
 *                             feature-difference list showing which modules
 *                             appear or disappear". Neither is answerable from
 *                             a price alone.
 *
 *   subscription_line_items   per-client commercial overrides. Screen 44's
 *                             spec: "type, description, amount, recurrence and
 *                             effective dates", and "an override is dated,
 *                             never retroactive, and appears on the next
 *                             invoice with its own line". Dates are therefore
 *                             columns, not metadata.
 *
 * These are NOT the same thing as `modules`. A module is a unit of ACCESS - the
 * role matrix grants a level on it and the sidebar is generated from it. A
 * package feature is a unit of COMMERCE, and the board's rows prove they do not
 * line up: "Panic button, notices, maintenance, dues payment" is one saleable
 * line covering four modules, and "Custom branding" is saleable and is no
 * module at all. Folding one into the other would mean either selling access
 * levels or granting access from a price list.
 *
 * Every amount is a bigint of minor units with an explicit currency. No float.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Platform-wide rates - the "Pricing & rates" panel on board 42.
         *
         * Keyed rather than a single settings row per rate name, so adding a
         * rate is inserting a row instead of altering a table. `basis` says
         * what the amount is charged against, because "per guard, per month"
         * and "per unit, per month" are not interchangeable and a bare amount
         * beside a label is how the two get confused.
         */
        Schema::create('platform_rates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 48)->unique();
            $table->string('label', 120);

            // The line under the name on the board, e.g. "Applies on top of
            // any tier". It states the rate's REACH, which is the thing that
            // distinguishes an add-on from a tier price.
            $table->string('applies_to', 160);

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('JMD');

            // guard | unit | client - what one unit of the amount buys.
            $table->string('basis', 24);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        /*
         * The feature catalogue - the rows of board 43's table.
         *
         * `is_core` is the board's "locked" cell: a feature every tier includes
         * and no package may drop. It is a property of the FEATURE rather than
         * a value repeated in every pivot row, because "the panic button is
         * never optional" is one fact about the product, and storing it once
         * per plan is three places for it to stop being true.
         */
        Schema::create('package_features', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('label', 160);

            // The smaller second line the board draws under some names, e.g.
            // "GL, AP/AR, bank import". Null where the board draws none.
            $table->string('sub_label', 160)->nullable();

            /*
             * toggle      the feature is in the package or it is not
             * tier_value  every tier has it, at a level that differs by tier
             *
             * Custom branding is the second kind: no tier is without branding,
             * and the difference between "Logo only" and "Full theme" is not
             * expressible as a tick. Rendering it as one would sell Essential
             * a feature it does not have or deny it one it does.
             */
            $table->string('kind', 16)->default('toggle');

            $table->boolean('is_core')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('package_feature_id')->constrained('package_features')->cascadeOnDelete();

            $table->boolean('included')->default(false);

            // Only ever set on a `tier_value` feature: "Logo only", "Full
            // theme". A toggle feature's answer is `included` and nothing else.
            $table->string('tier_value', 80)->nullable();

            $table->timestamps();

            // One answer per feature per plan. Two rows would mean a plan both
            // includes and excludes the same feature, and whichever the query
            // happened to read first would win.
            $table->unique(['plan_id', 'package_feature_id']);
        });

        /*
         * Per-client commercial overrides - board 44.
         *
         * Deliberately NOT invoice_lines. An invoice line is what a client was
         * billed for a period that has closed; this is what a client is billed
         * going forward, and it is the thing an invoice line is generated FROM.
         * Editing history to change a future charge is the mistake this
         * separation exists to make impossible.
         */
        Schema::create('subscription_line_items', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');

            /*
             * The catalogue feature this override concerns, where it concerns
             * one. Null for a charge that is not a feature at all - a one-off
             * migration fee, a negotiated discount.
             */
            $table->foreignId('package_feature_id')->nullable()
                ->constrained('package_features')->nullOnDelete();

            // addition | removal | discount | one_off
            $table->string('type', 24);

            $table->string('description', 200);

            // Why, in the operator's own words. The board prints it on the row
            // - "removed at the client's request" - and an override with no
            // stated reason is one nobody can review a year later.
            $table->string('reason', 300)->nullable();

            /*
             * Signed. A discount is negative and a charge is positive, so the
             * monthly total is a sum rather than a sum with a sign convention
             * carried in the caller's head.
             */
            $table->bigInteger('amount_minor')->default(0);
            $table->char('currency', 3)->default('JMD');

            // monthly | one_off
            $table->string('recurrence', 16)->default('monthly');

            /*
             * Dated, and never retroactive: the spec's rule for this screen.
             * `effective_from` is a date rather than a timestamp because
             * billing periods are days, and `effective_to` null means "until
             * ended" rather than "forever" - ending an override sets this
             * instead of deleting the row, so the history of what a client was
             * charged survives the change.
             */
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // Who did it. The board prints it on the row, and a commercial
            // override with no author is not auditable.
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_line_items');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('package_features');
        Schema::dropIfExists('platform_rates');
    }
};
