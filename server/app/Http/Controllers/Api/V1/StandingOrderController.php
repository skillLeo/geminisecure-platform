<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Services\Gemini\StandingOrders;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/standing-orders — the legacy read of a guard's orders.
 *
 * The guard's side of the acknowledgement cycle (12 §2, item 28). Acknowledging
 * moved to `POST /standing-orders/{version}/acknowledge` (13 D2), which signs a
 * version by its own id; the set-and-number form it replaced shared that path
 * and is gone. See `Guard\OrdersController`.
 *
 * INVARIANT 2. Orders are text and version numbers. Nothing here carries an
 * amount, a balance or a household.
 */
class StandingOrderController extends Controller
{
    public function index(StandingOrders $orders, DeviceContext $context): JsonResponse
    {
        return response()->json(['orders' => $orders->forGuard($context->guardOrFail())]);
    }
}
