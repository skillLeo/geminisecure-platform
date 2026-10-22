<?php

declare(strict_types=1);

namespace App\Live;

use InvalidArgumentException;

/**
 * Which database's live log an event goes to (DESIGN.md §1.1).
 *
 * ONE LOG PER DATABASE, because a change and its event can share one transaction only if they are
 * in the same database. The central stream (`gs_platform`, code `c`) carries every central record's
 * events; each estate's stream (`gs_estate_*`, code `e`) carries its own, and never leaves it.
 *
 * `Stream::central($tenantId)` is still the ONE central stream; the tenant only says which estate
 * the event concerns (it is stored as the row's `tenant_id`, and it decides which estate's channels
 * the event may name, §1.3). NULL is a platform event, which may name platform channels only.
 */
final class Stream
{
    public const CENTRAL = 'central';

    public const ESTATE = 'estate';

    /** The grammar every estate key follows (EstateProvisioner, §3.1). */
    public const TENANT_PATTERN = '/^[a-z][a-z0-9]{2,29}$/';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $tenantId,
    ) {}

    /** The central stream, about one estate or (NULL) about the platform. */
    public static function central(?string $tenantId = null): self
    {
        if ($tenantId !== null) {
            self::assertTenant($tenantId);
        }

        return new self(self::CENTRAL, $tenantId);
    }

    /** One estate's own stream, in its own database. */
    public static function estate(string $tenantId): self
    {
        self::assertTenant($tenantId);

        return new self(self::ESTATE, $tenantId);
    }

    /** The stream a wake key names: `c`, or `e:{tenant}`. */
    public static function fromKey(string $key): self
    {
        if ($key === 'c') {
            return self::central();
        }

        if (str_starts_with($key, 'e:')) {
            return self::estate(substr($key, 2));
        }

        throw new InvalidArgumentException("'{$key}' is not a live stream key (expected c or e:{tenant}).");
    }

    public function isCentral(): bool
    {
        return $this->kind === self::CENTRAL;
    }

    public function isEstate(): bool
    {
        return $this->kind === self::ESTATE;
    }

    /** The connection the stream's tables are read and written on. */
    public function connection(): string
    {
        return $this->isCentral()
            ? (string) config('tenancy.database.central_connection', 'mysql')
            : 'tenant';
    }

    /** The stream's one row in its own `live_streams`. */
    public function name(): string
    {
        return $this->kind;
    }

    /** `s` in the envelope (§2.2): `c` or `e`. */
    public function code(): string
    {
        return $this->isCentral() ? 'c' : 'e';
    }

    /**
     * What the relay's wake set names (§1.3): `c` for the central stream whatever estate an event
     * concerns (it is one sequence), `e:{tenant}` for an estate's.
     */
    public function key(): string
    {
        return $this->isCentral() ? 'c' : 'e:'.$this->tenantId;
    }

    private static function assertTenant(string $tenantId): void
    {
        if (preg_match(self::TENANT_PATTERN, $tenantId) !== 1) {
            throw new InvalidArgumentException("'{$tenantId}' is not an estate key.");
        }
    }
}
