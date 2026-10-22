<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\ResidentApp\ResidentAccounts;
use App\Support\JamaicanNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * A HOUSEHOLD REMOVES ITS OWN VEHICLE OR EMERGENCY CONTACT (INV-11).
 *
 * The user's household listed the same spouse four times under Emergency contacts, and the phone could only say
 * "To remove a vehicle or a contact, contact the estate office" — while no estate console screen reads either
 * table, so nobody could. Nothing records a ruling that the estate owns these rows (DECISIONS.md holds none; the
 * handoff said "Not specified by 13 D3"), and no gate code reads `household_vehicles`, so removing a plate cannot
 * change who is admitted. So the household may remove them, after the app's confirmation, and every removal is
 * on the estate's audit log. (An access level narrows this to Members, in `AppMatrix::LEVELS`.)
 *
 * THE ROW MUST BE THIS HOUSEHOLD'S. Resolved by id AND the household of the signed-in account, exactly as every
 * other Resident App read is (`ResidentAccounts::home`); another household's id and an id nobody has are the
 * same 404, so the answer says nothing about which rows exist elsewhere.
 *
 * A NEW CLASS, so `MeController` gains no hunk (merge notes, STAGE2 §5.3).
 */
class HouseholdRemovalsController extends Controller
{
    public function __construct(
        private readonly ResidentAccounts $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function destroyContact(int $contact, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $table = DB::connection('tenant')->table('household_emergency_contacts');
        $row = (clone $table)->where('id', $contact)->where('household_id', $home['household']->id)->first();

        if ($row === null) {
            throw ApiError::notFound('not_found', 'No such emergency contact on your household.');
        }

        (clone $table)->where('id', $contact)->where('household_id', $home['household']->id)->delete();

        // The relationship and a masked number — never the name: the audit log is read by the office.
        $this->audit->record(
            action: 'household.contact_removed',
            entityType: 'Household',
            entityId: (string) $home['household']->id,
            before: [
                'contact_id' => (int) $row->id,
                'relationship' => $row->relationship,
                'number' => JamaicanNumber::mask(JamaicanNumber::e164((string) $row->phone) ?? (string) $row->phone),
            ],
            after: ['removed_by_account_id' => $home['account']->id, 'by' => $home['account']->full_name, 'via' => 'resident_app'],
            tenantId: $context->tenantId,
        );

        return response()->json(['id' => (int) $row->id, 'removed' => true]);
    }

    public function destroyVehicle(int $vehicle, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $table = DB::connection('tenant')->table('household_vehicles');
        $row = (clone $table)->where('id', $vehicle)->where('household_id', $home['household']->id)->first();

        if ($row === null) {
            throw ApiError::notFound('not_found', 'No such vehicle on your household.');
        }

        (clone $table)->where('id', $vehicle)->where('household_id', $home['household']->id)->delete();

        $this->audit->record(
            action: 'household.vehicle_removed',
            entityType: 'Household',
            entityId: (string) $home['household']->id,
            before: ['vehicle_id' => (int) $row->id, 'plate' => (string) $row->plate, 'make' => $row->make, 'model' => $row->model, 'colour' => $row->colour],
            after: ['removed_by_account_id' => $home['account']->id, 'by' => $home['account']->full_name, 'via' => 'resident_app'],
            tenantId: $context->tenantId,
        );

        return response()->json(['id' => (int) $row->id, 'removed' => true]);
    }
}
