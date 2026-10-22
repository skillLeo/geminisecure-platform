<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Api\AccessLevels;
use App\Api\ApiError;
use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Api\DeviceContext;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A HOUSEHOLD MEMBER'S ACCESS LEVEL, CHECKED ON EVERY REQUEST (`AppMatrix::LEVELS`).
 *
 * After `device`, which has resolved the account and opened its estate, and before any route binding
 * — so a level that may not reach an endpoint learns nothing about its records, the rule `device` and
 * `ability` already keep (`bootstrap/app.php`). A refusal answers `403 not_for_your_access_level` in
 * words that say what the endpoint is for, what the person's access is, and who can change it.
 *
 * WHAT IT DOES NOT TOUCH
 *   - a Guard App token on a shared route (panic, sign-out, push) — it has no household;
 *   - an account still waiting on its claim — `device` lets it reach the pending routes only, and
 *     every one of those is open to every level;
 *   - an endpoint every level may reach — nothing is looked up at all, so the panic button never
 *     waits on, or fails with, a read of the register.
 *
 * THE REGISTER ENTRY, NOT THE ACCOUNT. `DeviceContext::$resident` is the Resident App ACCOUNT; the level
 * lives on the estate's register entry it signs in as, read fresh here, so the office or the primary
 * changing it takes effect on the very next request. An entry that cannot be found — removed, or never
 * there — is a Helper: never widened by an error.
 */
final class EnforceAccessLevel
{
    /**
     * Said when an endpoint has no row at all: it is refused to everybody until one is written.
     *
     * Only what is true. `AppMatrix::levelAllows` REPORTS the missing row (`report()`, the platform's error
     * log) — nobody at the estate is told anything, so the sentence does not say they were.
     */
    public const UNLISTED = 'This part of the app isn\'t open to any access level yet. It has been reported so it can be fixed.';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $endpoints = null;

    public function handle(Request $request, Closure $next): Response
    {
        $context = app()->bound(DeviceContext::class) ? app(DeviceContext::class) : null;
        $account = $context instanceof DeviceContext ? $context->resident : null;

        if (! $account instanceof ResidentAccount || ! $account->isActive()) {
            return $next($request);
        }

        $endpoint = Str::after((string) $request->route()?->getName(), 'api.v1.');
        $capability = self::endpoints()[$endpoint]['capability'] ?? null;
        $capability = is_string($capability) ? $capability : null;

        // Open to every level: nothing to look up, and nothing a lookup could break.
        if (self::openToEveryLevel($endpoint, $capability)) {
            return $next($request);
        }

        $resident = $account->resident_id === null ? null : Resident::query()->find($account->resident_id);
        $level = AccessLevels::of($resident);

        if (AppMatrix::levelAllows($level, $endpoint, $capability)) {
            return $next($request);
        }

        // The household the account belongs to — through its unit when the register entry itself is gone.
        $household = $resident !== null ? $resident->household : Unit::query()->with('household')->find($account->unit_id)?->household;

        throw ApiError::forbidden(
            'not_for_your_access_level',
            array_key_exists($endpoint, AppMatrix::LEVELS)
                ? AccessLevels::refusal($endpoint, $level, $household, AccessLevels::primaryOf($household))
                : self::UNLISTED,
        );
    }

    /** Whether every level may reach it — panic, sign-out, the person's own profile and e-pass. */
    private static function openToEveryLevel(string $endpoint, ?string $capability): bool
    {
        foreach (AppMatrix::LEVELS_ALL as $level) {
            if (! AppMatrix::levelAllows($level, $endpoint, $capability)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, array<string, mixed>> */
    private static function endpoints(): array
    {
        return self::$endpoints ??= Catalogue::endpoints();
    }
}
