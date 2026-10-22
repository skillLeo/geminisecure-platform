<?php

declare(strict_types=1);

namespace App\Live\Auth;

use App\Api\AppMatrix;
use App\Api\DeviceContext;
use App\Models\Guard;
use App\Models\ResidentAccount;
use App\Models\User;
use Illuminate\Http\Request;
use LogicException;

/**
 * WHO IS ASKING FOR A LIVE CHANNEL (M11 Part C, DESIGN §3.2, §3.6).
 *
 * One of three kinds, never two: a console User from a web session, a Guard or a
 * ResidentAccount from a handset token (`DeviceContext`, as `ResolveDevice` resolved
 * it). The class is the first question every rule asks, and a principal of the wrong
 * class is simply refused — there is no path by which a User is read as a Guard.
 *
 * ITS KEY IS SIGNED INTO EVERY GRANT. `key()` is `u-12`, `g-5` or `r-9` — prefixed,
 * because ids overlap across the three tables — and it goes into `channel_data` as
 * `user_id`. Reverb keeps it on the subscription, and `terminate_connections` for that
 * key closes every socket the principal holds (§3.6, §7.1).
 *
 * THE PRINCIPAL GATE (§3.2) lives here, so no rule copies it:
 *
 *   `activeUser()`      class User, status active, and not waiting on the contact
 *                       gate (`needsContactVerification()`) — so a socket is never
 *                       wider than the pages, which that gate closes too;
 *   `hostAllows()`      on an estate's own host, only that estate's channels (and
 *                       `user.*`, which every rule for it admits on any host);
 *   `activeGuard()`     class Guard, active, posted at the channel's estate, and that
 *                       estate is the one `ResolveDevice` opened;
 *   `resident()`        class ResidentAccount at the channel's estate, never revoked,
 *                       suspended or deleted, and pending only where the channel
 *                       allows it (`resident.*`, `claim.*`);
 *   `tokenCan()`        the handset token's own ability, as `AppMatrix` issued it.
 *
 * A principal built with `server()` or `fromKey()` has no token: it is the server
 * re-asking a question about somebody (the reconcile backstop, a probe), so its
 * abilities are the app's as the matrix issues them. Neither door ever builds one.
 */
final class Principal
{
    private function __construct(
        public readonly ?User $user,
        public readonly ?Guard $guard,
        public readonly ?ResidentAccount $resident,
        public readonly ?string $hostTenant,
        public readonly ?string $deviceTenant,
        private readonly bool $matrixAbilities,
    ) {}

    /** A console user; `$hostTenant` is the estate whose own host the request came to, if any. */
    public static function web(User $user, ?string $hostTenant = null): self
    {
        return new self($user, null, null, $hostTenant, null, false);
    }

    /** A console user, bound to the host the request arrived at. */
    public static function forRequest(User $user, Request $request): self
    {
        return self::web($user, self::estateHost($request->getHost()));
    }

    /** The handset `ResolveDevice` resolved, with the token it presented. */
    public static function device(DeviceContext $context): self
    {
        if ($context->guard !== null) {
            return new self(null, $context->guard, null, null, $context->tenantId, false);
        }

        if ($context->resident !== null) {
            return new self(null, null, $context->resident, null, $context->tenantId, false);
        }

        throw new LogicException('A device context with neither a guard nor a resident account.');
    }

    /** Somebody the server is asking about, with no request and no token. */
    public static function server(User|Guard|ResidentAccount $who): self
    {
        return match (true) {
            $who instanceof User => new self($who, null, null, null, null, true),
            $who instanceof Guard => new self(null, $who, null, null, null, true),
            default => new self(null, null, $who, null, null, true),
        };
    }

    /** The principal a key names — `u-12`, `g-5`, `r-9` — or null. */
    public static function fromKey(string $key): ?self
    {
        if (preg_match('/\A([ugr])-([1-9][0-9]{0,18})\z/', $key, $parts) !== 1) {
            return null;
        }

        $model = match ($parts[1]) {
            'u' => User::query()->find((int) $parts[2]),
            'g' => Guard::query()->find((int) $parts[2]),
            default => ResidentAccount::query()->find((int) $parts[2]),
        };

        return $model === null ? null : self::server($model);
    }

    /** `u-{id}`, `g-{id}` or `r-{id}`: the `user_id` in every grant this principal holds. */
    public function key(): string
    {
        return match (true) {
            $this->user !== null => 'u-'.$this->user->id,
            $this->guard !== null => 'g-'.$this->guard->id,
            $this->resident !== null => 'r-'.$this->resident->id,
            default => throw new LogicException('A principal with nobody in it.'),
        };
    }

    /** The app a handset principal belongs to, or null for a console user. */
    public function app(): ?string
    {
        return match (true) {
            $this->guard !== null => AppMatrix::GUARD,
            $this->resident !== null => AppMatrix::RESIDENT,
            default => null,
        };
    }

    /** Whether the handset may do this, by its token — or, asked server-side, by the matrix. */
    public function tokenCan(string $ability): bool
    {
        $app = $this->app();

        if ($app === null) {
            return false;
        }

        if ($this->matrixAbilities) {
            return in_array($ability, AppMatrix::abilitiesFor($app), true);
        }

        return $this->guard !== null ? $this->guard->tokenCan($ability) : (bool) $this->resident?->tokenCan($ability);
    }

    /** The console user, if they may hold any live channel at all. */
    public function activeUser(): ?User
    {
        $user = $this->user;

        if ($user === null || $user->status !== 'active' || $user->needsContactVerification()) {
            return null;
        }

        return $user;
    }

    /** Host binding: on an estate's own host, only that estate's channels. */
    public function hostAllows(?string $tenantId): bool
    {
        return $this->hostTenant === null || $tenantId === $this->hostTenant;
    }

    /** The guard, if active and posted at this estate — the estate this handset's request opened. */
    public function activeGuard(string $tenantId): ?Guard
    {
        $guard = $this->guard;

        if ($guard === null || $guard->status !== 'active' || (string) $guard->tenant_id !== $tenantId) {
            return null;
        }

        return $this->deviceTenant === null || $this->deviceTenant === $tenantId ? $guard : null;
    }

    /** The resident account at this estate, if it may hold a channel there. */
    public function resident(string $tenantId, bool $allowPending): ?ResidentAccount
    {
        $account = $this->resident;

        if ($account === null || $account->tenant_id !== $tenantId) {
            return null;
        }

        if ($this->deviceTenant !== null && $this->deviceTenant !== $tenantId) {
            return null;
        }

        if ($account->deleted_at !== null || $account->isRevoked() || $account->status === ResidentAccount::SUSPENDED) {
            return null;
        }

        if ($account->isActive()) {
            return $account;
        }

        return $allowPending && in_array($account->status, [ResidentAccount::PENDING, ResidentAccount::ACTIVE], true) ? $account : null;
    }

    /**
     * The estate an estate console's own host names — `{x}.{estate_domain}` → `x` — or
     * null for the central host and anything that is not an estate's.
     */
    public static function estateHost(string $host): ?string
    {
        $host = strtolower(rtrim($host, '.'));
        $domain = strtolower(trim((string) config('app.estate_domain'), '.'));

        if ($domain === '' || $host === $domain) {
            return null;
        }

        $central = array_map(static fn (mixed $d): string => strtolower((string) $d), (array) config('tenancy.central_domains', []));
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if (in_array($host, $central, true) || $host === $appHost) {
            return null;
        }

        $suffix = '.'.$domain;

        return str_ends_with($host, $suffix) ? substr($host, 0, -strlen($suffix)) : null;
    }
}
