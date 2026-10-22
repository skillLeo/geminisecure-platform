<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Services\Estate\DemoCardPayments;
use App\Services\Estate\HouseholdCards;
use App\Services\ResidentApp\ResidentAccounts;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Save card" (board resident-app-35) and "Saved payment methods" (board resident-app-40) — M50 §4.2 #3.
 *
 *   GET    /me/cards                  this person's saved cards in this home, the default first
 *   POST   /me/cards                  save one: a TEST card's brand, last four, expiry and name — never its number
 *   POST   /me/cards/{card}/default   make it the default
 *   DELETE /me/cards/{card}           remove it (an AutoPay that used it stops, and says why)
 *
 * See `HouseholdCards`.
 */
class CardsController extends Controller
{
    public function __construct(
        private readonly ResidentAccounts $accounts,
        private readonly HouseholdCards $cards,
    ) {}

    public function index(DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $today = SiteTime::today($context->tenantId);

        return response()->json([
            'available' => DemoCardPayments::enabled(),
            'items' => $this->cards->of($home['unit'], $home['account'])->map(static fn ($card): array => HouseholdCards::shape($card, $today))->values()->all(),
        ]);
    }

    public function store(Request $request, DeviceContext $context): JsonResponse
    {
        $data = $request->validate([
            'brand' => ['required', 'string', 'in:visa,mastercard,amex,discover'],
            'last4' => ['required', 'string', 'regex:/^\d{4}$/'],
            'exp_month' => ['required', 'integer', 'between:1,12'],
            'exp_year' => ['required', 'integer', 'between:0,2099'],
            'holder' => ['sometimes', 'nullable', 'string', 'max:120'],
            'default' => ['sometimes', 'boolean'],
        ], [
            'last4.regex' => 'The card is identified by its last four digits.',
            'exp_month.between' => 'Enter the expiry month, 01 to 12.',
        ]);

        $home = $this->accounts->home($context);

        try {
            $card = $this->cards->save($home['unit'], $home['account'], (string) $data['brand'], (string) $data['last4'], (int) $data['exp_month'], (int) $data['exp_year'], $data['holder'] ?? null, (bool) ($data['default'] ?? false));
        } catch (DomainException $refused) {
            throw ApiError::unprocessable('card_refused', $refused->getMessage());
        }

        return response()->json(HouseholdCards::shape($card, SiteTime::today($context->tenantId)), 201);
    }

    public function makeDefault(int $card, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $record = $this->cards->find($home['unit'], $home['account'], $card) ?? throw ApiError::notFound('not_found', 'No saved card of yours with that id.');

        return response()->json(HouseholdCards::shape($this->cards->makeDefault($home['unit'], $home['account'], $record), SiteTime::today($context->tenantId)));
    }

    public function destroy(int $card, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $record = $this->cards->find($home['unit'], $home['account'], $card) ?? throw ApiError::notFound('not_found', 'No saved card of yours with that id.');
        $this->cards->remove($home['unit'], $home['account'], $record);

        return response()->json(['removed' => true]);
    }
}
