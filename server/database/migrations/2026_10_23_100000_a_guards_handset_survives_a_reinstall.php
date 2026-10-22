<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A GUARD'S HANDSET SURVIVES A REINSTALL (M46 §1).
 *
 * A phone was bound by its INSTALL alone — a random id and a key in the app's own storage — so anything that wiped the
 * app's data (an uninstall, a reinstall over a differently signed build, "Clear storage", the development build beside
 * the release one) made the same handset a stranger, and the code it had used was dead. A guard was locked out at night
 * with nobody to ask for another.
 *
 *   device_bindings.hardware_hash        the handset's own identity, as the app reads it (Android's per-app device id,
 *                                        which a reinstall of the same app keeps), hashed: never the id itself. Null on
 *                                        a binding made before this until the phone next signs in with its key
 *                                        (`DeviceResume`), and on a phone that does not send one.
 *   device_rebind_requests.hardware_hash the same, for a phone waiting on a supervisor: its binding is made when it
 *                                        collects its token.
 *   device_enrolment_codes.binding_id    the binding the code made — or, after a recovery, the binding that replaced
 *                                        it — so a used code can be checked against the handset it set up, and no other.
 *
 * ADDITIVE: three nullable columns and an index; nothing existing is rewritten. `DeviceEnrolment::recover` reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('device_bindings', 'hardware_hash')) {
            Schema::table('device_bindings', function (Blueprint $table): void {
                $table->string('hardware_hash', 64)->nullable()->after('public_key');
                $table->index('hardware_hash');
            });
        }

        if (! Schema::hasColumn('device_rebind_requests', 'hardware_hash')) {
            Schema::table('device_rebind_requests', function (Blueprint $table): void {
                $table->string('hardware_hash', 64)->nullable()->after('public_key');
            });
        }

        if (! Schema::hasColumn('device_enrolment_codes', 'binding_id')) {
            Schema::table('device_enrolment_codes', function (Blueprint $table): void {
                $table->unsignedBigInteger('binding_id')->nullable()->after('guard_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('device_rebind_requests', 'hardware_hash')) {
            Schema::table('device_rebind_requests', function (Blueprint $table): void {
                $table->dropColumn('hardware_hash');
            });
        }

        if (Schema::hasColumn('device_enrolment_codes', 'binding_id')) {
            Schema::table('device_enrolment_codes', function (Blueprint $table): void {
                $table->dropColumn('binding_id');
            });
        }

        if (Schema::hasColumn('device_bindings', 'hardware_hash')) {
            Schema::table('device_bindings', function (Blueprint $table): void {
                $table->dropIndex(['hardware_hash']);
                $table->dropColumn('hardware_hash');
            });
        }
    }
};
