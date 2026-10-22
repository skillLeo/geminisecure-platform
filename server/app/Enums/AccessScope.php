<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which records a role may reach, independent of what it may do to them.
 *
 * This is the axis the Gemini matrix calls `Scoped`. Head of Security is the
 * only role whose header reads "Assigned sites only", and the only role that
 * ever receives a Scoped cell — the two are the same mechanism, so `Scoped` is
 * not a fourth permission level but Full narrowed by scope.
 */
enum AccessScope: string
{
    case All = 'all';
    case AssignedSites = 'assigned_sites';
    case OwnRecords = 'own_records';

    /** The label drawn under the role name in the Gemini matrix header. */
    public function label(): string
    {
        return match ($this) {
            self::All => 'All sites',
            self::AssignedSites => 'Assigned sites only',
            self::OwnRecords => 'Own records only',
        };
    }
}
