<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * An estate's receipt prefix — the `PPV` in `PPV-R-00001` (13 A1).
 *
 * ONE FACT, ON THE ESTATE RECORD. At most six characters, a letter first, set
 * when the estate is provisioned. Receipt numbers are never reused, so a prefix
 * that has printed one receipt has printed a series: changing it afterwards
 * would split one estate's receipts across two sequences, and a resident holding
 * receipt one would hold a number the register no longer draws. So it is
 * immutable from the first receipt, and the refusal is enforced on the model,
 * where every path that saves an estate passes.
 */
class ReceiptPrefix
{
    /** A letter, then up to five letters or digits. */
    public const PATTERN = '/^[A-Z][A-Z0-9]{0,5}$/';

    public const MAX = 6;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * A prefix nobody holds, from the initials of the estate's name.
     *
     * "Phoenix Park Village 1" gives `PPV`, "Ocean View Gardens" gives `OVG`.
     * Digits in the name are skipped, because a phase or a village number is
     * not what anybody abbreviates an estate to. A collision takes a digit.
     */
    public function suggest(string $name, ?string $ignoreEstate = null): string
    {
        preg_match_all('/[A-Za-z]+/', $name, $words);

        $base = strtoupper(implode('', array_map(static fn (string $word): string => $word[0], $words[0])));
        $base = substr($base === '' ? 'E' : $base, 0, self::MAX);

        $candidate = $base;
        $n = 2;

        while ($this->taken($candidate, $ignoreEstate)) {
            $suffix = (string) $n++;
            $candidate = substr($base, 0, self::MAX - strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    /**
     * Refuse a prefix that is malformed or already another estate's.
     *
     * @throws DomainException
     */
    public function assertAvailable(string $prefix, ?string $ignoreEstate = null): void
    {
        if (preg_match(self::PATTERN, $prefix) !== 1) {
            throw new DomainException(
                "A receipt prefix is one to six capital letters or digits, starting with a letter. [{$prefix}] is not."
            );
        }

        if ($this->taken($prefix, $ignoreEstate)) {
            throw new DomainException("Another estate already numbers its receipts [{$prefix}-R-…].");
        }
    }

    /**
     * Whether this estate has issued a receipt.
     *
     * READ FROM THE SEQUENCE'S HIGH-WATER MARK, not from the payments table: a
     * receipt whose payment was later lost still printed a number, and that is
     * enough to fix the prefix. Queried through the schema owner by qualified
     * name — the same credentials tenancy connects an estate with — so it can be
     * asked from the central console without initialising tenancy and leaving
     * the connection pointed somewhere a caller did not expect.
     *
     * An estate whose database has not been built has issued nothing.
     */
    public function issued(Tenant $estate): bool
    {
        $database = config('tenancy.database.prefix').$estate->getTenantKey();
        $owner = DB::connection('mysql_owner');

        $built = $owner->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, 'receipt_sequences'],
        );

        if ((int) ($built->n ?? 0) === 0) {
            return false;
        }

        return $owner->table($database.'.receipt_sequences')->where('last_no', '>', 0)->exists();
    }

    /**
     * Correct a prefix before the estate's first receipt.
     *
     * @throws DomainException when the prefix is malformed, taken, or the estate has issued a receipt
     */
    public function change(Tenant $estate, string $prefix): Tenant
    {
        $prefix = strtoupper(trim($prefix));
        $before = $estate->receipt_prefix;

        if ($before === $prefix) {
            return $estate;
        }

        $this->assertAvailable($prefix, (string) $estate->getTenantKey());

        // The model refuses it too; asking here first gives the refusal its
        // own sentence rather than a failed save.
        if ($this->issued($estate)) {
            throw new DomainException(self::lockedMessage($estate, $before));
        }

        $estate->forceFill(['receipt_prefix' => $prefix])->save();

        $this->audit->record(
            action: 'client.receipt_prefix_set',
            entityType: 'tenant',
            entityId: (string) $estate->getTenantKey(),
            before: ['receipt_prefix' => $before],
            after: ['receipt_prefix' => $prefix],
            tenantId: (string) $estate->getTenantKey(),
        );

        return $estate;
    }

    /** The one sentence every refusal uses, so the command and the model agree. */
    public static function lockedMessage(Tenant $estate, ?string $prefix): string
    {
        return "{$estate->name} has issued receipts numbered [{$prefix}-R-…]. Receipt numbers are never reused, so the prefix cannot change.";
    }

    private function taken(string $prefix, ?string $ignoreEstate): bool
    {
        return Tenant::query()
            ->where('receipt_prefix', $prefix)
            ->when($ignoreEstate !== null, fn ($query) => $query->whereKeyNot($ignoreEstate))
            ->exists();
    }
}
