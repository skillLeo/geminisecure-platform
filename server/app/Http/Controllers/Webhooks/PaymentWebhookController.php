<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Estate\CardGateway;
use App\Services\Payments\GatewayDrivers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * THE CARD PROVIDER'S WEBHOOK — the only thing that moves a card payment (M16 §2.2, M17 §1.2).
 *
 *   POST /api/webhooks/payments/{provider}/{estate}
 *
 * Verified before anything is read: the provider must be the connected one, a webhook secret must be set, and the
 * signature over the RAW body must hold within the replay window. A webhook that fails is refused 401, logged, and
 * never stored — an unsigned request is not the provider, and storing it would let anybody fill the table.
 *
 * Every verified webhook is answered 200 — applied, duplicate, stale or for an intent this estate does not have —
 * because each is final: a provider retries anything else, and a retry of a duplicate is still a duplicate.
 */
final class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, string $estate): JsonResponse
    {
        $driver = GatewayDrivers::current();

        if ($driver === null || $driver->name() !== $provider) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'No card gateway of that name is connected.']], 404);
        }

        $secret = (string) config('payments.gateway.webhook_secret', '');

        if ($secret === '') {
            return response()->json(['error' => ['code' => 'gateway_unconfigured', 'message' => 'The card gateway has no webhook secret set.']], 503);
        }

        $tenant = Tenant::query()->whereKey($estate)->where('status', 'active')->first();

        if (! $tenant instanceof Tenant) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'No such estate.']], 404);
        }

        $raw = (string) $request->getContent();
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        if (! $driver->verify($raw, $headers, $secret, (int) config('payments.gateway.webhook_tolerance_seconds', 300))) {
            Log::warning('A payment webhook was refused: its signature did not verify, or it is outside the replay window.', [
                'provider' => $provider,
                'estate' => $estate,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => ['code' => 'signature_invalid', 'message' => 'The signature did not verify.']], 401);
        }

        try {
            $event = $driver->parse($raw);
        } catch (InvalidArgumentException $malformed) {
            return response()->json(['error' => ['code' => 'malformed', 'message' => $malformed->getMessage()]], 400);
        }

        // Inside the estate: the intent, the ledger and the event log are all that estate's own.
        $outcome = $tenant->run(static fn (): string => app(CardGateway::class)->apply($provider, $event, hash('sha256', $raw)));

        return response()->json(['received' => true, 'outcome' => $outcome]);
    }
}
