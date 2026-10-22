<?php

declare(strict_types=1);

namespace App\Services\Live;

use App\Live\Auth\EstateScope;
use App\Live\SummaryPolicy;
use App\Models\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * gate:isolation STEP 14 - the live log holds nothing it must not (DESIGN.md sections 4.2, 10.1).
 *
 * Every event's stored summary, in the platform's log and in every estate's, for the last seven
 * days (the retention window), is read back and held to SummaryPolicy: no name, money, free text,
 * location, credential or record of anybody's vote. LiveBus refuses such a key before a byte is
 * written, so this is the second line: it would catch a row written some other way, or a policy
 * that was loosened after rows were written under it.
 *
 * It reports the database, the event type and the forbidden KEY, never the value.
 *
 * Pure ASCII.
 */
final class LivePayloadAudit
{
    public const DAYS = 7;

    /**
     * @param  list<string>|null  $estates  estate keys to read; every estate when null
     * @return list<array{status: string, proves: string, detail: string}>
     */
    public function run(?array $estates = null): array
    {
        $results = [$this->audit('the platform', DB::connection((string) config('tenancy.database.central_connection', 'mysql')))];

        $keys = $estates ?? Tenant::estates()->map(static fn (Tenant $t): string => (string) $t->getTenantKey())->all();

        foreach ($keys as $key) {
            try {
                $results[] = EstateScope::run($key, fn (): array => $this->audit("estate {$key}", DB::connection('tenant')));
            } catch (Throwable $e) {
                $results[] = ['status' => 'fail', 'proves' => "estate {$key}'s live log holds no forbidden summary key", 'detail' => 'could not be read: '.class_basename($e)];
            }
        }

        return $results;
    }

    /** @return array{status: string, proves: string, detail: string} */
    private function audit(string $where, Connection $db): array
    {
        $proves = "{$where}'s live log holds no forbidden summary key (last ".self::DAYS.' days)';
        $found = [];
        $read = 0;

        $db->table('live_events')
            ->select(['id', 'type', 'summary'])
            ->whereNotNull('summary')
            ->whereRaw('created_at >= UTC_TIMESTAMP(6) - INTERVAL '.self::DAYS.' DAY')
            ->orderBy('id')
            ->chunk(1000, static function ($rows) use (&$found, &$read): void {
                foreach ($rows as $row) {
                    $read++;
                    $summary = json_decode((string) $row->summary, true);

                    if (! is_array($summary)) {
                        $found[] = "row {$row->id} ({$row->type}): unreadable";

                        continue;
                    }

                    foreach (SummaryPolicy::violations($summary) as $key) {
                        $found[] = "row {$row->id} ({$row->type}): {$key}";
                    }
                }
            });

        if ($found === []) {
            return ['status' => 'pass', 'proves' => $proves." - {$read} summaries read", 'detail' => ''];
        }

        return ['status' => 'fail', 'proves' => $proves, 'detail' => count($found).' forbidden: '.implode('; ', array_slice($found, 0, 10))];
    }
}
