<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Services\ResidentApp\ResidentAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The household's emergency contacts FOR THE PANIC SCREEN, at every access level (`panic.contacts`).
 *
 * PANIC IS NEVER REMOVED FROM ANYBODY, and the panic screen's "text / call my emergency contacts" is
 * part of it: while no SMS provider is connected, the server's own texts to a household's contacts reach
 * nobody, so the phone texting them itself is how they hear. The sheet read its people from
 * `me.household` — which a Helper may not read — so it gets a read of its own that every level reaches.
 *
 * WHAT A HELPER LEARNS FROM IT, AND NOTHING MORE: the names, relationships and numbers of the people a
 * panic texts. Not the household's members, vehicles or money. That is the price of the panic leg, and
 * it is the one this read exists to pay.
 *
 * IN THE ORDER THE HOUSEHOLD ADDED THEM — the order the server's own panic texts take them in
 * (`HouseholdContactsSms`): the ones a household thought of first, never a surname's alphabetical luck.
 */
class PanicContactsController extends Controller
{
    public function __construct(private readonly ResidentAccounts $accounts) {}

    public function index(DeviceContext $context): JsonResponse
    {
        $household = $this->accounts->home($context)['household'];

        return response()->json([
            'items' => DB::connection('tenant')->table('household_emergency_contacts')
                ->where('household_id', $household->id)
                ->orderBy('id')
                ->get(['name', 'relationship', 'phone'])
                ->map(static fn (object $c): array => [
                    'name' => (string) $c->name,
                    'relationship' => $c->relationship === null ? null : (string) $c->relationship,
                    'phone' => (string) $c->phone,
                ])->values()->all(),
        ]);
    }
}
