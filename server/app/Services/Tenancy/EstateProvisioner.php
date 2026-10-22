<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates an estate: its tenant record, its domain, its own database, its own
 * MySQL user, and its append-only grants.
 *
 * Business logic lives here rather than in a controller so that the Inertia
 * console and the /api/v1 endpoints the mobile apps will call cannot drift
 * apart — both call this.
 */
class EstateProvisioner
{
    public function __construct(private readonly ReceiptPrefix $receiptPrefix) {}

    /**
     * Subdomains that must never become an estate, because each already
     * resolves to something else on the central domain.
     */
    private const RESERVED_SUBDOMAINS = [
        'www', 'api', 'admin', 'app', 'mail', 'ftp', 'localhost',
        'gemini', 'console', 'static', 'assets', 'cdn', 'status',
    ];

    /**
     * @param  string  $subdomain  becomes the tenant id AND the database suffix
     * @param  string  $name  display name, e.g. "Phoenix Park"
     * @param  string|null  $receiptPrefix  the `PPV` in `PPV-R-00001`; suggested from the name when omitted
     */
    public function provision(string $subdomain, string $name, string $status = 'onboarding', ?string $receiptPrefix = null): Tenant
    {
        $subdomain = strtolower(trim($subdomain));

        $this->assertValidSubdomain($subdomain);

        /*
         * THE RECEIPT PREFIX IS CHOSEN HERE, with the name (13 A1). It is fixed
         * by the estate's first receipt, so provisioning is the moment it is
         * easiest to get right; checked before the database exists, so a taken
         * prefix refuses the estate rather than leaving one half-built.
         */
        $receiptPrefix = $receiptPrefix === null
            ? $this->receiptPrefix->suggest($name)
            : strtoupper(trim($receiptPrefix));

        $this->receiptPrefix->assertAvailable($receiptPrefix);

        /*
         * Creating the tenant fires TenantCreated, whose job pipeline creates
         * the database, runs the tenant migrations, and applies the
         * append-only grants — synchronously, so a failure anywhere leaves no
         * half-provisioned estate behind.
         */
        $tenant = Tenant::create([
            'id' => $subdomain,
            'name' => $name,
            'receipt_prefix' => $receiptPrefix,
            'status' => $status,
            'provisioned_at' => now(),
        ]);

        /*
         * The bare subdomain, NOT the full hostname.
         *
         * InitializeTenancyBySubdomain strips the central domain off the host
         * and looks up what remains, so storing "oceanview.geminisecure.test"
         * here makes the tenant unidentifiable — the resolver searches for
         * "oceanview" and finds nothing. Storing the subdomain also means the
         * estate domain can change in config without rewriting every row.
         */
        $tenant->domains()->create(['domain' => $subdomain]);

        return $tenant->refresh();
    }

    private function assertValidSubdomain(string $subdomain): void
    {
        if (! preg_match('/^[a-z][a-z0-9]{2,29}$/', $subdomain)) {
            throw new InvalidArgumentException(
                "Invalid subdomain [{$subdomain}]. Use 3-30 characters, lowercase letters and digits, ".
                'starting with a letter. It becomes both a hostname and a MySQL database name, so '.
                'hyphens and underscores are excluded to keep the two forms identical.'
            );
        }

        if (in_array($subdomain, self::RESERVED_SUBDOMAINS, true)) {
            throw new InvalidArgumentException("Subdomain [{$subdomain}] is reserved.");
        }

        if (Tenant::find($subdomain)) {
            throw new InvalidArgumentException("Estate [{$subdomain}] already exists.");
        }

        // A leftover database from a failed provision would be silently adopted
        // by the next attempt, handing one estate another's data.
        $database = config('tenancy.database.prefix').$subdomain;

        $exists = DB::connection('mysql_owner')->select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$database],
        );

        if ($exists !== []) {
            throw new InvalidArgumentException(
                "Database [{$database}] already exists but no tenant record references it. ".
                'Refusing to adopt it — inspect and drop it deliberately.'
            );
        }
    }
}
