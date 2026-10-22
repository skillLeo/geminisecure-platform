<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Services\Gemini\StandingOrders;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Standing orders by site and by version (13 D2). Boards guard-app-03 (screen 11)
 * and -04 (screen 16, order history).
 *
 * ACKNOWLEDGED BY VERSION ID. The version a guard read has its own id, so a
 * revision published while the screen was open can never be signed unseen: the
 * old version's id is not the current one, and the acknowledgement is refused.
 */
class OrdersController extends Controller
{
    public function current(string $site, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        if ($site !== $context->tenantId) {
            throw ApiError::forbidden('wrong_site', 'A handset reads the orders for its own site.');
        }

        $sets = DB::connection('mysql')->table('standing_order_sets')
            ->where(function ($query) use ($guard): void {
                $query->whereNull('post_id');

                if ($guard->post_id !== null) {
                    $query->orWhere('post_id', $guard->post_id);
                }
            })
            ->orderByRaw('post_id IS NULL')
            ->orderBy('title')
            ->get();

        $acks = DB::connection('mysql')->table('standing_order_acknowledgements')
            ->where('guard_id', $guard->id)
            ->get()
            ->keyBy(static fn (object $a): string => $a->standing_order_set_id.'@'.$a->version);

        $versionIds = DB::connection('mysql')->table('standing_order_versions')
            ->whereIn('standing_order_set_id', $sets->pluck('id'))
            ->get(['id', 'standing_order_set_id', 'version'])
            ->keyBy(static fn (object $v): string => $v->standing_order_set_id.'@'.$v->version);

        $items = $sets->map(static function (object $set) use ($acks, $versionIds): array {
            $key = $set->id.'@'.$set->version;
            $ack = $acks->get($key);

            return [
                'set_id' => (int) $set->id,
                'version_id' => $versionIds->has($key) ? (int) $versionIds->get($key)->id : null,
                'version' => (int) $set->version,
                'title' => (string) $set->title,
                'effective_on' => (string) $set->effective_on,
                'body' => (string) $set->body,
                'requires_acknowledgement' => $set->post_id !== null,
                'acknowledged_at' => $ack === null ? null : Carbon::parse((string) $ack->acknowledged_at)->toIso8601String(),
            ];
        })->values()->all();

        $history = DB::connection('mysql')->table('standing_order_acknowledgements as a')
            ->join('standing_order_sets as s', 's.id', '=', 'a.standing_order_set_id')
            ->where('a.guard_id', $guard->id)
            ->orderByDesc('a.acknowledged_at')
            ->limit(50)
            ->get(['s.id as set_id', 's.title', 'a.version', 'a.acknowledged_at'])
            ->map(static fn (object $h): array => [
                'set_id' => (int) $h->set_id,
                'title' => (string) $h->title,
                'version' => (int) $h->version,
                'acknowledged_at' => Carbon::parse((string) $h->acknowledged_at)->toIso8601String(),
            ])->all();

        return response()->json(['site_id' => $site, 'items' => $items, 'history' => $history]);
    }

    public function acknowledge(int $version, DeviceContext $context, StandingOrders $orders): JsonResponse
    {
        $guard = $context->guardOrFail();

        $row = DB::connection('mysql')->table('standing_order_versions')->where('id', $version)->first();

        if ($row === null) {
            throw ApiError::notFound('not_found', 'No such version of any standing orders.');
        }

        try {
            $ack = $orders->acknowledge($guard, (int) $row->standing_order_set_id, (int) $row->version);
        } catch (DomainException $refused) {
            throw ApiError::conflict('orders_changed', $refused->getMessage());
        }

        return response()->json([
            'set_id' => $ack['set'],
            'version_id' => (int) $row->id,
            'version' => $ack['version'],
            'acknowledged_at' => $ack['acknowledged_at'],
        ]);
    }
}
