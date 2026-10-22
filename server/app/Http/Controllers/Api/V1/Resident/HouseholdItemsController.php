<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\ResidentApp\ResidentAccounts;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A HOUSEHOLD KEEPS ITS OWN VEHICLES AND PETS (M50 §5.3).
 *
 * The Profile card "Vehicles & pets" listed the cars and offered no way to change one, and pets did not exist at all.
 * Now a vehicle is changed in place (`vehicles.update` — plate, make, model, colour), and a pet is added, changed and
 * removed (`pets.*` — a name and a kind). The household's own rows only, resolved by id AND the signed-in account's
 * household, as every other Resident App write is (`ResidentAccounts::home`); another household's id and an id nobody
 * has are the same 404. Members only, in `AppMatrix::LEVELS`; an estate may open them to Dependents or Helpers
 * (AccessRules · household_edit). No gate decision reads either list.
 *
 * A NEW CLASS, so `MeController` gains no hunk (merge notes, STAGE2 §5.3).
 */
class HouseholdItemsController extends Controller
{
    /** What a pet may be, and how it is said. */
    public const KINDS = ['dog' => 'Dog', 'cat' => 'Cat', 'bird' => 'Bird', 'fish' => 'Fish', 'rabbit' => 'Rabbit', 'other' => 'Other'];

    public function __construct(
        private readonly ResidentAccounts $accounts,
        private readonly AuditLogger $audit,
    ) {}

    /* ------------------------------------------------------------------ a vehicle, changed in place */

    public function updateVehicle(Request $request, int $vehicle, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate([
            'plate' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9 \-]+$/'],
            'make' => ['required', 'string', 'max:40'],
            'model' => ['required', 'string', 'max:40'],
            'colour' => ['required', 'string', 'max:24'],
        ], [
            'make.required' => "Enter the vehicle's make — a guard checks it against the car at the gate.",
            'model.required' => "Enter the vehicle's model.",
            'colour.required' => "Enter the vehicle's colour.",
        ]);

        $table = DB::connection('tenant')->table('household_vehicles');
        $row = (clone $table)->where('id', $vehicle)->where('household_id', $home['household']->id)->first();

        if ($row === null) {
            throw ApiError::notFound('not_found', 'No such vehicle on your household.');
        }

        $plate = strtoupper(preg_replace('/\s+/', ' ', trim($data['plate'])) ?? '');
        $taken = (clone $table)->where('household_id', $home['household']->id)->where('plate', $plate)->where('id', '!=', $vehicle)->exists();

        if ($taken) {
            throw ApiError::conflict('already_listed', 'That plate is already on your list for another vehicle.');
        }

        $changes = ['plate' => $plate, 'make' => trim($data['make']), 'model' => trim($data['model']), 'colour' => trim($data['colour'])];
        (clone $table)->where('id', $vehicle)->update([...$changes, 'updated_at' => Carbon::now()]);

        $this->audit->record(
            action: 'household.vehicle_changed',
            entityType: 'Household',
            entityId: (string) $home['household']->id,
            before: ['vehicle_id' => (int) $row->id, 'plate' => (string) $row->plate, 'make' => $row->make, 'model' => $row->model, 'colour' => $row->colour],
            after: [...$changes, 'by_account_id' => $home['account']->id, 'by' => $home['account']->full_name, 'via' => 'resident_app'],
            tenantId: $context->tenantId,
        );

        return response()->json(self::vehicleShape((clone $table)->where('id', $vehicle)->first()));
    }

    /* ------------------------------------------------------------------ pets */

    public function pets(DeviceContext $context): JsonResponse
    {
        $household = $this->accounts->home($context)['household'];

        return response()->json(['items' => self::petsOf((int) $household->id)]);
    }

    /** A name and a kind. The same pet again (its name in any capitals, and its kind) is answered with the row already there. */
    public function addPet(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $data = $this->validPet($request);
        $table = DB::connection('tenant')->table('household_pets');

        $listed = (clone $table)->where('household_id', $home['household']->id)->where('kind', $data['kind'])->get()
            ->first(static fn (object $pet): bool => mb_strtolower((string) $pet->name) === mb_strtolower($data['name']));

        $id = $listed->id ?? $table->insertGetId([
            'household_id' => $home['household']->id,
            'name' => $data['name'],
            'kind' => $data['kind'],
            'added_by_account_id' => $home['account']->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return response()->json([...self::petShape((clone $table)->where('id', $id)->first()), 'already_listed' => $listed !== null], $listed === null ? 201 : 200);
    }

    public function updatePet(Request $request, int $pet, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $data = $this->validPet($request);
        $table = DB::connection('tenant')->table('household_pets');
        $row = (clone $table)->where('id', $pet)->where('household_id', $home['household']->id)->first();

        if ($row === null) {
            throw ApiError::notFound('not_found', 'No such pet on your household.');
        }

        (clone $table)->where('id', $pet)->update([...$data, 'updated_at' => Carbon::now()]);

        $this->audit->record(
            action: 'household.pet_changed',
            entityType: 'Household',
            entityId: (string) $home['household']->id,
            before: ['pet_id' => (int) $row->id, 'name' => (string) $row->name, 'kind' => (string) $row->kind],
            after: [...$data, 'by_account_id' => $home['account']->id, 'by' => $home['account']->full_name, 'via' => 'resident_app'],
            tenantId: $context->tenantId,
        );

        return response()->json(self::petShape((clone $table)->where('id', $pet)->first()));
    }

    public function destroyPet(int $pet, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $table = DB::connection('tenant')->table('household_pets');
        $row = (clone $table)->where('id', $pet)->where('household_id', $home['household']->id)->first();

        if ($row === null) {
            throw ApiError::notFound('not_found', 'No such pet on your household.');
        }

        (clone $table)->where('id', $pet)->where('household_id', $home['household']->id)->delete();

        $this->audit->record(
            action: 'household.pet_removed',
            entityType: 'Household',
            entityId: (string) $home['household']->id,
            before: ['pet_id' => (int) $row->id, 'name' => (string) $row->name, 'kind' => (string) $row->kind],
            after: ['removed_by_account_id' => $home['account']->id, 'by' => $home['account']->full_name, 'via' => 'resident_app'],
            tenantId: $context->tenantId,
        );

        return response()->json(['id' => (int) $row->id, 'removed' => true]);
    }

    /**
     * The household's pets, by name — for `pets.index` and `me.household`. None on an estate not yet migrated.
     *
     * @return list<array<string, mixed>>
     */
    public static function petsOf(int $householdId): array
    {
        try {
            return DB::connection('tenant')->table('household_pets')->where('household_id', $householdId)->orderBy('name')->orderBy('id')->get()
                ->map(static fn (object $pet): array => self::petShape($pet))->values()->all();
        } catch (QueryException) {
            // An estate whose migrations have not reached this release has no pets table yet: none to show, and the
            // household's other lists still answer. One query either way — never a schema lookup on every read.
            return [];
        }
    }

    /** @return array<string, mixed> */
    public static function petShape(object $pet): array
    {
        $kind = (string) $pet->kind;

        return ['id' => (int) $pet->id, 'name' => (string) $pet->name, 'kind' => $kind, 'kind_label' => self::KINDS[$kind] ?? ucfirst($kind)];
    }

    /** @return array<string, mixed> */
    private static function vehicleShape(object $v): array
    {
        return ['id' => (int) $v->id, 'plate' => (string) $v->plate, 'make' => $v->make, 'model' => $v->model, 'colour' => $v->colour];
    }

    /** @return array{name: string, kind: string} */
    private function validPet(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:40'],
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys(self::KINDS))],
        ], [
            'name.required' => "Enter your pet's name.",
            'kind.required' => 'Choose what kind of pet it is.',
            'kind.in' => 'Choose what kind of pet it is: '.implode(', ', array_map('strtolower', self::KINDS)).'.',
        ]);

        return ['name' => trim(preg_replace('/\s+/', ' ', (string) $data['name']) ?? ''), 'kind' => (string) $data['kind']];
    }
}
